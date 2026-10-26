<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\AcceptedMtoRow;
use App\Models\MtoAcceptanceJournal;
use App\Models\MtoScopeObservation;
use App\Models\Subsystem;
use App\Services\MtoScopeReadinessService;
use App\Services\MtoTopologyReconciliationService;
use App\Services\ProjectArchiveService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\MtoStagingRow;
use App\Models\Project;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class MtoTopologyReconciliationTest extends TestCase
{
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

    private function captured(bool $pin = true, bool $scope = true, bool $area = false): AcceptedMtoBatch
    {
        $leaf = $this->asPars(fn () => Subsystem::where('project_id',$this->project()->id)->orderBy('id')->firstOrFail());
        $this->asPars(function () use ($leaf,$area): void {
            $a = $area ? \App\Models\Area::create(['project_id'=>$leaf->project_id,'company_id'=>$leaf->company_id,
                'facility_id'=>$leaf->facility_id,'code'=>'REC-AREA','name_en'=>'Synthetic area','name_fa'=>'آزمایش']) : null;
            $leaf->update(['area_id'=>$a?->id]);
        });
        $rev = $this->draftRevision([$this->row(['row_scope_id'=>$scope ? $leaf->id : null])]);
        if ($pin) { $this->asPars(fn () => $rev->update(['hierarchy_revision_id'=>'H-SYNTHETIC-CLAIM'])); }
        return $this->acceptObserved($rev);
    }

    private function proposal(AcceptedMtoBatch $b): array
    {
        return $this->asPars(function () use ($b): array {
            $o = $b->scopeObservation()->firstOrFail(); $p = $o->payload;
            $binding = ['project_id'=>$b->project_id,'batch_id'=>$b->id,'observation_id'=>$o->id,
                'payload_hash'=>$o->payload_hash,'source_rows_hash'=>$o->source_rows_hash,
                'hierarchy_revision_id'=>$b->hierarchy_revision_id,'header_scope_id'=>$b->header_scope_id];
            $nodes = [['id'=>$b->project_id,'project_id'=>$b->project_id,'kind'=>'PROJECT','parent_id'=>null]];
            foreach ($p['facilities'] as $f) { $nodes[]=['id'=>$f['id'],'project_id'=>$b->project_id,'kind'=>'FACILITY','parent_id'=>$b->project_id]; }
            foreach ($p['areas'] as $a) { $nodes[]=['id'=>$a['id'],'project_id'=>$b->project_id,'kind'=>'AREA','parent_id'=>$a['facility_id']]; }
            foreach ($p['subsystems'] as $s) { $nodes[]=['id'=>$s['id'],'project_id'=>$b->project_id,'kind'=>'SUBSYSTEM','parent_id'=>$s['area_id'] ?? $s['facility_id']]; }
            return [$binding,$nodes];
        });
    }

    private function compare(AcceptedMtoBatch $b, array $binding, array $nodes): array
    {
        $r = $this->asPars(fn () => app(MtoTopologyReconciliationService::class)->compare($b,$binding,$nodes));
        $this->assertFalse($r['ready_to_admit']); $this->assertFalse($r['ready']);
        $this->assertNull($r['gross']); $this->assertSame('UNADMITTED',$r['admission_state']);
        return $r;
    }

    public function test_matching_frozen_edges_are_not_authority_or_coverage_and_no_writes_occur(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        $before=$this->asPars(fn () => DB::table('mto_scope_observations')->get()->toJson());
        $r=$this->compare($b,$binding,$nodes);
        $this->assertTrue($r['comparison_complete']);$this->assertSame('MATCHES_OBSERVATION',$r['status']);
        $this->assertSame([],$r['differences']);$this->assertContains('COVERAGE_NOT_VERIFIED',$r['findings']);
        $this->assertSame($before,$this->asPars(fn () => DB::table('mto_scope_observations')->get()->toJson()));
    }

    public function test_optional_area_path_is_compared(): void
    {
        $b=$this->captured(area:true);[$binding,$nodes]=$this->proposal($b);
        $this->assertSame('MATCHES_OBSERVATION',$this->compare($b,$binding,$nodes)['status']);
    }

    public function test_changed_parent_and_unobserved_proposed_nodes_are_explicit(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        $new=(string) \Illuminate\Support\Str::uuid();$nodes[]=['id'=>$new,'project_id'=>$b->project_id,'kind'=>'FACILITY','parent_id'=>$b->project_id];
        foreach ($nodes as &$n) { if ($n['kind']==='SUBSYSTEM') { $n['parent_id']=$new; } } unset($n);
        $r=$this->compare($b,$binding,$nodes);
        $this->assertSame('DIFFERENCES_FOUND',$r['status']);$this->assertTrue($r['comparison_complete']);
        $this->assertContains('OBSERVED_EDGE_OR_KIND_DIFFERS',array_column($r['differences'],'code'));
        $this->assertContains('NOT_OBSERVED_AT_CAPTURE',array_column($r['differences'],'code'));
    }

    public function test_later_mutable_graph_reparenting_is_not_used_as_history(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        $this->asPars(function () use ($b): void {
            $f=\App\Models\Facility::create(['project_id'=>$b->project_id,'company_id'=>$b->company_id,'code'=>'REC-NEW',
                'name_en'=>'Later facility','name_fa'=>'آزمایش','kind'=>'PLANT']);
            $b->rows()->firstOrFail()->scope->update(['facility_id'=>$f->id]);
        });
        $this->assertSame('MATCHES_OBSERVATION',$this->compare($b,$binding,$nodes)['status']);
    }

    public function test_missing_proposed_nodes_are_not_approved_retirement(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        $r=$this->compare($b,$binding,[$nodes[0]]);
        $this->assertSame('DIFFERENCES_FOUND',$r['status']);
        $this->assertContains('NODE_NOT_IN_PROPOSAL',array_column($r['differences'],'code'));
    }

    public function test_missing_pin_stays_incomplete(): void
    {
        $b=$this->captured(pin:false);[$binding,$nodes]=$this->proposal($b);
        $r=$this->compare($b,$binding,$nodes);$this->assertSame(['MISSING_HIERARCHY_PIN'],$r['findings']);$this->assertSame([],$r['differences']);
    }

    public function test_missing_scope_is_not_inferred_from_nodes(): void
    {
        $b=$this->captured(scope:false);[$binding,$nodes]=$this->proposal($b);
        $this->assertSame(['OBSERVED_ROW_SCOPE_UNRESOLVED'],$this->compare($b,$binding,$nodes)['findings']);
    }

    public function test_every_binding_field_is_exact_and_cross_version_is_not_mapping(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        foreach (array_keys($binding) as $key) {
            $x=$binding;$x[$key]=$key==='header_scope_id' ? (string) \Illuminate\Support\Str::uuid() : 'DIFFERENT';
            $r=$this->compare($b,$x,$nodes);$this->assertSame(['SOURCE_BINDING_MISMATCH'],$r['findings']);$this->assertFalse($r['comparison_complete']);
        }
    }

    public function test_unknown_binding_field_is_not_an_approval_capability(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);$binding['approved']=true;
        $this->assertSame(['SOURCE_BINDING_MISMATCH'],$this->compare($b,$binding,$nodes)['findings']);
    }

    public function test_invalid_graphs_fail_without_partial_differences(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        $bad=[];$bad[]=[];$bad[]=[false];$x=$nodes;$x[]=$x[1];$bad[]=$x;
        $x=$nodes;$x[1]['project_id']=(string) \Illuminate\Support\Str::uuid();$bad[]=$x;
        $x=$nodes;$x[1]['parent_id']=$x[2]['id'];$bad[]=$x;
        $x=$nodes;$x[2]['parent_id']=(string) \Illuminate\Support\Str::uuid();$bad[]=$x;
        $x=$nodes;$x[2]['kind']='AREA';$x[2]['parent_id']=$b->project_id;$bad[]=$x;
        $x=$nodes;$x[1]['approved']=true;$bad[]=$x;
        foreach ($bad as $x) {
            $r=$this->compare($b,$binding,$x);$this->assertSame(['PROPOSED_TOPOLOGY_INVALID'],$r['findings']);$this->assertSame([],$r['differences']);
        }
    }

    public function test_input_order_does_not_change_differences_or_mutate_inputs(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);$new=(string) \Illuminate\Support\Str::uuid();
        $nodes[]=['id'=>$new,'project_id'=>$b->project_id,'kind'=>'FACILITY','parent_id'=>$b->project_id];
        $before=serialize([$binding,$nodes]);$r=$this->compare($b,$binding,$nodes);
        $this->assertSame($before,serialize([$binding,$nodes]));$this->assertSame($r,$this->compare($b,$binding,array_reverse($nodes)));
    }

    public function test_late_row_addition_blocks_comparison(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);$rev=$this->draftRevision([$this->row()]);
        $this->asPars(function () use ($b,$rev): void {
            $a=$b->rows()->firstOrFail()->getAttributes();$a['id']=(string) \Illuminate\Support\Str::uuid();
            $a['source_staging_row_id']=$rev->rows()->firstOrFail()->id;DB::table('accepted_mto_rows')->insert($a);
        });
        $r=$this->compare($b,$binding,$nodes);$this->assertSame(['SOURCE_ROWS_DRIFT'],$r['findings']);$this->assertSame([],$r['differences']);
    }

    public function test_outsider_with_forged_model_attributes_reads_nothing(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        $other=$this->tenant->forIdentityLookup(fn () => Company::where('code','EPC-ARVAND')->firstOrFail());
        $b->project_id=(string) \Illuminate\Support\Str::uuid();
        $r=$this->tenant->runAs($other->id,fn () => app(MtoTopologyReconciliationService::class)->compare($b,$binding,$nodes),$binding['project_id']);
        $this->assertSame(['SOURCE_NOT_VISIBLE'],$r['findings']);$this->assertNull($r['observation_id']);$this->assertSame([],$r['differences']);
    }

    public function test_missing_observation_before_commit_stays_incomplete(): void
    {
        $rev=$this->draftRevision([$this->row()]);
        $this->asPars(function () use ($rev): void {
            $b=$this->acceptance->accept($rev,'en');
            $r=app(MtoTopologyReconciliationService::class)->compare($b,[],[]);
            $this->assertSame(['SCOPE_OBSERVATION_MISSING'],$r['findings']);$this->assertFalse($r['comparison_complete']);
        });
    }

    public function test_archived_project_keeps_read_only_comparison(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);$p=$this->project();
        $owner=$this->userByEmail('karimi@nioc.example');app(ProjectArchiveService::class)->archive($p,$owner,'Synthetic reconciliation');
        $this->assertSame('MATCHES_OBSERVATION',$this->compare($b,$binding,$nodes)['status']);
    }
    public function test_kind_change_is_a_difference_not_scope_equivalence(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        foreach ($nodes as &$n) { if ($n['kind']==='SUBSYSTEM') { $n['kind']='AREA'; } } unset($n);
        $r=$this->compare($b,$binding,$nodes);
        $this->assertSame('DIFFERENCES_FOUND',$r['status']);
        $this->assertContains('OBSERVED_EDGE_OR_KIND_DIFFERS',array_column($r['differences'],'code'));
    }

    public function test_unresolved_capture_parent_is_not_filled_from_proposal(): void
    {
        $leaf=$this->asPars(fn () => Subsystem::where('project_id',$this->project()->id)->orderBy('id')->firstOrFail());
        $other=$this->tenant->forIdentityLookup(fn () => Project::where('code','BB-2')->firstOrFail());
        $f=$this->tenant->runAs($other->owner_company_id,fn () => \App\Models\Facility::create([
            'project_id'=>$other->id,'company_id'=>$other->owner_company_id,'code'=>'REC-FOREIGN',
            'name_en'=>'Other project','name_fa'=>'آزمایش','kind'=>'PLANT']),$other->id);
        $this->asPars(fn () => $leaf->update(['area_id'=>null,'facility_id'=>$f->id]));
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        $r=$this->compare($b,$binding,$nodes);
        $this->assertSame(['OBSERVED_TOPOLOGY_INCOMPLETE'],$r['findings']);
        $this->assertSame([],$r['differences']);$this->assertFalse($r['comparison_complete']);
    }

}
