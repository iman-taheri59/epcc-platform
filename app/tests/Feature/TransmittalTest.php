<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\Project;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\TransmittalPackager;
use App\Services\TransmittalService;
use App\Support\CdeState;
use App\Support\TenantContext;
use App\Support\TransmittalPurpose;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

/**
 * Transmittal management (SRS FR-ENG-05, journey 3).
 *
 * A transmittal is EVIDENCE: it settles "when did you have the drawing?",
 * which is the question a delay claim turns on. The tests protect, in order:
 *
 *   1. Immutability after issue. A letter that can be edited after it was sent
 *      proves nothing about what was sent.
 *   2. Per-recipient acknowledgement, by the recipient only.
 *   3. Confidentiality - correspondence is not broadcast to the whole project.
 */
final class TransmittalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        config(['filesystems.disks.local.root' => storage_path('framework/testing/trfiles')]);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    private function project(string $code): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    private function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function svc(): TransmittalService
    {
        return app(TransmittalService::class);
    }

    private function company(string $code): Company
    {
        return Company::where('code', $code)->firstOrFail();
    }

    /** Draft a transmittal as EPC-PARS carrying one shareable document. */
    private function draftOne(string $purpose = TransmittalPurpose::FOR_REVIEW): Transmittal
    {
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        return $this->ctx()->runAs($author->company_id, function () use ($author, $project, $purpose) {
            $tr = $this->svc()->draft($project, $author, 'Subject', 'موضوع', $purpose);
            $doc = Document::with('currentRevision')->where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail();
            $this->svc()->addItem($tr, $doc);
            $this->svc()->addRecipient($tr, $this->company('PMC-TEHRAN'));

            return $tr;
        }, $project->id);
    }

    // ---- numbering and drafting -------------------------------------------

    public function test_transmittal_numbers_are_sequential_per_project(): void
    {
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        // Three are seeded, so the next is 4.
        $tr = $this->ctx()->runAs(
            $author->company_id,
            fn () => $this->svc()->draft($project, $author, 'Next', 'بعدی', TransmittalPurpose::FOR_REVIEW),
            $project->id,
        );

        $this->assertSame('PH-14-TR-0004', $tr->number);
        $this->assertSame(4, $tr->sequence);
        $this->assertTrue($tr->isDraft());
    }

    public function test_a_work_in_progress_document_cannot_be_transmitted(): void
    {
        // Formally issuing work the author has not finished. If it is ready to
        // send, it is ready to be SHARED.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($author->company_id, function () use ($author, $project): void {
            $tr = $this->svc()->draft($project, $author, 'S', 'م', TransmittalPurpose::FOR_REVIEW);
            $wip = Document::with('currentRevision')->where('number', 'PH-14-CIVIL-DWG-0001')->firstOrFail();
            $this->assertSame(CdeState::WIP, $wip->currentRevision->cde_state);
            $this->svc()->addItem($tr, $wip, 'en');
        }, $project->id);
    }

    public function test_the_sender_cannot_be_its_own_recipient(): void
    {
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($author->company_id, function () use ($author, $project): void {
            $tr = $this->svc()->draft($project, $author, 'S', 'م', TransmittalPurpose::FOR_REVIEW);
            $this->svc()->addRecipient($tr, $this->company('EPC-PARS'), 'en');
        }, $project->id);
    }

    public function test_a_company_outside_the_project_cannot_be_a_recipient(): void
    {
        // A letter to a company that is not on the project is not a
        // transmittal, it is a leak.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($author->company_id, function () use ($author, $project): void {
            $tr = $this->svc()->draft($project, $author, 'S', 'م', TransmittalPurpose::FOR_REVIEW);
            // EPC-ARVAND is on KISH-1 only.
            $this->svc()->addRecipient($tr, $this->company('EPC-ARVAND'), 'en');
        }, $project->id);
    }

    // ---- issuing and immutability -----------------------------------------

    public function test_an_empty_transmittal_cannot_be_issued(): void
    {
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($author->company_id, function () use ($author, $project): void {
            $tr = $this->svc()->draft($project, $author, 'S', 'م', TransmittalPurpose::FOR_REVIEW);
            $this->svc()->issue($tr, $author, 'en');
        }, $project->id);
    }

    public function test_an_issued_transmittal_cannot_be_changed(): void
    {
        // THE property of this module.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $tr = $this->draftOne();

        $this->ctx()->runAs(
            $author->company_id,
            fn () => $this->svc()->issue($tr, $author),
            $project->id,
        );

        try {
            $this->ctx()->runAs($author->company_id, function () use ($tr): void {
                $doc = Document::with('currentRevision')->where('number', 'PH-14-CIVIL-CALC-0001')->firstOrFail();
                $this->svc()->addItem($tr->refresh(), $doc, 'en');
            }, $project->id);
            $this->fail('a document was added to an already-issued transmittal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be changed', implode(' ', $e->validator->errors()->all()));
        }

        // And no recipient may be slipped in afterwards either.
        $this->expectException(ValidationException::class);
        $this->ctx()->runAs(
            $author->company_id,
            fn () => $this->svc()->addRecipient($tr->refresh(), $this->company('EPC-SAHAND'), 'en'),
            $project->id,
        );
    }

    public function test_items_record_the_revision_as_it_was_at_issue(): void
    {
        // "We sent you Rev B" must stay true after Rev C exists.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $doc = $this->ctx()->runAs(
            $author->company_id,
            fn () => Document::with('currentRevision')->where('number', 'PH-14-PIPING-PID-0001')->firstOrFail(),
            $project->id,
        );
        $originalRev = $doc->currentRevision->revision;

        $tr = $this->ctx()->runAs($author->company_id, function () use ($author, $project, $doc) {
            $tr = $this->svc()->draft($project, $author, 'S', 'م', TransmittalPurpose::FOR_INFORMATION);
            $this->svc()->addItem($tr, $doc);
            $this->svc()->addRecipient($tr, $this->company('PMC-TEHRAN'));

            return $this->svc()->issue($tr, $author);
        }, $project->id);

        // Now supersede the drawing.
        $this->ctx()->runAs(
            $author->company_id,
            fn () => app(DocumentRegister::class)->issueRevision($doc->fresh('currentRevision'), $author, 'D'),
            $project->id,
        );

        $item = $this->ctx()->runAs(
            $author->company_id,
            fn () => TransmittalItem::where('transmittal_id', $tr->id)->firstOrFail(),
            $project->id,
        );

        $this->assertSame($originalRev, $item->revision_at_issue);
        $this->assertNotSame('D', $item->revision_at_issue);
    }

    public function test_the_deadline_follows_the_purpose(): void
    {
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        // For construction: 7 days.
        $build = $this->draftOne(TransmittalPurpose::FOR_CONSTRUCTION);
        $build = $this->ctx()->runAs(
            $author->company_id,
            fn () => $this->svc()->issue($build, $author),
            $project->id,
        );
        $this->assertNotNull($build->due_at);
        $this->assertSame(7, (int) round(now()->diffInDays($build->due_at, false)));

        // For information: no clock at all. Overdue noise on things nobody
        // must answer trains people to ignore the whole list.
        $info = $this->draftOne(TransmittalPurpose::FOR_INFORMATION);
        $info = $this->ctx()->runAs(
            $author->company_id,
            fn () => $this->svc()->issue($info, $author),
            $project->id,
        );
        $this->assertNull($info->due_at);
        $this->assertFalse($info->isOverdue());
    }

    // ---- acknowledgement ---------------------------------------------------

    public function test_only_a_named_recipient_can_acknowledge(): void
    {
        // The employer can READ every letter on its project, but reading is
        // not receiving.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $tr = $this->draftOne();

        $this->ctx()->runAs($author->company_id, fn () => $this->svc()->issue($tr, $author), $project->id);

        $owner = $this->user('karimi@nioc.example');
        $this->expectException(ValidationException::class);

        $this->ctx()->runAs(
            $owner->company_id,
            fn () => $this->svc()->acknowledge($tr->refresh(), $owner, null, 'en'),
            $project->id,
        );
    }

    public function test_a_recipient_acknowledges_only_for_itself(): void
    {
        // A transmittal to two parties is acknowledged twice.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $tr = $this->ctx()->runAs($author->company_id, function () use ($author, $project) {
            $tr = $this->svc()->draft($project, $author, 'S', 'م', TransmittalPurpose::FOR_REVIEW);
            $doc = Document::with('currentRevision')->where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail();
            $this->svc()->addItem($tr, $doc);
            $this->svc()->addRecipient($tr, $this->company('PMC-TEHRAN'));
            $this->svc()->addRecipient($tr, $this->company('NIOC-AFF'));

            return $this->svc()->issue($tr, $author);
        }, $project->id);

        $pmc = $this->user('tehrani@pmc.example');
        $this->ctx()->runAs(
            $pmc->company_id,
            fn () => $this->svc()->acknowledge($tr->refresh(), $pmc, 'Logged.'),
            $project->id,
        );

        $fresh = $this->ctx()->runAs(
            $author->company_id,
            fn () => Transmittal::with('recipients')->find($tr->id),
            $project->id,
        );

        $this->assertSame(1, $fresh->acknowledgedCount());
        $this->assertSame(2, $fresh->recipients->count());
    }

    public function test_acknowledging_twice_does_not_overwrite_the_first_timestamp(): void
    {
        // The timestamp IS the evidence; a double-click must not move it.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $tr = $this->draftOne();
        $this->ctx()->runAs($author->company_id, fn () => $this->svc()->issue($tr, $author), $project->id);

        $pmc = $this->user('tehrani@pmc.example');

        $first = $this->ctx()->runAs(
            $pmc->company_id,
            fn () => $this->svc()->acknowledge($tr->refresh(), $pmc, 'First.'),
            $project->id,
        );
        $second = $this->ctx()->runAs(
            $pmc->company_id,
            fn () => $this->svc()->acknowledge($tr->refresh(), $pmc, 'Second.'),
            $project->id,
        );

        $this->assertEquals($first->acknowledged_at, $second->acknowledged_at);
        $this->assertSame('First.', $second->acknowledgement_note);
    }

    public function test_a_draft_cannot_be_acknowledged(): void
    {
        $tr = $this->draftOne();
        $pmc = $this->user('tehrani@pmc.example');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs(
            $pmc->company_id,
            fn () => $this->svc()->acknowledge($tr, $pmc, null, 'en'),
            $tr->project_id,
        );
    }

    public function test_overdue_is_derived_and_finds_the_seeded_letter(): void
    {
        // The fixture backdates one letter past its deadline.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $overdue = $this->ctx()->runAs(
            $author->company_id,
            fn () => $this->svc()->overdue($project),
            $project->id,
        );

        $this->assertCount(1, $overdue);
        $this->assertTrue($overdue->first()->isOverdue());

        // An acknowledged letter is never overdue, whatever the clock says.
        $acked = $this->ctx()->runAs(
            $author->company_id,
            fn () => Transmittal::with('recipients')->where('sequence', 1)->first(),
            $project->id,
        );
        $this->assertGreaterThan(0, $acked->acknowledgedCount());
    }

    // ---- confidentiality ---------------------------------------------------

    public function test_a_third_party_cannot_read_correspondence_it_is_not_part_of(): void
    {
        // A contractor must not read a letter between the PMC and a rival.
        // EPC-SAHAND participates in PH-14 but is neither sender nor recipient.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $tr = $this->draftOne();
        $this->ctx()->runAs($author->company_id, fn () => $this->svc()->issue($tr, $author), $project->id);

        $sahand = $this->user('mousavi@sahand.example');

        $visible = $this->ctx()->runAs(
            $sahand->company_id,
            fn () => Transmittal::where('id', $tr->id)->exists(),
            $project->id,
        );

        $this->assertFalse($visible, 'a transmittal leaked to a company that was neither sender nor recipient');
    }

    public function test_the_employer_sees_every_issued_letter_but_not_a_draft(): void
    {
        // The employer's oversight runs to correspondence that has actually been
        // sent. A draft is not correspondence yet - the sender may still change
        // the addressees or abandon it - so it stays private to the sender even
        // from the owner of the project. The seed has 2 issued + 1 draft.
        $owner = $this->user('karimi@nioc.example');
        $project = $this->project('PH-14');

        [$count, $draftVisible] = $this->ctx()->runAs(
            $owner->company_id,
            fn () => [
                Transmittal::count(),
                Transmittal::whereNull('issued_at')->exists(),
            ],
            $project->id,
        );

        $this->assertSame(2, $count, 'the employer should see both issued letters');
        $this->assertFalse($draftVisible, "another company's unsent draft leaked to the employer");
    }

    public function test_a_draft_is_invisible_to_its_own_addressees_until_issued(): void
    {
        // Regression: the first cut of the policy gated on recipiency alone, so
        // an addressee could read a letter the sender had not yet sent.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $tr = $this->draftOne();

        $recipientCompany = $this->ctx()->runAs(
            $author->company_id,
            fn () => $tr->recipients()->first()->recipient_company_id,
            $project->id,
        );

        $seenAsDraft = $this->ctx()->runAs(
            $recipientCompany,
            fn () => Transmittal::where('id', $tr->id)->exists(),
            $project->id,
        );
        $this->assertFalse($seenAsDraft, 'an addressee read a letter that had not been issued yet');

        $this->ctx()->runAs($author->company_id, fn () => $this->svc()->issue($tr, $author), $project->id);

        $seenAfterIssue = $this->ctx()->runAs(
            $recipientCompany,
            fn () => Transmittal::where('id', $tr->id)->exists(),
            $project->id,
        );
        $this->assertTrue($seenAfterIssue, 'issuing the letter must make it visible to its addressee');
    }

    // ---- ZIP export --------------------------------------------------------

    public function test_the_zip_carries_the_files_a_manifest_and_a_cover_sheet(): void
    {
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $tr = $this->draftOne();
        $this->ctx()->runAs($author->company_id, fn () => $this->svc()->issue($tr, $author), $project->id);

        $path = $this->ctx()->runAs(
            $author->company_id,
            fn () => app(TransmittalPackager::class)->build(
                Transmittal::with(['items', 'recipients.company'])->find($tr->id),
                'en',
            ),
            $project->id,
        );

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('MANIFEST.csv', $names);
        $this->assertContains('TRANSMITTAL.txt', $names);

        $manifest = $zip->getFromName('MANIFEST.csv');
        // A BOM, or Excel on a Persian Windows install renders this as
        // mojibake - and a document controller opens it in Excel.
        $this->assertStringStartsWith("\u{FEFF}", $manifest);
        // The checksum is what makes the ZIP evidence rather than a folder.
        $this->assertMatchesRegularExpression('/[0-9a-f]{64}/', $manifest);

        $cover = $zip->getFromName('TRANSMITTAL.txt');
        $this->assertStringContainsString($tr->number, $cover);
        // Bilingual regardless of UI language (FR-I18N-06): the sender and the
        // recipient may not read the same one.
        $this->assertStringContainsString('نامهٔ ارسال مدارک', $cover);

        // The seeded document has two attachments on its current revision.
        $payload = array_values(array_filter($names, fn ($n) => ! str_contains($n, 'MANIFEST') && ! str_contains($n, 'TRANSMITTAL')));
        $this->assertNotEmpty($payload, 'the archive carried no document files');
        $this->assertStringContainsString('PH-14-PIPING-DWG-0001', $payload[0]);

        $zip->close();
        @unlink($path);
    }

    // ---- HTTP --------------------------------------------------------------

    public function test_the_index_renders_for_a_participant(): void
    {
        $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/projects/PH-14/transmittals?lang=en', ['Host' => 'preview.arena.site'])
            ->assertOk()
            ->assertSee('Transmittals')
            ->assertSee('PH-14-TR-0001')
            ->assertSee('New transmittal');
    }

    public function test_a_recipient_sees_an_acknowledge_button_and_the_sender_does_not(): void
    {
        $pmcHtml = $this->actingAs($this->user('tehrani@pmc.example'))
            ->get('/projects/PH-14/transmittals?lang=en', ['Host' => 'preview.arena.site'])
            ->assertOk()->getContent();

        // The seeded overdue letter is addressed to the PMC and unacknowledged.
        $this->assertStringContainsString('Acknowledge', $pmcHtml);

        $senderHtml = $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/projects/PH-14/transmittals?lang=en', ['Host' => 'preview.arena.site'])
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('>Acknowledge<', $senderHtml);
    }

    public function test_a_non_participant_gets_404(): void
    {
        $this->actingAs($this->user('hosseini@arvand.example'))
            ->get('/projects/PH-14/transmittals', ['Host' => 'preview.arena.site'])
            ->assertStatus(404);
    }

    public function test_a_viewer_cannot_create_a_transmittal(): void
    {
        // site_engineer holds doc.view only.
        $this->actingAs($this->user('mousavi@sahand.example'))
            ->post('/projects/PH-14/transmittals?lang=en', [
                'subject_en' => 'x', 'subject_fa' => 'x',
                'purpose' => TransmittalPurpose::FOR_REVIEW,
                'documents' => [(string) \Illuminate\Support\Str::uuid()],
                'recipients' => [(string) \Illuminate\Support\Str::uuid()],
            ], ['Host' => 'preview.arena.site'])
            ->assertStatus(403);
    }

    public function test_acknowledging_over_http_does_not_need_the_issue_capability(): void
    {
        // The recipient confirms receipt; requiring the SENDER's capability
        // would make acknowledgement impossible for a consultant.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $tr = $this->draftOne();
        $this->ctx()->runAs($author->company_id, fn () => $this->svc()->issue($tr, $author), $project->id);

        $this->actingAs($this->user('tehrani@pmc.example'))
            ->post("/projects/PH-14/transmittals/{$tr->id}?lang=en", ['action' => 'acknowledge'],
                ['Host' => 'preview.arena.site'])
            ->assertRedirect();

        $fresh = $this->ctx()->runAs(
            $author->company_id,
            fn () => Transmittal::with('recipients')->find($tr->id),
            $project->id,
        );
        $this->assertSame(1, $fresh->acknowledgedCount());
    }

    public function test_every_purpose_is_bilingual(): void
    {
        foreach (TransmittalPurpose::all() as $p) {
            $this->assertNotSame($p, TransmittalPurpose::label($p, 'fa'));
            $this->assertNotSame($p, TransmittalPurpose::label($p, 'en'));
            $this->assertNotSame(
                TransmittalPurpose::label($p, 'fa'),
                TransmittalPurpose::label($p, 'en'),
            );
        }
    }
}
