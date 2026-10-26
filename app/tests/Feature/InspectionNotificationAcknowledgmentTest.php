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
use App\Services\PdfRenderer;
use App\Services\ProjectArchiveService;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationAcknowledgmentTest extends TestCase
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

    private function browser(): void
    {
        if (! app(PdfRenderer::class)->isAvailable()) {
            $this->markTestSkipped('Headless Chromium required for actual PDF verification.');
        }
    }

    private function inspect(string $bytes, string $tool = 'pdftotext'): string
    {
        if (trim((string) shell_exec('command -v '.escapeshellarg($tool))) === '') {
            $this->markTestSkipped($tool.' required.');
        }
        $file = tempnam(sys_get_temp_dir(), 'notification-pdf-');
        try {
            file_put_contents($file, $bytes);
            $args = $tool === 'pdftotext' ? [$tool, '-enc', 'UTF-8', $file, '-'] : [$tool, $file];
            $p = new Process($args);
            $p->mustRun();

            return preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $p->getOutput()) ?? $p->getOutput();
        } finally {
            @unlink($file);
        }
    }

    private function artifact(string $name, string $bytes): void
    {
        if (getenv('EPCC_NOTIFICATION_ACK_EVIDENCE') === '1') {
            $dir = base_path('../docs/evidence/2026-10-20-inspection-notification-acknowledgment');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.'/'.$name, $bytes);
        }
    }

    private function asRecipient(callable $f): mixed
    {
        return app(TenantContext::class)->runAs($this->recipient->company_id, $f, $this->project->id);
    }

    private function endpoint(): string
    {
        return $this->base.'/'.$this->id;
    }

    private function data(string $kind = 'ISSUE'): array
    {
        $out = ['version' => $this->row()->version, 'event_kind' => $kind];
        if ($kind === 'CANCELLATION') {
            $out['cancellation_id'] = $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->where('notification_id', $this->id)->value('id'));
        }

        return $out;
    }

    private function ack(string $kind = 'ISSUE', ?User $actor = null): void
    {
        $this->service()->acknowledge($this->project, $actor ?? $this->recipient, $this->id, $this->data($kind));
    }

    private function events()
    {
        return $this->asWriter(fn () => DB::table('inspection_notification_acknowledgments')->where('notification_id', $this->id)->orderBy('recorded_at')->orderBy('id')->get());
    }

    private function cancel(): void
    {
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => $this->row()->version, 'reason' => 'Synthetic withdrawal']);
    }

    private function raw(array $overrides = []): void
    {
        $d = $this->data();
        $this->asRecipient(fn () => DB::table('inspection_notification_acknowledgments')->insert(array_replace([
            'project_id' => $this->project->id, 'company_id' => $this->recipient->company_id, 'notification_id' => $this->id,
            'issued_version' => $d['version'], 'event_kind' => 'ISSUE', 'actor_id' => $this->recipient->id,
        ], $overrides)));
    }

    private function rawRefused(array $overrides = []): void
    {
        try {
            $this->raw($overrides);
            $this->fail('Expected SQL acknowledgment refusal');
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOTIFICATION_ACK_', $e->getMessage());
        }
    }

    private function anotherReader(): User
    {
        return $this->asRecipient(function () {
            $u = User::create(['company_id' => $this->recipient->company_id, 'name' => 'SECONDRECIPIENTREADER / خواننده دوم', 'email' => 'synthetic-receipt@karan.example', 'password' => 'synthetic-test-only', 'is_active' => true, 'locale' => 'fa']);
            ProjectMembership::create(['project_id' => $this->project->id, 'user_id' => $u->id, 'role_codes' => [], 'discipline_scope' => null]);

            return $u;
        });
    }

    public function test_explicit_company_acknowledgment_is_not_a_read_tracker_and_does_not_mutate_issued_content_or_business_records(): void
    {
        $this->issue();
        $tables = ['inspection_notifications', 'inspection_notification_cancellations', 'inspection_notification_draft_history', 'inspection_notification_settings', 'inspection_itp_stages', 'material_inspections', 'purchase_orders', 'material_requisitions', 'material_lots', 'material_movements'];
        $snapshot = fn () => $this->asWriter(function () use ($tables) {
            $out = [];
            foreach ($tables as $table) {
                $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
        $before = $snapshot();
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->recipient)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee('id="inspection-notification-acknowledge"', false);
        }
        $this->assertCount(0, $this->events());
        $this->postJson($this->endpoint().'/acknowledge?lang=fa', ['acknowledgment' => $this->data()])->assertRedirect($this->endpoint().'?lang=fa');
        $e = $this->events()->sole();
        $this->assertSame($this->recipient->id, $e->actor_id);
        $this->assertSame($this->recipient->company_id, $e->company_id);
        $this->assertSame($this->recipient->name, $e->actor_name);
        $this->assertSame('ISSUE', $e->event_kind);
        $this->assertNull($e->cancellation_id);
        $this->assertSame(2, $e->issued_version);
        $this->assertSame('AUTHORIZED_RECIPIENT_READER', $e->authority_model);
        $this->assertNotNull($e->recorded_at);
        $this->assertSame($before, $snapshot());
        $this->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data()])->assertUnprocessable();
        $this->assertEquals($e, $this->events()->sole());
        $this->get($this->endpoint())->assertOk()->assertDontSee('id="inspection-notification-acknowledge"', false);
    }

    public function test_any_current_recipient_reader_can_represent_company_without_issue_draft_or_document_rights_and_first_reader_wins(): void
    {
        $this->issue();
        $other = $this->anotherReader();
        $this->assertNull($this->asRecipient(fn () => $this->service()->permission($this->project, $other, 'issue', 'PIPING')));
        $this->assertNull($this->asRecipient(fn () => $this->service()->permission($this->project, $other, 'draft', 'PIPING')));
        $this->assertFalse($this->asRecipient(fn () => app(AuthorizationPipeline::class)->allows($other, 'doc.view', $this->project, ['discipline' => 'PIPING'])));
        $this->ack('ISSUE', $other);
        $e = $this->events()->sole();
        $this->assertSame($other->id, $e->actor_id);
        $this->actingAs($this->recipient)->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data()])->assertUnprocessable();
        $this->assertEquals($e, $this->events()->sole());
        $this->asRecipient(fn () => User::whereKey($other->id)->update(['name' => 'LATERRECIPIENTNAME']));
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($other->company_id)->update(['name_en' => 'LATERCOMPANYNAME', 'name_fa' => 'نام جدید شرکت']));
        $this->actingAs($this->writer)->get($this->endpoint().'?lang=en')->assertOk()->assertSee($e->actor_name)->assertSee($e->company_name_en)->assertDontSee('LATERRECIPIENTNAME')->assertDontSee('LATERCOMPANYNAME');
    }

    public function test_original_and_cancellation_are_independent_company_events_and_late_original_receipt_is_refused(): void
    {
        $this->issue();
        $this->ack();
        $original = $this->events()->sole();
        $this->cancel();
        $this->actingAs($this->recipient)->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data()])->assertUnprocessable();
        $r = $this->get($this->endpoint().'?lang=en')->assertOk();
        $this->assertSame('CANCELLATION', $r->viewData('ackKind'));
        $this->assertTrue($r->viewData('canAcknowledge'));
        $this->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data('CANCELLATION')])->assertRedirect();
        $events = $this->events();
        $this->assertCount(2, $events);
        $this->assertEquals($original, $events->first());
        $this->assertSame('CANCELLATION', $events->last()->event_kind);
        $this->assertSame($this->data('CANCELLATION')['cancellation_id'], $events->last()->cancellation_id);
        $this->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data('CANCELLATION')])->assertUnprocessable();
        $this->get($this->endpoint())->assertOk()->assertDontSee('id="inspection-notification-acknowledge"', false);
        $this->assertCount(2, $this->events());
    }

    public function test_cancel_receipt_does_not_fabricate_original_receipt_and_wrong_or_unavailable_targets_are_refused(): void
    {
        $this->issue();
        $this->actingAs($this->recipient);
        $bad = ['version' => 2, 'event_kind' => 'CANCELLATION', 'cancellation_id' => (string) Str::uuid()];
        $this->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $bad])->assertUnprocessable();
        $this->cancel();
        $this->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $bad])->assertUnprocessable();
        $this->rawRefused();
        $this->rawRefused(['event_kind' => 'CANCELLATION', 'cancellation_id' => (string) Str::uuid()]);
        $this->ack('CANCELLATION');
        $this->assertCount(1, $this->events());
        $this->assertSame('CANCELLATION', $this->events()->sole()->event_kind);
    }

    public function test_sender_and_unaddressed_admin_cannot_ack_for_other_company_but_selected_sender_can_ack_for_itself(): void
    {
        $this->issue();
        $this->actingAs($this->writer)->get($this->endpoint())->assertOk()->assertDontSee('id="inspection-notification-acknowledge"', false);
        $this->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data()])->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data()])->assertNotFound();
        $this->assertCount(0, $this->events());
        $d = $this->draft();
        $d['recipient_company_ids'] = [$this->writer->company_id, $this->recipient->company_id];
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        $this->issue();
        $this->ack('ISSUE', $this->writer);
        $this->assertSame($this->writer->company_id, $this->events()->sole()->company_id);
    }

    public function test_all_authorized_original_readers_see_other_company_receipts_without_foreign_directory_or_private_history_queries(): void
    {
        $d = $this->draft();
        $d['recipient_company_ids'] = [$this->recipient->company_id, $this->owner->company_id];
        $d['notes_en'] = 'PRIVATEACKDRAFTBODY';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        unset($d['itp_revision_id'],$d['itp_stage_id']);
        $d['version'] = 1;
        $d['notes_en'] = 'CURRENTACKISSUEDBODY';
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $d);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $this->ack();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->owner)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee($this->recipient->name)->assertSee('MC-KARAN')->assertSee('CURRENTACKISSUEDBODY')->assertDontSee('PRIVATEACKDRAFTBODY')->assertDontSee('inspection-notification-draft-history');
            $this->assertCount(1, $r->viewData('acknowledgments'));
            $this->assertTrue($r->viewData('canAcknowledge'));
            $this->artifact('other-recipient-'.$lang.'.html', preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-test-token"', $r->getContent()));
        }
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('inspection_notification_draft_history', $sql);
        }
        $rows = app(TenantContext::class)->runAs($this->owner->company_id, fn () => DB::table('inspection_notification_acknowledgments')->get(), $this->project->id);
        $this->assertCount(1, $rows);
        $unaddressed = $this->userByEmail('tehrani@pmc.example');
        $rows = app(TenantContext::class)->runAs($unaddressed->company_id, fn () => DB::table('inspection_notification_acknowledgments')->get(), $this->project->id);
        $this->assertCount(0, $rows);
        $this->actingAs($unaddressed)->getJson($this->endpoint())->assertNotFound();
    }

    public function test_malformed_draft_stale_or_actor_company_time_injected_payloads_create_no_receipt(): void
    {
        $this->actingAs($this->recipient)->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => ['version' => 2, 'event_kind' => 'ISSUE']])->assertNotFound();
        $this->issue();
        $this->actingAs($this->recipient);
        foreach ([[], ['version' => 1, 'event_kind' => 'ISSUE'], ['version' => 999, 'event_kind' => 'ISSUE'], ['version' => 2, 'event_kind' => 'READ'], ['version' => 2, 'event_kind' => ['ISSUE']], ['version' => 2, 'event_kind' => 'CANCELLATION'], ['version' => 2, 'event_kind' => 'ISSUE', 'cancellation_id' => (string) Str::uuid()], ['version' => 2, 'event_kind' => 'ISSUE', 'actor_id' => $this->writer->id], ['version' => 2, 'event_kind' => 'ISSUE', 'company_id' => $this->writer->company_id], ['version' => 2, 'event_kind' => 'ISSUE', 'recorded_at' => '1999-01-01']] as $bad) {
            $this->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $bad])->assertUnprocessable();
        }
        $this->assertCount(0, $this->events());
    }

    public function test_sql_guard_rechecks_current_scope_and_captures_identity_company_time_and_refuses_mutation(): void
    {
        $this->issue();
        $before = $this->row();
        $this->rawRefused(['issued_version' => 999]);
        $this->rawRefused(['actor_id' => $this->writer->id]);
        $this->rawRefused(['company_id' => $this->writer->company_id]);
        $this->rawRefused(['project_id' => Project::where('code', 'BB-2')->value('id')]);
        $this->rawRefused(['event_kind' => 'READ']);
        $this->raw(['actor_name' => 'FORGED', 'company_code' => 'FORGED', 'company_name_en' => 'FORGED', 'company_name_fa' => 'FORGED', 'discipline' => 'CIVIL', 'authority_model' => 'FORGED', 'recorded_at' => '1999-01-01T00:00:00+00:00']);
        $e = $this->events()->sole();
        $this->assertSame($this->recipient->name, $e->actor_name);
        $this->assertSame('MC-KARAN', $e->company_code);
        $this->assertSame('PIPING', $e->discipline);
        $this->assertSame('AUTHORIZED_RECIPIENT_READER', $e->authority_model);
        $this->assertNotSame('1999-01-01', substr($e->recorded_at, 0, 10));
        $this->rawRefused();
        foreach (['update', 'delete'] as $op) {
            try {
                $this->asRecipient(function () use ($op) {
                    $q = DB::table('inspection_notification_acknowledgments')->where('id', $this->events()->sole()->id);
                    $op === 'update' ? $q->update(['actor_name' => 'Rewrite']) : $q->delete();
                });
                $this->fail('Expected immutable receipt guard');
            } catch (QueryException$ex) {
                $this->assertStringContainsString('NOTIFICATION_ACK_IMMUTABLE', $ex->getMessage());
            }
        }
        $this->assertEquals($e, $this->events()->sole());
        $this->assertEquals($before, $this->row());
    }

    public function test_current_recipient_active_membership_discipline_module_pack_and_participation_gates_apply_before_receipt(): void
    {
        $this->issue();
        $this->actingAs($this->recipient);
        $body = ['acknowledgment' => $this->data()];
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->postJson($this->endpoint().'/acknowledge', $body)->assertNotFound();
        $this->rawRefused();
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => null]));
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['is_active' => false]));
        $this->postJson($this->endpoint().'/acknowledge', $body)->assertNotFound();
        $this->rawRefused();
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['is_active' => true]));
        $membership = $this->asRecipient(fn () => DB::table('project_memberships')->where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->first());
        $this->asRecipient(fn () => DB::table('project_memberships')->where('id', $membership->id)->delete());
        $this->postJson($this->endpoint().'/acknowledge', $body)->assertNotFound();
        $this->rawRefused();
        $this->asRecipient(fn () => DB::table('project_memberships')->insert((array) $membership));
        $starter = Plan::where('code', 'starter')->value('id');
        $full = Plan::where('code', 'full')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $starter]));
        $this->postJson($this->endpoint().'/acknowledge', $body)->assertForbidden();
        $this->rawRefused();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $full]));
        $bridge = $this->asRecipient(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->recipient->company_id)->first());
        $this->asRecipient(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->recipient->company_id)->delete());
        $this->postJson($this->endpoint().'/acknowledge', $body)->assertNotFound();
        $this->rawRefused();
        $this->asWriter(fn () => DB::table('project_company')->insert((array) $bridge));
        $this->assertCount(0, $this->events());
        $this->ack();
        $this->assertCount(1, $this->events());
    }

    public function test_quality_only_recipient_without_document_module_can_ack_but_wrong_pack_cannot(): void
    {
        $this->issue();
        $plan = Plan::where('code', 'full')->firstOrFail()->replicate();
        $plan->code = 'receipt-qaqc-only';
        $plan->modules = ['qaqc'];
        $plan->standards_packs = [$this->project->standards_pack];
        $plan->save();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $plan->id]));
        $plan->standards_packs = ['NOT-THIS-PACK'];
        $plan->save();
        $this->actingAs($this->recipient)->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data()])->assertForbidden();
        $this->rawRefused();
        $plan->standards_packs = [$this->project->standards_pack];
        $plan->save();
        $this->ack();
        $this->assertCount(1, $this->events());
    }

    public function test_archived_source_does_not_delegate_source_or_prevent_receipt_but_project_archive_prevents_new_receipt(): void
    {
        $this->issue();
        $this->asWriter(fn () => DB::table('document_revisions')->where('id', $this->revision)->update(['cde_state' => 'ARCHIVED']));
        $this->ack();
        $event = $this->events()->sole();
        $this->cancel();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only receipt history');
        $this->actingAs($this->recipient)->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data('CANCELLATION')])->assertUnprocessable();
        $this->rawRefused(['event_kind' => 'CANCELLATION', 'cancellation_id' => $this->data('CANCELLATION')['cancellation_id']]);
        $r = $this->get($this->endpoint())->assertOk();
        $this->assertFalse($r->viewData('canAcknowledge'));
        $this->assertEquals($event, $this->events()->sole());
    }

    public function test_late_insert_failure_rolls_back_receipt_without_altering_notification_or_cancellation(): void
    {
        $this->issue();
        $this->cancel();
        $before = $this->row();
        $cancelBefore = $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->get());
        DB::unprepared("CREATE OR REPLACE FUNCTION notification_ack_late_test() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'NOTIFICATION_ACK_TEST_LATE'; END \$\$; CREATE TRIGGER zz_notification_ack_late_test AFTER INSERT ON inspection_notification_acknowledgments FOR EACH ROW EXECUTE FUNCTION notification_ack_late_test();");
        $this->actingAs($this->recipient)->postJson($this->endpoint().'/acknowledge', ['acknowledgment' => $this->data('CANCELLATION')])->assertUnprocessable();
        $this->assertCount(0, $this->events());
        $this->assertEquals($before, $this->row());
        $this->assertEquals($cancelBefore, $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->get()));
    }

    public function test_real_current_bilingual_pdf_includes_both_company_receipts_without_old_private_draft_body_or_business_mutation(): void
    {
        $this->browser();
        $d = $this->draft();
        $d['notes_en'] = 'PRIVATEACKPDFDRAFT';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        unset($d['itp_revision_id'],$d['itp_stage_id']);
        $d['version'] = 1;
        $d['notes_en'] = 'CURRENTACKPDFBODY';
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $d);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $this->ack();
        $this->cancel();
        $this->ack('CANCELLATION');
        $before = $this->row();
        $events = $this->events();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->url($lang))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $text = $this->inspect($r->getContent());
            $this->assertStringContainsString('CANCELLED', $text);
            $this->assertStringContainsString('CURRENTACKPDFBODY', $text);
            $this->assertStringNotContainsString('PRIVATEACKPDFDRAFT', $text);
            $this->assertStringContainsString('MC-KARAN', $text);
            $this->assertStringContainsString('no-store', $r->headers->get('cache-control'));
            if ($lang === 'en') {
                $this->assertStringContainsString('current in-system lifecycle/receipt records.', preg_replace('/\s+/u', ' ', $text));
                $this->assertStringContainsString('Original issued notice', $text);
                $this->assertStringContainsString('Cancellation of the notice', $text);
            }
            $this->artifact('receipts-'.$lang.'.pdf', $r->getContent());
            $this->artifact('receipts-'.$lang.'.txt', $text);
        }
        $this->assertEquals($before, $this->row());
        $this->assertEquals($events, $this->events());
    }

    public function test_forward_install_on_archived_populated_data_does_not_fabricate_receipts_or_change_previous_events(): void
    {
        $this->issue();
        $this->cancel();
        $before = $this->row();
        $cancelBefore = $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->get());
        DB::statement('DROP TABLE inspection_notification_acknowledgments');
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Populated acknowledgment forward upgrade');
        $migration = require database_path('migrations/2026_10_20_140000_add_inspection_notification_acknowledgments.php');
        $migration->up();
        $this->assertCount(0, $this->events());
        $this->assertEquals($before, $this->row());
        $this->assertEquals($cancelBefore, $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->get()));
        $r = $this->actingAs($this->recipient)->get($this->endpoint())->assertOk();
        $this->assertFalse($r->viewData('canAcknowledge'));
        $this->assertCount(0, $r->viewData('acknowledgments'));
    }

    private function waitingReaders(array $readers, string $change = ''): array
    {
        $workers = [];
        $payload = $this->data();
        $this->asWriter(function () use ($readers, $change, $payload, &$workers): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-notification:'.$this->project->id]);
            foreach ($readers as $reader) {
                $p = new Process([PHP_BINARY, base_path('../scripts/tests/inspection_notification_worker.php'), $reader->id, $this->project->id, $this->id, 'acknowledge', json_encode($payload)], base_path(), ['APP_ENV' => 'testing', 'EPCC_NOTIFICATION_TEST' => 'isolated']);
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
                    }$this->fail('Acknowledgment barrier not reached: '.$messages);
                }
                usleep(1000);
            } while (true);
            $this->assertSame(count($readers), $waiting);
            if ($change === 'cancel') {
                $this->cancel();
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

    public function test_two_independent_readers_of_same_company_record_exactly_one_company_receipt(): void
    {
        $this->issue();
        $before = $this->row();
        $other = $this->anotherReader();
        $this->assertSame([0, 2], $this->waitingReaders([$this->recipient, $other]));
        $this->assertCount(1, $this->events());
        $this->assertEquals($before, $this->row());
    }

    public function test_independent_recipient_companies_can_each_record_their_own_receipt(): void
    {
        $d = $this->draft();
        $d['recipient_company_ids'] = [$this->recipient->company_id, $this->owner->company_id];
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        $this->issue();
        $this->assertSame([0, 0], $this->waitingReaders([$this->recipient, $this->owner]));
        $this->assertCount(2, $this->events());
        $this->assertCount(2, $this->events()->pluck('company_id')->unique());
    }

    public function test_original_receipt_waiting_behind_committed_cancellation_is_refused(): void
    {
        $this->issue();
        $before = $this->row();
        $this->assertSame([2], $this->waitingReaders([$this->recipient], 'cancel'));
        $this->assertCount(0, $this->events());
        $this->assertEquals($before, $this->row());
        $this->ack('CANCELLATION');
        $this->assertSame('CANCELLATION', $this->events()->sole()->event_kind);
    }

    public function test_waiting_receipt_rechecks_committed_reader_discipline_change_in_sql(): void
    {
        $this->issue();
        $this->assertSame([2], $this->waitingReaders([$this->recipient], 'discipline'));
        $this->assertCount(0, $this->events());
    }

    public function test_new_company_receipt_during_pdf_render_refuses_stale_bytes_and_requires_retry(): void
    {
        $this->issue();
        $before = $this->row();
        $config = config('database.connections.pgsql');
        $this->assertSame('epcc_test', $config['database']);
        $binary = tempnam(sys_get_temp_dir(), 'notification-ack-render-');
        $marker = $binary.'.ran';
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];
        $script = '#!'.PHP_BINARY."\n<?php\n";
        $script .= '$db=new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);\n";
        $script .= '$db->beginTransaction();$db->exec("SET LOCAL statement_timeout=5000");'."\n";
        $script .= '$q=$db->prepare("SELECT set_config(\'epcc.company_id\', ?, true), set_config(\'epcc.project_id\', ?, true)");$q->execute('.var_export([$this->recipient->company_id, $this->project->id], true).");\n";
        $script .= '$q=$db->prepare("INSERT INTO inspection_notification_acknowledgments(project_id,company_id,notification_id,issued_version,actor_id,event_kind) VALUES(?,?,?,2,?,\'ISSUE\')");$q->execute('.var_export([$this->project->id, $this->recipient->company_id, $this->id, $this->recipient->id], true).");\n";
        $script .= '$db->commit();file_put_contents('.var_export($marker, true).',"acknowledged");'."\n";
        $script .= 'foreach($argv as$arg){if(str_starts_with($arg,"--print-to-pdf=")){file_put_contents(substr($arg,15),"%PDF-".str_repeat("test-only",200));}}';
        try {
            file_put_contents($binary, $script);
            chmod($binary, 0700);
            $this->app->instance(PdfRenderer::class, new PdfRenderer(10, [$binary]));
            $r = $this->actingAs($this->writer)->getJson($this->url())->assertStatus(409)->assertHeaderMissing('content-disposition');
            $this->assertFileExists($marker);
            $this->assertStringNotContainsString('%PDF-', $r->getContent());
            $this->assertCount(1, $this->events());
            $this->assertEquals($before, $this->row());
        } finally {
            @unlink($binary);
            @unlink($marker);
        }
    }

    public function test_recorded_actor_and_company_text_are_escaped_for_all_readers_without_live_lookup(): void
    {
        $this->issue();
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['name' => 'ACKACTOR <script>ACKSCRIPT()</script>']));
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['name_en' => 'ACKCOMPANY <img src="https://invalid.example/receipt.png">']));
        $this->ack();
        $this->actingAs($this->writer)->get($this->endpoint().'?lang=en')->assertOk()->assertSee('ACKACTOR &lt;script&gt;ACKSCRIPT()&lt;/script&gt;', false)->assertDontSee('<script>ACKSCRIPT()', false)->assertSee('ACKCOMPANY &lt;img', false)->assertDontSee('<img src="https://invalid.example/receipt.png">', false);
    }
}
