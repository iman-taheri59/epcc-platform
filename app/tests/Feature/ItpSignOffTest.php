<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\ItpSignOffService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4d slice 1 — structured hold/witness sign-offs on ITP stages.
 *
 * A notification says "we asked you to attend". Until now nothing recorded
 * what happened at the stage, so a hold point nobody attended and one the
 * client's inspector attended and accepted looked identical in the data.
 */
final class ItpSignOffTest extends TestCase
{
    private TenantContext $tenant;

    /** Notification settings are versioned; each fixture re-issue bumps it. */
    private int $notificationSettingVersion = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
    }

    // ------------------------------------------------------------------
    // cast: rezaei = EPC-PARS writer, karimi = NIOC employer,
    //       jafari = MC-KARAN (the notified recipient), tehrani = PMC.
    // ------------------------------------------------------------------

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function writer(): User
    {
        return $this->userByEmail('rezaei@pars.example');
    }

    private function employer(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function recipient(): User
    {
        return $this->userByEmail('jafari@karan.example');
    }

    private function asWriter(callable $fn): mixed
    {
        return $this->tenant->runAs($this->writer()->company_id, $fn, $this->project()->id);
    }

    private function asEmployer(callable $fn): mixed
    {
        return $this->tenant->runAs($this->employer()->company_id, $fn, $this->project()->id);
    }

    private function notifications(): InspectionNotificationService
    {
        return app(InspectionNotificationService::class);
    }

    /**
     * Builds an ITP document, one stage of the given point, and issues a notice.
     *
     * Only the issuing company and the notified recipients can see a notice, so
     * a party that must sign has to be on the recipient list -- which is how the
     * real thing works: you cannot sign a stage you were never asked to attend.
     */
    private function issuedNotification(string $point = 'W', array $extraRecipients = []): string
    {
        $doc = $this->asWriter(fn () => app(DocumentRegister::class)->register($this->project(), $this->writer(), [
            'discipline' => 'PIPING', 'doc_type' => 'ITP',
            'title_en' => 'Synthetic ITP reference', 'title_fa' => 'سند آزمایشی بازرسی',
        ]));
        $revision = $doc->current_revision_id;

        $stage = $this->notifications()->addStage($this->project(), $this->writer(), [
            'revision_id' => $revision, 'sequence' => 1, 'point' => $point,
            'requires_notification' => true,
            'description_en' => 'Synthetic stage description',
            'description_fa' => 'شرح مرحلهٔ آزمایشی',
        ]);

        $this->notifications()->configure($this->project(), $this->employer(), [
            'version' => $this->notificationSettingVersion++, 'main_company_id' => $this->writer()->company_id,
            'draft_users' => [$this->writer()->id], 'issue_users' => [$this->writer()->id],
            'reason' => 'Sign-off test fixture',
        ]);

        $id = $this->notifications()->createDraft($this->project(), $this->writer(), [
            'itp_revision_id' => $revision, 'itp_stage_id' => $stage,
            'work_reference' => 'SYNTHETIC FIELD INSPECTION',
            'activity_start_at' => '2026-10-25T09:30:00+03:30',
            'activity_end_at' => '2026-10-25T11:30:00+03:30',
            'location' => 'Synthetic site',
            'recipient_company_ids' => array_values(array_unique(array_merge(
                [$this->recipient()->company_id], $extraRecipients,
            ))),
        ]);

        $this->notifications()->issue($this->project(), $this->writer(), $id, ['version' => 1]);

        return $id;
    }

    private function signOffs(): ItpSignOffService
    {
        return app(ItpSignOffService::class);
    }

    private function nominate(User $signer): void
    {
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $signer, $signer->company_id,
            'sign_off_stage', ['user:'.$signer->id],
        );
    }

    /** @param  array<string,mixed>  $overrides */
    private function sign(User $actor, string $notificationId, array $overrides = []): array
    {
        return $this->signOffs()->signOff($this->project(), $actor, $notificationId, array_replace([
            'outcome' => 'ACCEPTED',
            'signed_at' => '2026-10-25T10:30:00+03:30',
            'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function signOffCount(): int
    {
        return (int) $this->asWriter(fn () => DB::table('inspection_stage_signoffs')->count());
    }

    // ------------------------------------------------------------------
    // authority
    // ------------------------------------------------------------------

    public function test_signing_a_stage_is_default_deny_without_a_nomination(): void
    {
        $id = $this->issuedNotification('W');

        // jafari is the notified party and still cannot sign until nominated.
        try {
            $this->sign($this->recipient(), $id);
            $this->fail('Expected default-deny refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not nominated', $e->errors()['sign_off'][0]);
        }
        $this->assertSame(0, $this->signOffCount());
    }

    public function test_a_nominated_signer_records_and_the_stage_and_point_are_derived(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());

        $result = $this->sign($this->recipient(), $id);

        $this->assertSame(1, $this->signOffCount());
        $this->assertSame('W', $result['point'], 'the point is derived from the stage, never declared');

        $row = $this->asWriter(fn () => DB::table('inspection_stage_signoffs')->where('id', $result['id'])->first());
        $this->assertSame('ACCEPTED', $row->outcome);
        $this->assertSame($this->recipient()->id, (string) $row->signed_by);
        $this->assertNotEmpty($row->signer_policy_id, 'the authorising nomination is stamped on the row');
        // The derived stage is the notification's own stage.
        $this->assertSame(
            (string) $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $id)->value('stage_id')),
            (string) $row->stage_id,
        );
    }

    public function test_a_draft_notification_cannot_be_signed_off(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());
        $this->nominate($this->writer());

        // A second notice on the same stage, left as a draft: not yet a request.
        $draftId = $this->notifications()->createDraft($this->project(), $this->writer(), [
            'itp_revision_id' => $this->revisionOf($id),
            'itp_stage_id' => $this->stageOf($id),
            'work_reference' => 'DRAFT NOTICE',
            'activity_start_at' => '2026-10-25T09:30:00+03:30',
            'activity_end_at' => '2026-10-25T11:30:00+03:30',
            'location' => 'Synthetic site',
            'recipient_company_ids' => [$this->recipient()->company_id],
        ]);

        // The writer can see its own draft, so the refusal is about state, not
        // visibility: a draft is not yet a request for attendance.
        try {
            $this->sign($this->writer(), $draftId);
            $this->fail('Expected refusal for a draft notification');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only an ISSUED notification', $e->errors()['sign_off'][0]);
        }
        $this->assertSame(0, $this->signOffCount());

        // And a party that was not on the notice cannot see the draft at all.
        try {
            $this->sign($this->recipient(), $draftId);
            $this->fail('Expected refusal: a draft is not visible to the notified party');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not found', strtolower($e->errors()['sign_off'][0]));
        }
        $this->assertSame(0, $this->signOffCount());
    }

    // ------------------------------------------------------------------
    // honest outcomes
    // ------------------------------------------------------------------

    public function test_a_rejection_is_never_recorded_without_a_cause(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());

        try {
            $this->sign($this->recipient(), $id, ['outcome' => 'REJECTED']);
            $this->fail('Expected refusal: a rejection without a cause');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertSame(0, $this->signOffCount());

        $this->sign($this->recipient(), $id, [
            'outcome' => 'REJECTED', 'reason' => 'Fit-up gap exceeds tolerance',
            'request_id' => (string) Str::uuid(),
        ]);
        $this->assertSame(1, $this->signOffCount());
    }

    public function test_conditional_acceptance_needs_the_comments_and_waiver_needs_its_reason(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());

        try {
            $this->sign($this->recipient(), $id, ['outcome' => 'ACCEPTED_WITH_COMMENTS']);
            $this->fail('Expected refusal: comments without comments');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->sign($this->recipient(), $id, [
            'outcome' => 'ACCEPTED_WITH_COMMENTS', 'reason' => 'Two minor paint touch-ups noted',
            'request_id' => (string) Str::uuid(),
        ]);
        $this->assertSame(1, $this->signOffCount());
    }

    public function test_waiving_attendance_records_that_nobody_attended(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());

        $this->sign($this->recipient(), $id, [
            'outcome' => 'WAIVED_NOT_ATTENDED', 'reason' => 'Client released the witness requirement in writing',
            'request_id' => (string) Str::uuid(),
        ]);

        $row = $this->asWriter(fn () => DB::table('inspection_stage_signoffs')->first());
        $this->assertSame('WAIVED_NOT_ATTENDED', $row->outcome);
        $this->assertStringContainsString('released the witness', (string) $row->reason);
    }

    // ------------------------------------------------------------------
    // hold points
    // ------------------------------------------------------------------

    public function test_a_contractor_cannot_waive_the_employers_hold_point(): void
    {
        // The client is notified for a hold point: that is the whole point of one.
        $id = $this->issuedNotification('H', [$this->employer()->company_id]);
        $this->nominate($this->recipient());
        $this->nominate($this->employer());

        try {
            $this->sign($this->recipient(), $id, [
                'outcome' => 'WAIVED_NOT_ATTENDED', 'reason' => 'We were in a hurry',
                'request_id' => (string) Str::uuid(),
            ]);
            $this->fail('Expected refusal: a contractor waiving the employer hold');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('employer', $e->errors()['sign_off'][0]);
        }
        $this->assertSame(0, $this->signOffCount(), 'nothing was recorded');

        // The employer may waive its own hold, and may also accept it.
        $this->sign($this->employer(), $id, [
            'outcome' => 'WAIVED_NOT_ATTENDED', 'reason' => 'Hold released after document review',
            'request_id' => (string) Str::uuid(),
        ]);
        $this->assertSame(1, $this->signOffCount());

        // A witness point, by contrast, can be waived by the notified party.
        $witness = $this->issuedNotification('W');
        $this->sign($this->recipient(), $witness, [
            'outcome' => 'WAIVED_NOT_ATTENDED', 'reason' => 'Covered by surveillance instead',
        ]);
        $this->assertSame(2, $this->signOffCount());
    }

    // ------------------------------------------------------------------
    // lineage and immutability
    // ------------------------------------------------------------------

    public function test_a_new_sign_off_chains_to_the_rejection_it_corrects(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());

        $rejected = $this->sign($this->recipient(), $id, [
            'outcome' => 'REJECTED', 'reason' => 'Fit-up gap exceeds tolerance',
        ])['id'];

        // A correction chains to a REJECTED sign-off -- never to an acceptance.
        $accepted = $this->sign($this->recipient(), $id, ['request_id' => (string) Str::uuid()])['id'];
        try {
            $this->sign($this->recipient(), $id, [
                'previous_signoff_id' => $accepted, 'request_id' => (string) Str::uuid(),
            ]);
            $this->fail('Expected refusal: the previous sign-off is not a rejection of this one');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('chain to a REJECTED', $e->errors()['sign_off'][0]);
        }

        // The correction is a new sign-off, and the rejection stays on the record.
        $this->sign($this->recipient(), $id, [
            'outcome' => 'ACCEPTED', 'previous_signoff_id' => $rejected,
            'request_id' => (string) Str::uuid(),
        ]);

        $this->assertSame(3, $this->signOffCount());
        $rows = $this->signOffs()->signOffsFor($this->project(), $this->recipient(), $id);
        $this->assertSame('REJECTED', $rows[0]->outcome);
        $this->assertSame('ACCEPTED', $rows[2]->outcome);
        $this->assertSame($rejected, (string) $rows[2]->previous_signoff_id);

        // What a walk-down shows: the newest position per company.
        $latest = $this->signOffs()->latestPerCompany($this->project(), $this->recipient(), $id);
        $this->assertCount(1, $latest);
        $this->assertSame('ACCEPTED', $latest->first()->outcome);
    }

    public function test_sign_offs_are_append_only(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());
        $result = $this->sign($this->recipient(), $id);

        $updated = $this->asWriter(fn () => DB::table('inspection_stage_signoffs')
            ->where('id', $result['id'])->update(['outcome' => 'REJECTED']));
        $this->assertSame(0, $updated);
        $deleted = $this->asWriter(fn () => DB::table('inspection_stage_signoffs')
            ->where('id', $result['id'])->delete());
        $this->assertSame(0, $deleted);
    }

    public function test_duplicate_request_ids_are_refused(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());
        $rid = (string) Str::uuid();

        $this->sign($this->recipient(), $id, ['request_id' => $rid]);
        try {
            $this->sign($this->recipient(), $id, ['request_id' => $rid, 'outcome' => 'REJECTED', 'reason' => 'again']);
            $this->fail('Expected duplicate refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->errors()['sign_off'][0]));
        }
        $this->assertSame(1, $this->signOffCount());
    }

    public function test_sign_offs_are_member_readable_but_cannot_be_written_in_another_name(): void
    {
        $id = $this->issuedNotification('W');
        $this->nominate($this->recipient());
        $result = $this->sign($this->recipient(), $id);

        // Another project member company reads the same quality record.
        $this->assertCount(1, $this->signOffs()->signOffsFor($this->project(), $this->employer(), $id));

        // But nobody can post a sign-off naming a different actor.
        try {
            $this->tenant->runAs($this->recipient()->company_id, function () use ($id): void {
                DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$this->recipient()->id]);
                DB::table('inspection_stage_signoffs')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $this->project()->id,
                    'company_id' => $this->recipient()->company_id, 'notification_id' => $id,
                    'stage_id' => $this->stageOf($id), 'point' => 'W', 'outcome' => 'ACCEPTED',
                    'signed_by' => $this->employer()->id, 'signed_at' => now(),
                    'signer_policy_id' => DB::table('result_recorder_policies')->value('id'),
                    'request_id' => (string) Str::uuid(), 'recorded_at' => now(),
                ]);
            }, $this->project()->id);
            $this->fail('Expected refusal of a sign-off naming another actor');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        }
        $this->assertSame(1, $this->signOffCount());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function stageOf(string $notificationId): string
    {
        return (string) $this->asWriter(fn () => DB::table('inspection_notifications')
            ->where('id', $notificationId)->value('stage_id'));
    }

    private function revisionOf(string $notificationId): string
    {
        return (string) $this->asWriter(fn () => DB::table('inspection_itp_stages')
            ->where('id', $this->stageOf($notificationId))->value('revision_id'));
    }
}
