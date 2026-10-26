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
final class MtoReconciliationEvidenceTest extends TestCase
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
        \Illuminate\Support\Facades\Storage::fake('local');
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

    private function evidence(bool $published = true, string $email = 'rezaei@pars.example'): array
    {
        
        $actor=$this->userByEmail($email);
        return $this->tenant->runAs($actor->company_id,function () use ($published,$actor): array {
            $doc=app(\App\Services\DocumentRegister::class)->register($this->project(),$actor,[
                'discipline'=>'REC-TEST','doc_type'=>'DWG','title_en'=>'Synthetic dependency','title_fa'=>'مدرک آزمایشی']);
            $f=app(\App\Services\DocumentFileStore::class)->attach($doc,$actor,
                \Illuminate\Http\UploadedFile::fake()->createWithContent('dependency.pdf','%PDF-1.4 synthetic dependency'));
            if ($published) {
                app(\App\Services\DocumentRegister::class)->transition($doc->fresh(),$actor,\App\Support\CdeState::SHARED,'S2');
                app(\App\Services\DocumentRegister::class)->transition($doc->fresh(),$actor,\App\Support\CdeState::PUBLISHED,'A1');
            }
            $ref=['project_id'=>$this->project()->id,'document_id'=>$doc->id,'revision_id'=>$f->revision_id,'file_id'=>$f->id,'sha256'=>$f->sha256];
            return [$ref,$f,$doc->fresh()];
        },$this->project()->id);
    }

    private function inspect(AcceptedMtoBatch $b, array $binding, array $nodes, array $refs): array
    {
        $r=$this->asPars(fn () => app(\App\Services\MtoReconciliationEvidenceService::class)->inspect($b,$binding,$nodes,$refs));
        $this->assertFalse($r['ready_to_admit']);$this->assertFalse($r['ready']);$this->assertNull($r['gross']);
        $this->assertFalse($r['evidence_validity_verified']);$this->assertFalse($r['evidence_relationship_verified']);
        $this->assertSame('UNADMITTED',$r['admission_state']);
        return $r;
    }

    public function test_matching_bytes_for_both_claimed_roles_are_not_validity_or_admission_and_write_nothing(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$t]=$this->evidence();[$c]=$this->evidence();
        $snapshot=fn () => $this->asPars(fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['accepted_mto_batches','accepted_mto_rows','mto_scope_observations','documents','document_revisions','document_files']));
        $before=$snapshot();$input=serialize([$binding,$nodes,$t,$c]);
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$t,'coverage'=>$c]);
        $this->assertSame('DEPENDENCY_BYTES_CHECKED',$r['status']);$this->assertTrue($r['diagnostic_complete']);
        $this->assertTrue($r['all_evidence_bytes_verified']);$this->assertSame('MATCHES_OBSERVATION',$r['comparison']['status']);
        $this->assertContains('EVIDENCE_ROLES_AND_CONTENT_RELATIONSHIP_UNVERIFIED',$r['findings']);
        $this->assertContains('EVIDENCE_CHECKS_NOT_ATOMIC',$r['findings']);
        $this->assertSame($before,$snapshot());$this->assertSame($input,serialize([$binding,$nodes,$t,$c]));
        $this->assertArrayNotHasKey('storage_path',$r['evidence']['topology']['source']);
    }

    public function test_differences_are_not_resolved_by_correct_bytes(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        $r=$this->inspect($b,$binding,[$nodes[0]],['topology'=>$ref,'coverage'=>$ref]);
        $this->assertSame('DIFFERENCES_FOUND',$r['comparison']['status']);$this->assertNotEmpty($r['comparison']['differences']);
        $this->assertTrue($r['all_evidence_bytes_verified']);
    }

    public function test_reusing_one_file_in_two_claimed_roles_is_not_independent_coverage(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertTrue($r['diagnostic_complete']);$this->assertContains('COVERAGE_NOT_VERIFIED',$r['findings']);
    }

    public function test_incomplete_source_prevents_any_evidence_stream(): void
    {
        $b=$this->captured(pin:false);[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->never();
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertSame('INCOMPLETE',$r['status']);$this->assertSame([],$r['evidence']);
        $this->assertSame(['MISSING_HIERARCHY_PIN'],$r['comparison']['findings']);
    }

    public function test_stale_binding_prevents_any_evidence_stream(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();$binding['source_rows_hash']=str_repeat('0',64);
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->never();
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertSame([],$r['evidence']);$this->assertSame(['SOURCE_BINDING_MISMATCH'],$r['comparison']['findings']);
    }

    public function test_malformed_reference_shapes_are_rejected_before_any_stream(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        $bad=[[],['topology'=>$ref],['topology'=>$ref,'coverage'=>null],['topology'=>$ref,'coverage'=>$ref,'approved'=>true]];
        $x=$ref;$x['retained_uri']='https://example.invalid/';$bad[]=['topology'=>$ref,'coverage'=>$x];
        $x=$ref;unset($x['revision_id']);$bad[]=['topology'=>$ref,'coverage'=>$x];
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->never();
        foreach ($bad as $refs) {
            $r=$this->inspect($b,$binding,$nodes,$refs);$this->assertFalse($r['diagnostic_complete']);
            $this->assertSame(['INVALID_EVIDENCE_REFERENCE_SHAPE'],$r['findings']);$this->assertSame([],$r['evidence']);
        }
    }

    public function test_invalid_id_and_digest_types_are_rejected_without_stream(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->never();
        foreach (['document_id','revision_id','file_id','project_id','sha256'] as $key) {
            foreach ([null,[],true,'bad'] as $value) {
                $x=$ref;$x[$key]=$value;
                $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$x]);
                $this->assertSame([$key==='sha256'?'INVALID_EXPECTED_DIGEST':'INVALID_EXACT_REFERENCE'],$r['findings']);
                $this->assertSame([],$r['evidence']);
            }
        }
    }

    public function test_foreign_project_reference_is_not_a_permission_to_resolve_other_project(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();$ref['project_id']=(string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->never();
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertSame(['EVIDENCE_PROJECT_MISMATCH'],$r['findings']);
    }

    public function test_missing_blob_and_hash_mismatch_preserve_per_role_failures(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$t,$file]=$this->evidence();[$c]=$this->evidence();
        // Different files may share the same canonical content-addressed blob.
        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->storage_path);$c['sha256']=str_repeat('0',64);
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$t,'coverage'=>$c]);
        $this->assertFalse($r['all_evidence_bytes_verified']);$this->assertFalse($r['diagnostic_complete']);
        $this->assertSame(['BLOB_NOT_READABLE'],$r['evidence']['topology']['findings']);
        $this->assertSame(['EXPECTED_DIGEST_MISMATCH'],$r['evidence']['coverage']['findings']);
        $this->assertContains('EVIDENCE_BYTES_INCOMPLETE',$r['findings']);
    }

    public function test_tuple_mismatch_does_not_substitute_equal_bytes(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$t]=$this->evidence();[$c]=$this->evidence();
        $t['document_id']=$c['document_id'];
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$t,'coverage'=>$c]);
        $this->assertSame(['EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED'],$r['evidence']['topology']['findings']);
        $this->assertFalse($r['diagnostic_complete']);$this->assertTrue($r['evidence']['coverage']['integrity_verified']);
    }

    public function test_wip_bytes_are_reported_as_mutable_and_unadmitted(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence(false);
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertContains('REVISION_CONTENT_MUTABLE',$r['evidence']['topology']['findings']);$this->assertTrue($r['all_evidence_bytes_verified']);
    }

    public function test_historical_revision_is_not_replaced_by_latest(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref,$f,$doc]=$this->evidence();
        $this->asPars(fn () => app(\App\Services\DocumentRegister::class)->issueRevision($doc,$this->userByEmail('rezaei@pars.example'),'B'));
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertTrue($r['all_evidence_bytes_verified']);$this->assertSame($f->revision_id,$r['evidence']['topology']['source']['revision_id']);
        $this->assertSame(\App\Support\CdeState::ARCHIVED,$r['evidence']['topology']['source']['cde_state']);
    }

    public function test_invisible_batch_does_not_attempt_file_reads_or_disclose_observation(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->never();
        $r=$this->tenant->runAs($this->company('EPC-ARVAND')->id,
            fn () => app(\App\Services\MtoReconciliationEvidenceService::class)->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]),$b->project_id);
        $this->assertSame(['SOURCE_NOT_VISIBLE'],$r['comparison']['findings']);$this->assertNull($r['comparison']['observation_id']);
        $this->assertSame([],$r['evidence']);$this->assertFalse($r['ready_to_admit']);
    }

    public function test_final_source_reread_discards_checked_bytes_if_row_set_changes_during_stream(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();$rev=$this->draftRevision([$this->row()]);
        $disk=\Illuminate\Support\Facades\Storage::disk('local');$done=false;
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('local')->andReturn(new class($disk,function () use ($b,$rev,&$done): void {
            if ($done) { return; } $done=true;
            $a=$b->rows()->firstOrFail()->getAttributes();$a['id']=(string) \Illuminate\Support\Str::uuid();
            $a['source_staging_row_id']=$rev->rows()->firstOrFail()->id;DB::table('accepted_mto_rows')->insert($a);
        }) {
            public function __construct(private $disk,private $hook) {}
            public function readStream($path) { ($this->hook)();return $this->disk->readStream($path); }
        });
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertSame(['SOURCE_CHANGED_OR_NOT_VISIBLE_AFTER_EVIDENCE_CHECK'],$r['findings']);
        $this->assertSame(['SOURCE_ROWS_DRIFT'],$r['comparison']['findings']);$this->assertSame([],$r['evidence']);
        $this->assertFalse($r['all_evidence_bytes_verified']);$this->assertFalse($r['diagnostic_complete']);
    }

    public function test_role_and_reference_key_order_do_not_change_output(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        $a=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $x=array_reverse($ref,true);$this->assertSame($a,$this->inspect($b,$binding,$nodes,['coverage'=>$x,'topology'=>$x]));
    }
    public function test_visible_batch_does_not_grant_visibility_of_another_company_private_evidence(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);
        [$ref]=$this->evidence(false,'mousavi@sahand.example');
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->never();
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        $this->assertTrue($r['comparison']['comparison_complete']);$this->assertFalse($r['all_evidence_bytes_verified']);
        foreach ($r['evidence'] as $e) {
            $this->assertSame(['EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED'],$e['findings']);$this->assertNull($e['source']);
        }
    }

    public function test_archived_project_preserves_read_only_diagnostics(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref]=$this->evidence();
        app(ProjectArchiveService::class)->archive($this->project(),$this->userByEmail('karimi@nioc.example'),'Synthetic diagnostic');
        $this->assertTrue($this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref])['diagnostic_complete']);
    }

    public function test_changed_blob_is_not_valid_despite_unchanged_reference_metadata(): void
    {
        $b=$this->captured();[$binding,$nodes]=$this->proposal($b);[$ref,$f]=$this->evidence();
        \Illuminate\Support\Facades\Storage::disk('local')->put($f->storage_path,str_repeat('X',$f->size_bytes));
        $r=$this->inspect($b,$binding,$nodes,['topology'=>$ref,'coverage'=>$ref]);
        foreach ($r['evidence'] as $e) {$this->assertSame(['BLOB_DIGEST_MISMATCH'],$e['findings']);}
        $this->assertFalse($r['diagnostic_complete']);
    }

}
