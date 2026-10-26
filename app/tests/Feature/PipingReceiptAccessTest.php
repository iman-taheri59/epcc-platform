<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\PipingReceiptAccessService;
use App\Services\ProjectArchiveService;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PipingReceiptAccessTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private Project $project;

    private string $url = '/projects/PH-14/materials/receipt-access?lang=en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->qc = $this->userByEmail('tehrani@pmc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
    }

    private function scoped(User $actor, callable $f): mixed
    {
        return app(TenantContext::class)->runAs($actor->company_id, $f, $this->project->id);
    }

    private function rows()
    {
        return $this->scoped($this->owner, fn () => DB::table('piping_receipt_author_policies')->orderBy('version')->get());
    }

    private function payload(int $version = 0): array
    {
        return ['version' => $version, 'suspend' => false, 'reason' => 'Synthetic nomination <script>unsafe()</script>',
            'custodian_company_id' => $this->buyer->company_id,
            'actions' => ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id],
                'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]]];
    }

    private function save(array $payload): void
    {
        $this->actingAs($this->owner)->post($this->url, ['receipt_access' => $payload])->assertRedirect($this->url);
    }

    public function test_unconfigured_bilingual_owner_page_has_no_defaults_and_preserves_private_shell(): void
    {
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->owner)->get(str_replace('lang=en', 'lang='.$lang, $this->url))->assertOk()
                ->assertSee('piping-receipt-access-form')->assertSee('sb-burger')->assertSee('piping-receipt-access-status');
            $r->assertSee('lang="'.$lang.'"', false)->assertHeader('cache-control', 'max-age=0, no-store, private');
            $this->assertStringNotContainsString(' selected', $r->getContent());
            $this->assertSame(0, $this->rows()->count());
        }
        $this->actingAs($this->owner)->get('/projects/PH-14/materials?lang=en')->assertOk()->assertSee('piping-receipt-access-link');
        $this->actingAs($this->buyer)->get('/projects/PH-14/materials?lang=en')->assertOk()->assertDontSee('piping-receipt-access-link');
    }

    public function test_owner_stores_independent_company_bound_sets_and_no_commercial_or_stock_changes(): void
    {
        $snapshot = fn () => $this->scoped($this->buyer, function (): array {
            $out = [];
            foreach (['purchase_orders', 'purchase_order_items', 'material_lots', 'material_movements', 'material_inspections', 'material_requisitions'] as $table) {
                $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
        $before = $snapshot();
        $this->save($this->payload());
        $row = $this->rows()->sole();
        $this->assertSame(1, $row->version);
        $this->assertFalse($row->suspended);
        $this->assertSame($this->buyer->company_id, $row->custodian_company_id);
        $this->assertSame($this->owner->id, $row->created_by);
        $actions = json_decode($row->actions, true);
        $this->assertSame(PipingReceiptAccessService::ACTIONS, array_keys($this->payload()['actions']));
        foreach ($this->payload()['actions'] as $action => $set) {
            $this->assertSame($set[0], $actions[$action][0]['selector']);
            $this->assertArrayHasKey('company_id', $actions[$action][0]);
        }
        $this->assertEquals($before, $snapshot());
        $r = $this->actingAs($this->owner)->get($this->url)->assertOk()->assertSee('Synthetic nomination &lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()', false);
        if (getenv('EPCC_RECEIPT_ACCESS_EVIDENCE') === '1') {
            foreach (['en', 'fa'] as $lang) {
                $html = $this->get(str_replace('lang=en', 'lang='.$lang, $this->url))->getContent();
                $html = preg_replace('/<input[^>]*name="_token"[^>]*>/', '', $html);
                file_put_contents(base_path('../docs/evidence/2026-10-22-piping-receipt-access/configured-'.$lang.'.html'), $html);
            }
        }
    }

    public function test_company_role_selection_is_independent_and_stored_with_its_company(): void
    {
        $membership = $this->scoped($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->firstOrFail());
        $code = $membership->role_codes[0];
        $payload = $this->payload();
        $key = 'role:'.$this->buyer->company_id.':'.$code;
        $payload['actions']['post_grn'] = [$key];
        $this->save($payload);
        $entry = json_decode($this->rows()->sole()->actions, true)['post_grn'][0];
        $this->assertSame($key, $entry['selector']);
        $this->assertSame($this->buyer->company_id, $entry['company_id']);
    }

    public function test_replacement_stale_refusal_and_suspension_keep_immutable_history(): void
    {
        $this->save($this->payload());
        $first = $this->rows()->first();
        $this->actingAs($this->owner)->postJson($this->url, ['receipt_access' => $this->payload()])->assertUnprocessable();
        $payload = $this->payload(1);
        $payload['reason'] = '0';
        $this->save($payload);
        $this->save(['version' => 2, 'suspend' => true, 'reason' => 'Suspend without a default replacement']);
        $rows = $this->rows();
        $this->assertCount(3, $rows);
        $this->assertEquals($first, $rows->first());
        $this->assertSame('0', $rows[1]->reason);
        $this->assertTrue($rows[2]->suspended);
        $this->assertNull($rows[2]->custodian_company_id);
        $this->assertNull($rows[2]->custodian_snapshot);
        foreach (json_decode($rows[2]->actions, true) as $set) {
            $this->assertSame([], $set);
        }
        $this->scoped($this->owner, function () use ($first): void {
            $this->assertSame(0, DB::table('piping_receipt_author_policies')->where('id', $first->id)->update(['reason' => 'rewrite']));
            $this->assertSame(0, DB::table('piping_receipt_author_policies')->where('id', $first->id)->delete());
        });
        $this->assertEquals($first, $this->rows()->first());
    }

    public function test_only_owner_admin_reads_directory_or_writes_even_when_other_member_has_configure(): void
    {
        $roles = $this->scoped($this->owner, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->owner->id)->firstOrFail()->role_codes);
        $this->scoped($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['role_codes' => json_encode($roles)]));
        $this->assertTrue($this->scoped($this->buyer, fn () => app(AuthorizationPipeline::class)->allows($this->buyer->fresh(), 'project.configure', $this->project)));
        $this->actingAs($this->buyer)->get($this->url)->assertForbidden()->assertDontSee('piping-receipt-access-form');
        $this->postJson($this->url, ['receipt_access' => $this->payload()])->assertForbidden();
        $this->assertCount(0, $this->rows());
        $this->scoped($this->owner, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->owner->id)->update(['role_codes' => '[]']));
        $this->actingAs($this->owner)->get($this->url)->assertForbidden();
        $this->postJson($this->url, ['receipt_access' => $this->payload()])->assertForbidden();
    }

    public function test_malformed_missing_foreign_duplicate_and_server_owned_fields_are_rejected(): void
    {
        $cases = [];
        foreach (['created_by' => $this->buyer->id, 'company_id' => $this->buyer->company_id, 'actor_name' => 'forged', 'extra' => 'x'] as $key => $value) {
            $p = $this->payload();
            $p[$key] = $value;
            $cases[] = $p;
        }
        foreach ([[], ['user:'.Str::uuid()], ['user:'.$this->buyer->id, 'user:'.$this->buyer->id], [['nested' => 'x']]] as $set) {
            $p = $this->payload();
            $p['actions']['receiving_qc'] = $set;
            $cases[] = $p;
        }
        $p = $this->payload();
        $p['actions']['unknown'] = ['user:'.$this->buyer->id];
        $cases[] = $p;
        $p = $this->payload();
        $p['custodian_company_id'] = (string) Str::uuid();
        $cases[] = $p;
        $p = $this->payload();
        $p['actions'] = 'bad';
        $cases[] = $p;
        $p = $this->payload();
        $p['reason'] = '   ';
        $cases[] = $p;
        foreach ($cases as $input) {
            $this->actingAs($this->owner)->postJson($this->url, ['receipt_access' => $input])->assertUnprocessable();
            $this->assertCount(0, $this->rows());
        }
    }

    public function test_current_nominee_membership_discipline_role_and_active_identity_are_not_assumed(): void
    {
        $this->scoped($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['discipline_scope' => '["CIVIL"]']));
        $this->actingAs($this->owner)->postJson($this->url, ['receipt_access' => $this->payload()])->assertUnprocessable();
        $this->scoped($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['discipline_scope' => null]));
        $this->scoped($this->buyer, fn () => User::whereKey($this->buyer->id)->update(['is_active' => false]));
        $this->postJson($this->url, ['receipt_access' => $this->payload()])->assertUnprocessable();
        $this->assertCount(0, $this->rows());
    }

    public function test_archive_keeps_owner_read_history_but_has_no_directory_or_form_and_refuses_writes(): void
    {
        $this->save($this->payload());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Retain receipt nomination evidence');
        $this->actingAs($this->owner)->get($this->url)->assertOk()->assertDontSee('piping-receipt-access-form')->assertSee('Synthetic nomination', false);
        $this->postJson($this->url, ['receipt_access' => $this->payload(1)])->assertUnprocessable();
        $this->assertCount(1, $this->rows());
    }

    public function test_owner_entitlement_active_status_and_project_membership_are_fresh(): void
    {
        $this->scoped($this->owner, fn () => User::whereKey($this->owner->id)->update(['is_active' => false]));
        $this->actingAs($this->owner)->get($this->url)->assertNotFound();
        $this->scoped($this->owner, fn () => User::whereKey($this->owner->id)->update(['is_active' => true]));
        $this->scoped($this->owner, fn () => DB::table('project_memberships')->where('project_id', $this->project->id)->where('user_id', $this->owner->id)->delete());
        $this->get($this->url)->assertNotFound();
    }

    public function test_policy_rows_are_invisible_to_other_company_and_other_project(): void
    {
        $this->save($this->payload());
        $this->assertSame(0, $this->scoped($this->buyer, fn () => DB::table('piping_receipt_author_policies')->count()));
        $other = Project::where('code', 'BB-2')->firstOrFail();
        $this->assertSame(0, app(TenantContext::class)->runAs($this->owner->company_id, fn () => DB::table('piping_receipt_author_policies')->count(), $other->id));
        $this->actingAs($this->owner)->get(str_replace('PH-14', 'BB-2', $this->url))->assertNotFound();
        $this->assertCount(1, $this->rows());
    }

    public function test_role_change_and_plan_removal_remove_nomination_options_on_submission(): void
    {
        $dashboard = app(PipingReceiptAccessService::class)->dashboard($this->project, $this->owner);
        $roles = array_keys(array_filter($dashboard['options'], fn ($o) => str_starts_with($o['selector'], 'role:'.$this->buyer->company_id.':')));
        $this->assertNotEmpty($roles);
        $payload = $this->payload();
        $payload['actions']['post_grn'] = [$roles[0]];
        $this->scoped($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['role_codes' => '[]']));
        $this->actingAs($this->owner)->postJson($this->url, ['receipt_access' => $payload])->assertUnprocessable();
        $company = app(TenantContext::class)->forIdentityLookup(fn () => $this->buyer->company()->first());
        Plan::whereKey($company->plan_id)->update(['modules' => json_encode(['core'])]);
        $this->postJson($this->url, ['receipt_access' => $this->payload()])->assertUnprocessable();
        $this->assertCount(0, $this->rows());
    }

    public function test_sql_guards_stamp_server_metadata_reject_stale_shape_and_require_current_configurator(): void
    {
        $this->save($this->payload());
        $row = (array) $this->rows()->sole();
        $row['id'] = (string) Str::uuid();
        $row['version'] = 2;
        $row['actor_name'] = 'FORGED-SQL-NAME';
        $row['created_at'] = '1970-01-01';
        $row['custodian_snapshot'] = '{"code":"FORGED-CUSTODIAN"}';
        $this->scoped($this->owner, fn () => DB::table('piping_receipt_author_policies')->insert($row));
        $latest = $this->rows()->last();
        $this->assertSame($this->owner->name, $latest->actor_name);
        $this->assertStringNotContainsString('1970', $latest->created_at);
        $this->assertStringNotContainsString('FORGED', $latest->custodian_snapshot);
        $bad = $row;
        $bad['id'] = (string) Str::uuid();
        $bad['version'] = 3;
        $bad['actions'] = '{}';
        try {
            $this->scoped($this->owner, fn () => DB::transaction(fn () => DB::table('piping_receipt_author_policies')->insert($bad)));
            $this->fail('Malformed SQL policy must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_RECEIPT_POLICY_ACTIONS', $e->getMessage());
        }
        $this->scoped($this->owner, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->owner->id)->update(['role_codes' => '[]']));
        $row['id'] = (string) Str::uuid();
        $row['version'] = 3;
        try {
            $this->scoped($this->owner, fn () => DB::transaction(fn () => DB::table('piping_receipt_author_policies')->insert($row)));
            $this->fail('Unprivileged SQL configurator must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_RECEIPT_POLICY_CONFIG_FORBIDDEN', $e->getMessage());
        }
        $this->assertCount(2, $this->rows());
    }

    public function test_forward_install_over_populated_archived_project_adds_no_default_policy_or_business_changes(): void
    {
        DB::statement('DROP TABLE piping_receipt_author_policies CASCADE');
        DB::table('migrations')->where('migration', '2026_10_22_100000_add_piping_receipt_author_configuration')->delete();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic populated forward install');
        $snapshot = fn () => $this->scoped($this->buyer, fn () => [DB::table('material_lots')->orderBy('id')->get()->toJson(), DB::table('material_movements')->orderBy('id')->get()->toJson(), DB::table('purchase_orders')->orderBy('id')->get()->toJson()]);
        $before = $snapshot();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertCount(0, $this->rows());
        $this->assertEquals($before, $snapshot());
        $this->actingAs($this->owner)->get($this->url)->assertOk()->assertDontSee('piping-receipt-access-form');
        $rls = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname='piping_receipt_author_policies'");
        $this->assertTrue($rls->relrowsecurity);
        $this->assertTrue($rls->relforcerowsecurity);
    }

    public function test_fresh_owner_plan_and_pack_revoke_configuration_and_validation_is_localized(): void
    {
        $payload = $this->payload();
        $payload['actions']['receiving_qc'] = [];
        $r = $this->actingAs($this->owner)->postJson(str_replace('lang=en', 'lang=fa', $this->url), ['receipt_access' => $payload])->assertUnprocessable();
        $this->assertStringContainsString('معتبر نیست', $r->json('errors')['access.actions.receiving_qc'][0]);
        $company = app(TenantContext::class)->forIdentityLookup(fn () => $this->owner->company()->first());
        $plan = Plan::whereKey($company->plan_id)->firstOrFail();
        $originalModules = $plan->modules;
        $plan->update(['modules' => ['core']]);
        $this->actingAs($this->owner)->get($this->url)->assertForbidden();
        $this->postJson($this->url, ['receipt_access' => $this->payload()])->assertForbidden();
        $plan->update(['modules' => $originalModules, 'standards_packs' => ['NO-SUCH-PACK']]);
        $this->get($this->url)->assertForbidden();
        $this->assertCount(0, $this->rows());
    }
}
