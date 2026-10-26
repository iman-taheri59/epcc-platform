<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentRevision;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\PdfRenderer;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationDraftEditTest extends TestCase
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

    private function content(int $version = 1): array
    {
        $d = $this->draft();
        unset($d['itp_stage_id'], $d['itp_revision_id']);

        return ['version' => $version] + array_replace($d, ['work_reference' => 'Edited activity / فعالیت اصلاح‌شده', 'location' => 'Edited site', 'notes_en' => '<script>escaped_edit()</script>']);
    }

    private function endpoint(): string
    {
        return $this->base.'/'.$this->id;
    }

    public function test_edit_form_and_save_in_both_languages_preserve_source_creation_and_no_number_or_snapshot(): void
    {
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->writer)->get($this->endpoint().'/edit?lang='.$lang)->assertOk()->assertSee('inspection-notification-edit-form')->assertSee('notification[version]', false)->assertDontSee('name="notification[itp_stage_id]"', false);
        }
        $this->get($this->endpoint())->assertSee('inspection-notification-edit');
        $this->put($this->endpoint().'?lang=fa', ['notification' => $this->content()])->assertRedirect($this->endpoint().'?lang=fa');
        $after = $this->row();
        $this->assertSame(2, $after->version);
        $this->assertSame('DRAFT', $after->state);
        $this->assertSame('Edited site', $after->location);
        $this->assertSame($this->writer->id, $after->last_edited_by);
        $this->assertNotNull($after->last_edited_at);
        $this->assertNotNull($after->last_edit_setting_id);
        foreach (['stage_id', 'discipline', 'company_id', 'project_id', 'created_by', 'created_at', 'entry_setting_id', 'number', 'sequence', 'issued_snapshot', 'issued_at', 'issued_by'] as $key) {
            $this->assertSame($before->$key, $after->$key);
        }
        $this->get($this->endpoint().'/edit')->assertSee('&lt;script&gt;escaped_edit()&lt;/script&gt;', false)->assertDontSee('<script>escaped_edit()', false);
        $this->actingAs($this->recipient)->get($this->endpoint())->assertNotFound();
    }

    public function test_second_edit_advances_version_and_issue_requires_latest_version_and_freezes_edited_content(): void
    {
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $this->content());
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $this->content(2));
        $this->actingAs($this->writer)->postJson($this->endpoint().'/issue', ['issuance' => ['version' => 1]])->assertUnprocessable();
        $this->postJson($this->endpoint().'/issue', ['issuance' => ['version' => 3]])->assertRedirect();
        $before = $this->row();
        if (app(PdfRenderer::class)->isAvailable()) {
            $pdf = $this->actingAs($this->recipient)->get($this->url('fa'))->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            $this->actingAs($this->writer);
        }
        $this->assertSame(4, $before->version);
        $this->assertSame('ISSUED', $before->state);
        $this->assertSame(1, $before->sequence);
        $this->putJson($this->endpoint(), ['notification' => $this->content(4)])->assertUnprocessable();
        $this->getJson($this->endpoint().'/edit')->assertUnprocessable();
        $this->get($this->endpoint())->assertDontSee('id="inspection-notification-edit"', false);
        $this->assertEquals($before, $this->row());
    }

    public function test_stale_save_does_not_overwrite_and_stale_form_input_is_not_rebased(): void
    {
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $this->content());
        $before = $this->row();
        $stale = $this->content();
        $stale['location'] = 'STALE_LOST_INPUT';
        $this->actingAs($this->writer)->from($this->endpoint().'/edit')->put($this->endpoint(), ['edit_target' => $this->id, 'notification' => $stale])->assertRedirect($this->endpoint().'/edit')->assertSessionHasErrors('notification');
        $this->get($this->endpoint().'/edit')->assertDontSee('STALE_LOST_INPUT')->assertSee('Edited site')->assertSee('value="2"', false);
        $this->assertEquals($before, $this->row());
    }

    public function test_validation_recovery_is_bound_to_notice_and_version_and_unknown_fields_are_rejected(): void
    {
        $d = $this->content();
        $d['location'] = '';
        $this->actingAs($this->writer)->from($this->endpoint().'/edit')->put($this->endpoint(), ['edit_target' => $this->id, 'notification' => $d])->assertSessionHasErrors('notification.location');
        $this->get($this->endpoint().'/edit')->assertSee('Edited activity');
        $bad = $this->content();
        $bad['itp_stage_id'] = $this->stage;
        $this->putJson($this->endpoint(), ['notification' => $bad])->assertUnprocessable();
        $this->assertSame(1, $this->row()->version);
    }

    public function test_recipients_owners_foreign_projects_and_hidden_ids_cannot_edit(): void
    {
        $before = $this->row();
        foreach ([$this->recipient, $this->owner] as $actor) {
            $this->actingAs($actor)->getJson($this->endpoint().'/edit')->assertNotFound();
            $this->putJson($this->endpoint(), ['notification' => $this->content()])->assertNotFound();
        }
        $this->actingAs($this->writer)->putJson(str_replace('PH-14', 'BB-2', $this->endpoint()), ['notification' => $this->content()])->assertNotFound();
        $this->putJson($this->base.'/'.Str::uuid(), ['notification' => $this->content()])->assertNotFound();
        $this->assertEquals($before, $this->row());
    }

    public function test_issue_only_grant_does_not_authorize_edit_and_revocation_removes_form(): void
    {
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [], 'issue_users' => [$this->writer->id], 'reason' => 'Issue-only test']);
        $before = $this->row();
        $this->actingAs($this->writer)->get($this->endpoint())->assertDontSee('id="inspection-notification-edit"', false);
        $this->getJson($this->endpoint().'/edit')->assertForbidden();
        $this->putJson($this->endpoint(), ['notification' => $this->content()])->assertForbidden();
        $this->assertEquals($before, $this->row());
    }

    public function test_document_read_loss_blocks_edit_without_delegating_source_access(): void
    {
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['role_codes' => ['qc_inspector']]));
        $before = $this->row();
        $this->actingAs($this->writer)->getJson($this->endpoint().'/edit')->assertForbidden();
        $this->putJson($this->endpoint(), ['notification' => $this->content()])->assertForbidden();
        $this->assertEquals($before, $this->row());
    }

    public function test_current_scope_and_active_status_block_edit(): void
    {
        $before = $this->row();
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $this->content()])->assertNotFound();
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => null]));
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['is_active' => false]));
        $this->putJson($this->endpoint(), ['notification' => $this->content()])->assertNotFound();
        $this->assertEquals($before, $this->row());
    }

    public function test_archived_source_or_project_prevent_edit(): void
    {
        $this->asWriter(fn () => DocumentRevision::whereKey($this->revision)->update(['cde_state' => 'ARCHIVED']));
        $before = $this->row();
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $this->content()])->assertNotFound();
        $this->asWriter(fn () => DocumentRevision::whereKey($this->revision)->update(['cde_state' => 'WIP']));
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Edit denial');
        $this->putJson($this->endpoint(), ['notification' => $this->content()])->assertUnprocessable();
        $this->assertEquals($before, $this->row());
    }

    public function test_invalid_recipients_optional_po_and_offset_roll_back_all_fields(): void
    {
        $before = $this->row();
        foreach (['recipient_company_ids' => [(string) Str::uuid()], 'purchase_order_id' => (string) Str::uuid(), 'activity_start_at' => '2026-10-25T09:30:00'] as $key => $value) {
            $d = $this->content();
            $d[$key] = $value;
            $r = $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $d]);
            $this->assertContains($r->status(), [404, 422]);
            $this->assertEquals($before, $this->row());
        }
    }

    public function test_sql_guard_rejects_source_creation_evidence_or_version_rewrite_and_issued_edits(): void
    {
        foreach ([['stage_id' => (string) Str::uuid()], ['created_by' => $this->owner->id], ['version' => 7], ['issued_snapshot' => '{}']] as $change) {
            $before = $this->row();
            try {
                $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $this->id)->update($change + ['last_edited_by' => $this->writer->id, 'version' => 2]));
                $this->fail('Expected SQL guard rejection');
            } catch (QueryException $e) {
                $this->assertStringContainsString('NOTIFICATION_', $e->getMessage());
            }
            $this->assertEquals($before, $this->row());
        }
        $this->issue();
        $before = $this->row();
        try {
            $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $this->id)->update(['location' => 'forged', 'version' => 3]));
            $this->fail('Expected immutable issued row');
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOTIFICATION_IMMUTABLE', $e->getMessage());
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_another_explicitly_granted_same_company_member_can_edit_without_changing_original_creator(): void
    {
        $editor = $this->asWriter(function () {
            $u = $this->writer->replicate();
            $u->id = (string) Str::uuid();
            $u->email = 'draft-editor@synthetic.example';
            $u->name = 'Synthetic second editor';
            $u->save();
            $m = ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->firstOrFail()->replicate();
            $m->id = (string) Str::uuid();
            $m->user_id = $u->id;
            $m->save();

            return $u;
        });
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$editor->id], 'issue_users' => [$this->writer->id], 'reason' => 'Explicit editor, not original-author-only']);
        $this->actingAs($editor)->putJson($this->endpoint(), ['notification' => $this->content()])->assertRedirect();
        $row = $this->row();
        $this->assertSame($editor->id, $row->last_edited_by);
        $this->assertSame($this->writer->id, $row->created_by);
    }

    public function test_main_contractor_change_and_module_loss_remove_edit_authority(): void
    {
        $before = $this->row();
        $starter = Plan::where('code', 'starter')->value('id');
        $full = Plan::where('code', 'full')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $this->content()])->assertForbidden();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        $other = Company::where('code', 'EPC-SAHAND')->value('id');
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $other, 'draft_users' => [], 'issue_users' => [], 'reason' => 'Main contractor changed']);
        $this->putJson($this->endpoint(), ['notification' => $this->content()])->assertForbidden();
        $this->assertEquals($before, $this->row());
    }

    public function test_forward_migration_preserves_existing_issued_and_draft_rows_on_archived_project(): void
    {
        $this->issue();
        $issued = $this->row();
        $draftId = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        $draft = $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $draftId)->first());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Populated forward upgrade');
        DB::unprepared('ALTER TABLE inspection_notifications DROP CONSTRAINT notification_state_evidence; ALTER TABLE inspection_notifications DROP CONSTRAINT notification_edit_evidence; ALTER TABLE inspection_notifications DROP COLUMN last_edited_by, DROP COLUMN last_edit_setting_id, DROP COLUMN last_edited_at;');
        $old = file_get_contents(database_path('migrations/2026_10_19_200000_add_itp_inspection_notifications.php'));
        preg_match('/ALTER TABLE inspection_notifications ADD CHECK \(\(state=.*?;/', $old, $check);
        $this->assertNotEmpty($check);
        DB::unprepared($check[0]);
        $start = strpos($old, 'CREATE OR REPLACE FUNCTION epcc_guard_notification()');
        $end = strpos($old, "\nSQL);", $start);
        DB::unprepared(substr($old, $start, $end - $start));
        $migration = require database_path('migrations/2026_10_20_100000_allow_inspection_notification_draft_edits.php');
        $migration->up();
        $this->assertEquals($issued, $this->row());
        $this->assertEquals($draft, $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $draftId)->first()));
        $this->actingAs($this->recipient)->get($this->endpoint())->assertOk();
    }

    public function test_independent_edit_and_issue_workers_using_same_version_cannot_both_commit(): void
    {
        $id = $this->id;
        $workers = [];
        $this->asWriter(function () use ($id, &$workers): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-notification:'.$this->project->id]);
            for ($i = 0; $i < 2; $i++) {
                $p = new Process([PHP_BINARY, base_path('../scripts/tests/inspection_notification_worker.php'), $this->writer->id, $this->project->id, $id, $i === 0 ? 'edit' : 'issue', json_encode($this->content())], base_path(), ['APP_ENV' => 'testing', 'EPCC_NOTIFICATION_TEST' => 'isolated']);
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
        $this->assertContains($this->row()->state, ['DRAFT', 'ISSUED']);
        $this->assertSame(2, $this->row()->version);
        $this->assertSame($this->row()->state === 'ISSUED' ? 1 : null, $this->row()->sequence);
        $this->assertSame(2, $this->row()->version);
    }

    public function test_sql_edit_guard_rechecks_current_draft_permission(): void
    {
        $before = $this->row();
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [], 'issue_users' => [$this->writer->id], 'reason' => 'Revoke draft before SQL write']);
        try {
            $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $this->id)->update(['version' => 2, 'location' => 'forged', 'last_edited_by' => $this->writer->id, 'last_edit_setting_id' => $before->entry_setting_id, 'last_edited_at' => now()]));
            $this->fail('Expected revoked draft permission');
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOTIFICATION_PERMISSION_REVOKED', $e->getMessage());
        }
        $this->assertEquals($before, $this->row());
    }
}
