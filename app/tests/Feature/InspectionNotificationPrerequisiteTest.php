<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationPrerequisiteTest extends TestCase
{
    private User $writer;

    private User $owner;

    private User $recipient;

    private Project $project;

    private string $revision;

    private string $stage;

    private string $id;

    private string $base = '/projects/PH-14/inspection-notifications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->recipient = $this->userByEmail('jafari@karan.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->project->update(['name_en' => 'SYNTHETIC PROJECT RECORD', 'name_fa' => 'پروژهٔ آزمایشی — SYNTHETIC']);
        $doc = $this->asWriter(fn () => app(DocumentRegister::class)->register($this->project, $this->writer, [
            'discipline' => 'PIPING', 'doc_type' => 'ITP', 'title_en' => 'Synthetic ITP reference', 'title_fa' => 'سند آزمایشی بازرسی',
        ]));
        $this->revision = $doc->current_revision_id;
        $this->stage = $this->service()->addStage($this->project, $this->writer, [
            'revision_id' => $this->revision, 'sequence' => 1, 'point' => 'W', 'requires_notification' => true,
            'description_en' => 'Recorded stage <script>stage()</script>', 'description_fa' => 'بازرسی مرحلهٔ پیش‌نویس گچ ژاله',
        ]);
        $this->service()->configure($this->project, $this->owner, [
            'version' => 0, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$this->writer->id], 'issue_users' => [$this->writer->id], 'reason' => 'Synthetic PDF fixture',
        ]);
        $this->id = $this->service()->createDraft($this->project, $this->writer, $this->draft());
    }

    private function service(): InspectionNotificationService
    {
        return app(InspectionNotificationService::class);
    }

    private function asWriter(callable $f): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $f, $this->project->id);
    }

    private function row(): object
    {
        return $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $this->id)->first());
    }

    private function issue(): void
    {
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 1]);
    }

    private function url(string $lang = 'en'): string
    {
        return $this->base.'/'.$this->id.'.pdf?lang='.$lang;
    }

    private function draft(): array
    {
        return ['itp_revision_id' => $this->revision, 'itp_stage_id' => $this->stage, 'work_reference' => 'SYNTHETIC FIELD INSPECTION / نمونهٔ آزمایشی', 'activity_start_at' => '2026-10-25T09:30:00+03:30', 'activity_end_at' => '2026-10-25T11:30:00+03:30', 'location' => 'Synthetic site / محل آزمایشی', 'recipient_company_ids' => [$this->recipient->company_id], 'notes_en' => '<img src="https://invalid.example/a"> & <script>notes()</script>', 'notes_fa' => 'ارجاع‌شده برای تأیید حضور، نه تأیید نتیجه'];
    }

    private function artifact(string $name, string $bytes): void
    {
        if (getenv('EPCC_PREREQUISITE_EVIDENCE') === '1') {
            $dir = base_path('../docs/evidence/2026-10-20-inspection-notification-prerequisites');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.'/'.$name, $bytes);
        }
    }

    private string $prerequisiteId;

    private function asRecipient(callable $f): mixed
    {
        return app(TenantContext::class)->runAs($this->recipient->company_id, $f, $this->project->id);
    }

    private function create(array $overrides = [], ?User $actor = null): string
    {
        return $this->prerequisiteId = $this->service()->createPrerequisite($this->project, $actor ?? $this->recipient, $this->id, array_replace(['item_reference' => 'ACTIVITY-A / مورد آزمایشی', 'requirement' => 'PREREQUISITEBODY / آماده‌سازی محل'], $overrides));
    }

    private function prerequisite(): object
    {
        return $this->asWriter(fn () => DB::table('inspection_notification_prerequisites')->where('id', $this->prerequisiteId)->first());
    }

    private function events()
    {
        return $this->asWriter(fn () => DB::table('inspection_notification_prerequisite_events')->where('prerequisite_id', $this->prerequisiteId)->orderBy('version')->get());
    }

    private function command(string $action, ?User $actor = null, array $overrides = []): void
    {
        $this->service()->updatePrerequisite($this->project, $actor ?? ($action === 'REPORT' ? $this->writer : $this->recipient), $this->id, $this->prerequisiteId,
            array_replace(['version' => $this->prerequisite()->version, 'action' => $action, 'note' => 'Explanation '.$action], $overrides));
    }

    private function endpoint(): string
    {
        return $this->base.'/'.$this->id.'/prerequisites/'.$this->prerequisiteId;
    }

    private function anotherReader(): User
    {
        return $this->asRecipient(function () {
            $u = User::create(['company_id' => $this->recipient->company_id, 'name' => 'OTHERPREREQUISITEREADER', 'email' => 'synthetic-prerequisite@karan.example', 'password' => 'synthetic-only', 'is_active' => true]);
            ProjectMembership::create(['project_id' => $this->project->id, 'user_id' => $u->id, 'role_codes' => [], 'discipline_scope' => null]);

            return $u;
        });
    }

    public function test_recipient_registers_multiple_separate_prerequisites_without_rewriting_issue_or_business_data(): void
    {
        $this->issue();
        $tables = ['inspection_notifications', 'inspection_notification_cancellations', 'inspection_notification_acknowledgments', 'inspection_notification_draft_history', 'inspection_notification_settings', 'inspection_itp_stages', 'material_inspections', 'purchase_orders', 'material_requisitions', 'material_lots', 'material_movements'];
        $snapshot = fn () => $this->asWriter(function () use ($tables) {
            $out = [];
            foreach ($tables as $t) {
                $out[$t] = DB::table($t)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
        $before = $snapshot();
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->recipient)->get($this->base.'/'.$this->id.'?lang='.$lang)->assertOk()->assertSee('id="inspection-prerequisite-create"', false);
        }
        $r = $this->postJson($this->base.'/'.$this->id.'/prerequisites?lang=fa', ['prerequisite' => ['item_reference' => 'ACTIVITY-A', 'requirement' => 'PREREQUISITEBODY']])->assertRedirect();
        $this->prerequisiteId = $this->asWriter(fn () => DB::table('inspection_notification_prerequisites')->value('id'));
        $p = $this->prerequisite();
        $this->assertSame('OPEN', $p->state);
        $this->assertSame(1, $p->version);
        $this->assertSame($this->recipient->company_id, $p->company_id);
        $this->assertSame($this->recipient->id, $p->created_by);
        $this->assertSame('CREATE', $p->latest_action);
        $event = $this->events()->sole();
        $this->assertNull($event->before_snapshot);
        $this->assertSame('PREREQUISITEBODY', json_decode($event->after_snapshot, true)['requirement']);
        $this->create(['item_reference' => 'ACTIVITY-B']);
        $r = $this->actingAs($this->writer)->get($this->base.'/'.$this->id)->assertOk()->assertSee('ACTIVITY-A')->assertSee('ACTIVITY-B')->assertDontSee('id="inspection-prerequisite-create"', false);
        $this->assertSame(2, $r->viewData('prerequisites')->total());
        $this->assertSame($before, $snapshot());
    }

    public function test_company_reader_management_report_rejection_revision_and_confirmation_are_versioned_and_terminal(): void
    {
        $this->issue();
        $this->create();
        $other = $this->anotherReader();
        $this->command('EDIT', $other, ['item_reference' => 'REVISED ITEM', 'requirement' => 'REVISED REQUIREMENT', 'note' => '0']);
        $this->assertSame('0', $this->prerequisite()->latest_note);
        $this->assertSame($other->id, $this->prerequisite()->latest_actor_id);
        $this->command('REPORT');
        $this->command('REPORT', null, ['note' => 'Corrected completion explanation']);
        $this->assertSame('REPORTED', $this->prerequisite()->state);
        $this->assertSame(4, $this->prerequisite()->version);
        $this->actingAs($this->recipient)->postJson($this->endpoint(), ['prerequisite' => ['version' => 3, 'action' => 'CONFIRM']])->assertUnprocessable();
        $this->command('REJECT', $other, ['note' => 'Evidence incomplete']);
        $this->assertSame('OPEN', $this->prerequisite()->state);
        $this->command('EDIT', null, ['item_reference' => 'REVISED ITEM', 'requirement' => 'ADDITIONAL DETAIL', 'note' => 'Clarification after rejection']);
        $this->command('REPORT');
        $this->command('CONFIRM', $other, ['note' => null]);
        $p = $this->prerequisite();
        $this->assertSame('FULFILLED', $p->state);
        $this->assertSame(8, $p->version);
        $this->assertCount(8, $this->events());
        foreach (['EDIT', 'WITHDRAW', 'REPORT', 'CONFIRM', 'REJECT'] as $action) {
            $actor = $action === 'REPORT' ? $this->writer : $this->recipient;
            $payload = ['version' => 8, 'action' => $action, 'note' => 'Try terminal mutation'];
            if ($action === 'EDIT') {
                $payload += ['item_reference' => 'Other', 'requirement' => 'Other'];
            }
            $this->actingAs($actor)->postJson($this->endpoint(), ['prerequisite' => $payload])->assertUnprocessable();
        }
        $this->assertEquals($p, $this->prerequisite());
        $this->assertCount(8, $this->events());
    }

    public function test_only_originating_company_current_issuer_can_report_and_only_own_recipient_company_confirms_or_rejects(): void
    {
        $this->issue();
        $this->create();
        $this->actingAs($this->recipient)->postJson($this->endpoint(), ['prerequisite' => ['version' => 1, 'action' => 'REPORT', 'note' => 'Wrong party']])->assertForbidden();
        $draftOnly = $this->userByEmail('sadeghi@pars.example');
        $this->actingAs($draftOnly)->postJson($this->endpoint(), ['prerequisite' => ['version' => 1, 'action' => 'REPORT', 'note' => 'No issue nomination']])->assertForbidden();
        $this->command('REPORT');
        foreach (['CONFIRM', 'REJECT', 'WITHDRAW'] as $action) {
            $this->actingAs($this->writer)->postJson($this->endpoint(), ['prerequisite' => ['version' => 2, 'action' => $action, 'note' => 'Sender cannot attest receiver fulfillment']])->assertForbidden();
        }
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$this->writer->id], 'issue_users' => [], 'reason' => 'Revoke reporter']);
        $this->actingAs($this->writer)->postJson($this->endpoint(), ['prerequisite' => ['version' => 2, 'action' => 'REPORT', 'note' => 'Revoked issuer']])->assertForbidden();
        $this->command('CONFIRM');
        $this->assertSame('FULFILLED', $this->prerequisite()->state);
    }

    public function test_own_company_reader_can_withdraw_reported_requirement_with_reason_without_deletion_or_waiver(): void
    {
        $this->issue();
        $this->create();
        $this->command('REPORT');
        $this->command('WITHDRAW', $this->anotherReader(), ['note' => 'No longer requested for this item']);
        $p = $this->prerequisite();
        $this->assertSame('WITHDRAWN', $p->state);
        $this->assertCount(3, $this->events());
        $this->actingAs($this->recipient)->postJson($this->endpoint(), ['prerequisite' => ['version' => 3, 'action' => 'REPORT', 'note' => 'Cannot reopen']])->assertForbidden();
        $this->get($this->endpoint())->assertOk();
        $this->assertSame('WITHDRAWN', $this->prerequisite()->state);
    }

    public function test_all_original_readers_see_requirement_and_public_history_without_old_private_draft_history_or_foreign_directory(): void
    {
        $d = $this->draft();
        $d['recipient_company_ids'] = [$this->recipient->company_id, $this->owner->company_id];
        $d['notes_en'] = 'PRIVATEPREREQUISITEDRAFT';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        unset($d['itp_revision_id'],$d['itp_stage_id']);
        $d['version'] = 1;
        $d['notes_en'] = 'CURRENT ISSUED NOTES';
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $d);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $this->create();
        $this->command('REPORT');
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->owner)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee('PREREQUISITEBODY')->assertSee('Explanation REPORT')->assertDontSee('PRIVATEPREREQUISITEDRAFT')->assertDontSee('inspection-notification-draft-history');
            $this->assertSame([], $r->viewData('actions'));
            $this->assertSame(2, $r->viewData('prerequisiteHistory')->total());
            $this->artifact('prerequisite-'.$lang.'.html', preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-test-token"', $r->getContent()));
        }
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('inspection_notification_draft_history', $sql);
        }
        $this->actingAs($this->owner)->postJson($this->endpoint(), ['prerequisite' => ['version' => 2, 'action' => 'CONFIRM']])->assertNotFound();
        $unaddressed = $this->userByEmail('tehrani@pmc.example');
        $this->actingAs($unaddressed)->getJson($this->endpoint())->assertNotFound();
        $rows = app(TenantContext::class)->runAs($unaddressed->company_id, fn () => DB::table('inspection_notification_prerequisite_events')->get(), $this->project->id);
        $this->assertCount(0, $rows);
    }

    public function test_malformed_payloads_wrong_notice_draft_or_unauthorized_sender_cannot_create_or_inject_status_identity_or_body(): void
    {
        $this->actingAs($this->recipient)->postJson($this->base.'/'.$this->id.'/prerequisites', ['prerequisite' => ['item_reference' => 'A', 'requirement' => 'B']])->assertNotFound();
        $this->issue();
        $this->actingAs($this->writer)->postJson($this->base.'/'.$this->id.'/prerequisites', ['prerequisite' => ['item_reference' => 'A', 'requirement' => 'B']])->assertForbidden();
        $this->actingAs($this->recipient);
        foreach ([[], ['item_reference' => ['array'], 'requirement' => 'B'], ['item_reference' => 'A', 'requirement' => "\u{00A0}"], ['item_reference' => 'A', 'requirement' => str_repeat('x', 4001)], ['item_reference' => 'A', 'requirement' => 'B', 'state' => 'FULFILLED'], ['item_reference' => 'A', 'requirement' => 'B', 'company_id' => $this->owner->company_id]] as $bad) {
            $this->postJson($this->base.'/'.$this->id.'/prerequisites', ['prerequisite' => $bad])->assertUnprocessable();
        }
        $this->create();
        foreach ([['version' => 1, 'action' => 'REPORT', 'note' => ['array']], ['version' => 1, 'action' => 'EDIT', 'item_reference' => 'A', 'requirement' => 'B', 'note' => "\u{00A0}"], ['version' => 1, 'action' => 'CONFIRM', 'latest_actor_id' => $this->writer->id], ['version' => 1, 'action' => 'CONFIRM', 'state' => 'FULFILLED']] as $bad) {
            $this->postJson($this->endpoint(), ['prerequisite' => $bad])->assertUnprocessable();
        }
        $second = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        $this->getJson($this->base.'/'.$second.'/prerequisites/'.$this->prerequisiteId)->assertNotFound();
        $this->assertCount(1, $this->events());
    }

    public function test_direct_sql_captures_server_metadata_and_state_then_refuses_body_rewrite_version_jump_delete_and_forged_event(): void
    {
        $this->issue();
        $this->prerequisiteId = (string) Str::uuid();
        $this->asRecipient(fn () => DB::table('inspection_notification_prerequisites')->insert(['id' => $this->prerequisiteId, 'project_id' => $this->project->id, 'company_id' => $this->recipient->company_id, 'notification_id' => $this->id, 'issued_version' => 2, 'latest_actor_id' => $this->recipient->id, 'latest_actor_company_id' => $this->recipient->company_id, 'item_reference' => 'RAW ITEM', 'requirement' => 'RAW REQUIREMENT', 'version' => 999, 'state' => 'FULFILLED', 'latest_action' => 'CONFIRM', 'created_name' => 'FORGED', 'created_by' => (string) Str::uuid(), 'company_code' => 'FORGED', 'latest_actor_name' => 'FORGED', 'latest_at' => '1999-01-01']));
        $p = $this->prerequisite();
        $this->assertSame(1, $p->version);
        $this->assertSame('OPEN', $p->state);
        $this->assertSame($this->recipient->name, $p->created_name);
        $this->assertSame('MC-KARAN', $p->company_code);
        $this->assertSame($this->recipient->id, $p->created_by);
        foreach ([['version' => 3, 'latest_action' => 'EDIT', 'latest_note' => 'Jump'], ['version' => 2, 'latest_action' => 'EDIT', 'created_name' => 'Rewrite', 'latest_note' => 'Forge origin'], ['version' => 2, 'latest_action' => 'REPORT', 'latest_note' => 'Receiver cannot report']] as $patch) {
            try {
                $this->asRecipient(fn () => DB::table('inspection_notification_prerequisites')->where('id', $p->id)->update($patch));
                $this->fail('Expected raw refusal');
            } catch (QueryException$e) {
                $this->assertStringContainsString('NOTIFICATION_PREREQUISITE_', $e->getMessage());
            }
        }
        try {
            $this->asRecipient(fn () => DB::table('inspection_notification_prerequisites')->where('id', $p->id)->delete());
            $this->fail('Expected no deletion');
        } catch (QueryException$e) {
            $this->assertStringContainsString('NOTIFICATION_PREREQUISITE_NO_DELETE', $e->getMessage());
        }
        $event = $this->events()->sole();
        try {
            $this->asRecipient(fn () => DB::table('inspection_notification_prerequisite_events')->insert(['project_id' => $this->project->id, 'prerequisite_id' => $p->id, 'version' => 1, 'action' => 'CREATE', 'actor_company_id' => $this->recipient->company_id, 'after_snapshot' => $event->after_snapshot, 'recorded_at' => now()]));
            $this->fail('Expected capture only');
        } catch (QueryException$e) {
            $this->assertStringContainsString('NOTIFICATION_PREREQUISITE_CAPTURE_ONLY', $e->getMessage());
        }
        try {
            $this->asRecipient(fn () => DB::table('inspection_notification_prerequisite_events')->where('id', $event->id)->update(['action' => 'EDIT']));
            $this->fail('Expected immutable history');
        } catch (QueryException$e) {
            $this->assertStringContainsString('NOTIFICATION_PREREQUISITE_HISTORY_IMMUTABLE', $e->getMessage());
        }
        $this->assertEquals($p, $this->prerequisite());
        $this->assertEquals($event, $this->events()->sole());
    }

    public function test_cancelled_or_archived_notice_preserves_history_but_blocks_further_prerequisite_actions(): void
    {
        $this->issue();
        $this->create();
        $before = $this->prerequisite();
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'Cancel original']);
        $this->actingAs($this->recipient)->postJson($this->endpoint(), ['prerequisite' => ['version' => 1, 'action' => 'WITHDRAW', 'note' => 'Late']])->assertUnprocessable();
        $this->postJson($this->base.'/'.$this->id.'/prerequisites', ['prerequisite' => ['item_reference' => 'A', 'requirement' => 'B']])->assertUnprocessable();
        $r = $this->get($this->endpoint())->assertOk();
        $this->assertSame([], $r->viewData('actions'));
        $this->assertEquals($before, $this->prerequisite());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Retain prerequisites');
        $this->get($this->endpoint())->assertOk();
    }

    public function test_current_reader_discipline_active_membership_pack_and_participation_still_bound_management_and_visibility(): void
    {
        $this->issue();
        $this->create();
        $body = ['prerequisite' => ['version' => 1, 'action' => 'WITHDRAW', 'note' => 'Eligibility']];
        $this->actingAs($this->recipient);
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->getJson($this->endpoint())->assertNotFound();
        $this->postJson($this->endpoint(), $body)->assertNotFound();
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => null]));
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['is_active' => false]));
        $this->postJson($this->endpoint(), $body)->assertNotFound();
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['is_active' => true]));
        $full = Plan::where('code', 'full')->value('id');
        $starter = Plan::where('code', 'starter')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $starter]));
        $this->getJson($this->endpoint())->assertForbidden();
        $this->postJson($this->endpoint(), $body)->assertForbidden();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $full]));
        $this->asRecipient(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->recipient->company_id)->delete());
        $this->postJson($this->endpoint(), $body)->assertNotFound();
        $this->assertCount(1, $this->events());
    }

    public function test_failed_history_capture_rolls_back_projection_and_successful_metadata_is_not_rewritten_after_rename(): void
    {
        $this->issue();
        $this->create();
        $p = $this->prerequisite();
        $e = $this->events()->sole();
        DB::unprepared("CREATE OR REPLACE FUNCTION prerequisite_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_TEST_LATE'; END \$\$; CREATE TRIGGER zz_prerequisite_test_fail BEFORE INSERT ON inspection_notification_prerequisite_events FOR EACH ROW EXECUTE FUNCTION prerequisite_test_fail();");
        $this->actingAs($this->writer)->postJson($this->endpoint(), ['prerequisite' => ['version' => 1, 'action' => 'REPORT', 'note' => 'Must roll back']])->assertUnprocessable();
        $this->assertEquals($p, $this->prerequisite());
        $this->assertEquals($e, $this->events()->sole());
        DB::statement('DROP TRIGGER zz_prerequisite_test_fail ON inspection_notification_prerequisite_events');
        $this->command('REPORT');
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['name' => 'LATERREPORTERNAME']));
        $this->actingAs($this->recipient)->get($this->endpoint())->assertOk()->assertSee($this->writer->name)->assertDontSee('LATERREPORTERNAME');
    }

    public function test_text_is_escaped_and_invalid_form_restoration_is_scoped_to_same_prerequisite_and_action(): void
    {
        $this->issue();
        $this->create(['requirement' => '<script>PREREQXSS()</script>']);
        $this->actingAs($this->writer)->get($this->endpoint().'?lang=en')->assertOk()->assertSee('&lt;script&gt;PREREQXSS()&lt;/script&gt;', false)->assertDontSee('<script>PREREQXSS()', false);
        $this->actingAs($this->recipient)->from($this->endpoint())->post($this->endpoint(), ['prerequisite_target' => $this->prerequisiteId, 'prerequisite' => ['version' => 1, 'action' => 'EDIT', 'item_reference' => 'Restored item', 'requirement' => 'Restored requirement', 'note' => '']])->assertSessionHasErrors('prerequisite.note');
        $this->get($this->endpoint())->assertOk()->assertSee('Restored item')->assertSee('Restored requirement');
        $other = $this->create(['item_reference' => 'Other item']);
        $this->get($this->endpoint())->assertOk()->assertDontSee('Restored item');
    }

    public function test_forward_install_on_archived_populated_issue_leaves_no_fabricated_prerequisites_or_parent_changes(): void
    {
        $this->issue();
        $before = $this->row();
        DB::statement('DROP TABLE inspection_notification_prerequisite_events');
        DB::statement('DROP TABLE inspection_notification_prerequisites');
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Forward prerequisite install');
        $migration = require database_path('migrations/2026_10_20_150000_add_inspection_notification_prerequisites.php');
        $migration->up();
        $this->assertEquals($before, $this->row());
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_prerequisites')->count()));
        $r = $this->actingAs($this->recipient)->get($this->base.'/'.$this->id)->assertOk();
        $this->assertFalse($r->viewData('canCreatePrerequisite'));
    }

    private function waitingPrerequisites(array $readers, string $action, string $change = ''): array
    {
        $workers = [];
        $payload = ['id' => $this->prerequisiteId, 'command' => ['version' => $this->prerequisite()->version, 'action' => $action, 'note' => 'Concurrent action']];
        $this->asWriter(function () use ($readers, $change, $payload, &$workers): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-notification:'.$this->project->id]);
            foreach ($readers as $reader) {
                $p = new Process([PHP_BINARY, base_path('../scripts/tests/inspection_notification_worker.php'), $reader->id, $this->project->id, $this->id, 'prerequisite', json_encode($payload)], base_path(), ['APP_ENV' => 'testing', 'EPCC_NOTIFICATION_TEST' => 'isolated']);
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = true;
                foreach ($workers as $p) {
                    $p->checkTimeout();
                    $ready = str_contains($p->getOutput(), 'ready') && $ready;
                }
                $waiting = (int) DB::selectOne("WITH k AS (SELECT hashtextextended(?,0) v) SELECT count(*) n FROM pg_locks,k WHERE locktype='advisory' AND NOT granted AND classid::bigint=((v >> 32) & 4294967295) AND objid::bigint=(v & 4294967295) AND objsubid=1", ['inspection-notification:'.$this->project->id])->n;
                if ($ready && $waiting === count($readers)) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $messages = implode(' | ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $workers));
                    foreach ($workers as $p) {
                        $p->stop(0.1);
                    }$this->fail('Prerequisite barrier not reached: '.$messages);
                }
                usleep(1000);
            } while (true);
            $this->assertSame(count($readers), $waiting);
            if ($change === 'cancel') {
                $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'Cancellation ahead of prerequisite']);
            }
            if ($change === 'revoke') {
                $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$this->writer->id], 'issue_users' => [], 'reason' => 'Revoke waiting reporter']);
            }
            if ($change === 'discipline') {
                $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]));
            }
        });
        $codes = [];
        foreach ($workers as $p) {
            $codes[] = $p->wait();
            $this->assertNotSame(3, $p->getExitCode(), $p->getErrorOutput());
        }sort($codes);

        return $codes;
    }

    public function test_concurrent_same_version_company_confirmations_commit_exactly_one_event(): void
    {
        $this->issue();
        $this->create();
        $this->command('REPORT');
        $other = $this->anotherReader();
        $this->assertSame([0, 2], $this->waitingPrerequisites([$this->recipient, $other], 'CONFIRM'));
        $this->assertSame(3, $this->prerequisite()->version);
        $this->assertSame('FULFILLED', $this->prerequisite()->state);
        $this->assertCount(3, $this->events());
    }

    public function test_waiting_sender_report_rechecks_revoked_issue_grant_before_projection_or_history_write(): void
    {
        $this->issue();
        $this->create();
        $before = $this->prerequisite();
        $this->assertSame([2], $this->waitingPrerequisites([$this->writer], 'REPORT', 'revoke'));
        $this->assertEquals($before, $this->prerequisite());
        $this->assertCount(1, $this->events());
    }

    public function test_waiting_sender_report_cannot_cross_committed_cancellation(): void
    {
        $this->issue();
        $this->create();
        $before = $this->prerequisite();
        $this->assertSame([2], $this->waitingPrerequisites([$this->writer], 'REPORT', 'cancel'));
        $this->assertEquals($before, $this->prerequisite());
        $this->assertCount(1, $this->events());
    }

    public function test_waiting_recipient_confirmation_rechecks_current_reader_discipline(): void
    {
        $this->issue();
        $this->create();
        $this->command('REPORT');
        $before = $this->prerequisite();
        $this->assertSame([2], $this->waitingPrerequisites([$this->recipient], 'CONFIRM', 'discipline'));
        $this->assertEquals($before, $this->prerequisite());
        $this->assertCount(2, $this->events());
    }

    public function test_archived_project_blocks_uncancelled_prerequisite_and_create_without_hiding_retained_history(): void
    {
        $this->issue();
        $this->create();
        $before = $this->prerequisite();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Archive active prerequisite');
        $this->actingAs($this->writer)->postJson($this->endpoint(), ['prerequisite' => ['version' => 1, 'action' => 'REPORT', 'note' => 'Archived report']])->assertUnprocessable();
        $this->actingAs($this->recipient)->postJson($this->base.'/'.$this->id.'/prerequisites', ['prerequisite' => ['item_reference' => 'A', 'requirement' => 'B']])->assertUnprocessable();
        $r = $this->get($this->endpoint())->assertOk();
        $this->assertSame([], $r->viewData('actions'));
        $this->assertEquals($before, $this->prerequisite());
        $this->assertCount(1, $this->events());
    }

    public function test_prerequisite_and_history_pages_are_independent_bounded_and_preserve_language(): void
    {
        $this->issue();
        for ($i = 0; $i < 26; $i++) {
            $this->create(['item_reference' => 'ITEM-'.$i]);
        }
        $this->actingAs($this->recipient);
        $r = $this->get($this->base.'/'.$this->id.'?lang=fa&prerequisite_page=2')->assertOk();
        $this->assertSame(26, $r->viewData('prerequisites')->total());
        $this->assertCount(1, $r->viewData('prerequisites'));
        $this->assertStringContainsString('lang=fa', $r->viewData('prerequisites')->url(1));
        for ($i = 0; $i < 26; $i++) {
            $this->command('EDIT', null, ['item_reference' => 'EDITED ITEM', 'requirement' => 'REVISION '.$i]);
        }
        $r = $this->get($this->endpoint().'?lang=en&prerequisite_history_page=2')->assertOk();
        $this->assertSame(27, $r->viewData('prerequisiteHistory')->total());
        $this->assertCount(2, $r->viewData('prerequisiteHistory'));
        $this->assertStringContainsString('lang=en', $r->viewData('prerequisiteHistory')->url(1));
    }

    public function test_quality_only_reader_needs_correct_pack_but_no_document_or_issue_grant_and_archived_source_is_not_new_access(): void
    {
        $this->issue();
        $plan = Plan::where('code', 'full')->firstOrFail()->replicate();
        $plan->code = 'prerequisite-qaqc';
        $plan->modules = ['qaqc'];
        $plan->standards_packs = ['NOT-THIS-PACK'];
        $plan->save();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $plan->id]));
        $this->actingAs($this->recipient)->postJson($this->base.'/'.$this->id.'/prerequisites', ['prerequisite' => ['item_reference' => 'A', 'requirement' => 'B']])->assertForbidden();
        $plan->standards_packs = [$this->project->standards_pack];
        $plan->save();
        $this->asWriter(fn () => DB::table('document_revisions')->where('id', $this->revision)->update(['cde_state' => 'ARCHIVED']));
        $member = $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->first());
        $member->role_codes = [];
        $this->asRecipient(fn () => $member->save());
        $this->create();
        $this->command('REPORT');
        $this->command('CONFIRM');
        $this->get($this->endpoint())->assertOk();
        $this->assertSame('FULFILLED', $this->prerequisite()->state);
    }
}
