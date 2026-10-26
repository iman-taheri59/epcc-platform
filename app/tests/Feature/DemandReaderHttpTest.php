<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\Company;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\DemandReaderService;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class DemandReaderHttpTest extends TestCase
{
    private User $owner;

    private User $reader;

    private string $scope;

    private AcceptedMtoBatch $batch;

    private MtoStagingService $staging;

    private MtoAcceptanceService $acceptance;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);

        $this->staging = app(MtoStagingService::class);
        $this->acceptance = app(MtoAcceptanceService::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->reader = $this->userByEmail('rezaei@pars.example');
        $this->scope = $this->asPars(fn () => Subsystem::where('project_id', $this->project()->id)->value('id'));
        $doc = $this->document();
        $revision = $this->asPars(fn () => $this->staging->createRevision($doc, $this->reader, 'A', [$this->row(['row_scope_id' => $this->scope])], hierarchyRevisionId: 'H1', lang: 'en'));
        $this->batch = $this->acceptObserved($revision);
    }

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', 'PH-14')->firstOrFail()
        );
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    private function document(): MtoDocument
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        return $this->tenant->runAs($pars->id, fn () => app(MtoStagingService::class)->createDocument(
            $project,
            $rezaei,
            'PH-14-PIP-MTO-'.fake()->unique()->numerify('####'),
            'Piping bulk MTO',
            'MTO حجمی پایپینگ',
            'PIPING',
            MtoDocument::SOURCE_MANUAL,
            lang: 'en'
        ), $project->id);
    }

    /** @param array<string,mixed> $override */
    private function row(array $override = []): array
    {
        return $override + [
            'mto_item_no' => '10',
            'commodity_code' => 'PIP-CS-6-SCH40',
            'raw_description' => '6" CS pipe, SCH 40, API 5L X60 PSL2',
            'raw_quantity' => 120,
            'raw_unit' => 'M',
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function draftRevision(array $rows): MtoRevision
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $document = $this->document();

        return $this->tenant->runAs($pars->id, fn () => $this->staging->createRevision(
            $document,
            $rezaei,
            'A',
            $rows,
            revisionDate: '2026-10-06',
            lang: 'en'
        ), $project->id);
    }

    private function asPars(callable $fn): mixed
    {
        return $this->tenant->runAs($this->company('EPC-PARS')->id, $fn, $this->project()->id);
    }

    private function assertSqlState(string $state, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected SQLSTATE '.$state);
        } catch (\PDOException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
        }
    }

    private function acceptObserved(MtoRevision $revision): AcceptedMtoBatch
    {
        // Commit is outside this callback. Observation is intentionally deferred.
        return $this->asPars(fn () => $this->acceptance->accept($revision, 'en'));
    }

    private function input(array $changes = []): array
    {
        return array_replace(['target_company_id' => $this->reader->company_id, 'document_id' => $this->batch->mto_document_id, 'scope_id' => $this->scope, 'hierarchy_revision_id' => 'H1', 'version' => 0, 'suspended' => false, 'selectors' => ['user:'.$this->reader->id], 'reason' => 'Explicit synthetic reader'], $changes);
    }

    private function configure(array $changes = [], ?User $actor = null): string
    {
        return app(DemandReaderService::class)->configure($this->project(), $actor ?? $this->owner, $this->input($changes));
    }

    private function assess(?User $actor = null, ?string $batch = null, ?string $document = null, ?string $scope = null, string $hierarchy = 'H1'): array
    {
        return app(DemandReaderService::class)->assess($this->project(), $actor ?? $this->reader, $batch ?? $this->batch->id, $document ?? $this->batch->mto_document_id, $scope ?? $this->scope, $hierarchy);
    }

    private function denied(callable $f): void
    {
        try {
            $f();
            $this->fail('Expected deny');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function url(array $extra = []): string
    {
        return route('materials.demand-readers', ['projectCode' => 'PH-14'] + $extra + ['lang' => 'en']);
    }

    private function selection(): array
    {
        return ['target_company_id' => $this->reader->company_id, 'batch_id' => $this->batch->id, 'document_id' => $this->batch->mto_document_id, 'scope_id' => $this->scope, 'hierarchy_revision_id' => 'H1'];
    }

    private function readyUrl(array $extra = []): string
    {
        return route('materials.demand-readiness', ['projectCode' => 'PH-14'] + $extra + ['lang' => 'en'] + $this->selection());
    }

    public function test_guests_redirect_and_nonowner_cannot_view_or_write(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->post($this->url(), [])->assertRedirect('/login');
        $this->actingAs($this->reader)->get($this->url())->assertForbidden();
        $this->postJson($this->url(), ['policy' => $this->input()])->assertForbidden();
    }

    public function test_owner_picker_and_selected_form_private_bilingual(): void
    {
        foreach (['en', 'fa'] as $lang) {
            $res = $this->actingAs($this->owner)->get($this->url(['lang' => $lang] + $this->selection()))->assertOk()->assertSee('id="demand-reader-policy-form"', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            if (getenv('DEMAND_UI_EVIDENCE') === '1') {
                file_put_contents(base_path('../docs/evidence/2026-10-23-demand-reader-ui/http-config-'.$lang.'.html'), preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $res->getContent()));
            }
        }
    }

    public function test_configuration_post_and_nominated_readiness_not_capacity(): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['policy' => $this->input()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get($this->readyUrl())->assertOk()->assertSee('Unknown / not assessed')->assertSee('not zero')->assertDontSee('demand-reader-policy-form')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->actingAs($this->owner)->get($this->readyUrl())->assertForbidden();
    }

    public function test_suspend_without_checkbox_group_is_explicit_empty(): void
    {
        $this->configure();
        $d = $this->input(['version' => 1, 'suspended' => true]);
        unset($d['selectors']);
        $this->actingAs($this->owner)->post($this->url(), ['policy' => $d])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get($this->readyUrl())->assertForbidden();
    }

    public function test_stale_version_not_silently_rebased_and_unchecked_nominees_stay_empty(): void
    {
        $this->configure();
        $selected = $this->url($this->selection());
        $d = $this->input();
        unset($d['selectors']);
        $this->actingAs($this->owner)->from($selected)->post($this->url(), ['policy' => $d])->assertRedirect($selected)->assertSessionHasErrors('version');
        $res = $this->get($selected)->assertOk();
        $html = $res->getContent();
        $this->assertMatchesRegularExpression('/name="policy\[version\]" value="0"/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="policy\[selectors\]\[\]"[^>]*checked/', $html);
    }

    public function test_nested_malformed_old_input_is_type_safe(): void
    {
        $url = $this->url($this->selection());
        $d = $this->input(['version' => ['bad'], 'selectors' => 'not-array', 'reason' => ['bad']]);
        $this->actingAs($this->owner)->from($url)->post($this->url(), ['policy' => $d])->assertRedirect($url)->assertSessionHasErrors();
        $this->get($url)->assertOk();
    }

    public function test_picker_hidden_source_not_exposed_and_forged_selection_denied(): void
    {
        DB::unprepared('CREATE POLICY demand_test_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->actingAs($this->owner)->get($this->url())->assertOk()->assertDontSee($this->batch->id)->assertSee('not proof of no project demand');
            $this->get($this->url($this->selection()))->assertForbidden();
            $this->postJson($this->url(), ['policy' => $this->input()])->assertForbidden();
        } finally {
            DB::unprepared('DROP POLICY demand_test_hide ON accepted_mto_rows');
        }
    }

    public function test_unknown_batch_returns_generic_unavailable_without_source_metadata(): void
    {
        $this->configure();
        $this->actingAs($this->reader)->get($this->readyUrl(['batch_id' => (string) Str::uuid()]))->assertOk()->assertSee('Source unavailable')->assertDontSee($this->batch->id);
    }

    public function test_archive_history_readable_form_absent_and_post_blocked(): void
    {
        $this->configure();
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'HTTP synthetic archive');
        $this->actingAs($this->owner)->get($this->url($this->selection()))->assertOk()->assertSee('Archived project')->assertDontSee('id="demand-reader-policy-form"', false);
        $this->postJson($this->url(), ['policy' => $this->input(['version' => 1])])->assertUnprocessable();
        $this->actingAs($this->reader)->get($this->readyUrl())->assertOk();
    }

    public function test_history_reason_and_nominee_labels_are_escaped(): void
    {
        $this->configure(['reason' => '<script>history-secret()</script>']);
        $this->asPars(fn () => DB::table('users')->where('id', $this->reader->id)->update(['name' => '<script>nominee-secret()</script>']));
        $this->actingAs($this->owner)->get($this->url($this->selection()))->assertOk()->assertDontSee('<script>history-secret()</script>', false)->assertSee('&lt;script&gt;history-secret()', false)->assertDontSee('<script>nominee-secret()</script>', false)->assertSee('&lt;script&gt;nominee-secret()', false);
    }

    public function test_readiness_bilingual_and_no_mutation_route(): void
    {
        $this->configure();
        foreach (['en', 'fa'] as $lang) {
            $res = $this->actingAs($this->reader)->get($this->readyUrl(['lang' => $lang]))->assertOk();
            if (getenv('DEMAND_UI_EVIDENCE') === '1') {
                file_put_contents(base_path('../docs/evidence/2026-10-23-demand-reader-ui/http-readiness-'.$lang.'.html'), preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $res->getContent()));
            }
        }
        $this->postJson($this->readyUrl(), [])->assertStatus(405);
    }

    public function test_malformed_get_tuple_has_validation_error_and_foreign_company_is_denied(): void
    {
        $this->actingAs($this->owner)->getJson($this->url(['batch_id' => 'bad']))->assertUnprocessable();
        $this->getJson($this->url(['target_company_id' => (string) Str::uuid()]))->assertForbidden();
        $this->actingAs($this->reader)->getJson($this->readyUrl(['scope_id' => 'bad']))->assertUnprocessable();
    }
}
