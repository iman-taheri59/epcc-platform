<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\PdfRenderer;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionPrerequisitePdfTest extends TestCase
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

    private function add(string $reference = 'SYNTHETIC-ITEM', string $body = 'SYNTHETIC-REQUIREMENT'): string
    {
        return $this->service()->createPrerequisite($this->project, $this->recipient, $this->id, ['item_reference' => $reference, 'requirement' => $body]);
    }

    private function step(string $id, int $version, string $action, string $note = '0', array $extra = []): void
    {
        $this->service()->updatePrerequisite($this->project, $action === 'REPORT' ? $this->writer : $this->recipient, $this->id, $id, compact('version', 'action', 'note') + $extra);
    }

    private function capture(callable $assertions, string $mutation = ''): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'prerequisite-pdf-render-');
        $html = $binary.'.html';
        $script = '#!'.PHP_BINARY."\n<?php\n";
        $script .= 'foreach($argv as $arg){if(str_starts_with($arg,"file://")){copy(substr($arg,7),'.var_export($html, true).');}}';
        $script .= $mutation;
        $script .= 'foreach($argv as $arg){if(str_starts_with($arg,"--print-to-pdf=")){file_put_contents(substr($arg,15),"%PDF-".str_repeat("test-only",200));}}';
        try {
            file_put_contents($binary, $script);
            chmod($binary, 0700);
            $this->app->instance(PdfRenderer::class, new PdfRenderer(10, [$binary]));
            $assertions($html);
        } finally {
            @unlink($binary);
            @unlink($html);
        }
    }

    private function artifact(string $name, string $bytes): void
    {
        if (getenv('EPCC_PREREQUISITE_PDF_EVIDENCE') === '1') {
            file_put_contents(base_path('../docs/evidence/2026-10-20-prerequisite-pdf/'.$name), $bytes);
        }
    }

    public function test_real_bilingual_current_pdf_contains_all_states_full_history_and_pagination_without_mutation(): void
    {
        $this->browser();
        $this->issue();
        $open = $this->add('ITEM-OPEN', 'ORIGINAL-REQUIREMENT');
        $this->step($open, 1, 'EDIT', 'EDIT-REASON', ['item_reference' => 'ITEM-OPEN-EDITED', 'requirement' => str_repeat("گچ ژاله — Long prerequisite line.\n", 45).'END-OF-LONG-REQUIREMENT']);
        $reported = $this->add('ITEM-REPORTED');
        $this->step($reported, 1, 'REPORT', 'REPORT-EXPLANATION');
        $fulfilled = $this->add('ITEM-FULFILLED');
        $this->step($fulfilled, 1, 'REPORT', 'FULFILLED-REPORT');
        $this->step($fulfilled, 2, 'CONFIRM', 'CONFIRMATION-NOTE');
        $withdrawn = $this->add('ITEM-WITHDRAWN');
        $this->step($withdrawn, 1, 'WITHDRAW', 'WITHDRAWAL-REASON');
        $snapshot = fn () => $this->asWriter(fn () => [DB::table('inspection_notifications')->orderBy('id')->get(), DB::table('inspection_notification_prerequisites')->orderBy('id')->get(), DB::table('inspection_notification_prerequisite_events')->orderBy('id')->get()]);
        $before = $snapshot();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->url($lang))->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringContainsString('no-store', $r->headers->get('cache-control'));
            $text = $this->inspect($r->getContent());
            foreach (['ITEM-OPEN-EDITED', 'ITEM-REPORTED', 'ITEM-FULFILLED', 'ITEM-WITHDRAWN', 'ORIGINAL-REQUIREMENT', 'END-OF-LONG-REQUIREMENT', 'EDIT-REASON', 'REPORT-EXPLANATION', 'CONFIRMATION-NOTE', 'WITHDRAWAL-REASON', 'MC-KARAN', 'EPC-PARS', 'گچ', 'ژاله'] as $marker) {
                $this->assertStringContainsString($marker, $text);
            }
            $this->assertMatchesRegularExpression('/Pages:\s+[2-9][0-9]*|Pages:\s+1[0-9]+/', $this->inspect($r->getContent(), 'pdfinfo'));
            $this->artifact('sample-'.$lang.'.pdf', $r->getContent());
            $this->artifact('sample-'.$lang.'.txt', $text);
            if ($lang === 'fa') {
                $this->assertStringContainsString('Vazirmatn', $this->inspect($r->getContent(), 'pdffonts'));
            }
        }
        $this->assertEquals($before, $snapshot());
    }

    public function test_export_ignores_web_filters_and_pages_and_keeps_more_than_25_records_and_history_events(): void
    {
        $this->issue();
        $first = $this->add('FIRST-ITEM');
        for ($i = 1; $i <= 26; $i++) {
            $this->step($first, $i, 'REPORT', 'HISTORY-MARKER-'.$i);
        }
        for ($i = 2; $i <= 26; $i++) {
            $this->add('ITEM-MARKER-'.$i);
        }
        $this->capture(function ($file): void {
            $this->actingAs($this->writer)->get($this->url().'&prerequisite_page=999&prerequisite_history_page=999&prerequisite_state=WITHDRAWN&prerequisite_notice_state=CANCELLED')->assertOk();
            $html = file_get_contents($file);
            $this->assertSame(26, substr_count($html, '<article id="prerequisite-'));
            $this->assertSame(52, substr_count($html, 'class="prerequisite-event"'));
            foreach (['FIRST-ITEM', 'ITEM-MARKER-26', 'HISTORY-MARKER-1', 'HISTORY-MARKER-26'] as $marker) {
                $this->assertStringContainsString($marker, $html);
            }
            $this->assertStringNotContainsString('<details', $html);
        });
    }

    public function test_captured_bilingual_html_escapes_text_preserves_zero_and_uses_no_live_sources_or_drafts(): void
    {
        $this->issue();
        $id = $this->add('ITEM <script>unsafe()</script>', '<img src="https://invalid.example/private"> & REQUIREMENT');
        $this->step($id, 1, 'REPORT', '0');
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->capture(function ($file): void {
            foreach (['en', 'fa'] as $lang) {
                $this->actingAs($this->recipient)->get($this->url($lang))->assertOk();
                $html = file_get_contents($file);
                $this->assertStringContainsString('ITEM &lt;script&gt;unsafe()&lt;/script&gt;', $html);
                $this->assertStringContainsString('&lt;img src=', $html);
                $this->assertStringNotContainsString('<script>unsafe()', $html);
                $this->assertStringNotContainsString('<img src="https://invalid.example/private">', $html);
                $this->assertStringContainsString('class="text-block">0</div>', $html);
                $this->assertStringContainsString('inspection-prerequisite-stage', $html);
                $this->assertStringNotContainsString('<form', $html);
                $this->artifact('sample-'.$lang.'.html', $html);
            }
        });
        foreach ($queries as $sql) {
            foreach (['"documents"', '"document_revisions"', '"inspection_itp_stages"', '"purchase_orders"', '"material_requisitions"', '"inspection_notification_draft_events"'] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
    }

    public function test_empty_notice_and_other_notice_records_do_not_create_false_readiness_or_leak(): void
    {
        $this->issue();
        $other = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        $this->service()->issue($this->project, $this->writer, $other, ['version' => 1]);
        $this->service()->createPrerequisite($this->project, $this->recipient, $other, ['item_reference' => 'OTHER-NOTICE-SECRET', 'requirement' => 'NOT-THIS-NOTICE']);
        $this->capture(function ($file): void {
            $this->actingAs($this->recipient)->get($this->url())->assertOk();
            $html = file_get_contents($file);
            $this->assertStringContainsString('No prerequisites recorded for this notice.', $html);
            $this->assertStringNotContainsString('OTHER-NOTICE-SECRET', $html);
        });
    }

    public function test_cancelled_and_archived_notice_retains_current_records_and_full_history(): void
    {
        $this->issue();
        $id = $this->add('RETAINED-CANCELLED-ITEM');
        $this->step($id, 1, 'REPORT', 'RETAINED-REPORT');
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'CANCELLED-REASON']);
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only PDF');
        $before = $this->row();
        $this->capture(function ($file): void {
            $this->actingAs($this->recipient)->get($this->url())->assertOk();
            $html = file_get_contents($file);
            foreach (['RETAINED-CANCELLED-ITEM', 'RETAINED-REPORT', 'CANCELLED-REASON'] as $marker) {
                $this->assertStringContainsString($marker, $html);
            }
            $this->assertSame(2, substr_count($html, 'class="prerequisite-event"'));
        });
        $this->assertEquals($before, $this->row());
    }

    private function mutationScript(string $sql, array $params, User $actor): string
    {
        $c = config('database.connections.pgsql');
        $this->assertSame('epcc_test', $c['database']);
        $dsn = 'pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'];

        return '$db=new PDO('.var_export($dsn, true).','.var_export($c['username'], true).','.var_export($c['password'], true).',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->beginTransaction();'
            .'$q=$db->prepare("SELECT set_config(\'epcc.company_id\', ?, true),set_config(\'epcc.project_id\', ?, true)");$q->execute('.var_export([$actor->company_id, $this->project->id], true).');'
            .'$q=$db->prepare('.var_export($sql, true).');$q->execute('.var_export($params, true).');if($q->rowCount()!==1){exit(17);}$db->commit();';
    }

    public function test_prerequisite_update_during_render_rejects_stale_bytes_and_retry_includes_change(): void
    {
        $this->issue();
        $id = $this->add();
        $mutation = $this->mutationScript('UPDATE inspection_notification_prerequisites SET version=version+1,latest_action=\'REPORT\',latest_note=\'DURING-RENDER-REPORT\',latest_actor_id=?,latest_actor_company_id=? WHERE id=?', [$this->writer->id, $this->writer->company_id, $id], $this->writer);
        $this->capture(function (): void {
            $r = $this->actingAs($this->recipient)->getJson($this->url())->assertStatus(409)->assertHeaderMissing('content-disposition');
            $this->assertStringNotContainsString('%PDF-', $r->getContent());
        }, $mutation);
        $this->capture(function ($file): void {
            $this->actingAs($this->recipient)->get($this->url())->assertOk();
            $this->assertStringContainsString('DURING-RENDER-REPORT', file_get_contents($file));
        });
    }

    public function test_new_prerequisite_during_render_also_invalidates_empty_appendix(): void
    {
        $this->issue();
        $mutation = $this->mutationScript('INSERT INTO inspection_notification_prerequisites(id,project_id,company_id,notification_id,issued_version,item_reference,requirement,latest_actor_id,latest_actor_company_id) VALUES(?,?,?,?,2,\'DURING-RENDER-ITEM\',\'DURING-RENDER-BODY\',?,?)', [(string) Str::uuid(), $this->project->id, $this->recipient->company_id, $this->id, $this->recipient->id, $this->recipient->company_id], $this->recipient);
        $this->capture(function (): void {
            $r = $this->actingAs($this->writer)->getJson($this->url())->assertStatus(409)->assertHeaderMissing('content-disposition');
            $this->assertStringNotContainsString('%PDF-', $r->getContent());
        }, $mutation);
        $this->capture(function ($file): void {
            $this->actingAs($this->writer)->get($this->url())->assertOk();
            $this->assertStringContainsString('DURING-RENDER-ITEM', file_get_contents($file));
        });
    }
}
