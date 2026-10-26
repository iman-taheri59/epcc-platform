<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Project;
use App\Models\User;
use App\Services\CdeEvidenceIntegrityService;
use App\Services\DocumentFileStore;
use App\Services\DocumentRegister;
use App\Support\CdeState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CdeEvidenceIntegrityTest extends TestCase
{
    private User $actor;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->actor = $this->userByEmail('rezaei@pars.example');
        $this->project = app(TenantContext::class)->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
        Storage::fake('local'); // Real local scratch bytes + readStream, not a mocked successful hash.
    }

    private function asActor(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->actor->company_id, $fn, $this->project->id);
    }

    private function fixture(bool $published = true): array
    {
        return $this->asActor(function () use ($published): array {
            $doc = app(DocumentRegister::class)->register($this->project, $this->actor, [
                'discipline' => 'CIVIL', 'doc_type' => 'DWG', 'title_en' => 'Synthetic evidence', 'title_fa' => 'مدرک آزمایشی',
            ]);
            $file = app(DocumentFileStore::class)->attach($doc, $this->actor,
                UploadedFile::fake()->createWithContent('evidence.pdf', '%PDF-1.4 controlled evidence'));
            if ($published) {
                app(DocumentRegister::class)->transition($doc->fresh(), $this->actor, CdeState::SHARED, 'S2');
                app(DocumentRegister::class)->transition($doc->fresh(), $this->actor, CdeState::PUBLISHED, 'A1');
            }
            return [$doc->fresh(), $file];
        });
    }

    private function verify(Document $doc, DocumentFile $file, ?string $hash = null): array
    {
        return app(CdeEvidenceIntegrityService::class)->verify($this->project->id, $doc->id, $file->revision_id, $file->id, $hash ?? $file->sha256);
    }

    private function blocked(array $r, string $code): void
    {
        $this->assertFalse($r['integrity_verified']);
        $this->assertSame([$code], $r['findings']);
        $this->assertNull($r['source']);
        $this->assertFalse($r['ready_to_admit']);
        $this->assertNull($r['gross']);
    }

    public function test_exact_visible_revision_bytes_are_verified_without_admission_or_writes(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(function () use ($doc,$file): void {
            $before = [DB::table('document_files')->get()->toJson(), DB::table('document_revisions')->get()->toJson(), DB::table('documents')->get()->toJson()];
            $r = $this->verify($doc,$file);
            $this->assertSame('BYTES_VERIFIED', $r['status']);
            $this->assertTrue($r['integrity_verified']);
            $this->assertSame($file->revision_id, $r['source']['revision_id']);
            $this->assertSame($file->sha256, $r['source']['sha256']);
            $this->assertSame($file->size_bytes, $r['source']['size_bytes']);
            $this->assertSame('UNADMITTED', $r['admission_state']);
            $this->assertFalse($r['ready_to_admit']);
            $this->assertFalse($r['ready']);
            $this->assertNull($r['gross']);
            $this->assertArrayNotHasKey('storage_path', $r['source']);
            $this->assertArrayNotHasKey('disk', $r['source']);
            $this->assertSame($before, [DB::table('document_files')->get()->toJson(), DB::table('document_revisions')->get()->toJson(), DB::table('documents')->get()->toJson()]);
        });
    }

    public function test_wip_integrity_is_not_publication_or_engineering_approval(): void
    {
        [$doc,$file] = $this->fixture(false);
        $this->asActor(function () use ($doc,$file): void {
            $r = $this->verify($doc,$file);
            $this->assertTrue($r['integrity_verified']);
            $this->assertContains('REVISION_CONTENT_MUTABLE', $r['findings']);
            $this->assertFalse($r['ready_to_admit']);
        });
    }

    public function test_archived_exact_revision_is_used_not_current_revision(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(function () use ($doc,$file): void {
            $new = app(DocumentRegister::class)->issueRevision($doc->fresh(), $this->actor, 'B');
            $r = $this->verify($doc->fresh(),$file);
            $this->assertTrue($r['integrity_verified']);
            $this->assertSame(CdeState::ARCHIVED, $r['source']['cde_state']);
            $this->assertSame($file->revision_id, $r['source']['revision_id']);
            $this->assertNotSame($new->id, $r['source']['revision_id']);
            $this->assertFalse($r['ready_to_admit']);
        });
    }

    public function test_same_hash_does_not_substitute_file_from_another_document(): void
    {
        [$doc,$file] = $this->fixture(); [$other,$otherFile] = $this->fixture();
        $this->asActor(fn () => $this->blocked($this->verify($doc,$otherFile), 'EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED'));
    }

    public function test_outsider_does_not_trigger_storage_access_or_leak_reference(): void
    {
        [$doc,$file] = $this->fixture();
        $outsider = $this->userByEmail('hosseini@arvand.example');
        Storage::shouldReceive('disk')->never();
        app(TenantContext::class)->runAs($outsider->company_id,
            fn () => $this->blocked($this->verify($doc,$file), 'EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED'), $this->project->id);
    }

    public function test_wrong_project_reference_is_not_resolved_by_id_alone(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(fn () => $this->blocked(app(CdeEvidenceIntegrityService::class)->verify(
            (string) \Illuminate\Support\Str::uuid(), $doc->id,$file->revision_id,$file->id,$file->sha256), 'EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED'));
    }

    public function test_malformed_reference_is_rejected_before_database_or_storage_lookup(): void
    {
        $this->blocked(app(CdeEvidenceIntegrityService::class)->verify('not-an-id','x','x','x',str_repeat('a',64)), 'INVALID_EXACT_REFERENCE');
    }

    public function test_wrong_expected_digest_does_not_fall_back_to_stored_digest(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(fn () => $this->blocked($this->verify($doc,$file,str_repeat('0',64)), 'EXPECTED_DIGEST_MISMATCH'));
    }

    public function test_missing_blob_fails_closed(): void
    {
        [$doc,$file] = $this->fixture(); Storage::disk('local')->delete($file->storage_path);
        $this->asActor(fn () => $this->blocked($this->verify($doc,$file), 'BLOB_NOT_READABLE'));
    }

    public function test_same_size_tampered_blob_fails_digest_check(): void
    {
        [$doc,$file] = $this->fixture(); Storage::disk('local')->put($file->storage_path, str_repeat('X',$file->size_bytes));
        $this->asActor(fn () => $this->blocked($this->verify($doc,$file), 'BLOB_DIGEST_MISMATCH'));
    }

    public function test_truncated_blob_fails_size_check(): void
    {
        [$doc,$file] = $this->fixture(); Storage::disk('local')->put($file->storage_path, 'short');
        $this->asActor(fn () => $this->blocked($this->verify($doc,$file), 'BLOB_SIZE_MISMATCH'));
    }

    public function test_noncanonical_database_path_is_not_used(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(function () use ($doc,$file): void {
            DB::table('document_files')->where('id',$file->id)->update(['storage_path'=>'../.env']);
            $this->blocked($this->verify($doc,$file), 'NONCANONICAL_STORAGE_REFERENCE');
        });
    }

    public function test_remote_disk_is_not_fetched(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(function () use ($doc,$file): void {
            DB::table('document_files')->where('id',$file->id)->update(['disk'=>'s3']);
            $this->blocked($this->verify($doc,$file), 'STORAGE_ADAPTER_UNSUPPORTED');
        });
    }

    public function test_metadata_mutation_during_stream_read_is_detected(): void
    {
        [$doc,$file] = $this->fixture();
        $path = Storage::disk('local')->path($file->storage_path);
        $disk = \Mockery::mock();
        $disk->shouldReceive('readStream')->once()->andReturnUsing(function () use ($file,$path) {
            DB::table('document_files')->where('id',$file->id)->update(['size_bytes'=>$file->size_bytes+1]);
            return fopen($path,'rb');
        });
        Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);
        $this->asActor(fn () => $this->blocked($this->verify($doc,$file), 'SOURCE_METADATA_CHANGED_DURING_READ'));
    }
    public function test_competitor_cannot_read_private_wip_revision(): void
    {
        [$doc,$file] = $this->fixture(false);
        $other = $this->userByEmail('mousavi@sahand.example');
        Storage::shouldReceive('disk')->never();
        app(TenantContext::class)->runAs($other->company_id,
            fn () => $this->blocked($this->verify($doc,$file), 'EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED'), $this->project->id);
    }

    public function test_invalid_hash_shape_is_rejected(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(fn () => $this->blocked($this->verify($doc,$file,'invalid'), 'INVALID_EXPECTED_DIGEST'));
    }

    public function test_oversized_record_is_rejected_before_stream_access(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(function () use ($doc,$file): void {
            DB::table('document_files')->where('id',$file->id)->update(['size_bytes'=>\App\Support\FileType::MAX_BYTES+1]);
            $this->blocked($this->verify($doc,$file), 'RECORDED_SIZE_OUT_OF_BOUNDS');
        });
    }

    public function test_blob_larger_than_record_stops_stream_verification(): void
    {
        [$doc,$file] = $this->fixture(); Storage::disk('local')->put($file->storage_path, str_repeat('X',$file->size_bytes+1));
        $this->asActor(fn () => $this->blocked($this->verify($doc,$file), 'BLOB_SIZE_MISMATCH'));
    }

    public function test_current_revision_id_cannot_replace_explicit_historical_reference(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(function () use ($doc,$file): void {
            $new = app(DocumentRegister::class)->issueRevision($doc->fresh(), $this->actor, 'B');
            $r = app(CdeEvidenceIntegrityService::class)->verify($this->project->id,$doc->id,$new->id,$file->id,$file->sha256);
            $this->blocked($r, 'EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED');
        });
    }

    public function test_uuid_case_does_not_break_content_addressed_path_binding(): void
    {
        [$doc,$file] = $this->fixture();
        $this->asActor(function () use ($doc,$file): void {
            $r = app(CdeEvidenceIntegrityService::class)->verify(strtoupper($this->project->id),strtoupper($doc->id),
                strtoupper($file->revision_id),strtoupper($file->id),$file->sha256);
            $this->assertTrue($r['integrity_verified']);
            $this->assertFalse($r['ready_to_admit']);
        });
    }

    public function test_existing_published_participant_visibility_is_preserved(): void
    {
        [$doc,$file] = $this->fixture();
        $other = $this->userByEmail('mousavi@sahand.example');
        app(TenantContext::class)->runAs($other->company_id, function () use ($doc,$file): void {
            $r = $this->verify($doc,$file);
            $this->assertTrue($r['integrity_verified']);
            $this->assertFalse($r['ready_to_admit']);
        }, $this->project->id);
    }

    public function test_archived_project_evidence_remains_readable_without_writes(): void
    {
        [$doc,$file] = $this->fixture();
        $owner = $this->userByEmail('karimi@nioc.example');
        app(\App\Services\ProjectArchiveService::class)->archive($this->project,$owner,'Synthetic evidence read');
        $this->asActor(function () use ($doc,$file): void {
            $r = $this->verify($doc,$file);
            $this->assertTrue($r['integrity_verified']);
            $this->assertFalse($r['ready_to_admit']);
        });
    }

}
