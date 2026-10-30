<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\WarehouseService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationTest extends TestCase
{
    private User $writer;

    private User $owner;

    private User $recipient;

    private Project $project;

    private Document $document;

    private string $stageId;

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
        $this->document = $this->asWriter(fn () => app(DocumentRegister::class)->register($this->project, $this->writer, [
            'discipline' => 'PIPING', 'doc_type' => 'ITP', 'title_en' => 'Field and material ITP', 'title_fa' => 'برنامهٔ مراحل بازرسی',
        ]));
        $this->stageId = $this->service()->addStage($this->project, $this->writer, $this->stageData());
    }

    private function service(): InspectionNotificationService
    {
        return app(InspectionNotificationService::class);
    }

    private function asWriter(callable $f): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $f, $this->project->id);
    }

    private function stageData(): array
    {
        return ['revision_id' => $this->document->current_revision_id, 'sequence' => 1, 'point' => 'H', 'requires_notification' => true, 'description_en' => '<script>stage()</script>', 'description_fa' => 'بازرسی مرحلهٔ اجرا'];
    }

    private function config(int $version = 0): array
    {
        return ['version' => $version, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$this->writer->id], 'issue_users' => [$this->writer->id], 'reason' => 'Explicit main contractor notification permissions'];
    }

    private function grant(?array $d = null): void
    {
        $this->service()->configure($this->project, $this->owner, $d ?? $this->config());
    }

    private function draft(): array
    {
        return ['itp_revision_id' => $this->document->current_revision_id, 'itp_stage_id' => $this->stageId, 'work_reference' => 'Site activity, no PO / عملیات سایت', 'activity_start_at' => '2026-10-25T09:30:00+03:30', 'activity_end_at' => '2026-10-25T11:30:00+03:30', 'location' => 'Site / سایت', 'recipient_company_ids' => [$this->recipient->company_id], 'notes_en' => '<script>notes()</script>', 'notes_fa' => 'درخواست حضور، نه نتیجهٔ بازرسی'];
    }

    private function create(): string
    {
        return $this->service()->createDraft($this->project, $this->writer, $this->draft());
    }

    private function row(string $id): object
    {
        return $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $id)->first());
    }

    public function test_default_grants_do_not_exist_and_either_manager_or_epc_type_does_not_grant_issuance(): void
    {
        $this->actingAs($this->writer)->get($this->base.'?lang=en')->assertOk()->assertSee('ITP inspection notifications')->assertDontSee('notification-settings-form');
        $this->postJson($this->base, ['notification' => $this->draft()])->assertForbidden();
        $this->actingAs($this->owner)->get($this->base)->assertOk()->assertSee('notification-settings-form');
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_settings')->count()));
    }

    public function test_authorized_main_contractor_registers_and_issues_non_po_stage_notice_and_recipient_reads_only_after_issue(): void
    {
        $this->actingAs($this->owner)->postJson($this->base.'/settings?lang=en', ['settings' => $this->config()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->writer)->get($this->base.'?stage='.$this->stageId.'&lang=fa')->assertOk()->assertSee('inspection-notification-draft');
        $snapshot = $this->snapshot();
        $this->postJson($this->base.'?lang=en', ['notification' => $this->draft()])->assertRedirect()->assertSessionHasNoErrors();
        $id = $this->asWriter(fn () => DB::table('inspection_notifications')->value('id'));
        $this->assertNull($this->row($id)->purchase_order_id);
        $this->assertSame('DRAFT', $this->row($id)->state);
        $this->actingAs($this->recipient)->get($this->base.'/'.$id)->assertNotFound();
        $this->actingAs($this->writer)->postJson($this->base.'/'.$id.'/issue?lang=en', ['issuance' => ['version' => 1]])->assertRedirect()->assertSessionHasNoErrors();
        $row = $this->row($id);
        $this->assertSame('ISSUED', $row->state);
        $this->assertSame('PH-14-IN-EPC-PARS-000001', $row->number);
        $this->assertSame($this->writer->id, $row->issued_by);
        $this->assertSame(2, $row->version);
        $this->assertStringContainsString('2026-10-25 06:00:00', $row->activity_start_at);
        $this->assertSame($snapshot, $this->snapshot());
        $this->actingAs($this->recipient)->get($this->base.'/'.$id.'?lang=en')->assertOk()->assertSee($this->document->number)->assertSee($this->writer->name)->assertSee('&lt;script&gt;stage()&lt;/script&gt;', false);
        $this->get($this->base.'/'.$id.'?lang=fa')->assertOk()->assertSee('&lt;script&gt;notes()&lt;/script&gt;', false)->assertDontSee('<script>notes()</script>', false);
        $other = $this->userByEmail('tehrani@pmc.example');
        $this->actingAs($other)->get($this->base.'/'.$id)->assertNotFound();
    }

    private function snapshot(): array
    {
        return $this->asWriter(function () {
            $out = [];
            foreach (['purchase_orders', 'purchase_order_items', 'material_requisitions', 'material_requisition_items', 'material_inspections', 'material_lots', 'material_movements', 'po_execution_decisions', 'po_mr_allocations', 'po_pricing_revisions'] as $table) {
                $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
    }

    public function test_settings_are_versioned_project_scoped_and_wrong_company_or_forged_people_are_refused(): void
    {
        $this->actingAs($this->writer)->postJson($this->base.'/settings', ['settings' => $this->config()])->assertForbidden();
        $this->grant();
        $this->actingAs($this->owner)->postJson($this->base.'/settings', ['settings' => $this->config()])->assertUnprocessable();
        foreach (['main_company_id' => $this->recipient->company_id, 'draft_users' => [$this->recipient->id], 'issued_by' => $this->writer->id] as $key => $value) {
            $d = $this->config(1);
            $d[$key] = $value;
            $this->postJson($this->base.'/settings', ['settings' => $d])->assertUnprocessable();
        }
        $this->assertSame(1, $this->asWriter(fn () => DB::table('inspection_notification_settings')->count()));
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_settings')->update(['reason' => 'rewrite'])));
    }

    public function test_stage_requires_original_visible_itp_revision_and_own_document_issue_authority(): void
    {
        $this->actingAs($this->recipient)->postJson($this->base.'/stages', ['stage' => $this->stageData() + ['extra' => 'bad']])->assertUnprocessable();
        $d = $this->stageData();
        $d['sequence'] = 2;
        $this->postJson($this->base.'/stages', ['stage' => $d])->assertNotFound();
        $bad = $d;
        $bad['revision_id'] = (string) Str::uuid();
        $this->actingAs($this->writer)->postJson($this->base.'/stages', ['stage' => $bad])->assertNotFound();
        $other = $this->userByEmail('mousavi@sahand.example');
        $this->actingAs($other)->get($this->base.'?stage='.$this->stageId)->assertNotFound();
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_itp_stages')->update(['point' => 'W'])));
    }

    public function test_stage_source_type_and_required_flag_are_enforced_without_forcing_po(): void
    {
        $this->grant();
        $d = $this->stageData();
        $d['sequence'] = 2;
        $d['requires_notification'] = false;
        $id = $this->service()->addStage($this->project, $this->writer, $d);
        $n = $this->draft();
        $n['itp_stage_id'] = $id;
        $this->actingAs($this->writer)->postJson($this->base, ['notification' => $n])->assertUnprocessable();
        $wrong = $this->asWriter(fn () => Document::where('doc_type', 'DWG')->where('company_id', $this->writer->company_id)->firstOrFail());
        $d['revision_id'] = $wrong->current_revision_id;
        $this->postJson($this->base.'/stages', ['stage' => $d])->assertNotFound();
    }

    public function test_missing_invalid_recipients_wrong_revision_and_spoofed_fields_do_not_create_drafts(): void
    {
        $this->grant();
        foreach (['recipient_company_ids' => [], 'itp_revision_id' => (string) Str::uuid(), 'company_id' => $this->owner->company_id, 'result' => 'PASS', 'activity_start_at' => '2026-02-30T10:00:00+03:30'] as $key => $value) {
            $d = $this->draft();
            $d[$key] = $value;
            $this->actingAs($this->writer)->postJson($this->base, ['notification' => $d])->assertUnprocessable();
        }
        $foreign = Company::where('code', 'NIGC-REF')->firstOrFail();
        $d = $this->draft();
        $d['recipient_company_ids'] = [$foreign->id];
        $this->postJson($this->base, ['notification' => $d])->assertUnprocessable();
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notifications')->count()));
    }

    public function test_separate_draft_and_issue_permissions_revocation_and_repeat_issue_are_rechecked(): void
    {
        $d = $this->config();
        $d['issue_users'] = [];
        $this->grant($d);
        $id = $this->create();
        $this->actingAs($this->writer)->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertForbidden();
        $this->grant($this->config(1));
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1, 'issued_by' => $this->owner->id]])->assertUnprocessable();
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 2]])->assertUnprocessable();
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertRedirect();
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertUnprocessable();
        $this->assertSame(1, $this->row($id)->sequence);
    }

    public function test_plan_scope_inactive_and_main_contractor_changes_refuse_future_issue(): void
    {
        $this->grant();
        $id = $this->create();
        $full = Plan::where('code', 'full')->value('id');
        $starter = Plan::where('code', 'starter')->value('id');
        $this->asWriter(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->actingAs($this->writer)->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertForbidden();
        $this->asWriter(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertNotFound();
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => null]));
        $this->asWriter(fn () => DB::table('users')->where('id', $this->writer->id)->update(['is_active' => false]));
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertNotFound();
        $this->asWriter(fn () => DB::table('users')->where('id', $this->writer->id)->update(['is_active' => true]));
        $other = Company::where('code', 'EPC-SAHAND')->firstOrFail();
        $d = $this->config(1);
        $d['main_company_id'] = $other->id;
        $d['draft_users'] = $d['issue_users'] = [];
        $this->grant($d);
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertForbidden();
        $this->assertSame('DRAFT', $this->row($id)->state);
    }

    public function test_issued_evidence_cannot_be_updated_deleted_or_rewritten_by_later_itp_title(): void
    {
        $this->grant();
        $id = $this->create();
        $this->service()->issue($this->project, $this->writer, $id, ['version' => 1]);
        $before = $this->row($id)->issued_snapshot;
        try {
            $this->asWriter(fn () => DB::transaction(fn () => DB::table('inspection_notifications')->where('id', $id)->update(['location' => 'rewrite'])));
            $this->fail('Expected immutable evidence refusal');
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOTIFICATION_IMMUTABLE', $e->getMessage());
        }
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $id)->delete()));
        $this->asWriter(fn () => $this->document->update(['title_en' => 'Changed later title']));
        $this->assertSame($before, $this->row($id)->issued_snapshot);
    }

    public function test_archive_after_draft_refuses_issue_but_retains_read(): void
    {
        $this->grant();
        $id = $this->create();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Closure');
        $this->actingAs($this->writer)->get($this->base.'/'.$id)->assertOk()->assertDontSee('inspection-notification-issue');
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertUnprocessable();
        $this->assertSame('DRAFT', $this->row($id)->state);
    }

    public function test_permission_admin_is_existing_project_configure_not_owner_only_or_role_name(): void
    {
        $role = Role::create(['code' => 'notification_access_test', 'name_en' => 'Explicit manager', 'name_fa' => 'مدیر مجاز', 'capabilities' => ['project.configure']]);
        $this->asWriter(function () use ($role): void {
            $m = ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->firstOrFail();
            $m->update(['role_codes' => array_merge($m->role_codes, [$role->code])]);
        });
        $this->actingAs($this->writer)->postJson($this->base.'/settings', ['settings' => $this->config()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->writer->id, $this->asWriter(fn () => DB::table('inspection_notification_settings')->value('created_by')));
    }

    public function test_all_four_point_types_and_independent_paging_preserve_stage_selection(): void
    {
        $this->grant();
        for ($i = 2; $i <= 27; $i++) {
            $d = $this->stageData();
            $d['sequence'] = $i;
            $d['point'] = ['H', 'W', 'R', 'M'][$i % 4];
            $this->service()->addStage($this->project, $this->writer, $d);
        }
        $this->actingAs($this->writer)->get($this->base.'?lang=en&stage_page=2&stage='.$this->stageId)->assertOk()->assertSee('inspection-notification-draft')->assertSee('stage_page=1', false);
        $this->assertSame(27, $this->asWriter(fn () => DB::table('inspection_itp_stages')->count()));
    }

    public function test_revocation_and_recipient_departure_after_display_refuse_issue(): void
    {
        $this->grant();
        $id = $this->create();
        $this->actingAs($this->writer)->get($this->base.'/'.$id)->assertOk()->assertSee('inspection-notification-issue');
        $d = $this->config(1);
        $d['issue_users'] = [];
        $this->grant($d);
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertForbidden();
        $this->grant($this->config(2));
        $this->asWriter(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->recipient->company_id)->delete());
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertUnprocessable();
        $this->assertSame('DRAFT', $this->row($id)->state);
    }

    public function test_archived_itp_revision_and_foreign_project_routes_cannot_issue(): void
    {
        $this->grant();
        $id = $this->create();
        $this->asWriter(fn () => DocumentRevision::whereKey($this->document->current_revision_id)->update(['cde_state' => 'ARCHIVED']));
        $this->actingAs($this->writer)->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertNotFound();
        $this->get($this->base.'?stage='.$this->stageId)->assertNotFound();
        $this->postJson(str_replace('PH-14', 'BB-2', $this->base).'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertNotFound();
        $this->assertSame('DRAFT', $this->row($id)->state);
    }

    public function test_late_issue_failure_rolls_back_number_snapshot_and_state_then_retry_uses_first_number(): void
    {
        $this->grant();
        $id = $this->create();
        $this->asWriter(fn () => DB::unprepared("CREATE OR REPLACE FUNCTION notification_test_late() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'NOTIFICATION_TEST_LATE'; END \$\$; CREATE TRIGGER zz_notification_test_late AFTER UPDATE ON inspection_notifications FOR EACH ROW EXECUTE FUNCTION notification_test_late();"));
        $this->actingAs($this->writer)->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertUnprocessable();
        $r = $this->row($id);
        $this->assertSame('DRAFT', $r->state);
        $this->assertNull($r->number);
        $this->assertNull($r->issued_snapshot);
        $this->assertSame(1, $r->version);
        $this->asWriter(fn () => DB::statement('DROP TRIGGER zz_notification_test_late ON inspection_notifications'));
        $this->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertRedirect();
        $this->assertSame(1, $this->row($id)->sequence);
    }

    public function test_two_independent_issuers_of_same_draft_produce_one_issue_and_one_refusal(): void
    {
        $this->grant();
        $id = $this->create();
        $workers = [];
        $this->asWriter(function () use ($id, &$workers): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-notification:'.$this->project->id]);
            for ($i = 0; $i < 2; $i++) {
                $p = new Process([PHP_BINARY, base_path('../scripts/tests/inspection_notification_worker.php'), $this->writer->id, $this->project->id, $id], base_path(), ['APP_ENV' => 'testing', 'EPCC_NOTIFICATION_TEST' => 'isolated']);
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            // waitUntil() can consume an already-buffered readiness line in its
            // initial updateStatus before installing the callback. Read the
            // accumulated buffer instead, and prove both DB sessions are waiting
            // on the exact held advisory key before releasing the barrier.
            $deadline = microtime(true) + 8;
            do {
                $ready = true;
                foreach ($workers as $p) {
                    $p->checkTimeout();
                    $ready = str_contains($p->getOutput(), 'ready') && $ready;
                }
                $waiting = (int) DB::selectOne("WITH k AS (SELECT hashtextextended(?,0) v) SELECT count(*) n FROM pg_locks,k WHERE locktype='advisory' AND NOT granted AND classid::bigint=((v >> 32) & 4294967295) AND objid::bigint=(v & 4294967295) AND objsubid=1", ['inspection-notification:'.$this->project->id])->n;
                if ($ready && $waiting === 2) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $messages = implode(' | ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $workers));
                    foreach ($workers as $p) {
                        $p->stop(0.1);
                    }
                    $this->fail('Worker barrier not reached: '.$messages);
                }
                usleep(1000);
            } while (true);
            $this->assertSame(2, $waiting);
        });
        $codes = [];
        foreach ($workers as $p) {
            $codes[] = $p->wait();
            $this->assertNotSame(3, $p->getExitCode(), $p->getErrorOutput());
        }
        sort($codes);
        $this->assertSame([0, 2], $codes);
        $this->assertSame('ISSUED', $this->row($id)->state);
        $this->assertSame(1, $this->row($id)->sequence);
        $this->assertSame(2, $this->row($id)->version);
    }

    public function test_forward_upgrade_on_populated_archived_project_creates_no_implicit_main_or_grants(): void
    {
        $inspection = app(TenantContext::class)->runAs($this->recipient->company_id, fn () => app(WarehouseService::class)->inspect($this->project, $this->recipient, 'PASS', 'WORKS'), $this->project->id);
        $before = $this->asWriter(fn () => DB::table('material_inspections')->where('id', $inspection->id)->first());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Upgrade fixture');
        // A.4d hung two dependents off these tables: the stage sign-off table and the
        // additive stage-read policy both reference inspection_notifications. A.5b
        // slice 1 hung a third: the pack<->notice link, whose foreign key would
        // otherwise stop the drop below. This fixture models a database that
        // predates A.4d, so all of them come down first.
        DB::statement('DROP TABLE IF EXISTS inspection_stage_signoffs');
        DB::statement('DROP TABLE IF EXISTS piping_test_pack_notifications');
        DB::statement('DROP POLICY IF EXISTS stage_read_for_notified_parties ON inspection_itp_stages');
        foreach (['inspection_notification_prerequisite_events', 'inspection_notification_prerequisites', 'inspection_notification_acknowledgments', 'inspection_notification_cancellations', 'inspection_notification_draft_history', 'inspection_notifications', 'inspection_itp_stages', 'inspection_notification_settings'] as $table) {
            DB::statement('DROP TABLE '.$table);
        }
        $migration = require database_path('migrations/2026_10_19_200000_add_itp_inspection_notifications.php');
        $migration->up();
        foreach (['inspection_notifications', 'inspection_itp_stages', 'inspection_notification_settings'] as $table) {
            $this->assertSame(0, $this->asWriter(fn () => DB::table($table)->count()));
        }
        $this->assertEquals($before, $this->asWriter(fn () => DB::table('material_inspections')->where('id', $inspection->id)->first()));
    }

    public function test_optional_po_binds_only_visible_same_project_same_discipline_source_without_copying_commercial_data(): void
    {
        $this->grant();
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'PO test fixture grant',
        ]);
        $vendor = $this->asWriter(fn () => Company::where('code', 'VEN-AZAR')->value('id'));
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, [
            'discipline' => 'PIPING', 'material_category' => 'PIPE', 'title_en' => 'Commercial PO not copied', 'title_fa' => 'سفارش', 'vendor_company_id' => $vendor,
            'lines' => [['item_code' => 'P-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '1']],
        ]);
        $d = $this->draft();
        $d['purchase_order_id'] = $po->id;
        $id = $this->service()->createDraft($this->project, $this->writer, $d);
        $this->service()->issue($this->project, $this->writer, $id, ['version' => 1]);
        $this->assertSame($po->id, $this->row($id)->purchase_order_id);
        $this->assertStringNotContainsString('Commercial PO not copied', $this->row($id)->issued_snapshot);
        $d['purchase_order_id'] = (string) Str::uuid();
        $this->actingAs($this->writer)->postJson($this->base, ['notification' => $d])->assertNotFound();
        $this->assertSame(1, $this->asWriter(fn () => DB::table('inspection_notifications')->count()));
    }

    public function test_losing_document_read_role_after_draft_blocks_issue_without_granting_source_access(): void
    {
        $this->grant();
        $id = $this->create();
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['role_codes' => ['qc_inspector']]));
        $this->actingAs($this->writer)->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertForbidden();
        $this->assertSame('DRAFT', $this->row($id)->state);
    }

    public function test_in_app_recipient_quality_entitlement_is_rechecked_at_issue_without_granting_document_reads(): void
    {
        $this->grant();
        $id = $this->create();
        $starter = Plan::where('code', 'starter')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $starter]));
        $this->actingAs($this->writer)->postJson($this->base.'/'.$id.'/issue', ['issuance' => ['version' => 1]])->assertUnprocessable();
        $this->assertSame('DRAFT', $this->row($id)->state);
        $this->assertNull($this->row($id)->number);
    }

    public function test_known_other_buyers_po_remains_hidden_even_for_authorized_notification_issuer(): void
    {
        $this->grant();
        $other = $this->userByEmail('mousavi@sahand.example');
        app(TenantContext::class)->runAs($other->company_id, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $other->id)->update(['discipline_scope' => null]), $this->project->id);
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'selectors' => ['user:'.$other->id], 'suspend' => false, 'reason' => 'Separate buyer hidden source fixture',
        ]);
        $vendor = $this->asWriter(fn () => Company::where('code', 'VEN-AZAR')->value('id'));
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $other, [
            'discipline' => 'PIPING', 'material_category' => 'PIPE', 'title_en' => 'Other buyer hidden PO', 'title_fa' => 'سفارش پنهان', 'vendor_company_id' => $vendor,
            'lines' => [['item_code' => 'HIDDEN-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '1']],
        ]);
        $this->assertSame(0, $this->asWriter(fn () => DB::table('purchase_orders')->where('id', $po->id)->count()));
        $d = $this->draft();
        $d['purchase_order_id'] = $po->id;
        $this->actingAs($this->writer)->postJson($this->base, ['notification' => $d])->assertNotFound();
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notifications')->count()));
    }

    public function test_actual_project_gate_allows_independent_child_foreign_key_share_lock(): void
    {
        $this->grant();
        $probed = false;
        DB::listen(function ($event) use (&$probed): void {
            if ($probed || ! str_contains(strtolower($event->sql), 'for no key update') || ! str_contains($event->sql, '"projects"')) {
                return;
            }
            $probed = true;
            $p = new Process([PHP_BINARY, base_path('../scripts/tests/inspection_notification_worker.php'), $this->writer->id, $this->project->id, (string) Str::uuid(), 'fk-probe'], base_path(), ['APP_ENV' => 'testing', 'EPCC_NOTIFICATION_TEST' => 'isolated']);
            $p->setTimeout(5);
            $p->run();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $this->assertStringContainsString('key-share-compatible', $p->getOutput());
        });
        $id = $this->create();
        $this->assertTrue($probed);
        $this->assertSame('DRAFT', $this->row($id)->state);
    }
}
