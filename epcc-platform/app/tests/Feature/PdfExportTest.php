<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\McCertificate;
use App\Models\Project;
use App\Models\PunchItem;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\McCertificateService;
use App\Services\PdfRenderer;
use App\Services\PunchListService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use RuntimeException;
use Tests\TestCase;

/**
 * PDF export via headless Chrome (risk IR-R2).
 *
 * The risk being retired here is NOT "can we make a PDF". It is: does Persian
 * survive the trip? Persian is a joining script with contextual letterforms,
 * ZWNJ, and Latin identifiers embedded in RTL text under the bidi algorithm.
 * A renderer that gets this wrong fails SILENTLY - a page that looks plausible
 * to a non-Persian reader and is gibberish to the client receiving it.
 */
final class PdfExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    private function company(string $code): Company
    {
        return Company::where('code', $code)->firstOrFail();
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function skipWithoutBrowser(): void
    {
        if (! app(PdfRenderer::class)->isAvailable()) {
            $this->markTestSkipped('no headless browser on this host');
        }
    }

    /** Close both open A-items so the certificate can issue on merit. */
    private function clearBlockingItems(): void
    {
        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $pars = $this->company('EPC-PARS');
        $nioc = $this->company('NIOC-AFF');
        $jafari = $this->user('jafari@karan.example');
        $rezaei = $this->user('rezaei@pars.example');
        $karimi = $this->user('karimi@nioc.example');
        $punch = app(PunchListService::class);

        foreach (['PH-14-PL-0001', 'PH-14-PL-0002'] as $number) {
            $item = $this->ctx()->runAs($pars->id, function () use ($number, $rezaei, $punch) {
                $i = PunchItem::where('number', $number)->first();

                return $punch->markFixed($i, $rezaei, 'Done.', 'en');
            }, $project->id);

            $item = $this->ctx()->runAs($mc->id,
                fn () => $punch->verify($item->refresh(), $jafari, true, 'ok', 'en'), $project->id);

            $this->ctx()->runAs($nioc->id,
                fn () => $punch->close($item->refresh(), $karimi, 'en'), $project->id);
        }
    }

    // ---- the renderer itself -----------------------------------------------

    public function test_a_missing_browser_fails_loudly_with_an_actionable_message(): void
    {
        // The dangerous behaviour would be a silent fallback that ships a text
        // file named .pdf to a client.
        $renderer = new PdfRenderer(30, ['definitely-not-a-real-browser']);

        $this->assertFalse($renderer->isAvailable());

        try {
            $renderer->render('<p>x</p>');
            $this->fail('a missing browser was not reported');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No headless browser', $e->getMessage());
            // Actionable: it must say how to fix it.
            $this->assertStringContainsString('apt-get install', $e->getMessage());
        }
    }

    public function test_rendering_produces_a_real_pdf(): void
    {
        $this->skipWithoutBrowser();

        $bytes = app(PdfRenderer::class)->render(
            '<!DOCTYPE html><html><body><p>'.str_repeat('hello ', 50).'</p></body></html>'
        );

        $this->assertSame('%PDF-', substr($bytes, 0, 5), 'output is not a PDF');
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_output_that_is_not_a_pdf_is_rejected(): void
    {
        /*
         * Chrome can exit 0 having written a truncated or empty file. Without
         * this check the bytes go straight into a Content-Type: application/pdf
         * response and the client gets a "corrupt file" dialog for a document
         * they were told is a signed certificate.
         *
         * Simulated with a stand-in binary that writes something plausible but
         * wrong, which is exactly the dangerous case.
         */
        $fake = $this->fakeBrowser('not a pdf at all');

        try {
            $renderer = new PdfRenderer(30, [$fake]);
            $this->assertTrue($renderer->isAvailable(), 'the stand-in binary was not picked up');

            $renderer->render('<p>x</p>');
            $this->fail('a non-PDF was accepted and would have been served as one');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a PDF', $e->getMessage());
        } finally {
            @unlink($fake);
        }
    }

    public function test_a_truncated_pdf_is_rejected(): void
    {
        // Correct magic bytes, implausible length: a half-written file.
        $fake = $this->fakeBrowser('%PDF-1.4 truncated');

        try {
            (new PdfRenderer(30, [$fake]))->render('<p>x</p>');
            $this->fail('a truncated PDF was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('suspiciously small', $e->getMessage());
        } finally {
            @unlink($fake);
        }
    }

    public function test_the_renderer_leaves_no_scratch_directories_behind(): void
    {
        $this->skipWithoutBrowser();

        // Chrome treats the scratch HOME as real and mints .cache/.config/.local
        // inside it, so a plain rmdir() silently fails and /tmp fills up.
        $pattern = sys_get_temp_dir().'/epcc-pdf-*';
        $before = count(glob($pattern) ?: []);

        app(PdfRenderer::class)->render('<!DOCTYPE html><html><body><p>'.str_repeat('x ', 300).'</p></body></html>');

        $this->assertSame($before, count(glob($pattern) ?: []), 'the renderer leaked a scratch directory');
    }

    public function test_a_runaway_document_times_out_instead_of_hanging(): void
    {
        $this->skipWithoutBrowser();

        $renderer = new PdfRenderer(1);
        $spin = '<html><body><script>const t=Date.now();while(Date.now()-t<20000){}</script>x</body></html>';

        $started = microtime(true);

        try {
            $renderer->render($spin);
            $this->fail('a runaway render was not stopped');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertLessThan(15, microtime(true) - $started, 'the timeout did not actually bound the wait');
        }
    }

    // ---- Persian rendering: the actual risk (IR-R2) ------------------------

    public function test_persian_text_survives_the_round_trip(): void
    {
        $this->skipWithoutBrowser();

        $html = view('print.layout', ['lang' => 'fa', 'title' => 'probe'])->render();
        $html = str_replace('</body>', '<p>پیش‌نویس ارجاع‌شده تأیید نواقص گچ ژاله</p></body>', $html);

        $bytes = app(PdfRenderer::class)->render($html);
        $text = $this->pdfText($bytes);

        // Every one of these exercises something that breaks in a naive
        // renderer: ZWNJ, hamza, gaf, zhe.
        foreach (['پیش', 'نویس', 'تأیید', 'نواقص', 'گچ', 'ژاله'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Persian text lost: {$needle}");
        }
    }

    public function test_the_persian_font_is_embedded_rather_than_substituted(): void
    {
        $this->skipWithoutBrowser();

        if (trim((string) shell_exec('command -v pdffonts')) === '') {
            $this->markTestSkipped('pdffonts (poppler-utils) not available');
        }

        $html = view('print.layout', ['lang' => 'fa', 'title' => 'probe'])->render();
        $html = str_replace('</body>', '<p>گواهی تکمیل مکانیکی</p></body>', $html);

        $file = tempnam(sys_get_temp_dir(), 'fontprobe').'.pdf';
        file_put_contents($file, app(PdfRenderer::class)->render($html));

        $fonts = (string) shell_exec('pdffonts '.escapeshellarg($file).' 2>/dev/null');
        @unlink($file);

        // A substituted font is how Persian silently degrades to boxes or
        // disconnected letters on a machine that lacks the real one.
        $this->assertStringContainsString('Vazirmatn', $fonts, 'Vazirmatn was not embedded');
        $this->assertStringContainsString('yes', $fonts, 'no embedded font at all');
    }

    // ---- the certificate export -------------------------------------------

    public function test_the_certificate_exports_as_a_pdf_in_both_languages(): void
    {
        $this->skipWithoutBrowser();
        $this->clearBlockingItems();

        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);
        $cert = $this->ctx()->runAs($mc->id, fn () => McCertificate::first(), $project->id);
        $this->ctx()->runAs($mc->id,
            fn () => app(McCertificateService::class)->issue($cert, $jafari, 'en'), $project->id);

        foreach (['fa', 'en'] as $lang) {
            $response = $this->actingAs($jafari)
                ->get("/projects/PH-14/subsystems/{$sub->id}/certificate.pdf?lang={$lang}");

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $response->assertHeader('x-content-type-options', 'nosniff');

            $bytes = $response->getContent();
            $this->assertSame('%PDF-', substr($bytes, 0, 5), "{$lang} export is not a PDF");

            $text = $this->pdfText($bytes);
            $this->assertStringContainsString('PH-14-MCC-0001', $text, "{$lang} export lost the number");
        }
    }

    public function test_the_pdf_states_the_certificate_number_in_its_filename(): void
    {
        $this->skipWithoutBrowser();
        $this->clearBlockingItems();

        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');
        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);

        $response = $this->actingAs($jafari)
            ->get("/projects/PH-14/subsystems/{$sub->id}/certificate.pdf?lang=en");

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="PH-14-MCC-0001.pdf"');
    }

    public function test_the_exported_certificate_records_the_exception_not_a_live_count(): void
    {
        $this->skipWithoutBrowser();

        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $jafari = $this->user('jafari@karan.example');
        $karimi = $this->user('karimi@nioc.example');
        $mcc = app(McCertificateService::class);

        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);
        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);

        $cert = $this->ctx()->runAs($nioc->id, fn () => $mcc->approveException(
            $cert, $karimi, 'Deferred to the first maintenance window.', 'en',
        ), $project->id);

        $this->ctx()->runAs($mc->id, fn () => $mcc->issue($cert->refresh(), $jafari, 'en'), $project->id);

        $response = $this->actingAs($jafari)
            ->get("/projects/PH-14/subsystems/{$sub->id}/certificate.pdf?lang=en");

        $text = $this->pdfText($response->getContent());

        // The waived items must be NAMED on the certificate. "Issued with an
        // exception" without saying what was waived is not evidence.
        $this->assertStringContainsString('Owner-approved exception', $text);
        $this->assertStringContainsString('PH-14-PL-0001', $text);
        $this->assertStringContainsString('PH-14-PL-0002', $text);
        $this->assertStringContainsString('Deferred to the first maintenance window', $text);
    }

    public function test_the_certificate_names_the_person_who_issued_it(): void
    {
        // A Mechanical Completion certificate is signature evidence: it says a
        // named person, on a named date, accepted a subsystem into the record.
        // The blade guards the signatory line with @if($issuer), so an issuer
        // that fails to resolve does not error - the line simply vanishes and
        // the PDF still exports, still 200, still a valid PDF, and no longer
        // says who issued it.
        //
        // That resolution goes through a plain User::find() on a table that is
        // now under RLS, and the issuer is usually from ANOTHER company than
        // the reader (the owner issues; the contractor reads). This asserts
        // the counterparty policy actually covers it, from both sides.
        $this->skipWithoutBrowser();
        $this->clearBlockingItems();

        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $karimi = $this->user('karimi@nioc.example');

        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);
        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);

        // Issued by the OWNER, so every other participant reads it across a
        // company boundary.
        $this->ctx()->runAs($nioc->id,
            fn () => app(McCertificateService::class)->issue($cert, $karimi, 'en'), $project->id);

        foreach (['jafari@karan.example', 'rezaei@pars.example', 'karimi@nioc.example'] as $email) {
            $reader = $this->user($email);

            $text = $this->pdfText($this->actingAs($reader)
                ->get("/projects/PH-14/subsystems/{$sub->id}/certificate.pdf?lang=fa")
                ->assertOk()
                ->getContent());

            $this->assertStringContainsString($karimi->name, $text,
                "the certificate read by {$email} does not name its issuer");
            $this->assertStringContainsString('صادرکننده', $text,
                "the signatory line is missing for {$email}");
        }
    }

    public function test_an_issued_certificate_does_not_rewrite_its_own_evidence(): void
    {
        // Found by downloading the same certificate twice.
        //
        // The punch summary - total / closed / open category-A - is the
        // technical justification for the handover, and it was recomputed live
        // on every render. So an ISSUED certificate's own numbers drifted as
        // the site worked on: issued over two open A-items, it quietly became
        // a certificate issued over one. Always in the flattering direction,
        // always erasing exactly what an auditor would look for.
        //
        // `exception_items` already froze the waived list; this asserts the
        // summary is frozen the same way.
        $this->skipWithoutBrowser();

        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $pars = $this->company('EPC-PARS');
        $jafari = $this->user('jafari@karan.example');
        $karimi = $this->user('karimi@nioc.example');
        $rezaei = $this->user('rezaei@pars.example');
        $mcc = app(McCertificateService::class);
        $punch = app(PunchListService::class);

        $sub = $this->ctx()->runAs($mc->id,
            fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);
        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);

        // Issue over the two open A-items, under an owner exception.
        $cert = $this->ctx()->runAs($nioc->id, fn () => $mcc->approveException(
            $cert, $karimi, 'Accepted against a written undertaking.', 'en',
        ), $project->id);
        $this->ctx()->runAs($nioc->id,
            fn () => $mcc->issue($cert->refresh(), $karimi, 'en'), $project->id);

        $url = "/projects/PH-14/subsystems/{$sub->id}/certificate.pdf?lang=en";
        $before = $this->pdfText($this->actingAs($jafari)->get($url)->assertOk()->getContent());

        $this->assertStringContainsString('Open — category A:', $before);
        $this->assertMatchesRegularExpression('/Open\s*—\s*category A:\s*2/u', $before,
            'the certificate did not record two open A-items at issue');

        // The site carries on: one of the waived items is genuinely finished.
        $this->ctx()->runAs($pars->id, fn () => $punch->markFixed(
            PunchItem::where('number', 'PH-14-PL-0001')->firstOrFail(), $rezaei, 'Guard fitted.', 'en',
        ), $project->id);
        $this->ctx()->runAs($mc->id, fn () => $punch->verify(
            PunchItem::where('number', 'PH-14-PL-0001')->firstOrFail(), $jafari, true, 'Checked.', 'en',
        ), $project->id);
        $this->ctx()->runAs($nioc->id, fn () => $punch->close(
            PunchItem::where('number', 'PH-14-PL-0001')->firstOrFail(), $karimi, 'en',
        ), $project->id);

        // The live register must move...
        $live = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')
            ->firstOrFail()->openBlockingItems()->count(), $project->id);
        $this->assertSame(1, $live, 'the punch item was not actually closed');

        // ...and the issued certificate must not.
        $after = $this->pdfText($this->actingAs($jafari)->get($url)->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/Open\s*—\s*category A:\s*2/u', $after,
            'an issued certificate rewrote its own punch summary after the fact');
        $this->assertMatchesRegularExpression('/Closed:\s*2/u', $after,
            'the closed count on an issued certificate drifted');
    }

    public function test_a_draft_certificate_still_shows_live_numbers(): void
    {
        // The counterweight. A DRAFT is a working view - somebody deciding
        // whether to issue needs today's position, not a frozen one. Only
        // issuing freezes.
        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $pars = $this->company('EPC-PARS');
        $jafari = $this->user('jafari@karan.example');
        $karimi = $this->user('karimi@nioc.example');
        $rezaei = $this->user('rezaei@pars.example');
        $punch = app(PunchListService::class);

        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);
        $this->assertSame(McCertificate::DRAFT, $cert->state, 'the fixture certificate is not a draft');

        $sub = $this->ctx()->runAs($mc->id,
            fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);

        $before = $this->ctx()->runAs($mc->id,
            fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail()
                ->openBlockingItems()->count(), $project->id);

        $this->ctx()->runAs($pars->id, fn () => $punch->markFixed(
            PunchItem::where('number', 'PH-14-PL-0001')->firstOrFail(), $rezaei, 'Guard fitted.', 'en',
        ), $project->id);
        $this->ctx()->runAs($mc->id, fn () => $punch->verify(
            PunchItem::where('number', 'PH-14-PL-0001')->firstOrFail(), $jafari, true, 'Checked.', 'en',
        ), $project->id);
        $this->ctx()->runAs($nioc->id, fn () => $punch->close(
            PunchItem::where('number', 'PH-14-PL-0001')->firstOrFail(), $karimi, 'en',
        ), $project->id);

        $items = $this->ctx()->runAs($mc->id,
            fn () => PunchItem::where('subsystem_id', $sub->id)->get(), $project->id);
        $openA = $items->filter(fn (PunchItem $i) => $i->blocksMc())->values();

        // refresh() re-reads the row, so it needs a tenant binding: outside
        // runAs() RLS hides the certificate and refresh() raises rather than
        // returning stale data.
        $summary = $this->ctx()->runAs($nioc->id,
            fn () => $cert->refresh()->punchSummary($items, $openA), $project->id);

        $this->assertSame($before - 1, $summary['open_blocking'],
            'a draft certificate is not tracking the live punch list');
    }

    public function test_only_issuing_freezes_the_summary_not_merely_having_one(): void
    {
        // Asserted on the model, not through a page.
        //
        // punchSummary() returns the snapshot only when the certificate is
        // ISSUED *and* a snapshot exists. Today draft() never writes one, so
        // dropping the isIssued() half changes no rendered page and no
        // end-to-end test - the branch is unreachable through the UI. It is
        // still the load-bearing half: the day a snapshot gets written earlier
        // (at draft, at a revision, by an import), a draft would silently stop
        // tracking the live register and start reporting a stale position to
        // the person deciding whether to issue.
        //
        // So the state is set directly and the contract asserted, rather than
        // leaving an untested branch or deleting a guard that is protecting a
        // real future mistake.
        $project = $this->project();
        $nioc = $this->company('NIOC-AFF');

        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);
        $this->assertSame(McCertificate::DRAFT, $cert->state);

        // A snapshot present on a DRAFT must be ignored.
        $cert->punch_summary = ['total' => 99, 'closed' => 99, 'open_blocking' => 99];

        $items = collect();
        $summary = $cert->punchSummary($items, collect());

        $this->assertSame(0, $summary['total'],
            'a draft returned a stored snapshot instead of the live count');
        $this->assertSame(99, $cert->punch_summary['total'],
            'the snapshot itself should be untouched');

        // The same object, once issued, must use it.
        $cert->state = McCertificate::ISSUED;
        $this->assertSame(99, $cert->punchSummary($items, collect())['total'],
            'an issued certificate ignored its frozen summary');
    }

    public function test_a_non_participant_cannot_export_the_certificate(): void
    {
        $outsider = $this->user('hosseini@arvand.example');
        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);

        $this->actingAs($outsider)
            ->get("/projects/PH-14/subsystems/{$sub->id}/certificate.pdf?lang=en")
            ->assertNotFound();
    }

    /**
     * A stand-in "browser" that writes $content to the --print-to-pdf target
     * and exits 0 - i.e. the dangerous case where the renderer believes it
     * succeeded. Nowdoc, so PHP does not interpolate the shell's ${...}.
     *
     * @return string path to the executable
     */
    private function fakeBrowser(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fakechrome');

        $script = <<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --print-to-pdf=*) target="${arg#--print-to-pdf=}" ;;
  esac
