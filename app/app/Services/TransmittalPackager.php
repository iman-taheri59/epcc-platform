<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentFile;
use App\Models\Project;
use App\Models\Transmittal;
use App\Support\FileType;
use Illuminate\Support\Facades\Storage;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;
use ZipArchive;

/**
 * Bulk ZIP export with manifest (SRS FR-ENG-05).
 *
 * The recipient of a transmittal wants one file they can save to their own
 * server, not fourteen downloads. The manifest is what makes the ZIP evidence
 * rather than a folder: it records the number, revision, state and SHA-256 of
 * every file at the moment of issue, so the receiving party can prove months
 * later that the bytes they hold are the bytes that were sent.
 */
final class TransmittalPackager
{
    public function __construct(
        private readonly PdfRenderer $pdf,
    ) {
    }

    /**
     * Build the ZIP and return its path on local disk.
     *
     * Written to a temp file rather than memory: a transmittal can carry
     * several hundred megabytes of CAD, and buffering that per request is how
     * a server falls over on the day of a big issue.
     */
    public function build(Transmittal $transmittal, string $lang = 'fa'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tr_').'.zip';
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the transmittal archive.');
        }

        $rows = [];
        $seen = [];

        foreach ($transmittal->items as $item) {
            $files = DocumentFile::where('revision_id', $item->revision_id)->get();

            foreach ($files as $file) {
                $disk = Storage::disk($file->disk);

                if (! $disk->exists($file->storage_path)) {
                    // Record the gap rather than failing the whole export: a
                    // missing blob is worth reporting in the manifest, not a
                    // reason the recipient gets nothing at all.
                    $rows[] = [$item, $file, 'MISSING', null];

                    continue;
                }

                // Name inside the ZIP: document number + revision + the
                // original filename. Prefixed so two revisions of the same
                // drawing cannot collide, and de-duplicated defensively.
                $entry = sprintf(
                    '%s_Rev%s_%s',
                    $item->number_at_issue,
                    $item->revision_at_issue,
                    FileType::sanitiseName($file->original_name),
                );

                $n = 1;
                $unique = $entry;

                while (isset($seen[$unique])) {
                    $unique = preg_replace('/(\.[^.]+)$/', '_'.(++$n).'$1', $entry) ?? $entry.'_'.$n;
                }

                $seen[$unique] = true;

                $zip->addFile($disk->path($file->storage_path), $unique);
                $rows[] = [$item, $file, 'OK', $unique];
            }
        }

        $zip->addFromString('MANIFEST.csv', $this->manifest($transmittal, $rows, $lang));
        /*
         * The cover sheet as a PDF when a renderer is available, falling back
         * to the plain-text sheet when it is not.
         *
         * The fallback is deliberate HERE and nowhere else: the ZIP's job is to
         * deliver the client's DRAWINGS, and refusing to build it because a
         * cover page cannot be typeset would be the tail wagging the dog. The
         * standalone /sheet.pdf endpoint has no fallback - there the PDF *is*
         * the deliverable, so it fails loudly instead.
         */
        $zip->addFromString('TRANSMITTAL.txt', $this->coverSheet($transmittal, $lang));

        $sheet = $this->coverSheetPdf($transmittal, $lang);

        if ($sheet !== null) {
            $zip->addFromString('TRANSMITTAL.pdf', $sheet);
        }
        $zip->close();

        return $path;
    }

    /**
     * CSV manifest. UTF-8 with a BOM, because Excel on a Persian Windows
     * install renders UTF-8 CSV as mojibake without one - and this file exists
     * to be opened in Excel by a document controller.
     *
     * @param  list<array{0:mixed,1:mixed,2:string,3:?string}>  $rows
     */
    private function manifest(Transmittal $transmittal, array $rows, string $lang): string
    {
        $out = "\u{FEFF}";
        $head = $lang === 'fa'
            ? ['شمارهٔ مدرک', 'بازنگری', 'وضعیت', 'کد تناسب', 'نام فایل', 'حجم (بایت)', 'SHA-256', 'نتیجه']
            : ['Document number', 'Revision', 'State', 'Suitability', 'File', 'Size (bytes)', 'SHA-256', 'Result'];
        $out .= implode(',', $head)."\r\n";

        foreach ($rows as [$item, $file, $result, $entry]) {
            $out .= implode(',', array_map($this->csv(...), [
                $item->number_at_issue,
                $item->revision_at_issue,
                $item->state_at_issue,
                $item->suitability_at_issue ?? '',
                $entry ?? $file->original_name,
                (string) $file->size_bytes,
                $file->sha256,
                $result,
            ]))."\r\n";
        }

        return $out;
    }

    /** The cover sheet as a PDF, or null when this host cannot render one. */
    private function coverSheetPdf(Transmittal $transmittal, string $lang): ?string
    {
        // Fast path only. The catch below is what actually guarantees the ZIP
        // survives a broken renderer - deleting this check alone changes
        // nothing observable, it just avoids spawning a doomed process.
        if (! $this->pdf->isAvailable()) {
            return null;
        }

        $project = Project::find($transmittal->project_id);

        if ($project === null) {
            return null;
        }

        try {
            return $this->pdf->render(view('print.transmittal', [
                'lang' => $lang,
                'title' => $transmittal->number,
                'project' => $project,
                'transmittal' => $transmittal,
                'sender' => Company::find($transmittal->company_id),
                'generatedAt' => CalendarUtils::convertNumbers(Jalalian::now()->format('Y/m/d')),
            ])->render());
        } catch (\RuntimeException) {
            // A cover page must never cost the client their drawings.
            return null;
        }
    }

    /** Bilingual cover sheet, always both languages regardless of UI language. */
    private function coverSheet(Transmittal $transmittal, string $lang): string
    {
        $issued = $transmittal->issued_at;
        $gregorian = $issued?->format('Y-m-d H:i') ?? '-';
        $jalali = $issued ? CalendarUtils::convertNumbers(
            Jalalian::fromCarbon($issued)->format('Y/m/d H:i')
        ) : '-';

        $recipients = $transmittal->recipients
            ->map(fn ($r) => '  - '.($r->company->code ?? '?').' '.($r->company?->name_en ?? ''))
            ->implode("\n");

        $items = $transmittal->items
            ->map(fn ($i) => sprintf(
                '  %-30s Rev %-4s %-10s %s',
                $i->number_at_issue,
                $i->revision_at_issue,
                $i->state_at_issue,
                $i->suitability_at_issue ?? '',
            ))
            ->implode("\n");

        // Deliberately both languages in one sheet (FR-I18N-06): the sender
        // and the recipient may not read the same one.
        return <<<TXT
        ================================================================
        TRANSMITTAL / نامهٔ ارسال مدارک
        ================================================================

        Number / شماره      : {$transmittal->number}
        Subject (EN)        : {$transmittal->subject_en}
        موضوع (FA)          : {$transmittal->subject_fa}
        Purpose / هدف       : {$transmittal->purposeLabel('en')} / {$transmittal->purposeLabel('fa')}
        Issued / تاریخ صدور : {$gregorian}  ({$jalali})

        Recipients / گیرندگان:
        {$recipients}

        Documents / مدارک:
        {$items}

        ----------------------------------------------------------------
        Every file in this archive is listed in MANIFEST.csv with its
        SHA-256 checksum as issued. Verify the checksums to confirm the
        contents have not changed since issue.

        تمام فایل‌های این بسته به همراه اثر انگشت SHA-256 در فایل
        MANIFEST.csv فهرست شده‌اند.
        ================================================================
        TXT;
    }

    private function csv(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
