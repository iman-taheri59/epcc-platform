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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PmsSpecialRequirementsTest extends TestCase
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

    private function special(array $changes = []): array
    {
        return $this->h(array_replace(['special_requirements_mode' => 'replace', 'special_requirements_text' => 'NACE / IBR, per Spec'], $changes));
    }

    private function fresh(PipingClass $class): PipingClass
    {
        return $this->bound(fn () => $class->fresh());
    }

    public function test_http_transcription_preserves_line_edges_order_duplicates_and_unicode_without_adoption(): void
    {
        $before = $this->bound(fn () => [DB::table('project_standards')->count(), DB::table('standard_citations')->count()]);
        $text = "  NACE / IBR, per Spec  \r\n\r\n\tمطابق مشخصات پروژه\t\r  NACE / IBR, per Spec  \n\u{00A0}\n";
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms?lang=en', ['header' => $this->special(['special_requirements_text' => $text])])->assertRedirect()->assertSessionHasNoErrors();
        $class = $this->bound(fn () => PipingClass::where('code', 'QA-150')->firstOrFail());
        $expected = ['  NACE / IBR, per Spec  ', "\tمطابق مشخصات پروژه\t", '  NACE / IBR, per Spec  '];
        self::assertSame($expected, $class->special_requirements);
        self::assertSame($expected, json_decode($this->events()[0]->after_data, true)['special_requirements']);
        self::assertSame($this->a->id, $this->events()[0]->created_by);
        self::assertNotNull($this->events()[0]->author_policy_id);
        self::assertSame($before, $this->bound(fn () => [DB::table('project_standards')->count(), DB::table('standard_citations')->count()]));
    }

    public function test_omission_keep_and_explicit_clear_preserve_audit_and_do_not_claim_absence(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->special());
        $this->svc()->saveHeader($this->p, $this->a, $this->h(['version' => 1]), $class->id);
        self::assertSame(['NACE / IBR, per Spec'], $this->fresh($class)->special_requirements);
        $this->svc()->saveHeader($this->p, $this->a, $this->special(['version' => 2, 'special_requirements_mode' => 'keep', 'special_requirements_text' => ['ignored']]), $class->id);
        self::assertSame(['NACE / IBR, per Spec'], $this->fresh($class)->special_requirements);
        $this->svc()->saveHeader($this->p, $this->a, $this->special(['version' => 3, 'special_requirements_mode' => 'clear']), $class->id);
        self::assertNull($this->fresh($class)->special_requirements);
        $event = $this->events()[3];
        self::assertSame(['NACE / IBR, per Spec'], json_decode($event->before_data, true)['special_requirements']);
        self::assertNull(json_decode($event->after_data, true)['special_requirements']);
        $this->actingAs($this->o)->get('/projects/PH-14/piping/pms/'.$class->id.'?lang=en')->assertOk()->assertSee('This does not establish that no requirements apply.');
    }

    public function test_new_header_omission_defaults_to_not_entered(): void
    {
        self::assertNull($this->fresh($this->create())->special_requirements);
    }

    public function test_exact_entry_and_unicode_length_bounds_are_accepted(): void
    {
        $line = str_repeat('ژ', 1000);
        $text = implode("\r\n", array_fill(0, 50, $line))."\r\n";
        self::assertSame(50100, mb_strlen($text));
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->special(['special_requirements_text' => $text]));
        self::assertSame(array_fill(0, 50, $line), $this->fresh($class)->special_requirements);
    }

    public static function invalidInputs(): array
    {
        return [
            'too many entries' => [['special_requirements_text' => implode("\n", array_fill(0, 51, 'X'))], 'text'],
            'long Unicode line' => [['special_requirements_text' => str_repeat('ژ', 1001)], 'text'],
            'total bound including blank lines' => [['special_requirements_text' => "X\n".str_repeat("\n", 50099)], 'text'],
            'blank' => [['special_requirements_text' => " \t\r\n\u{00A0}"], 'text'],
            'array' => [['special_requirements_text' => ['NACE']], 'text'],
            'null' => [['special_requirements_text' => null], 'text'],
            'non UTF8' => [['special_requirements_text' => "A\xFF"], 'text'],
            'NUL' => [['special_requirements_text' => "A\0B"], 'text'],
            'C1 control' => [['special_requirements_text' => "A\u{009F}B"], 'text'],
            'unknown action' => [['special_requirements_mode' => 'merge'], 'mode'],
            'array action' => [['special_requirements_mode' => ['replace']], 'mode'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_replacement_rolls_back_all_header_changes_and_audit(array $input, string $field): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->special());
        $before = $this->fresh($class)->toArray();
        try {
            $this->svc()->saveHeader($this->p, $this->a, $this->special(array_replace(['version' => 1, 'pressure_rating' => '600', 'design_temp_max_c' => '300'], $input)), $class->id);
            self::fail('Invalid input was accepted');
        } catch (ValidationException $e) {
            self::assertSame([__('pms.'.($field === 'mode' ? 'special_mode_required' : 'special_invalid'))], $e->errors()['header.special_requirements_'.$field]);
        }
        self::assertSame($before, $this->fresh($class)->toArray());
        self::assertCount(1, $this->events());
    }

    public function test_http_missing_explicit_mode_is_rejected_and_bad_old_input_is_safe_to_render(): void
    {
        $class = $this->create();
        $url = '/projects/PH-14/piping/pms/'.$class->id;
        $this->actingAs($this->a)->from($url)->post($url.'/header?lang=en', ['header' => $this->h(['version' => 1, 'target' => $class->id, 'special_requirements_text' => ['unexpected']])])
            ->assertRedirect($url)->assertSessionHasErrors('header.special_requirements_mode');
        $this->get($url.'?lang=en')->assertOk()->assertSee('Choose Keep, Replace or Clear explicitly');
        self::assertNull($this->fresh($class)->special_requirements);
        self::assertCount(1, $this->events());
    }

    public function test_legacy_shapes_survive_keep_and_render_without_auto_conversion(): void
    {
        $class = $this->create();
        $version = 1;
        foreach ([['old' => '<script>legacy()</script>'], 'scalar legacy', [null, ['nested' => true]], false, 7] as $legacy) {
            $this->bound(fn () => $class->fresh()->update(['special_requirements' => $legacy]));
            $this->svc()->saveHeader($this->p, $this->a, $this->h(['version' => $version++, 'special_requirements_mode' => 'keep']), $class->id);
            self::assertSame($legacy, $this->fresh($class)->special_requirements);
            $this->actingAs($this->a)->get('/projects/PH-14/piping/pms/'.$class->id.'?lang=en')->assertOk()->assertSee('not a plain list')->assertDontSee('<script>legacy()</script>', false);
        }
        $this->svc()->saveHeader($this->p, $this->a, $this->special(['version' => $version]), $class->id);
        self::assertSame(['NACE / IBR, per Spec'], $this->fresh($class)->special_requirements);
        self::assertSame(7, json_decode($this->events()[6]->before_data, true)['special_requirements']);
    }

    public function test_bilingual_reader_summary_escapes_script_and_textarea_breakout_without_edit_controls(): void
    {
        $evil = '</textarea><script>specialBad()</script>';
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->special(['special_requirements_text' => $evil]));
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $this->actingAs($this->o)->get('/projects/PH-14/piping/pms/'.$class->id.'?lang='.$lang)->assertOk()->assertSee('dir="'.$dir.'"', false)
                ->assertSee('id="pms-special-summary"', false)->assertDontSee('id="pms-special-editor"', false)
                ->assertSee('&lt;script&gt;specialBad()', false)->assertDontSee($evil, false);
            $this->actingAs($this->a)->get('/projects/PH-14/piping/pms/'.$class->id.'?lang='.$lang)->assertOk()->assertSee('id="pms-special-editor"', false)->assertDontSee($evil, false);
        }
    }

    public function test_nominated_other_company_cannot_modify_requirements(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->special());
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'selectors' => ['user:'.$this->a->id, 'user:'.$this->o->id], 'suspend' => false, 'reason' => 'Nominate two companies, not shared custody']);
        $this->actingAs($this->o)->post('/projects/PH-14/piping/pms/'.$class->id.'/header', ['header' => $this->special(['version' => 1, 'special_requirements_mode' => 'clear'])])->assertForbidden();
        self::assertSame(['NACE / IBR, per Spec'], $this->fresh($class)->special_requirements);
        self::assertCount(1, $this->events());
    }

    public function test_revoked_author_cannot_replace_requirements(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->special());
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'suspend' => true, 'reason' => 'Suspend entry']);
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/header', ['header' => $this->special(['version' => 1, 'special_requirements_text' => 'Changed'])])->assertForbidden();
        self::assertSame(['NACE / IBR, per Spec'], $this->fresh($class)->special_requirements);
        self::assertCount(1, $this->events());
    }

    public function test_archived_project_refuses_requirement_changes(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->special());
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->p, $this->o, 'Synthetic archive'), $this->o);
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/header', ['header' => $this->special(['version' => 1, 'special_requirements_mode' => 'clear'])])->assertRedirect()->assertSessionHasErrors('project');
        self::assertSame(['NACE / IBR, per Spec'], $this->fresh($class)->special_requirements);
        self::assertCount(1, $this->events());
    }

    public function test_stale_version_cannot_replace_requirements_and_header_change_invalidates_bulk_preview(): void
    {
        $class = $this->create();
        $bulk = app(PmsBulkEntryService::class);
        $preview = $bulk->preview($this->p, $this->a, $class->id, $this->row(), ['2']);
        $this->refuse(fn () => $this->svc()->saveHeader($this->p, $this->a, $this->special(['version' => 0]), $class->id));
        self::assertNull($this->fresh($class)->special_requirements);
        $this->svc()->saveHeader($this->p, $this->a, $this->special(['version' => 1]), $class->id);
        $this->refuse(fn () => $bulk->confirm($this->p, $this->a, $class->id, $preview['token']));
        self::assertSame(0, $this->bound(fn () => PipingClassItem::where('piping_class_id', $class->id)->count()));
        self::assertCount(2, $this->events());
    }
}