done
printf '%s' 'PAYLOAD' > "$target"
exit 0
SH;

        file_put_contents($path, str_replace('PAYLOAD', $content, $script));
        chmod($path, 0700);

        return $path;
    }

    /** Extract text from PDF bytes, tolerating a host without poppler. */
    private function pdfText(string $bytes): string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) not available');
        }

        $file = tempnam(sys_get_temp_dir(), 'pdftext').'.pdf';
        file_put_contents($file, $bytes);
        $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($file).' - 2>/dev/null');
        @unlink($file);

        /*
         * pdftotext emits bidi control characters (U+202A..U+202E, U+200E/F)
         * around RTL runs. They are invisible but they break naive substring
         * matching, so strip them before asserting.
         */
        return preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? $text;
    }

    // ---- the transmittal sheet ---------------------------------------------

    public function test_the_transmittal_sheet_exports_as_a_pdf(): void
    {
        $this->skipWithoutBrowser();

        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->user('rezaei@pars.example');

        $transmittal = $this->ctx()->runAs($pars->id,
            fn () => \App\Models\Transmittal::where('number', 'PH-14-TR-0002')->firstOrFail(),
            $project->id);

        $response = $this->actingAs($rezaei)
            ->get("/projects/PH-14/transmittals/{$transmittal->id}/sheet.pdf?lang=fa");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'attachment; filename="PH-14-TR-0002.pdf"');

        $text = $this->pdfText($response->getContent());

        $this->assertStringContainsString('PH-14-TR-0002', $text);
        // The document snapshot, which is the evidentiary content of the sheet.
        $this->assertStringContainsString('PH-14-PIPING-DWG-0001', $text);
    }

    public function test_the_zip_carries_a_pdf_cover_sheet(): void
    {
        $this->skipWithoutBrowser();

        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->user('rezaei@pars.example');

        $transmittal = $this->ctx()->runAs($pars->id,
            fn () => \App\Models\Transmittal::where('number', 'PH-14-TR-0002')->firstOrFail(),
            $project->id);

        $response = $this->actingAs($rezaei)
            ->get("/projects/PH-14/transmittals/{$transmittal->id}/download?lang=fa");

        $response->assertOk();

        $file = tempnam(sys_get_temp_dir(), 'zipprobe').'.zip';
        file_put_contents($file, $response->streamedContent() ?: $response->getContent());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true, 'the package is not a readable ZIP');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $pdf = $zip->getFromName('TRANSMITTAL.pdf');
        $zip->close();
        @unlink($file);

        $this->assertContains('MANIFEST.csv', $names);
        $this->assertContains('TRANSMITTAL.pdf', $names, 'the ZIP has no PDF cover sheet');
        // The text sheet stays: it is readable with no tooling at all, which
        // matters on a site PC that opens a ZIP but has no PDF reader.
        $this->assertContains('TRANSMITTAL.txt', $names);
        $this->assertSame('%PDF-', substr((string) $pdf, 0, 5));
    }

    public function test_the_zip_still_builds_when_no_renderer_exists(): void
    {
        /*
         * The ZIP's job is to deliver the client's drawings. A host without a
         * browser must still get its files - only the cover PDF is dropped.
         * This is the ONE place a fallback is correct.
         */
        $this->app->bind(PdfRenderer::class, fn () => new PdfRenderer(30, ['definitely-not-a-real-browser']));

        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->user('rezaei@pars.example');

        $transmittal = $this->ctx()->runAs($pars->id,
            fn () => \App\Models\Transmittal::where('number', 'PH-14-TR-0002')->firstOrFail(),
            $project->id);

        $response = $this->actingAs($rezaei)
            ->get("/projects/PH-14/transmittals/{$transmittal->id}/download?lang=fa");

        $response->assertOk();

        $file = tempnam(sys_get_temp_dir(), 'zipprobe').'.zip';
        file_put_contents($file, $response->streamedContent() ?: $response->getContent());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($file);

        $this->assertContains('MANIFEST.csv', $names, 'the manifest went missing without a renderer');
        $this->assertContains('TRANSMITTAL.txt', $names, 'the text fallback went missing');
        $this->assertNotContains('TRANSMITTAL.pdf', $names);
    }

    public function test_the_standalone_sheet_endpoint_has_no_silent_fallback(): void
    {
        // Opposite of the ZIP: here the PDF IS the deliverable, so a missing
        // renderer must be reported, never quietly downgraded to something else.
        $this->app->bind(PdfRenderer::class, fn () => new PdfRenderer(30, ['definitely-not-a-real-browser']));

        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->user('rezaei@pars.example');

        $transmittal = $this->ctx()->runAs($pars->id,
            fn () => \App\Models\Transmittal::where('number', 'PH-14-TR-0002')->firstOrFail(),
            $project->id);

        $this->actingAs($rezaei)
            ->get("/projects/PH-14/transmittals/{$transmittal->id}/sheet.pdf?lang=fa")
            ->assertStatus(503);
    }

    public function test_the_punch_page_offers_the_pdf_for_a_draft_certificate(): void
    {
        /*
         * Caught on the live page, not by a test: the download link was inside
         * the "issued" branch, so a draft certificate had a working endpoint
         * and no way to reach it. Drafts get circulated for wet-ink review, and
         * the sheet already prints "DRAFT - not valid".
         */
        $jafari = $this->user('jafari@karan.example');

        $response = $this->actingAs($jafari)->get('/projects/PH-14/punch?lang=en');

        $response->assertOk();
        $response->assertSee('certificate.pdf', false);
        $response->assertSee('Download certificate (PDF)');
    }
}
