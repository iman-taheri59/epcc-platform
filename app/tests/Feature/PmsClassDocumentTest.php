<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Support\CdeState;
use Illuminate\Support\Str;
use App\Models\Project;
use App\Models\Subsystem;
use App\Services\DocumentRegister;
use App\Services\PmsAccessService;
use App\Services\PmsClassDocumentService;
use App\Services\PmsLineEligibility;
use App\Services\PmsPinnedClassSpecification;
use App\Services\PmsRevisionDraftService;
use App\Services\PipingLineService;
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
        // Numbering config is owner-authored; the contractor may consume it
        // but cannot create it under the owner's company_id (RLS WITH CHECK).
        $tenant->runAs($owner->company_id,
            fn () => app(PipingLineService::class)->configFor($project)
                ->update(['services' => ['P']]), $project->id);
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
            DB::table('piping_class_rules')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id,
                'company_id' => $author->company_id, 'piping_class_id' => $class->id,
                'rule_kind' => 'PIPE_SCHEDULE', 'dn_range' => '[15,600)',
                'value' => json_encode(['schedule' => '80'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $class->update(['state' => 'PUBLISHED']); // Synthetic fixture: real publish gate has separate tests.
        }, $project->id);
        $tenant->runAs($author->company_id, function () use ($register, $doc, $author, $project, $class): void {
            $revision = $register->transition($doc->refresh(), $author, CdeState::PUBLISHED, 'A1');
            $row = DB::table('pms_class_revision_snapshots')->where('document_revision_id', $revision->id)->first();
            $this->assertNotNull($row);
            $this->assertSame($class->id, $row->piping_class_id);
            $spec = json_decode($row->specification, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('80', $spec['rules'][0]['value']['schedule']);
            try {
                DB::transaction(fn () => DB::table('piping_class_rules')->where('piping_class_id', $class->id)
                    ->update(['value' => json_encode(['schedule' => '40'], JSON_THROW_ON_ERROR)]));
                $this->fail('A published PMS rule must be read-only even to raw SQL.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
            try {
                DB::transaction(fn () => DB::table('piping_classes')->where('id', $class->id)
                    ->update(['design_pressure_mpa' => 99]));
                $this->fail('A published PMS class header must be read-only.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
            try {
                DB::transaction(fn () => DB::table('piping_class_branch_rules')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id,
                    'company_id' => $author->company_id, 'piping_class_id' => $class->id,
                    'header_range' => '[100,150)', 'branch_range' => '[25,50)',
                    'component_type' => 'TEE', 'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('A published PMS cannot gain a new branch rule via SQL.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
            try {
                DB::transaction(fn () => DB::table('piping_class_rules')->where('piping_class_id', $class->id)->delete());
                $this->fail('A published PMS rule cannot be deleted via SQL.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
            $unchanged = json_decode(DB::table('pms_class_revision_snapshots')->where('id', $row->id)->value('specification'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('80', $unchanged['rules'][0]['value']['schedule']);
            $resolver = app(PmsLineEligibility::class);
            $this->assertSame($row->id, $resolver->snapshotId($project->id, $class->id));
            $this->assertSame([$class->id], $resolver->eligibleClassIds($project->id));
            $subsystem = Subsystem::where('project_id', $project->id)->firstOrFail();
            $line = app(PipingLineService::class)->register(
                $subsystem, $author, 'SNAPSHOT-TEST-1', ['piping_class_id' => $class->id]
            );
            $this->assertSame($row->id, $line->pms_class_snapshot_id);
            $allocated = app(PipingLineService::class)->create($subsystem, $author, [
                'nominal_size' => '8', 'service_code' => 'P', 'piping_class_id' => $class->id,
            ]);
            $this->assertSame($row->id, $allocated->pms_class_snapshot_id);
            $this->assertSame(2, DB::table('piping_lines')->where('pms_class_snapshot_id', $row->id)->count());
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
        $tenant->runAs($author->company_id, function () use ($register, $doc, $author, $owner, $project, $class): void {
            $next = $register->issueRevision($doc->refresh(), $author, 'B');
            $draft = DB::table('pms_class_revision_drafts')
                ->where('document_revision_id', $next->id)->where('piping_class_id', $class->id)->first();
            $this->assertNotNull($draft);
            $baseline = json_decode($draft->specification, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('80', $baseline['rules'][0]['value']['schedule']);
            try {
                app(PmsRevisionDraftService::class)->changePipeSchedule(
                    $project, $owner, $doc->id, $class->id, $baseline['rules'][0]['id'], '40');
                $this->fail('The employer is not the nominated author of the contractor PMS.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('pms_access', $e->errors());
            }
            $tampered = $baseline;
            $tampered['rules'][0]['dn_range'] = '[15,800)';
            DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                ->update(['specification' => json_encode($tampered, JSON_THROW_ON_ERROR)]);
            try {
                app(PmsRevisionDraftService::class)->changePipeSchedule(
                    $project, $author, $doc->id, $class->id, $baseline['rules'][0]['id'], '40');
                $this->fail('An unauthorized DN-range edit must not hitchhike on a schedule change.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('specification', $e->errors());
            }
            DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                ->update(['specification' => $draft->specification]);
            $edited = app(PmsRevisionDraftService::class)->changePipeSchedule(
                $project, $author, $doc->id, $class->id, $baseline['rules'][0]['id'], 'STD');
            $this->assertSame('STD', $edited['rules'][0]['value']['schedule']);
            $this->assertSame('STD', json_decode(DB::table('pms_class_revision_drafts')
                ->where('id', $draft->id)->value('specification'), true, 512, JSON_THROW_ON_ERROR)['rules'][0]['value']['schedule']);
            $this->assertSame('80', json_decode(DB::table('pms_class_revision_snapshots')
                ->where('piping_class_id', $class->id)->value('specification'), true, 512, JSON_THROW_ON_ERROR)['rules'][0]['value']['schedule']);
            // SCH 40 is unavailable at DN550 (NPS22) in the reference pack;
            // this copy has a DN span up to 600, so review must reject it.
            $badSchedule = $edited;
            $badSchedule['rules'][0]['value']['schedule'] = '40';
            DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                ->update(['specification' => json_encode($badSchedule, JSON_THROW_ON_ERROR)]);
            try {
                $register->transition($doc->refresh(), $author, CdeState::SHARED, 'S2');
                $this->fail('A schedule missing from the size pack cannot be shared.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('DN550', implode(' ', $e->errors()['specification'] ?? []));
            }
            $reviewTamper = $edited;
            $reviewTamper['branch_rules'][] = ['id' => 'invented-branch'];
            DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                ->update(['specification' => json_encode($reviewTamper, JSON_THROW_ON_ERROR)]);
            try {
                $register->transition($doc->refresh(), $author, CdeState::SHARED, 'S2');
                $this->fail('A forged branch rule must block sharing the entire PMS revision.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('specification', $e->errors());
            }
            $this->assertSame(CdeState::WIP, $doc->refresh()->currentRevision->cde_state);
            DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                ->update(['specification' => json_encode($edited, JSON_THROW_ON_ERROR)]);
            $register->transition($doc->refresh(), $author, CdeState::SHARED, 'S2');
            try {
                app(PmsRevisionDraftService::class)->changePipeSchedule(
                    $project, $author, $doc->id, $class->id, $baseline['rules'][0]['id'], '80');
                $this->fail('The nominated author cannot change a SHARED revision.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('revision', $e->errors());
            }
            try {
                DB::transaction(fn () => DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                    ->update(['specification' => $draft->specification]));
                $this->fail('A shared PMS revision draft cannot be silently edited.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
            $this->assertSame([], app(PmsLineEligibility::class)->eligibleClassIds($project->id));
            $oldPin = DB::table('pms_class_revision_snapshots')
                ->where('piping_class_id', $class->id)->value('id');
            $reviewed = json_decode(DB::table('pms_class_revision_drafts')
                ->where('id', $draft->id)->value('specification'), true, 512, JSON_THROW_ON_ERROR);
            $oldSpec = json_decode(DB::table('pms_class_revision_snapshots')
                ->where('id', $oldPin)->value('specification'), true, 512, JSON_THROW_ON_ERROR);
            $forged = $reviewed;
            $forged['rules'][0]['dn_range'] = '[15,800)';
            $this->assertTrue((bool) DB::selectOne('SELECT epcc_pms_schedule_only_delta(?::jsonb, ?::jsonb) AS ok',
                [json_encode($oldSpec), json_encode($reviewed)])->ok);
            $this->assertFalse((bool) DB::selectOne('SELECT epcc_pms_schedule_only_delta(?::jsonb, ?::jsonb) AS ok',
                [json_encode($oldSpec), json_encode($forged)])->ok);
            try {
                DB::transaction(function () use ($next): void {
                    DB::table('document_revisions')->where('id', $next->id)->update([
                        'cde_state' => CdeState::PUBLISHED, 'suitability' => 'A1',
                    ]);
                    // Force the deferred constraint inside a savepoint so an
                    // expected rejection does not poison the outer RLS scope.
                    DB::statement('SET CONSTRAINTS pms_approved_revision_snapshots_required IMMEDIATE');
                });
                $this->fail('Direct SQL cannot approve a PMS without class snapshots.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
            $this->assertSame(CdeState::SHARED, $doc->refresh()->currentRevision->cde_state);
            $register->transition($doc->refresh(), $author, CdeState::PUBLISHED, 'A1');
            $newSnapshot = DB::table('pms_class_revision_snapshots')
                ->where('document_revision_id', $next->id)->where('piping_class_id', $class->id)->first();
            $this->assertNotNull($newSnapshot);
            $this->assertNotSame($oldPin, $newSnapshot->id);
            $this->assertSame('STD', json_decode($newSnapshot->specification, true, 512, JSON_THROW_ON_ERROR)['rules'][0]['value']['schedule']);
            $this->assertSame('80', json_decode(DB::table('pms_class_revision_snapshots')
                ->where('id', $oldPin)->value('specification'), true, 512, JSON_THROW_ON_ERROR)['rules'][0]['value']['schedule']);
            $this->assertSame($newSnapshot->id, app(PmsLineEligibility::class)->snapshotId($project->id, $class->id));
            try {
                DB::transaction(fn () => DB::table('pms_class_revision_snapshots')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id,
                    'company_id' => $author->company_id,
                    'pms_document_id' => $doc->id, 'document_revision_id' => $next->id,
                    'piping_class_id' => $class->id,
                    'specification' => json_encode($forged, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]));
                $this->fail('Raw SQL must not forge a different reviewed class snapshot.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
            $newLine = app(PipingLineService::class)->register(
                Subsystem::where('project_id', $project->id)->firstOrFail(),
                $author, 'SNAPSHOT-TEST-2', ['piping_class_id' => $class->id],
            );
            $this->assertSame($newSnapshot->id, $newLine->pms_class_snapshot_id);
            $issued = app(PmsPinnedClassSpecification::class);
            $oldLine = PipingLine::where('pms_class_snapshot_id', $oldPin)->firstOrFail();
            $this->assertSame('80', $issued->pipeSchedule($oldLine, 100));
            $this->assertSame('STD', $issued->pipeSchedule($newLine, 100));
            $this->assertSame('STD', $issued->ruleValue($newLine, 'PIPE_SCHEDULE', 100)['schedule']);
            $this->assertSame('80', app(\App\Services\PipingClassService::class)
                ->ruleFor($class, 'PIPE_SCHEDULE', 100)->value['schedule'],
                'The live published class is frozen; line rules must follow the issue-time pin.');
            $this->assertSame(2, DB::table('piping_lines')->where('pms_class_snapshot_id', $oldPin)->count());
            $this->assertSame(1, DB::table('piping_lines')->where('pms_class_snapshot_id', $newSnapshot->id)->count());

            // A published B-suitability revision is legitimate CDE history,
            // but cannot replace the last A-family engineering baseline.
            $commentIssue = $register->issueRevision($doc->refresh(), $author, 'C');
            $register->transition($doc->refresh(), $author, CdeState::SHARED, 'S2');
            $register->transition($doc->refresh(), $author, CdeState::PUBLISHED, 'B1');
            $this->assertSame(0, DB::table('pms_class_revision_snapshots')
                ->where('document_revision_id', $commentIssue->id)->count());
            $this->assertSame([], app(PmsLineEligibility::class)->eligibleClassIds($project->id));
            $afterComments = $register->issueRevision($doc->refresh(), $author, 'D');
            $carried = DB::table('pms_class_revision_drafts')
                ->where('document_revision_id', $afterComments->id)
                ->where('piping_class_id', $class->id)->first();
            $this->assertNotNull($carried);
            $this->assertSame('STD', json_decode($carried->specification, true, 512, JSON_THROW_ON_ERROR)['rules'][0]['value']['schedule']);
            $register->transition($doc->refresh(), $author, CdeState::SHARED, 'S2');
            $register->transition($doc->refresh(), $author, CdeState::PUBLISHED, 'A1');
            $resumedPin = app(PmsLineEligibility::class)->snapshotId($project->id, $class->id);
            $this->assertNotSame($newSnapshot->id, $resumedPin);
            $this->assertSame('STD', json_decode(DB::table('pms_class_revision_snapshots')
                ->where('id', $resumedPin)->value('specification'), true, 512, JSON_THROW_ON_ERROR)['rules'][0]['value']['schedule']);
            $this->assertSame($newSnapshot->id, PipingLine::findOrFail($newLine->id)->pms_class_snapshot_id);
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
