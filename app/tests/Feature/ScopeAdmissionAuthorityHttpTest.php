<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\Company;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\DemandReaderService;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Services\ProjectArchiveService;
use App\Services\ScopeAdmissionAuthorityService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class ScopeAdmissionAuthorityHttpTest extends TestCase
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
        return route('materials.scope-admission-actors', ['projectCode' => 'PH-14'] + $extra + ['lang' => 'en']);
    }

    private function selection(): array
    {
        return ['target_company_id' => $this->reader->company_id, 'batch_id' => $this->batch->id, 'document_id' => $this->batch->mto_document_id, 'scope_id' => $this->scope, 'hierarchy_revision_id' => 'H1'];
    }

    private function readyUrl(array $extra = []): string
    {
        return route('materials.scope-admission-diagnostics', ['projectCode' => 'PH-14'] + $extra + ['lang' => 'en'] + $this->selection());
    }

    private function policy(array $changes = []): array
    {
        return $this->input($changes) + ['batch_id' => $this->batch->id, 'action' => 'propose'];
    }

    private function nominate(array $changes = []): string
    {
        return app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->policy($changes));
    }

    private function suspendUrl(): string
    {
        return route('materials.scope-admission-suspensions', ['projectCode' => 'PH-14', 'lang' => 'en']);
    }

    private function countPolicies(): int
    {
        return $this->tenant->runAs($this->owner->company_id, fn () => DB::table('scope_admission_actor_policies')->count(), $this->project()->id);
    }

    private function capture(string $name, $res): void
    {
        if (getenv('ADMISSION_WEB_EVIDENCE') === '1') {
            file_put_contents(base_path('../docs/evidence/2026-10-24-admission-authority-web/'.$name.'.html'), preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $res->getContent()));
        }
    }

    public function test_guests_redirect_and_nonowner_cannot_configure_or_suspend(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->post($this->url(), [])->assertRedirect('/login');
        $this->get($this->suspendUrl())->assertRedirect('/login');
        $this->post($this->suspendUrl(), [])->assertRedirect('/login');
        $this->get($this->readyUrl())->assertRedirect('/login');
        $this->actingAs($this->reader)->get($this->url())->assertForbidden();
        $this->postJson($this->url(), ['policy' => $this->policy()])->assertForbidden();
        $this->get($this->suspendUrl())->assertForbidden();
        $this->postJson($this->suspendUrl(), ['suspension' => []])->assertForbidden();
    }

    public function test_owner_form_bilingual_action_specific_private_and_no_reader_history(): void
    {
        $this->configure(['reason' => 'READER-HISTORY-NOT-ADMISSION']);
        $id = $this->nominate(['reason' => 'VISIBLE-ACTION-HISTORY']);
        foreach (['en', 'fa'] as $lang) {
            $res = $this->actingAs($this->owner)->get($this->url(['lang' => $lang] + $this->selection()))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertSee('id="scope-admission-policy-form"', false)->assertSee('name="policy[batch_id]"', false)->assertSee($id)->assertSee('VISIBLE-ACTION-HISTORY')->assertDontSee('READER-HISTORY-NOT-ADMISSION')->assertSee('<html lang="'.$lang.'" dir="'.($lang === 'fa' ? 'rtl' : 'ltr').'">', false);
            $this->capture('config-'.$lang, $res);
        }
        $this->get($this->url(['action' => 'review'] + $this->selection()))->assertOk()->assertDontSee('VISIBLE-ACTION-HISTORY')->assertSee('name="policy[action]" value="review"', false);
    }

    public function test_post_writes_only_action_nomination_and_independent_reader_required(): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['policy' => $this->policy()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->countPolicies());
        $this->actingAs($this->reader)->get($this->readyUrl())->assertForbidden();
        $this->configure();
        $res = $this->get($this->readyUrl())->assertOk()->assertSee('data-action="propose"', false)->assertSee('data-nominated="yes"', false)->assertSee('data-package-verified="false"', false)->assertSee('data-operationally-authorized="false"', false)->assertSee('not zero');
        $this->actingAs($this->owner)->get($this->readyUrl())->assertForbidden();
    }

    public function test_reader_has_no_default_action_and_diagnostics_private_bilingual(): void
    {
        $this->configure();
        foreach (['en', 'fa'] as $lang) {
            $res = $this->actingAs($this->reader)->get($this->readyUrl(['lang' => $lang]))->assertOk()->assertDontSee('data-nominated="yes"', false)->assertDontSee('scope-admission-policy-form')->assertSee('data-ready-to-admit="false"', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertSee('<html lang="'.$lang.'"', false);
            $this->capture('diagnostics-'.$lang, $res);
        }
    }

    public function test_diagnostics_rejects_client_participant_or_approval_action_claims(): void
    {
        $this->configure();
        $this->nominate();
        foreach (['proposer_id', 'reviewer_id', 'action'] as $key) {
            $this->actingAs($this->reader)->getJson($this->readyUrl([$key => $key === 'action' ? 'record' : $this->owner->id]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }$this->assertSame(1, $this->countPolicies());
    }

    public function test_action_grants_and_history_do_not_bleed_between_actions(): void
    {
        $this->configure();
        $this->nominate(['action' => 'review', 'reason' => 'REVIEW-ONLY-HISTORY']);
        $this->actingAs($this->owner)->get($this->url(['action' => 'record'] + $this->selection()))->assertOk()->assertDontSee('REVIEW-ONLY-HISTORY');
        $res = $this->actingAs($this->reader)->get($this->readyUrl())->assertOk();
        $a = $res->viewData('assessment');
        $this->assertSame(['propose' => false, 'review' => true, 'record' => false], $a['actions']);
        $this->assertFalse($a['ready_to_admit']);
    }

    public function test_hidden_source_config_denied_and_diagnostics_unavailable_without_policy_leak(): void
    {
        $this->configure();
        $this->nominate(['reason' => 'HIDDEN-ACTION-REASON']);
        DB::unprepared('CREATE POLICY web_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->actingAs($this->owner)->get($this->url($this->selection()))->assertForbidden();
            $this->get($this->url())->assertOk()->assertDontSee($this->batch->id)->assertDontSee('HIDDEN-ACTION-REASON');
            $this->actingAs($this->reader)->get($this->readyUrl())->assertOk()->assertSee('UNAVAILABLE')->assertDontSee('data-nominated="yes"', false)->assertDontSee('HIDDEN-ACTION-REASON');
        } finally {
            DB::unprepared('DROP POLICY web_hide ON accepted_mto_rows');
        }
    }

    public function test_recorded_suspension_hidden_source_metadata_only_and_no_revival(): void
    {
        $this->configure();
        $id = $this->nominate(['reason' => 'PRIVATE-OLD-REASON']);
        DB::unprepared('CREATE POLICY web_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            DB::enableQueryLog();
            $res = $this->actingAs($this->owner)->get($this->suspendUrl())->assertOk()->assertSee($id)->assertDontSee($this->batch->id)->assertDontSee($this->scope)->assertDontSee($this->batch->mto_document_id)->assertDontSee('PRIVATE-OLD-REASON')->assertDontSee('user:'.$this->reader->id)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();
            $this->capture('hidden-suspensions-en', $res);
            $sql = strtolower(implode(' ', array_column($queries, 'query')));
            foreach (['accepted_mto_rows', 'accepted_mto_batches', 'mto_documents', 'mto_scope_observations'] as $t) {
                $this->assertStringNotContainsString($t, $sql);
            }
            $payload = ['suspension' => ['policy_id' => $id, 'version' => 1, 'reason' => 'Reduce hidden source action']];
            $this->post($this->suspendUrl(), $payload)->assertRedirect()->assertSessionHasNoErrors();
            $this->postJson($this->suspendUrl(), $payload)->assertUnprocessable();
            $this->postJson($this->url(), ['policy' => $this->policy(['version' => 2])])->assertForbidden();
        } finally {
            DB::disableQueryLog();
            DB::unprepared('DROP POLICY web_hide ON accepted_mto_rows');
        }
        $this->actingAs($this->reader)->get($this->readyUrl())->assertOk()->assertDontSee('data-nominated="yes"', false);
        $this->assertSame(2, $this->countPolicies());
    }

    public function test_reduction_endpoint_cannot_inject_active_state_or_action(): void
    {
        $id = $this->nominate();
        foreach ([['selectors' => ['user:'.$this->reader->id]], ['suspended' => false], ['action' => 'record'], ['document_id' => $this->batch->mto_document_id]] as $extra) {
            $this->actingAs($this->owner)->postJson($this->suspendUrl(), ['suspension' => ['policy_id' => $id, 'version' => 1, 'reason' => 'No grant injection'] + $extra])->assertUnprocessable();
        }$this->assertSame(1, $this->countPolicies());
        $this->postJson($this->suspendUrl(), ['suspension' => ['policy_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Unknown']])->assertNotFound();
    }

    public function test_stale_submission_and_unchecked_selectors_do_not_reuse_old_grant(): void
    {
        $this->nominate();
        $this->actingAs($this->owner)->postJson($this->url(), ['policy' => $this->policy()])->assertUnprocessable();
        $d = $this->policy(['version' => 1]);
        unset($d['selectors']);
        $this->postJson($this->url(), ['policy' => $d])->assertUnprocessable();
        $this->postJson($this->url(), ['policy' => $this->policy(['version' => 1, 'selectors' => ['named' => 'user:'.$this->reader->id]])])->assertUnprocessable();
        $this->assertSame(1, $this->countPolicies());
    }

    public function test_unknown_source_and_foreign_selector_rejected_without_write(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), ['policy' => $this->policy(['batch_id' => (string) Str::uuid()])])->assertForbidden();
        $this->postJson($this->url(), ['policy' => $this->policy(['selectors' => ['user:'.$this->owner->id]])])->assertUnprocessable();
        $this->postJson($this->url(), ['policy' => $this->policy(['action' => 'approve_all'])])->assertUnprocessable();
        $this->assertSame(0, $this->countPolicies());
    }

    public function test_reader_revocation_blocks_diagnostics_even_action_still_live(): void
    {
        $this->configure();
        $this->nominate();
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        $this->actingAs($this->reader)->get($this->readyUrl())->assertForbidden();
    }

    public function test_archive_has_readonly_ui_and_cannot_configure_or_suspend(): void
    {
        $this->configure();
        $id = $this->nominate();
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Archive web fixture');
        $this->actingAs($this->owner)->get($this->url($this->selection()))->assertOk()->assertDontSee('id="scope-admission-policy-form"', false);
        $this->get($this->suspendUrl())->assertOk()->assertDontSee('name="suspension[policy_id]"', false);
        $this->postJson($this->url(), ['policy' => $this->policy(['version' => 1])])->assertUnprocessable();
        $this->postJson($this->suspendUrl(), ['suspension' => ['policy_id' => $id, 'version' => 1, 'reason' => 'Archived']])->assertUnprocessable();
        $res = $this->actingAs($this->reader)->get($this->readyUrl())->assertOk();
        $this->assertFalse($res->viewData('assessment')['writable']);
        $this->assertSame(1, $this->countPolicies());
    }

    public function test_history_html_escaped_and_malformed_old_input_safe(): void
    {
        $this->nominate(['reason' => '<script>unsafe()</script>']);
        $this->actingAs($this->owner)->get($this->url($this->selection()))->assertOk()->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()</script>', false);
        $this->withSession(['_old_input' => ['policy' => ['action' => 'propose'] + $this->selection() + ['selectors' => 'not-array', 'version' => ['bad'], 'reason' => ['bad'], 'suspended' => ['bad']]]])->get($this->url($this->selection()))->assertOk();
    }

    public function test_diagnostics_reads_no_source_payload_or_cde_stock_or_mutation(): void
    {
        $this->configure();
        $this->nominate();
        DB::enableQueryLog();
        $this->actingAs($this->reader)->get($this->readyUrl())->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode(' ', array_column($queries, 'query')));
        foreach (['insert into', 'delete from', 'update "', 'raw_quantity', 'raw_description', '"document_files"', '"material_movements"', '"material_lots"', '"mto_scope_observations"'] as $t) {
            $this->assertStringNotContainsString($t, $sql);
        }
    }

    public function test_target_exit_does_not_block_owner_recorded_reduction(): void
    {
        $id = $this->nominate();
        DB::table('project_company')->where('project_id', $this->project()->id)->where('company_id', $this->reader->company_id)->delete();
        $this->actingAs($this->owner)->get($this->suspendUrl())->assertOk()->assertSee($id);
        $this->post($this->suspendUrl(), ['suspension' => ['policy_id' => $id, 'version' => 1, 'reason' => 'Exited participant reduction']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $this->countPolicies());
    }

    public function test_existing_reader_policy_cannot_be_used_as_admission_policy_id(): void
    {
        $id = $this->configure();
        $this->actingAs($this->owner)->postJson($this->suspendUrl(), ['suspension' => ['policy_id' => $id, 'version' => 1, 'reason' => 'Wrong aggregate']])->assertNotFound();
        $this->assertSame(0, $this->countPolicies());
    }

    public function test_diagnostic_role_nomination_is_live_not_a_stored_user_expansion(): void
    {
        $this->configure();
        $role = $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->firstOrFail()->role_codes[0]);
        $this->nominate(['selectors' => ['role:'.$this->reader->company_id.':'.$role]]);
        $this->actingAs($this->reader)->get($this->readyUrl())->assertOk()->assertSee('data-nominated="yes"', false);
        $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->update(['role_codes' => []]));
        $this->get($this->readyUrl())->assertOk()->assertDontSee('data-nominated="yes"', false);
    }
}
