<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the document register will accept, and what it calls it.
 *
 * ALLOW-LIST, NEVER A DENY-LIST. A deny-list of "dangerous" extensions is a
 * list of the attacks someone already thought of; everything absent is
 * permitted by default. Here, anything not named below is refused.
 *
 * The extension is taken from OUR list, not from the upload: the browser's
 * Content-Type header and the filename are both user input, and a file called
 * `plan.pdf` containing PHP is trivial to produce.
 */
final class FileType
{
    /**
     * extension => [mime, english label, persian label]
     *
     * Chosen for what an EPCC document register actually receives: issued PDFs,
     * native CAD, scans, and the spreadsheets MTOs arrive in.
     *
     * @var array<string,array{0:string,1:string,2:string}>
     */
    private const ALLOWED = [
        'pdf'  => ['application/pdf', 'PDF document', 'سند PDF'],
        'dwg'  => ['application/acad', 'AutoCAD drawing', 'نقشه اتوکد'],
        'dxf'  => ['image/vnd.dxf', 'CAD exchange file', 'فایل تبادل CAD'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Word document', 'سند ورد'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Excel workbook', 'کاربرگ اکسل'],
        'png'  => ['image/png', 'PNG image', 'تصویر PNG'],
        'jpg'  => ['image/jpeg', 'JPEG image', 'تصویر JPEG'],
        'jpeg' => ['image/jpeg', 'JPEG image', 'تصویر JPEG'],
        'tif'  => ['image/tiff', 'TIFF scan', 'اسکن TIFF'],
        'tiff' => ['image/tiff', 'TIFF scan', 'اسکن TIFF'],
        'zip'  => ['application/zip', 'ZIP archive', 'بایگانی ZIP'],
    ];

    /**
     * Only these render in the browser's own viewer (FR-DOC-02).
     *
     * Everything else downloads. Note what is NOT here: SVG and HTML are
     * images/documents to a user but executable script to a browser, so they
     * are not accepted at all, let alone inlined.
     *
     * @var list<string>
     */
    private const INLINE_VIEWABLE = ['pdf', 'png', 'jpg', 'jpeg'];

    /** Per-file ceiling. Native CAD and scanned drawing sets are genuinely large. */
    public const MAX_BYTES = 100 * 1024 * 1024;

    public static function isAllowed(string $extension): bool
    {
        return array_key_exists(strtolower($extension), self::ALLOWED);
    }

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return array_keys(self::ALLOWED);
    }

    /** The MIME type WE assign. Never the one the client sent. */
    public static function mime(string $extension): string
    {
        return self::ALLOWED[strtolower($extension)][0] ?? 'application/octet-stream';
    }

    public static function label(string $extension, string $lang = 'fa'): string
    {
        $row = self::ALLOWED[strtolower($extension)] ?? null;

        if ($row === null) {
            return strtoupper($extension);
        }

        return $lang === 'fa' ? $row[2] : $row[1];
    }

    /** May the browser display this inline, or must it download? */
    public static function isInlineViewable(string $extension): bool
    {
        return in_array(strtolower($extension), self::INLINE_VIEWABLE, true);
    }

    /**
     * Human-readable size. Persian digits in Persian, per FR-I18N-03.
     */
    public static function humanSize(int $bytes, string $lang = 'fa'): string
    {
        $units = $lang === 'fa'
            ? ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت']
            : ['B', 'KB', 'MB', 'GB'];

        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        $text = $i === 0
            ? sprintf('%d %s', (int) $size, $units[$i])
            : sprintf('%.1f %s', $size, $units[$i]);

        return $lang === 'fa' ? \Morilog\Jalali\CalendarUtils::convertNumbers($text) : $text;
    }

    /**
     * Make a user-supplied filename safe to show and to send in a header.
     *
     * This is DISPLAY sanitisation. It is not what keeps the filesystem safe -
     * that is handled by never using this value in a path at all.
     */
    public static function sanitiseName(string $name): string
    {
        // Strip any directory component the browser may have sent.
        $name = basename(str_replace('\\', '/', $name));

        // TRUNCATE at the first control character rather than deleting it.
        // Deleting splices the two halves together, so
        // "evil\r\nX-Injected: 1.pdf" becomes the innocuous-looking
        // "evilX-Injected: 1.pdf" - safe, but it preserves the attacker's
        // payload as a filename the user may well trust. Cutting the string
        // discards everything after the smuggling attempt.
        $cut = preg_split('/[\x00-\x1F\x7F]/u', $name);
        $name = $cut === false ? '' : ($cut[0] ?? '');

        // Quotes, backslashes and semicolons still need removing: they
        // terminate or extend the Content-Disposition parameter itself.
        $name = preg_replace('/["\\\\;]+/u', '', $name) ?? '';
        $name = trim($name) ?: 'file';

        return mb_substr($name, 0, 200);
    }
}
