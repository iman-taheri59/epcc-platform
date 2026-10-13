<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\Project;
use App\Services\DocumentRegister;
use App\Services\PmsAccessService;
use App\Services\PmsClassDocumentService;
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
