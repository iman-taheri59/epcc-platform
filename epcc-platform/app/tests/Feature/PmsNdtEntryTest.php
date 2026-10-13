<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\Project;
use App\Models\User;
use App\Services\PmsAccessService;
use App\Services\PmsBulkEntryService;
use App\Services\PmsEntryService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PmsNdtEntryTest extends TestCase
{
    private Project $p;

    private User $a;

    private User $o;

    private User $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->p = Project::where('code', 'PH-14')->firstOrFail();
        $this->a = $this->userByEmail('rezaei@pars.example');
        $this->o = $this->userByEmail('karimi@nioc.example');
        $this->c = $this->userByEmail('tehrani@pmc.example');
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 0, 'selectors' => ['user:'.$this->a->id], 'suspend' => false, 'reason' => 'Explicit test fixture author nomination']);
    }

    private function svc(): PmsEntryService
    {
        return app(PmsEntryService::class);
    }

    private function bound(callable $fn, ?User $u = null): mixed
    {
        return app(TenantContext::class)->runAs(($u ?? $this->a)->company_id, $fn, $this->p->id);
    }

    private function h(array $x = []): array
    {
        return array_replace(['code' => 'QA-150', 'source_document' => 'SYNTHETIC-PMS', 'source_revision' => 'A', 'source_text' => 'Source header <script>window.bad=true</script>',
            'reason' => 'Synthetic transcription', 'version' => 0, 'design_temp_min_c' => '-20', 'design_temp_max_c' => '200', 'corrosion_allowance_mm' => '3'], $x);
    }

    private function row(array $x = []): array
    {
        return array_replace(['component_type' => 'PIPE', 'qualifier' => null, 'size_key' => '6', 'description_as_written' => 'Original source row <script>window.bad=true</script>',
            'source_ref' => 'page 2 row 3', 'reason' => 'Transcribed only', 'version' => 0, 'schedule' => '40', 'dimensional_standard' => 'ASME B36.10M-2022', 'end_type' => 'BW'], $x);
    }

    private function create(): PipingClass
    {
        return $this->svc()->saveHeader($this->p, $this->a, $this->h());
    }

    private function events(): array
    {
        return $this->bound(fn () => DB::table('pms_entry_events')->orderBy('created_at')->get()->all());
    }

    private function refuse(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected validation refusal');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors());
        }
    }

    private function ndt(array $changes = []): array
    {
        return array_replace(['version' => 1, 'applicability' => 'Synthetic butt weld population', 'method' => 'RT', 'extent' => '10%',
            'sampling_basis' => 'Synthetic population A; selection per supplied procedure', 'acceptance' => 'Synthetic acceptance only',
            'reference' => 'SYNTHETIC-SPEC Rev A §4', 'conditions' => 'After required treatment', 'source_ref' => 'PMS source page 4 row 2',
            'reason' => 'Faithful transcription, not approval'], $changes);
    }

    private function records(PipingClass $class): array
    {
        return $this->bound(fn () => $class->fresh()->ndtRequirements->toArray());
    }

    public function test_http_capture_preserves_text_and_stamps_class_company_and_audit(): void
    {
        $class = $this->create();
        $input = $this->ndt(['applicability' => "  اتصال نمونه\nخط دوم  ", 'company_id' => $this->o->company_id, 'state' => 'APPROVED']);
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/ndt?lang=en', ['ndt' => $input])->assertRedirect()->assertSessionHasNoErrors();
        $record = $this->records($class)[0];
        self::assertSame($input['applicability'], $record['applicability']);
        self::assertSame($this->a->company_id, $record['company_id']);
        self::assertSame($this->p->id, $record['project_id']);
        self::assertSame($class->id, $record['piping_class_id']);
        self::assertArrayNotHasKey('state', $record);
        self::assertSame(2, $this->bound(fn () => $class->fresh()->entry_version));
        $event = $this->events()[1];
        self::assertNotNull($event->author_policy_id);
        self::assertSame([], json_decode($event->before_data, true)['ndt_requirements']);
        self::assertSame($record['id'], json_decode($event->after_data, true)['ndt_requirements'][0]['id']);
        self::assertSame($input['applicability'], json_decode($event->after_data, true)['ndt_requirements'][0]['applicability']);
    }

    public function test_incomplete_source_is_visible_without_inferred_sampling_or_acceptance_or_external_effects(): void
    {
        $class = $this->create();
        $tables = ['material_inspections', 'itps', 'itp_stages', 'itp_records', 'project_standards', 'standard_citations'];
        $snapshot = fn () => $this->bound(fn () => array_map(fn ($table) => json_encode(DB::table($table)->orderBy('id')->get()), $tables));
        $before = $snapshot();
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt(['sampling_basis' => null, 'acceptance' => null, 'reference' => null, 'conditions' => null]));
        self::assertSame(['sampling_basis', 'acceptance', 'reference'], $record->missingDetails());
        self::assertSame('10%', $record->extent);
        self::assertNull($record->sampling_basis);
        self::assertSame($before, $snapshot());
        $this->actingAs($this->a)->get('/projects/PH-14/piping/pms/'.$class->id.'?lang=en')->assertOk()->assertSee('data-ndt-incomplete', false)->assertSee('Not recorded — needs source clarification')->assertSee('10%');
    }

    public function test_edit_keeps_stable_id_and_history_while_header_and_note_updates_preserve_requirements(): void
    {
        $class = $this->create();
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt());
        $updated = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt(['id' => $record->id, 'version' => 2, 'extent' => '20%', 'conditions' => null]));
        self::assertSame($record->id, $updated->id);
        self::assertCount(1, $this->records($class));
        $event = $this->events()[2];
        self::assertSame('10%', json_decode($event->before_data, true)['ndt_requirements'][0]['extent']);
        self::assertSame('20%', json_decode($event->after_data, true)['ndt_requirements'][0]['extent']);
        $before = $this->records($class);
        $this->svc()->saveHeader($this->p, $this->a, $this->h(['version' => 3]), $class->id);
        $this->svc()->saveNote($this->p, $this->a, $class->id, ['version' => 4, 'number' => 1, 'text_en' => 'Unrelated note', 'reason' => 'Test']);
        self::assertSame($before, $this->records($class));
        self::assertSame('20%', json_decode($this->events()[4]->after_data, true)['ndt_requirements'][0]['extent']);
    }

    public function test_unknown_method_and_source_limits_are_preserved_without_catalogue_inference(): void
    {
        $class = $this->create();
        $input = $this->ndt(['method' => 'PROJECT METHOD X / RT', 'acceptance' => str_repeat('ژ', 4000)]);
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $input);
        self::assertSame('PROJECT METHOD X / RT', $record->method);
        self::assertSame($input['acceptance'], $this->records($class)[0]['acceptance']);
    }

    public static function invalidInputs(): array
    {
        return [
            'blank applicability' => [['applicability' => " \u{00A0}\t"], 'applicability'],
            'missing method' => [['method' => null], 'method'],
            'missing source' => [['source_ref' => null], 'source_ref'],
            'missing reason' => [['reason' => '  '], 'reason'],
            'array' => [['sampling_basis' => ['bad']], 'sampling_basis'],
            'long criteria' => [['acceptance' => str_repeat('ژ', 4001)], 'acceptance'],
            'long method' => [['method' => str_repeat('A', 121)], 'method'],
            'invalid UTF8' => [['extent' => "A\xFF"], 'extent'],
            'control' => [['reference' => "A\0B"], 'reference'],
            'bad id' => [['id' => ['bad']], 'id'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_does_not_create_requirement_change_version_or_append_audit(array $changes, string $field): void
    {
        $class = $this->create();
        try {
            $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt($changes));
            self::fail('Expected refusal');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ndt.'.$field, $e->errors());
        }
        self::assertSame([], $this->records($class));
        self::assertSame(1, $this->bound(fn () => $class->fresh()->entry_version));
        self::assertCount(1, $this->events());
    }

    public function test_bilingual_reader_escaping_author_editor_and_malformed_old_input_recovery(): void
    {
        $class = $this->create();
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt(['acceptance' => '</textarea><script>ndtBad()</script>']));
        $url = '/projects/PH-14/piping/pms/'.$class->id;
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $this->actingAs($this->o)->get($url.'?lang='.$lang)->assertOk()->assertSee('dir="'.$dir.'"', false)->assertSee('data-ndt-requirement=', false)->assertDontSee('id="pms-ndt-form"', false)->assertDontSee('<script>ndtBad()', false)->assertSee('&lt;script&gt;ndtBad()', false);
            $this->actingAs($this->a)->get($url.'?lang='.$lang.'&ndt='.$record->id)->assertOk()->assertSee('id="pms-ndt-form"', false)->assertDontSee('<script>ndtBad()', false);
        }
        $this->actingAs($this->a)->from($url)->post($url.'/ndt?lang=en', ['ndt' => $this->ndt(['version' => 2, 'target' => $class->id.':new', 'method' => ['bad']])])->assertRedirect($url)->assertSessionHasErrors('ndt.method');
        $this->get($url.'?lang=en')->assertOk()->assertSee('Enter valid UTF-8 text');
        self::assertCount(1, $this->records($class));
    }

    public function test_unauthenticated_and_revoked_author_requests_do_not_write(): void
    {
        $class = $this->create();
        $url = '/projects/PH-14/piping/pms/'.$class->id.'/ndt';
        $this->post($url, ['ndt' => $this->ndt()])->assertRedirect();
        self::assertSame([], $this->records($class));
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'suspend' => true, 'reason' => 'Suspend']);
        $this->actingAs($this->a)->post($url, ['ndt' => $this->ndt()])->assertForbidden();
        self::assertCount(1, $this->events());
    }

    public function test_nominated_other_company_cannot_write_and_foreign_requirement_cannot_be_moved(): void
    {
        $class = $this->create();
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt());
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'selectors' => ['user:'.$this->a->id, 'user:'.$this->o->id], 'suspend' => false, 'reason' => 'Both nominated']);
        $url = '/projects/PH-14/piping/pms/'.$class->id;
        $this->actingAs($this->o)->post($url.'/ndt', ['ndt' => $this->ndt(['id' => $record->id, 'version' => 2])])->assertForbidden();
        $other = $this->svc()->saveHeader($this->p, $this->a, $this->h(['code' => 'OTHER']));
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$other->id.'/ndt', ['ndt' => $this->ndt(['id' => $record->id])])->assertNotFound();
        $this->get('/projects/PH-14/piping/pms/'.$other->id.'?ndt='.$record->id)->assertNotFound();
        self::assertSame([], $this->records($other));
        self::assertSame('10%', $this->records($class)[0]['extent']);
    }

    public function test_stale_update_refused_and_ndt_change_invalidates_bulk_preview(): void
    {
        $class = $this->create();
        $bulk = app(PmsBulkEntryService::class);
        $preview = $bulk->preview($this->p, $this->a, $class->id, $this->row(), ['2']);
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt());
        $this->refuse(fn () => $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt(['id' => $record->id, 'extent' => '100%'])));
        $this->refuse(fn () => $bulk->confirm($this->p, $this->a, $class->id, $preview['token']));
        self::assertSame('10%', $this->records($class)[0]['extent']);
        self::assertCount(2, $this->events());
        self::assertSame(0, $this->bound(fn () => PipingClassItem::where('piping_class_id', $class->id)->count()));
    }

    private function sqlRefused(callable $fn): void
    {
        try {
            $this->bound(fn () => DB::transaction($fn));
            self::fail('Expected SQL refusal');
        } catch (QueryException $e) {
            self::assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_sql_parent_ownership_and_archive_guards_preserve_reads_and_reopening(): void
    {
        $class = $this->create();
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt());
        $other = $this->bound(fn () => PipingClass::create(['project_id' => $this->p->id, 'company_id' => $this->o->company_id, 'code' => 'OWN-OTHER']), $this->o);
        $this->sqlRefused(fn () => DB::table('piping_class_ndt_requirements')->where('id', $record->id)->update(['piping_class_id' => $other->id]));
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->p, $this->o, 'Synthetic archive'), $this->o);
        $this->sqlRefused(fn () => DB::table('piping_class_ndt_requirements')->where('id', $record->id)->update(['extent' => '100%']));
        $this->sqlRefused(fn () => DB::table('piping_class_ndt_requirements')->insert(array_replace($record->getAttributes(), ['id' => (string) Str::uuid()])));
        self::assertSame(0, $this->bound(fn () => DB::table('piping_class_ndt_requirements')->where('id', $record->id)->delete()));
        self::assertSame('10%', $this->records($class)[0]['extent']);
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/ndt', ['ndt' => $this->ndt(['version' => 2])])->assertRedirect()->assertSessionHasErrors('project');
        $this->bound(fn () => app(ProjectArchiveService::class)->reopen($this->p->fresh(), $this->o), $this->o);
        self::assertSame(1, $this->bound(fn () => DB::table('piping_class_ndt_requirements')->where('id', $record->id)->update(['extent' => '20%'])));
        self::assertSame(0, DB::table('piping_class_ndt_requirements')->count());
    }

    public function test_late_audit_failure_rolls_back_requirement_and_class_version(): void
    {
        $class = $this->create();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pg_temp.refuse_ndt_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.reason='synthetic late audit failure' THEN RAISE EXCEPTION 'synthetic audit failure'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER test_ndt_audit BEFORE INSERT ON pms_entry_events
FOR EACH ROW EXECUTE FUNCTION pg_temp.refuse_ndt_audit();
SQL);
        try {
            $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt(['reason' => 'synthetic late audit failure']));
            self::fail('Expected late failure');
        } catch (QueryException $e) {
            self::assertSame('P0001', $e->errorInfo[0]);
        }
        self::assertSame([], $this->records($class));
        self::assertSame(1, $this->bound(fn () => $class->fresh()->entry_version));
        self::assertCount(1, $this->events());
    }

    public function test_one_hundred_requirement_bound_allows_edit_but_refuses_another_creation(): void
    {
        $class = $this->create();
        $record = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt());
        $data = $record->getAttributes();
        $this->bound(function () use ($data): void {
            $rows = [];
            for ($i = 0; $i < 99; $i++) {
                $rows[] = array_replace($data, ['id' => (string) Str::uuid()]);
            }
            DB::table('piping_class_ndt_requirements')->insert($rows);
        });
        $this->refuse(fn () => $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt(['version' => 2])));
        $saved = $this->svc()->saveNdt($this->p, $this->a, $class->id, $this->ndt(['version' => 2, 'id' => $record->id, 'extent' => '25%']));
        self::assertSame('25%', $saved->extent);
        self::assertCount(100, $this->records($class));
        self::assertCount(3, $this->events());
    }
}
