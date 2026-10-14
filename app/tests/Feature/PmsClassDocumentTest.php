<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Support\CdeState;
use Illuminate\Support\Str;
use App\Models\Project;
use App\Models\Subsystem;
use App\Services\DocumentRegister;
use App\Services\PmsAccessService;
use App\Services\PmsClassDocumentService;
use App\Services\PmsLineEligibility;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PmsClassDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_approved_revision_captures_immutable_class_rules_and_rejects_drafts(): void
    {
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $owner = $this->userByEmail('karimi@nioc.example');
        $author = $this->userByEmail('rezaei@pars.example');
        $tenant = app(TenantContext::class);
        $register = app(DocumentRegister::class);
        $doc = $tenant->runAs($author->company_id, fn () => $register->register($project, $author, [
            'discipline' => 'PIPING', 'doc_type' => 'PMS',
            'title_en' => 'Versioned PMS', 'title_fa' => 'مدرک نسخه دار',
        ]), $project->id);
        $class = $tenant->runAs($author->company_id, fn () => PipingClass::create([
            'project_id' => $project->id, 'company_id' => $author->company_id,
            'code' => 'SNAP-CS', 'name_en' => 'CS', 'name_fa' => 'کربن استیل',
            'dn_span' => '[15,600)',
        ]), $project->id);
        app(PmsAccessService::class)->configure($project, $owner, [
            'version' => 0, 'suspend' => false, 'selectors' => ['user:'.$author->id],
            'reason' => 'Snapshot test',
        ]);
        app(PmsClassDocumentService::class)->attach($project, $author, $class->id, $doc->id);
        $this->assertSame([], $tenant->runAs($author->company_id,
            fn () => app(PmsLineEligibility::class)->eligibleClassIds($project->id), $project->id));
        $tenant->runAs($author->company_id, fn () => $register->transition($doc, $author, CdeState::SHARED, 'S2'), $project->id);
        try {
            $tenant->runAs($author->company_id, fn () => $register->transition($doc->refresh(), $author, CdeState::PUBLISHED, 'A1'), $project->id);
            $this->fail('A draft class cannot be in an approved PMS revision.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pms', $e->errors());
        }

        $tenant->runAs($author->company_id, function () use ($class, $project, $author): void {
            $class->update(['state' => 'PUBLISHED']); // Synthetic fixture: real publish gate has separate tests.
            DB::table('piping_class_rules')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id,
                'company_id' => $author->company_id, 'piping_class_id' => $class->id,
                'rule_kind' => 'PIPE_SCHEDULE', 'dn_range' => '[15,600)',
                'value' => json_encode(['schedule' => '80'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, $project->id);
        $tenant->runAs($author->company_id, function () use ($register, $doc, $author, $project, $class): void {
            $revision = $register->transition($doc->refresh(), $author, CdeState::PUBLISHED, 'A1');
            $row = DB::table('pms_class_revision_snapshots')->where('document_revision_id', $revision->id)->first();
            $this->assertNotNull($row);
            $this->assertSame($class->id, $row->piping_class_id);
            $spec = json_decode($row->specification, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('80', $spec['rules'][0]['value']['schedule']);
            DB::table('piping_class_rules')->where('piping_class_id', $class->id)
                ->update(['value' => json_encode(['schedule' => '40'], JSON_THROW_ON_ERROR)]);
            $unchanged = json_decode(DB::table('pms_class_revision_snapshots')->where('id', $row->id)->value('specification'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('80', $unchanged['rules'][0]['value']['schedule']);
            $resolver = app(PmsLineEligibility::class);
            $this->assertSame($row->id, $resolver->snapshotId($project->id, $class->id));
            $this->assertSame([$class->id], $resolver->eligibleClassIds($project->id));
            $subsystem = Subsystem::where('project_id', $project->id)->firstOrFail();
            DB::table('piping_lines')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id,
                'company_id' => $author->company_id, 'subsystem_id' => $subsystem->id,
                'line_number' => 'SNAPSHOT-TEST-1', 'piping_class_id' => $class->id,
                'pms_class_snapshot_id' => $row->id, 'release_state' => 'DRAFT',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->assertSame(1, DB::table('piping_lines')->where('pms_class_snapshot_id', $row->id)->count());
        }, $project->id);
        try {
            $tenant->runAs($author->company_id, function () use ($class, $project, $author): void {
                $snapshot = DB::table('pms_class_revision_snapshots')->where('piping_class_id', $class->id)->first();
                DB::table('pms_class_revision_snapshots')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id,
                    'company_id' => $author->company_id,
                    'pms_document_id' => $snapshot->pms_document_id,
                    'document_revision_id' => $snapshot->document_revision_id,
                    'piping_class_id' => $snapshot->piping_class_id,
                    'specification' => '{}', 'created_at' => now(),
                ]);
            }, $project->id);
            $this->fail('Direct INSERT must not forge an approved specification.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        try {
            $tenant->runAs($author->company_id, fn () => DB::table('pms_class_revision_snapshots')
                ->where('piping_class_id', $class->id)->update(['specification' => '{}']), $project->id);
            $this->fail('Issued snapshots must be append-only.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $tenant->runAs($author->company_id, function () use ($register, $doc, $author, $project, $class): void {
            $register->issueRevision($doc->refresh(), $author, 'B');
            $this->assertSame([], app(PmsLineEligibility::class)->eligibleClassIds($project->id));
            try {
                app(PmsLineEligibility::class)->snapshotId($project->id, $class->id);
                $this->fail('An archived PMS revision cannot qualify a new line.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('piping_class_id', $e->errors());
            }
            $this->assertSame(1, DB::table('piping_lines')->where('piping_class_id', $class->id)
                ->whereNotNull('pms_class_snapshot_id')->count(), 'Prior line retains its issued revision.');
        }, $project->id);
    }

    public function test_database_refuses_a_non_pms_document_on_direct_class_insert(): void
    {
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $author = $this->userByEmail('rezaei@pars.example');
        $tenant = app(TenantContext::class);
        $spec = $tenant->runAs($author->company_id, fn () => app(DocumentRegister::class)->register($project, $author, [
            'discipline' => 'PIPING', 'doc_type' => 'SPEC',
            'title_en' => 'Not a PMS', 'title_fa' => 'مدرک غیر PMS',
        ]), $project->id);

        try {
            $tenant->runAs($author->company_id, fn () => PipingClass::forceCreate([
                'project_id' => $project->id, 'company_id' => $author->company_id,
                'code' => 'INVALID-DOC', 'name_en' => 'Invalid', 'name_fa' => 'نامعتبر',
                'dn_span' => '[15,600)', 'pms_document_id' => $spec->id,
            ]), $project->id);
            $this->fail('A regular SPEC must not govern a PMS class.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_one_wip_pms_can_govern_multiple_draft_classes_but_not_be_reparented_or_used_after_suspension(): void
    {
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $owner = $this->userByEmail('karimi@nioc.example');
        $author = $this->userByEmail('rezaei@pars.example');
        $tenant = app(TenantContext::class);
        $access = app(PmsAccessService::class);
        $service = app(PmsClassDocumentService::class);
        $document = $tenant->runAs($author->company_id, fn () => app(DocumentRegister::class)->register($project, $author, [
            'discipline' => 'PIPING', 'doc_type' => 'PMS',
            'title_en' => 'Synthetic PMS', 'title_fa' => 'اسپک پایپینگ آزمایشی',
        ]), $project->id);
        $otherDocument = $tenant->runAs($author->company_id, fn () => app(DocumentRegister::class)->register($project, $author, [
            'discipline' => 'PIPING', 'doc_type' => 'PMS',
            'title_en' => 'Another PMS', 'title_fa' => 'اسپک دیگر',
        ]), $project->id);
        $classes = $tenant->runAs($author->company_id, function () use ($project, $author): array {
            return array_map(fn (string $code) => PipingClass::create([
                'project_id' => $project->id, 'company_id' => $author->company_id,
                'code' => $code, 'name_en' => $code, 'name_fa' => $code,
                'dn_span' => '[15,600)',
            ]), ['PMS-CS', 'PMS-SS']);
        }, $project->id);

        try {
            $service->attach($project, $author, $classes[0]->id, $document->id);
            $this->fail('PMS author nomination must default-deny.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pms_access', $e->errors());
        }
        $access->configure($project, $owner, [
            'version' => 0, 'suspend' => false,
            'selectors' => ['user:'.$author->id], 'reason' => 'Synthetic PMS author',
        ]);
        foreach ($classes as $class) {
            $attached = $service->attach($project, $author, $class->id, $document->id);
            $this->assertSame($document->id, $attached->pms_document_id);
        }
        try {
            $service->attach($project, $author, $classes[0]->id, $otherDocument->id);
            $this->fail('A class must not be moved to a second PMS.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pms_document_id', $e->errors());
        }
        try {
            $tenant->runAs($author->company_id, fn () => DB::table('piping_classes')
                ->where('id', $classes[0]->id)->update(['pms_document_id' => $otherDocument->id]), $project->id);
            $this->fail('Direct reparenting must be refused by the database.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $access->configure($project, $owner, [
            'version' => 1, 'suspend' => true, 'reason' => 'Synthetic suspension',
        ]);
        try {
            $service->attach($project, $author, $classes[0]->id, $otherDocument->id);
            $this->fail('Suspension must revoke new attachments.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pms_access', $e->errors());
        }
    }
}
