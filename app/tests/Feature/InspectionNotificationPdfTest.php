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
use App\Support\InspectionNotificationPrintData;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationPdfTest extends TestCase
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
        if (getenv('EPCC_NOTIFICATION_PDF_EVIDENCE') === '1') {
            $dir = base_path('../docs/evidence/2026-10-19-inspection-notification-pdf');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.'/'.$name, $bytes);
        }
    }

    public function test_real_bilingual_pdf_uses_recorded_data_and_safe_private_response_without_mutating_notice(): void
    {
        $this->browser();
        $this->issue();
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->url($lang))->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('x-content-type-options', 'nosniff');
            $r->assertHeader('content-disposition', 'attachment; filename="inspection-notification-'.$this->id.'-'.$lang.'.pdf"');
            $this->assertStringContainsString('no-store', $r->headers->get('cache-control'));
            $this->assertStringContainsString('private', $r->headers->get('cache-control'));
            $bytes = $r->getContent();
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertGreaterThan(1000, strlen($bytes));
            $text = $this->inspect($bytes);
            $this->assertStringContainsString($before->number, $text);
            $this->assertStringContainsString('EPC-PARS', $text);
            $this->assertStringContainsString('MC-KARAN', $text);
            $this->assertStringContainsString('گچ', $text);
            $this->assertStringContainsString('ژاله', $text);
            if ($lang === 'fa') {
                $fonts = $this->inspect($bytes, 'pdffonts');
                $this->assertStringContainsString('Vazirmatn', $fonts);
                $this->assertStringContainsString('yes', $fonts);
                $this->artifact('sample-fa-fonts.txt', $fonts);
            }
            $this->artifact('sample-'.$lang.'.pdf', $bytes);
            $this->artifact('sample-'.$lang.'.txt', $text);
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_draft_and_hidden_or_foreign_notices_are_refused_before_renderer_availability_is_checked(): void
    {
        $this->app->instance(PdfRenderer::class, new PdfRenderer(1, ['missing-notification-pdf-browser']));
        $this->actingAs($this->writer)->getJson($this->url())->assertUnprocessable();
        $this->actingAs($this->recipient)->getJson($this->url())->assertNotFound();
        $this->actingAs($this->writer)->getJson(str_replace('PH-14', 'BB-2', $this->url()))->assertNotFound();
        $this->get($this->base.'/not-a-uuid.pdf')->assertNotFound();
    }

    public function test_unaddressed_admin_cannot_export_even_though_it_can_manage_permissions(): void
    {
        $this->issue();
        $this->app->instance(PdfRenderer::class, new PdfRenderer(1, ['missing-notification-pdf-browser']));
        $this->actingAs($this->owner)->getJson($this->url())->assertNotFound();
        $other = $this->userByEmail('tehrani@pmc.example');
        $this->actingAs($other)->getJson($this->url())->assertNotFound();
    }

    public function test_write_revocation_and_no_doc_view_do_not_remove_existing_notice_read_or_expand_source_reads(): void
    {
        $this->browser();
        $this->issue();
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [], 'issue_users' => [], 'reason' => 'Revoke writing, not historical read']);
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->actingAs($this->recipient)->get($this->url())->assertOk();
        foreach ($queries as $sql) {
            foreach (['"documents"', '"document_revisions"', '"inspection_itp_stages"', '"purchase_orders"', '"material_requisitions"'] as $source) {
                $this->assertStringNotContainsString($source, $sql);
            }
        }
        $this->actingAs($this->writer)->get($this->url())->assertOk();
    }

    public function test_current_recipient_plan_scope_active_status_and_participation_still_apply(): void
    {
        $this->issue();
        $this->app->instance(PdfRenderer::class, new PdfRenderer(1, ['missing-notification-pdf-browser']));
        $full = Plan::where('code', 'full')->value('id');
        $starter = Plan::where('code', 'starter')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $starter]));
        $this->actingAs($this->recipient)->getJson($this->url())->assertForbidden();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $full]));
        app(TenantContext::class)->runAs($this->recipient->company_id, function (): void {
            ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]);
        }, $this->project->id);
        $this->getJson($this->url())->assertNotFound();
        app(TenantContext::class)->forIdentityLookup(function (): void {
            ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => null]);
            User::whereKey($this->recipient->id)->update(['is_active' => false]);
        });
        $this->getJson($this->url())->assertNotFound();
        app(TenantContext::class)->forIdentityLookup(fn () => User::whereKey($this->recipient->id)->update(['is_active' => true]));
        $this->asWriter(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->recipient->company_id)->delete());
        $this->getJson($this->url())->assertNotFound();
    }

    public function test_archived_project_and_source_or_later_names_do_not_rewrite_exported_evidence(): void
    {
        $this->browser();
        $this->issue();
        $before = $this->row();
        $this->project->update(['name_en' => 'CHANGED_PROJECT', 'name_fa' => 'نام جدید']);
        $this->asWriter(function (): void {
            DocumentRevision::whereKey($this->revision)->update(['cde_state' => 'ARCHIVED']);
            Company::whereKey($this->writer->company_id)->update(['name_en' => 'CHANGED_COMPANY']);
            User::whereKey($this->writer->id)->update(['name' => 'CHANGED_PERSON']);
        });
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Retain issued export');
        $r = $this->actingAs($this->recipient)->get($this->url())->assertOk();
        $text = preg_replace('/\s+/u', ' ', $this->inspect($r->getContent()));
        $this->assertStringContainsString('SYNTHETIC PROJECT RECORD', $text);
        foreach (['CHANGED_PROJECT', 'CHANGED_COMPANY', 'CHANGED_PERSON', 'ARCHIVED'] as $changed) {
            $this->assertStringNotContainsString($changed, $text);
        }
        $this->assertStringContainsString('WIP', $text);
        $this->assertEquals($before, $this->row());
    }

    public function test_older_snapshot_without_project_fields_has_explicit_unknown_and_is_not_backfilled(): void
    {
        $this->browser();
        // Reproduce the prior canonical issue shape BEFORE insertion commits;
        // never disable the production guard or mutate an already-issued row.
        $this->asWriter(fn () => DB::unprepared("CREATE OR REPLACE FUNCTION notification_pdf_old_shape() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.issued_snapshot:=NEW.issued_snapshot-ARRAY['project_id','project_code','project_name_en','project_name_fa']; RETURN NEW; END \$\$; CREATE TRIGGER zz_notification_pdf_old_shape BEFORE UPDATE ON inspection_notifications FOR EACH ROW EXECUTE FUNCTION notification_pdf_old_shape();"));
        $this->issue();
        $this->asWriter(fn () => DB::statement('DROP TRIGGER zz_notification_pdf_old_shape ON inspection_notifications'));
        $before = $this->row();
        $r = $this->actingAs($this->recipient)->get($this->url())->assertOk();
        $text = preg_replace('/\s+/u', ' ', $this->inspect($r->getContent()));
        $this->assertStringContainsString('not captured on this older issue', $text);
        $this->assertStringContainsString($this->project->id, $text);
        $this->assertStringNotContainsString('SYNTHETIC PROJECT RECORD', $text);
        $this->assertEquals($before, $this->row());
    }

    public function test_incomplete_or_inconsistent_snapshot_is_refused_not_rebuilt_from_live_sources(): void
    {
        $this->issue();
        $original = $this->row();
        $s = json_decode($original->issued_snapshot, true);
        $mutators = [
            fn ($s) => array_diff_key($s, ['issuer_name' => true]),
            fn ($s) => array_replace($s, ['stage_id' => (string) Str::uuid()]),
            fn ($s) => array_replace($s, ['recipients' => []]),
            fn ($s) => array_replace($s, ['point' => 'PASS']),
            fn ($s) => array_diff_key($s, ['project_code' => true]),
            fn ($s) => array_replace($s, ['project_id' => (string) Str::uuid()]),
        ];
        foreach ($mutators as $mutate) {
            $row = clone $original;
            $row->issued_snapshot = json_encode($mutate($s));
            try {
                InspectionNotificationPrintData::snapshot($row);
                $this->fail('Expected invalid recorded snapshot');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('notification', $e->errors());
            }
        }
        $this->assertEquals($original, $this->row());
    }

    public function test_print_html_escapes_recorded_text_and_has_no_remote_assets_or_signature_boxes(): void
    {
        $this->issue();
        app()->setLocale('en');
        $notice = $this->row();
        $snapshot = InspectionNotificationPrintData::snapshot($notice);
        $html = view('print.inspection-notification', compact('notice', 'snapshot') + ['lang' => 'en'])->render();
        $this->assertStringContainsString('&lt;script&gt;notes()&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=', $html);
        foreach (['<script', '<img ', '<iframe', '<link ', '@font-face', 'class="sigs"'] as $tag) {
            $this->assertStringNotContainsString($tag, $html);
        }
        $this->assertStringContainsString('not an inspection result', $html);
    }

    public function test_missing_renderer_returns_localized_503_without_fallback_file_or_state_change(): void
    {
        $this->issue();
        $before = $this->row();
        $this->app->instance(PdfRenderer::class, new PdfRenderer(1, ['missing-notification-pdf-browser']));
        $r = $this->actingAs($this->recipient)->getJson($this->url())->assertStatus(503)->assertHeaderMissing('content-disposition');
        $r->assertJsonPath('message', __('inspection_notifications.pdf_unavailable'));
        $this->assertStringNotContainsString('/tmp/', $r->getContent());
        $this->assertEquals($before, $this->row());
    }

    public function test_long_multilingual_notes_paginate_without_losing_recorded_end_marker(): void
    {
        $this->browser();
        $stage = $this->service()->addStage($this->project, $this->writer, ['revision_id' => $this->revision, 'sequence' => 2, 'point' => 'H', 'requires_notification' => true, 'description_en' => str_repeat("Stage evidence line\n", 150), 'description_fa' => str_repeat("بازرسی مرحلهٔ اجرا\n", 150)]);
        $d = $this->draft();
        $d['itp_stage_id'] = $stage;
        $d['notes_en'] = str_repeat("Recorded notes line\n", 250).'END-OF-RECORDED-NOTES';
        $d['notes_fa'] = str_repeat("پیش‌نویس ارجاع‌شده\n", 250).'END-OF-PERSIAN-NOTES';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        $this->issue();
        $r = $this->actingAs($this->recipient)->get($this->url('fa'))->assertOk();
        $bytes = $r->getContent();
        $text = $this->inspect($bytes);
        $this->assertStringContainsString('END-OF-RECORDED-NOTES', $text);
        $this->assertStringContainsString('END-OF-PERSIAN-NOTES', $text);
        $info = $this->inspect($bytes, 'pdfinfo');
        preg_match('/Pages:\s+(\d+)/', $info, $m);
        $this->assertGreaterThan(1, (int) ($m[1] ?? 0));
        $this->artifact('sample-long-fa.pdf', $bytes);
        $this->artifact('sample-long-fa-info.txt', $info);
    }

    public function test_recipient_departure_during_render_prevents_returning_previously_authorized_bytes(): void
    {
        $this->issue();
        $before = $this->row();
        $config = config('database.connections.pgsql');
        $this->assertSame('epcc_test', $config['database']);
        $binary = tempnam(sys_get_temp_dir(), 'notification-render-hook-');
        $marker = $binary.'.ran';
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];
        // Test-only executable, not an actual browser/PDF. Independent committed
        // connection revokes participation while the HTTP transaction is open.
        $script = '#!'.PHP_BINARY."\n<?php\n";
        $script .= '$db = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).", [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);\n";
        $script .= '$db->beginTransaction(); $db->exec("SET LOCAL statement_timeout=5000");'."\n";
        $script .= '$q=$db->prepare("SELECT set_config(\'epcc.company_id\', ?, true), set_config(\'epcc.project_id\', ?, true)"); $q->execute('.var_export([$this->writer->company_id, $this->project->id], true).");\n";
        $script .= '$q=$db->prepare("DELETE FROM project_company WHERE project_id=? AND company_id=?"); $q->execute('.var_export([$this->project->id, $this->recipient->company_id], true).");\n";
        $script .= 'if ($q->rowCount() !== 1) { exit(17); } $db->commit(); file_put_contents('.var_export($marker, true).', "revoked");'."\n";
        $script .= 'foreach ($argv as $arg) { if (str_starts_with($arg, "--print-to-pdf=")) { file_put_contents(substr($arg,15), "%PDF-".str_repeat("test-only",200)); } }';
        try {
            file_put_contents($binary, $script);
            chmod($binary, 0700);
            $this->app->instance(PdfRenderer::class, new PdfRenderer(10, [$binary]));
            $r = $this->actingAs($this->recipient)->getJson($this->url())->assertNotFound()->assertHeaderMissing('content-disposition');
            $this->assertFileExists($marker);
            $this->assertStringNotContainsString('%PDF-', $r->getContent());
            $this->assertEquals($before, $this->row());
        } finally {
            @unlink($binary);
            @unlink($marker);
        }
    }

    public function test_detail_offers_export_only_for_issued_notices(): void
    {
        $this->actingAs($this->writer)->get($this->base.'/'.$this->id)->assertOk()->assertDontSee('inspection-notification-pdf');
        $this->issue();
        $this->actingAs($this->recipient)->get($this->base.'/'.$this->id.'?lang=fa')->assertOk()->assertSee('inspection-notification-pdf')->assertSee('lang=fa', false);
    }
}
