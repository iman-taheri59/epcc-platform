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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class DemandReaderSuspensionTest extends TestCase
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

    private function revokeUrl(array $extra = []): string
    {
        return route('materials.demand-reader-suspensions', ['projectCode' => 'PH-14'] + $extra + ['lang' => 'en']);
    }

    private function hide(): void
    {
        DB::unprepared('CREATE POLICY suspension_test_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
    }

    private function unhide(): void
    {
        DB::unprepared('DROP POLICY IF EXISTS suspension_test_hide ON accepted_mto_rows');
    }

    private function payload(string $id, int $version = 1): array
    {
        return ['policy_id' => $id, 'version' => $version, 'reason' => 'Reduce recorded access without source content'];
    }

    private function latest(): object
    {
        return $this->tenant->runAs($this->owner->company_id, fn () => DB::table('demand_reader_policies')->orderByDesc('version')->first(), $this->project()->id);
    }

    public function test_hidden_source_can_be_suspended_and_does_not_revive_after_unhide(): void
    {
        $id = $this->configure();
        $this->hide();
        try {
            $new = app(DemandReaderService::class)->suspendRecorded($this->project(), $this->owner, $this->payload($id));
            $this->assertNotSame($id, $new);
            $this->assertTrue($this->latest()->suspended);
            $this->assertSame('[]', $this->latest()->selectors);
        } finally {
            $this->unhide();
        }
        $this->actingAs($this->reader)->get($this->readyUrl())->assertForbidden();
    }

    public function test_register_and_post_do_not_query_or_expose_source(): void
    {
        $id = $this->configure();
        $this->hide();
        try {
            DB::enableQueryLog();
            $res = $this->actingAs($this->owner)->get($this->revokeUrl())->assertOk()->assertSee($id)->assertDontSee($this->batch->mto_document_id)->assertDontSee($this->scope)->assertDontSee($this->batch->id)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $this->post($this->revokeUrl(), ['suspension' => $this->payload($id)])->assertRedirect()->assertSessionHasNoErrors();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();
            $sql = strtolower(implode("\n", array_column($queries, 'query')));
            foreach (['"accepted_mto_rows"', '"accepted_mto_batches"', '"mto_documents"', '"document_files"', '"material_items"', '"material_lots"'] as $token) {
                $this->assertStringNotContainsString($token, $sql);
            }
        } finally {
            $this->unhide();
        }
    }

    public function test_guest_nonowner_and_other_project_cannot_suspend(): void
    {
        $id = $this->configure();
        $this->get($this->revokeUrl())->assertRedirect('/login');
        $this->post($this->revokeUrl(), ['suspension' => $this->payload($id)])->assertRedirect('/login');
        $this->actingAs($this->reader)->get($this->revokeUrl())->assertForbidden();
        $this->postJson($this->revokeUrl(), ['suspension' => $this->payload($id)])->assertForbidden();
        $this->actingAs($this->owner)->postJson(str_replace('PH-14', 'BB-2', $this->revokeUrl()), ['suspension' => $this->payload($id)])->assertForbidden();
    }

    public function test_unknown_policy_not_a_new_grant(): void
    {
        $this->actingAs($this->owner)->postJson($this->revokeUrl(), ['suspension' => $this->payload((string) Str::uuid())])->assertNotFound();
    }

    public function test_stale_successor_and_repeat_are_conflicts_not_rebased(): void
    {
        $id = $this->configure();
        $this->configure(['version' => 1]);
        $this->actingAs($this->owner)->postJson($this->revokeUrl(), ['suspension' => $this->payload($id)])->assertUnprocessable();
        $current = $this->latest();
        $this->postJson($this->revokeUrl(), ['suspension' => $this->payload($current->id, 1)])->assertUnprocessable();
        $this->post($this->revokeUrl(), ['suspension' => $this->payload($current->id, 2)])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($this->revokeUrl(), ['suspension' => $this->payload($current->id, 2)])->assertUnprocessable();
        $this->assertSame(3, (int) $this->latest()->version);
    }

    public function test_reduction_payload_cannot_request_activation_selectors_or_different_source(): void
    {
        $id = $this->configure();
        foreach (['suspended' => false, 'selectors' => ['user:'.$this->reader->id], 'document_id' => (string) Str::uuid()] as $k => $v) {
            $d = $this->payload($id);
            $d[$k] = $v;
            $this->actingAs($this->owner)->postJson($this->revokeUrl(), ['suspension' => $d])->assertUnprocessable();
        }
        $this->assertSame(1, (int) $this->latest()->version);
    }

    public function test_participation_loss_of_target_does_not_prevent_reduction(): void
    {
        $id = $this->configure();
        DB::table('project_company')->where('project_id', $this->project()->id)->where('company_id', $this->reader->company_id)->delete();
        app(DemandReaderService::class)->suspendRecorded($this->project(), $this->owner, $this->payload($id));
        $this->assertTrue($this->latest()->suspended);
    }

    public function test_archive_readable_no_form_post_blocked(): void
    {
        $id = $this->configure();
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Archive suspension fixture');
        $this->actingAs($this->owner)->get($this->revokeUrl())->assertOk()->assertDontSee('name="suspension[policy_id]"', false);
        $this->postJson($this->revokeUrl(), ['suspension' => $this->payload($id)])->assertUnprocessable();
        $this->assertFalse($this->latest()->suspended);
    }

    public function test_no_reactivation_without_source_visibility(): void
    {
        $id = $this->configure();
        app(DemandReaderService::class)->suspendRecorded($this->project(), $this->owner, $this->payload($id));
        $this->hide();
        try {
            $this->actingAs($this->owner)->postJson($this->url(), ['policy' => $this->input(['version' => 2])])->assertForbidden();
        } finally {
            $this->unhide();
        }
        $this->assertTrue($this->latest()->suspended);
    }

    public function test_new_guard_still_denies_hidden_active_insert_and_fake_key(): void
    {
        $this->configure();
        $base = (array) $this->latest();
        $this->hide();
        try {
            foreach (['active', 'fake'] as $case) {
                $row = $base;
                $row['id'] = (string) Str::uuid();
                $row['version'] = 2;
                if ($case === 'fake') {
                    $row['scope_id'] = (string) Str::uuid();
                    $row['suspended'] = true;
                    $row['selectors'] = '[]';
                    $row['version'] = 1;
                }
                try {
                    $this->tenant->runAs($this->owner->company_id, fn () => DB::table('demand_reader_policies')->insert($row), $this->project()->id);
                    $this->fail('Expected SQL deny');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0]);
                }
            }
        } finally {
            $this->unhide();
        }
    }

    public function test_populated_upgrade_changes_guard_only_and_down_restores_old_rule(): void
    {
        $id = $this->configure();
        $guard = require database_path('migrations/2026_10_23_130000_allow_recorded_demand_reader_suspension.php');
        $guard->down();
        $this->hide();
        try {
            try {
                app(DemandReaderService::class)->suspendRecorded($this->project(), $this->owner, $this->payload($id));
                $this->fail('Old guard must deny');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
            $this->assertSame(1, (int) $this->latest()->version);
            $guard->up();
            app(DemandReaderService::class)->suspendRecorded($this->project(), $this->owner, $this->payload($id));
            $this->assertTrue($this->latest()->suspended);
        } finally {
            $guard->up();
            $this->unhide();
        }
    }

    public function test_bilingual_private_register_and_safe_invalid_reason(): void
    {
        $id = $this->configure();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->owner)->get($this->revokeUrl(['lang' => $lang]))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            if (getenv('SUSPENSION_EVIDENCE') === '1') {
                file_put_contents(base_path('../docs/evidence/2026-10-23-demand-reader-suspension/http-'.$lang.'.html'), preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $r->getContent()));
            }
        }
        $d = $this->payload($id);
        $d['reason'] = ['bad'];
        $this->from($this->revokeUrl())->post($this->revokeUrl(), ['suspension' => $d])->assertRedirect()->assertSessionHasErrors('reason');
        $this->get($this->revokeUrl())->assertOk();
    }
}
