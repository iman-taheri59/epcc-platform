<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\Project;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use App\Services\TransmittalService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Vendor transmittals as a separate stream (FR-ENG-05b).
 *
 * The property under test is SEPARATION. A vendor letter is internal to the
 * contractor's supply chain; a contractor letter is a contractual submission
 * that starts a review clock and is evidence in a delay claim. If the two ever
 * merge, the number that answers "how many submissions have we made to the
 * client" becomes wrong, and that number is what the product exists to defend.
 */
final class VendorTransmittalTest extends TestCase
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

    private function svc(): TransmittalService
    {
        return app(TransmittalService::class);
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function company(string $code): Company
    {
        return Company::where('code', $code)->firstOrFail();
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    /** Run inside the EPC contractor's tenant scope, which records vendor letters. */
    private function asPars(callable $fn): mixed
    {
        return $this->ctx()->runAs($this->company('EPC-PARS')->id, $fn, $this->project()->id);
    }

    // ---- numbering -------------------------------------------------------

    public function test_the_two_streams_use_separate_number_series(): void
    {
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $project = $this->project();

        [$contractor, $vendorTr] = $this->asPars(function () use ($project, $rezaei, $vendor): array {
            $c = $this->svc()->draft($project, $rezaei, 'Contract sub', 'ارسال قراردادی', 'FOR_REVIEW');
            $v = $this->svc()->draft(
                $project, $rezaei, 'Pump datasheets', 'دیتاشیت پمپ', 'FOR_REVIEW',
                null, 'fa', Transmittal::VENDOR, $vendor, 'AZ-2026-114',
            );

            return [$c, $v];
        });

        $this->assertStringContainsString('-TR-', $contractor->number);
        $this->assertStringContainsString('-VTR-', $vendorTr->number);

        /*
         * Both series continue independently from whatever the fixture seeded
         * (TR-0001..0003 and VTR-0001..0002). Asserting a literal number here
         * would just pin the fixture; what matters is that the two counters do
         * not share a value space, so the same sequence number can exist in
         * both streams at once.
         */
        $counts = $this->asPars(fn () => [
            Transmittal::VENDOR => Transmittal::where('kind', Transmittal::VENDOR)->count(),
            Transmittal::CONTRACTOR => Transmittal::where('kind', Transmittal::CONTRACTOR)->count(),
        ]);

        // Each new letter takes the next number WITHIN ITS OWN STREAM, so the
        // sequence equals that stream's row count - never the table's.
        $this->assertSame($counts[Transmittal::VENDOR], $vendorTr->sequence, 'the vendor counter is not per-stream');
        $this->assertSame($counts[Transmittal::CONTRACTOR], $contractor->sequence, 'the contractor counter is not per-stream');

        // The decisive property: the same sequence number lives in both
        // streams at once, which a shared counter makes impossible.
        $shared = $this->asPars(fn () => Transmittal::where('sequence', $vendorTr->sequence)->count());
        $this->assertGreaterThan(1, $shared, 'the two streams share one value space');
    }

    public function test_a_vendor_letter_does_not_perforate_the_contractual_series(): void
    {
        /*
         * The regression that a shared counter would cause: issuing vendor
         * letters between two contractor letters must not leave gaps in the
         * contractual numbering, because a gap in a submission register looks
         * like a missing submission.
         */
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $project = $this->project();

        $numbers = $this->asPars(function () use ($project, $rezaei, $vendor): array {
            $first = $this->svc()->draft($project, $rezaei, 'A', 'الف', 'FOR_REVIEW');

            for ($i = 0; $i < 3; $i++) {
                $this->svc()->draft(
                    $project, $rezaei, "V{$i}", "و{$i}", 'FOR_REVIEW',
                    null, 'fa', Transmittal::VENDOR, $vendor,
                );
            }

            $second = $this->svc()->draft($project, $rezaei, 'B', 'ب', 'FOR_REVIEW');

            return [$first->sequence, $second->sequence];
        });

        $this->assertSame($numbers[0] + 1, $numbers[1], 'vendor letters consumed contractual numbers');
    }

    // ---- integrity -------------------------------------------------------

    public function test_a_vendor_transmittal_must_name_its_vendor(): void
    {
        $rezaei = $this->user('rezaei@pars.example');
        $project = $this->project();

        try {
            $this->asPars(fn () => $this->svc()->draft(
                $project, $rezaei, 'No vendor', 'بدون وندور', 'FOR_REVIEW',
                null, 'en', Transmittal::VENDOR, null,
            ));
            $this->fail('a vendor transmittal was created with no vendor');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must name the vendor', $e->getMessage());
        }
    }

    public function test_a_contractor_transmittal_cannot_name_a_vendor(): void
    {
        $rezaei = $this->user('rezaei@pars.example');
        $project = $this->project();

        try {
            $this->asPars(fn () => $this->svc()->draft(
                $project, $rezaei, 'Mixed', 'مختلط', 'FOR_REVIEW',
                null, 'en', Transmittal::CONTRACTOR, $this->company('VEN-AZAR'),
            ));
            $this->fail('a contractor transmittal accepted a vendor');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot name a vendor', $e->getMessage());
        }
    }

    public function test_the_recording_company_cannot_be_its_own_vendor(): void
    {
        // Otherwise a contractor could file its own contractual submissions in
        // the vendor stream and drop them out of the client-facing count.
        $rezaei = $this->user('rezaei@pars.example');
        $project = $this->project();

        try {
            $this->asPars(fn () => $this->svc()->draft(
                $project, $rezaei, 'Self', 'خود', 'FOR_REVIEW',
                null, 'en', Transmittal::VENDOR, $this->company('EPC-PARS'),
            ));
            $this->fail('a company was allowed to be its own vendor');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot also be the vendor', $e->getMessage());
        }
    }

    public function test_the_database_rejects_a_mismatched_kind_even_outside_the_service(): void
    {
        /*
         * The service is not the only way rows get created - seeders, console
         * commands and future import jobs bypass it. The CHECK constraint is
         * the guarantee that holds regardless.
         */
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asPars(fn () => \Illuminate\Support\Facades\DB::table('transmittals')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'company_id' => $pars->id,
            'kind' => 'VENDOR',
            'vendor_company_id' => null,   // the violation
            'number' => 'PH-14-VTR-9999',
            'sequence' => 9999,
            'subject_en' => 'x', 'subject_fa' => 'x',
            'purpose' => 'FOR_REVIEW', 'status' => 'DRAFT',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    // ---- roll-up ---------------------------------------------------------

    /** @return array{0:Transmittal,1:Transmittal,2:Document} */
    private function vendorLetterWithDocument(): array
    {
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $pmc = $this->company('PMC-TEHRAN');
        $project = $this->project();

        return $this->asPars(function () use ($project, $rezaei, $vendor, $pmc): array {
            $document = Document::where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail();

            $vendorTr = $this->svc()->draft(
                $project, $rezaei, 'Pump GA', 'نقشهٔ کلی پمپ', 'FOR_REVIEW',
                null, 'fa', Transmittal::VENDOR, $vendor, 'AZ-77',
            );
            $this->svc()->addItem($vendorTr, $document);
            $this->svc()->addRecipient($vendorTr, $pmc);
            $this->svc()->issue($vendorTr, $rezaei);

            $target = $this->svc()->draft($project, $rezaei, 'Fwd to client', 'ارسال به کارفرما', 'FOR_APPROVAL');

            return [$vendorTr->refresh(), $target, $document];
        });
    }

    public function test_a_vendor_letter_rolls_up_into_a_contractor_letter(): void
    {
        [$vendorTr, $target] = $this->vendorLetterWithDocument();

        $created = $this->asPars(fn () => $this->svc()->rollUp($vendorTr, $target));

        $this->assertCount(1, $created);

        $item = $created[0];
        $this->assertSame($target->id, $item->transmittal_id);

        // The chain: this contractor item knows which vendor item it came from.
        $source = $this->asPars(fn () => TransmittalItem::find($item->source_item_id));
        $this->assertNotNull($source, 'the roll-up lost its source link');
        $this->assertSame($vendorTr->id, $source->transmittal_id);
    }

    public function test_the_rolled_up_snapshot_is_what_the_vendor_actually_sent(): void
    {
        /*
         * If the vendor issues a later revision after sending Rev B, the
         * contractor's forward of Rev B must still say Rev B. Copying from the
         * source item rather than re-reading the document is what guarantees
         * that.
         */
        [$vendorTr, $target] = $this->vendorLetterWithDocument();

        $sourceItem = $this->asPars(fn () => $vendorTr->items()->firstOrFail());
        $created = $this->asPars(fn () => $this->svc()->rollUp($vendorTr, $target));

        $this->assertSame($sourceItem->number_at_issue, $created[0]->number_at_issue);
        $this->assertSame($sourceItem->revision_at_issue, $created[0]->revision_at_issue);
        $this->assertSame($sourceItem->suitability_at_issue, $created[0]->suitability_at_issue);
    }

    public function test_rolling_the_same_letter_up_twice_does_not_duplicate_documents(): void
    {
        [$vendorTr, $target] = $this->vendorLetterWithDocument();

        $this->asPars(fn () => $this->svc()->rollUp($vendorTr, $target));
        $second = $this->asPars(fn () => $this->svc()->rollUp($vendorTr, $target));

        $this->assertSame([], $second, 'a second roll-up duplicated the documents');
        $this->assertSame(1, $this->asPars(fn () => $target->items()->count()));
    }

    public function test_an_unissued_vendor_letter_cannot_be_rolled_up(): void
    {
        // Forwarding a draft would put the EPC's name on something it never
        // formally received.
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $project = $this->project();

        [$draftVendor, $target] = $this->asPars(function () use ($project, $rezaei, $vendor): array {
            $v = $this->svc()->draft(
                $project, $rezaei, 'Draft', 'پیش‌نویس', 'FOR_REVIEW',
                null, 'en', Transmittal::VENDOR, $vendor,
            );
            $t = $this->svc()->draft($project, $rezaei, 'Target', 'مقصد', 'FOR_REVIEW');

            return [$v, $t];
        });

        try {
            $this->asPars(fn () => $this->svc()->rollUp($draftVendor, $target, 'en'));
            $this->fail('a draft vendor letter was rolled up');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('has not been issued', $e->getMessage());
        }
    }

    public function test_a_contractor_letter_cannot_be_rolled_up(): void
    {
        // Roll-up is one-directional: supply chain inward, contract outward.
        $rezaei = $this->user('rezaei@pars.example');
        $project = $this->project();

        [$a, $b] = $this->asPars(fn () => [
            Transmittal::where('number', 'PH-14-TR-0001')->firstOrFail(),
            $this->svc()->draft($project, $rezaei, 'T', 'ت', 'FOR_REVIEW'),
        ]);

        try {
            $this->asPars(fn () => $this->svc()->rollUp($a, $b, 'en'));
            $this->fail('a contractor letter was rolled up');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only a vendor transmittal', $e->getMessage());
        }
    }

    public function test_a_vendor_letter_cannot_be_the_roll_up_target(): void
    {
        [$vendorTr] = $this->vendorLetterWithDocument();

        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $project = $this->project();

        $otherVendor = $this->asPars(fn () => $this->svc()->draft(
            $project, $rezaei, 'Other', 'دیگر', 'FOR_REVIEW',
            null, 'en', Transmittal::VENDOR, $vendor,
        ));

        try {
            $this->asPars(fn () => $this->svc()->rollUp($vendorTr, $otherVendor, 'en'));
            $this->fail('a vendor letter was used as a roll-up target');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be a contractor transmittal', $e->getMessage());
        }
    }

    public function test_an_issued_contractor_letter_cannot_receive_a_roll_up(): void
    {
        // Immutability still holds: the roll-up is just another way of adding
        // items, and an issued letter's contents are fixed.
        [$vendorTr] = $this->vendorLetterWithDocument();

        $issued = $this->asPars(fn () => Transmittal::where('number', 'PH-14-TR-0001')->firstOrFail());
        $this->assertNotNull($issued->issued_at, 'fixture assumption: TR-0001 is issued');

        // TR-0001 is a contractor letter AND issued, so assertDraft must fire.
        try {
            $this->asPars(fn () => $this->svc()->rollUp($vendorTr, $issued, 'en'));
            $this->fail('an issued letter accepted a roll-up');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('issued', $e->getMessage());
        }
    }

    // ---- the two registers are separate places -------------------------

    public function test_the_contractor_register_never_shows_vendor_letters(): void
    {
        /*
         * The separation the user asked for, asserted at the screen. If a
         * vendor letter appears here it reads as a submission to the client.
         */
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $project = $this->project();

        $vendorTr = $this->asPars(fn () => $this->svc()->draft(
            $project, $rezaei, 'Vendor only', 'فقط وندور', 'FOR_REVIEW',
            null, 'fa', Transmittal::VENDOR, $vendor,
        ));

        $contractor = $this->actingAs($rezaei)
            ->get('/projects/PH-14/transmittals?lang=fa')->assertOk()->getContent();

        $this->assertStringNotContainsString($vendorTr->number, $contractor);
        $this->assertStringNotContainsString('-VTR-', $contractor);

        // ...and the contractual letters are still there.
        $this->assertStringContainsString('PH-14-TR-0001', $contractor);
    }

    public function test_the_vendor_register_never_shows_contractor_letters(): void
    {
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $project = $this->project();

        $vendorTr = $this->asPars(fn () => $this->svc()->draft(
            $project, $rezaei, 'Vendor only', 'فقط وندور', 'FOR_REVIEW',
            null, 'fa', Transmittal::VENDOR, $vendor,
        ));

        $html = $this->actingAs($rezaei)
            ->get('/projects/PH-14/vendor-transmittals?lang=fa')->assertOk()->getContent();

        $this->assertStringContainsString($vendorTr->number, $html);
        $this->assertStringNotContainsString('PH-14-TR-0001', $html);
    }

    public function test_the_vendor_register_names_the_originating_vendor(): void
    {
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $project = $this->project();

        $this->asPars(fn () => $this->svc()->draft(
            $project, $rezaei, 'GA', 'کلی', 'FOR_REVIEW',
            null, 'fa', Transmittal::VENDOR, $vendor, 'AZ-99',
        ));

        $html = $this->actingAs($rezaei)
            ->get('/projects/PH-14/vendor-transmittals?lang=fa')->assertOk()->getContent();

        // Both facts a document controller needs: who sent it, and under what
        // reference of their own.
        $this->assertStringContainsString('VEN-AZAR', $html);
        $this->assertStringContainsString('AZ-99', $html);
    }

    public function test_both_registers_are_reachable_from_the_sidebar(): void
    {
        $rezaei = $this->user('rezaei@pars.example');

        $html = $this->actingAs($rezaei)
            ->get('/projects/PH-14/transmittals?lang=fa')->assertOk()->getContent();

        $this->assertStringContainsString('/projects/PH-14/vendor-transmittals', $html);
    }

    public function test_the_roll_up_endpoint_forwards_the_documents(): void
    {
        [$vendorTr, $target] = $this->vendorLetterWithDocument();
        $rezaei = $this->user('rezaei@pars.example');

        $this->actingAs($rezaei)
            ->post("/projects/PH-14/vendor-transmittals/{$vendorTr->id}/rollup?lang=en", [
                'target_id' => $target->id,
            ])
            ->assertRedirect();

        $count = $this->asPars(fn () => TransmittalItem::where('transmittal_id', $target->id)->count());
        $this->assertSame(1, $count, 'the endpoint did not roll the document up');
    }

    public function test_the_create_endpoint_honours_the_vendor_kind(): void
    {
        /*
         * Caught by mutation testing: every other test here calls the service
         * directly, so a controller that ignored the submitted `kind` and
         * filed everything as CONTRACTOR passed the whole suite while the
         * vendor screen silently wrote into the contractual series.
         */
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $pmc = $this->company('PMC-TEHRAN');

        $document = $this->asPars(
            fn () => Document::where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail()
        );

        $this->actingAs($rezaei)
            ->post('/projects/PH-14/transmittals?lang=en', [
                'kind' => Transmittal::VENDOR,
                'vendor_company_id' => $vendor->id,
                'vendor_reference' => 'AZ-555',
                'subject_en' => 'Vendor GA',
                'subject_fa' => 'نقشهٔ وندور',
                'purpose' => 'FOR_REVIEW',
                'documents' => [$document->id],
                'recipients' => [$pmc->id],
            ])
            ->assertRedirect();

        $created = $this->asPars(
            fn () => Transmittal::where('vendor_reference', 'AZ-555')->first()
        );

        $this->assertNotNull($created, 'the transmittal was not created');
        $this->assertSame(Transmittal::VENDOR, $created->kind, 'the endpoint filed a vendor letter as contractual');
        $this->assertStringContainsString('-VTR-', $created->number);
        $this->assertSame($vendor->id, $created->vendor_company_id);
    }

    public function test_the_create_endpoint_returns_the_user_to_the_right_register(): void
    {
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $pmc = $this->company('PMC-TEHRAN');

        $document = $this->asPars(
            fn () => Document::where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail()
        );

        $this->actingAs($rezaei)
            ->post('/projects/PH-14/transmittals?lang=fa', [
                'kind' => Transmittal::VENDOR,
                'vendor_company_id' => $vendor->id,
                'subject_en' => 'x', 'subject_fa' => 'ایکس',
                'purpose' => 'FOR_REVIEW',
                'documents' => [$document->id],
                'recipients' => [$pmc->id],
            ])
            ->assertRedirectContains('/vendor-transmittals');
    }

    public function test_the_vendor_sheet_distinguishes_the_vendor_from_the_recorder(): void
    {
        /*
         * The PDF is the formal record. On a vendor letter the originator and
         * the company that recorded it are different, and the sheet must not
         * read as though the EPC wrote the vendor's letter.
         */
        if (! app(\App\Services\PdfRenderer::class)->isAvailable()) {
            $this->markTestSkipped('no headless browser on this host');
        }

        [$vendorTr] = $this->vendorLetterWithDocument();
        $rezaei = $this->user('rezaei@pars.example');

        $html = view('print.transmittal', [
            'lang' => 'en',
            'title' => $vendorTr->number,
            'project' => $this->project(),
            'transmittal' => $this->asPars(
                fn () => Transmittal::with(['items', 'recipients.company', 'vendor'])->find($vendorTr->id)
            ),
            'sender' => $this->company('EPC-PARS'),
            'generatedAt' => '2026-09-11',
        ])->render();

        $this->assertStringContainsString('Vendor', $html);
        $this->assertStringContainsString('VEN-AZAR', $html);
        $this->assertStringContainsString('Recorded by', $html);
        $this->assertStringContainsString('AZ-77', $html);
    }

    // ---- isolation at the DATA layer, not just the screen ----------------

    public function test_the_employer_does_not_see_vendor_letters_by_default(): void
    {
        /*
         * The screen filters by kind, but a filter is not isolation. The
         * Employer's blanket "I see every issued letter in my project" clause
         * would otherwise expose the contractor's supply-chain correspondence
         * to the client - separation that holds in the UI and leaks in the
         * data is not separation.
         */
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $pmc = $this->company('PMC-TEHRAN');
        $nioc = $this->company('NIOC-AFF');
        $project = $this->project();

        $document = $this->asPars(
            fn () => Document::where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail()
        );

        $vendorTr = $this->asPars(function () use ($project, $rezaei, $vendor, $pmc, $document) {
            $tr = $this->svc()->draft(
                $project, $rezaei, 'Supply chain', 'زنجیرهٔ تأمین', 'FOR_REVIEW',
                null, 'fa', Transmittal::VENDOR, $vendor, 'AZ-1',
            );
            $this->svc()->addItem($tr, $document);
            $this->svc()->addRecipient($tr, $pmc);

            return $this->svc()->issue($tr, $rezaei);
        });

        $visible = $this->ctx()->runAs(
            $nioc->id,
            fn () => Transmittal::whereKey($vendorTr->id)->exists(),
            $project->id,
        );

        $this->assertFalse($visible, "a vendor letter leaked to the employer through RLS");
    }

    public function test_the_employer_does_see_a_vendor_letter_addressed_to_them(): void
    {
        // Naming the Employer as a recipient is a deliberate act of sharing.
        // The narrowed policy must not break that.
        $rezaei = $this->user('rezaei@pars.example');
        $vendor = $this->company('VEN-AZAR');
        $nioc = $this->company('NIOC-AFF');
        $project = $this->project();

        $document = $this->asPars(
            fn () => Document::where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail()
        );

        $vendorTr = $this->asPars(function () use ($project, $rezaei, $vendor, $nioc, $document) {
            $tr = $this->svc()->draft(
                $project, $rezaei, 'Shared with owner', 'ارسال به کارفرما', 'FOR_INFORMATION',
                null, 'fa', Transmittal::VENDOR, $vendor, 'AZ-2',
            );
            $this->svc()->addItem($tr, $document);
            $this->svc()->addRecipient($tr, $nioc);

            return $this->svc()->issue($tr, $rezaei);
        });

        $visible = $this->ctx()->runAs(
            $nioc->id,
            fn () => Transmittal::whereKey($vendorTr->id)->exists(),
            $project->id,
        );

        $this->assertTrue($visible, 'the employer could not see a vendor letter addressed to them');
    }

    public function test_a_rolled_up_document_does_reach_the_employer(): void
    {
        /*
         * The other half of the rule: hiding the vendor LETTER must not hide
         * the DOCUMENT once the contractor formally submits it. Otherwise the
         * separation would silently break the delivery it exists to support.
         */
        [$vendorTr, $target] = $this->vendorLetterWithDocument();
        $rezaei = $this->user('rezaei@pars.example');
        $nioc = $this->company('NIOC-AFF');
        $project = $this->project();

        $this->asPars(function () use ($vendorTr, $target, $rezaei, $nioc) {
            $this->svc()->rollUp($vendorTr, $target);
            $this->svc()->addRecipient($target, $nioc);
            $this->svc()->issue($target, $rezaei);
        });

        $seen = $this->ctx()->runAs(
            $nioc->id,
            fn () => Transmittal::whereKey($target->id)->exists(),
            $project->id,
        );

        $this->assertTrue($seen, 'the contractor submission carrying the vendor document did not reach the employer');
    }
}
