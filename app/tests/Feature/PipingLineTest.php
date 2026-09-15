<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\LineDocument;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\ProjectPipingConfig;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\PipingLineService;
use App\Support\CdeState;
use App\Support\LineReleaseState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-006 phase 1 - the line register and the AFC gate.
 *
 * What is genuinely at stake: AFC is the moment the office tells the site
 * "this is safe to build". A gate that can be passed without real approved
 * evidence is worse than no gate, because every report downstream then claims
 * a check happened.
 */
final class PipingLineTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);
    }

    private function service(): PipingLineService
    {
        return app(PipingLineService::class);
    }

    private function project(string $code = 'PH-14'): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    /** Run inside the owner's tenant scope, which is what RLS expects. */
    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function subsystem(string $code = 'PH-14-SS-2201'): Subsystem
    {
        return $this->asOwner(fn () => Subsystem::where('code', $code)->firstOrFail());
    }

    /** A piping class on PH-14, since classes are per project (ADR-006 §11.5). */
    private function pipingClass(string $code = 'A1'): PipingClass
    {
        $project = $this->project();

        return $this->asOwner(fn () => PipingClass::firstOrCreate(
            ['project_id' => $project->id, 'code' => $code],
            ['company_id' => $project->owner_company_id, 'description_en' => 'Carbon steel 150#'],
        ));
    }

    /** Register the services/coatings the default pattern expects. */
    private function configure(): ProjectPipingConfig
    {
        $project = $this->project();

        return $this->asOwner(function () use ($project) {
            $config = $this->service()->configFor($project);
            $config->update([
                'services' => ['P', 'CW', 'HS'],
                'coatings' => ['CT1', 'CT2'],
            ]);

            return $config;
        });
    }

    /** @param array<string, mixed> $extra */
    private function makeLine(array $extra = []): PipingLine
    {
        $this->configure();
        $class = $this->pipingClass();
        $subsystem = $this->subsystem();
        $actor = $this->owner();

        return $this->asOwner(fn () => $this->service()->create($subsystem, $actor, array_merge([
            'nominal_size' => '8',
            'service_code' => 'P',
            'piping_class_id' => $class->id,
        ], $extra)));
    }

    // ------------------------------------------------------------ create --

    public function test_it_creates_a_line_numbered_from_the_project_pattern(): void
    {
        $line = $this->makeLine();

        $this->assertSame('8-P-A1-PH-14-SS-2201-001', $line->line_number);
        $this->assertSame(1, $line->sequence);
        $this->assertSame(LineReleaseState::DRAFT, $line->release_state);
    }

    public function test_the_sequence_increments_per_subsystem(): void
    {
        $first = $this->makeLine();
        $second = $this->makeLine(['nominal_size' => '6']);

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame('6-P-A1-PH-14-SS-2201-002', $second->line_number);
    }

    public function test_each_subsystem_counts_from_one(): void
    {
        // Scope is per subsystem, so a second subsystem restarts. The
        // alternative - a project-wide counter - would make the sequence
        // meaningless on a drawing that only shows one subsystem.
        $this->makeLine();

        $other = $this->subsystem('PH-14-SS-3301');
        $class = $this->pipingClass();
        $actor = $this->owner();

        $line = $this->asOwner(fn () => $this->service()->create($other, $actor, [
            'nominal_size' => '8', 'service_code' => 'P', 'piping_class_id' => $class->id,
        ]));

        $this->assertSame(1, $line->sequence);
        $this->assertSame('8-P-A1-PH-14-SS-3301-001', $line->line_number);
    }

    public function test_a_duplicate_line_number_is_refused(): void
    {
        $line = $this->makeLine();
        $actor = $this->owner();
        $subsystem = $this->subsystem();

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->register($subsystem, $actor, $line->line_number));
    }

    public function test_the_database_also_refuses_a_duplicate(): void
    {
        // The service checks first, but the unique index is what makes a race
        // impossible rather than unlikely.
        $line = $this->makeLine();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('piping_lines')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $line->project_id,
            'company_id' => $line->company_id,
            'subsystem_id' => $line->subsystem_id,
            'line_number' => $line->line_number,
            'release_state' => LineReleaseState::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_preview_shows_the_number_without_creating_anything(): void
    {
        $this->configure();
        $class = $this->pipingClass();
        $subsystem = $this->subsystem();

        $preview = $this->asOwner(fn () => $this->service()->preview($subsystem, [
            'nominal_size' => '12', 'service_code' => 'CW', 'piping_class_id' => $class->id,
        ]));

        $this->assertSame('12-CW-A1-PH-14-SS-2201-001', $preview);
        $this->assertSame(0, $this->asOwner(fn () => PipingLine::count()), 'preview must not persist');
    }

    // ---------------------------------------------------------- register --

    public function test_an_imported_number_is_kept_exactly_as_supplied(): void
    {
        // ADR-006 §10.2: import must not invent or normalise numbers.
        $this->configure();
        $this->pipingClass();
        $subsystem = $this->subsystem();
        $actor = $this->owner();

        $line = $this->asOwner(fn () => $this->service()->register($subsystem, $actor, '8-P-A1-PH-14-SS-2201-777'));

        $this->assertSame('8-P-A1-PH-14-SS-2201-777', $line->line_number);
        $this->assertNull($line->sequence, 'we did not allocate this number, so we claim no sequence');
    }

    public function test_an_imported_number_does_not_disturb_our_counter(): void
    {
        // The crux of keeping `sequence` null on import: if 777 were recorded
        // as our sequence, the next allocation would jump to 778 and collide
        // with the external scheme forever after.
        $this->configure();
        $class = $this->pipingClass();
        $subsystem = $this->subsystem();
        $actor = $this->owner();

        $this->asOwner(fn () => $this->service()->register($subsystem, $actor, '8-P-A1-PH-14-SS-2201-777'));

        $next = $this->asOwner(fn () => $this->service()->create($subsystem, $actor, [
            'nominal_size' => '6', 'service_code' => 'P', 'piping_class_id' => $class->id,
        ]));

        $this->assertSame(1, $next->sequence);
    }

    public function test_a_non_conforming_number_is_stored_not_rejected(): void
    {
        // A real project always has legacy or vendor-package tags that predate
        // the scheme. Refusing them would mean the register does not describe
        // the plant; what matters is that they are visible AS non-conforming.
        $this->configure();
        $subsystem = $this->subsystem();
        $actor = $this->owner();

        $line = $this->asOwner(fn () => $this->service()->register($subsystem, $actor, 'LEGACY-TAG-001'));

        $this->assertSame('LEGACY-TAG-001', $line->line_number);
        $this->assertNull($line->number_tokens, 'nothing could be parsed, and we do not pretend otherwise');

        $parsed = $this->asOwner(fn () => $this->service()->parse($this->project(), $subsystem, 'LEGACY-TAG-001'));
        $this->assertFalse($parsed->matched);
    }

    public function test_an_empty_number_is_refused(): void
    {
        $subsystem = $this->subsystem();
        $actor = $this->owner();

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->register($subsystem, $actor, '   '));
    }

    public function test_parsing_uses_the_projects_own_registers(): void
    {
        $this->configure();
        $this->pipingClass();
        $subsystem = $this->subsystem();

        $good = $this->asOwner(fn () => $this->service()->parse($this->project(), $subsystem, '8-P-A1-PH-14-SS-2201-001'));
        $this->assertTrue($good->isValid());

        // B2 is a perfectly plausible class - just not registered on PH-14.
        $bad = $this->asOwner(fn () => $this->service()->parse($this->project(), $subsystem, '8-P-B2-PH-14-SS-2201-001'));
        $this->assertTrue($bad->matched);
        $this->assertSame(['class' => 'B2'], $bad->unknownValues);
    }

    // --------------------------------------------------------- AFC gate ---

    /** Attach an approved isometric and P&ID so the gate can pass. */
    private function attachApprovedEvidence(PipingLine $line, string $suitability = 'A1'): void
    {
        $actor = $this->owner();
        $project = $this->project();

        $this->asOwner(function () use ($line, $actor, $project, $suitability): void {
            foreach ([LineDocument::ISOMETRIC, LineDocument::PID] as $i => $kind) {
                $doc = Document::create([
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'number' => "PH-14-PIP-{$kind}-000{$i}",
                    'discipline' => 'PIPING',
                    'doc_type' => 'DWG',
                    'sequence' => 900 + $i,
                    'title_en' => "Evidence {$kind}",
                    'title_fa' => "مدرک {$kind}",
                ]);

                $rev = DocumentRevision::create([
                    'document_id' => $doc->id,
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'revision' => 'A',
                    'ordinal' => 1,
                    'cde_state' => CdeState::PUBLISHED,
                    'suitability' => $suitability,
                    'issued_by' => $actor->id,
                    'issued_at' => now(),
                ]);

                $doc->update(['current_revision_id' => $rev->id]);

                $this->service()->attachEvidence($line, $actor, $doc, $kind);
            }
        });
    }

    public function test_a_new_line_is_draft_and_not_buildable(): void
    {
        $line = $this->makeLine();

        $this->assertFalse($line->isBuildable());
        $this->assertFalse($line->isAfc());
        $this->assertTrue($line->isEditable());
    }

    public function test_afc_is_refused_when_evidence_is_missing(): void
    {
        $line = $this->makeLine();
        $actor = $this->owner();

        try {
            $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor));
            $this->fail('AFC must not pass with no evidence at all');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['release_state'] ?? []);
            $this->assertStringContainsString('ISOMETRIC', $message);
            $this->assertStringContainsString('PID', $message);
        }
    }

    public function test_afc_passes_with_approved_evidence(): void
    {
        $line = $this->makeLine();
        $this->attachApprovedEvidence($line);
        $actor = $this->owner();

        $confirmed = $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor, 'Checked against Rev A'));

        $this->assertSame(LineReleaseState::AFC_CONFIRMED, $confirmed->release_state);
        $this->assertNotNull($confirmed->afc_confirmed_at);
        $this->assertSame($actor->id, $confirmed->afc_confirmed_by);
        $this->assertTrue($confirmed->isAfc());
        $this->assertFalse($confirmed->isBuildable(), 'approved is not the same as issued');
    }

    public function test_a_b_coded_revision_does_not_pass_the_gate(): void
    {
        // THE TRAP THIS GATE EXISTS FOR.
        // B1 is PUBLISHED and entirely legitimate - it means "approved WITH
        // COMMENTS". Treating PUBLISHED alone as approval would wave every
        // commented drawing through to the field, and the comment resurfaces
        // as rework at handover.
        $line = $this->makeLine();
        $this->attachApprovedEvidence($line, 'B1');
        $actor = $this->owner();

        try {
            $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor));
            $this->fail('a B-coded revision must not satisfy AFC');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'not at an approved revision',
                implode(' ', $e->errors()['release_state'] ?? []),
            );
        }
    }

    public function test_an_unpublished_revision_does_not_pass_the_gate(): void
    {
        $line = $this->makeLine();
        $actor = $this->owner();
        $project = $this->project();

        $this->asOwner(function () use ($line, $actor, $project): void {
            foreach ([LineDocument::ISOMETRIC, LineDocument::PID] as $i => $kind) {
                $doc = Document::create([
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'number' => "PH-14-PIP-SHARED-000{$i}",
                    'discipline' => 'PIPING',
                    'doc_type' => 'DWG',
                    'sequence' => 950 + $i,
                    'title_en' => 'Under review',
                    'title_fa' => 'در حال بررسی',
                ]);
                $rev = DocumentRevision::create([
                    'document_id' => $doc->id,
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'revision' => 'A',
                    'ordinal' => 1,
                    'cde_state' => CdeState::SHARED,
                    'suitability' => 'S2',
                    'issued_by' => $actor->id,
                    'issued_at' => now(),
                ]);
                $doc->update(['current_revision_id' => $rev->id]);
                $this->service()->attachEvidence($line, $actor, $doc, $kind);
            }
        });

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor));
    }

    public function test_a_shared_revision_with_an_a_code_still_fails_the_gate(): void
    {
        // Isolates the CDE-STATE half of the approval test.
        //
        // The obvious "not approved" fixture is SHARED + S2, but that proves
        // less than it appears to: S2 already fails the A-family check, so the
        // state condition is never exercised and could be deleted without any
        // test noticing. Found by mutation M8 - removing the PUBLISHED check
        // entirely left the suite green.
        //
        // SHARED + A1 is the case that separates them. The suitability is
        // right, the container is not: a drawing issued for review is not
        // buildable however it is coded.
        $line = $this->makeLine();
        $actor = $this->owner();
        $project = $this->project();

        $this->asOwner(function () use ($line, $actor, $project): void {
            foreach ([LineDocument::ISOMETRIC, LineDocument::PID] as $i => $kind) {
                $doc = Document::create([
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'number' => "PH-14-PIP-SHAREDA-000{$i}",
                    'discipline' => 'PIPING',
                    'doc_type' => 'DWG',
                    'sequence' => 970 + $i,
                    'title_en' => 'Shared but A-coded',
                    'title_fa' => 'اشتراکی با کد A',
                ]);
                $rev = DocumentRevision::create([
                    'document_id' => $doc->id,
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'revision' => 'A',
                    'ordinal' => 1,
                    'cde_state' => CdeState::SHARED,
                    'suitability' => 'A1',
                    'issued_by' => $actor->id,
                    'issued_at' => now(),
                ]);
                $doc->update(['current_revision_id' => $rev->id]);
                $this->service()->attachEvidence($line, $actor, $doc, $kind);
            }
        });

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor));
    }

    public function test_approval_needs_both_conditions(): void
    {
        // The truth table, asserted directly on the predicate so neither half
        // can rot unnoticed.
        $this->assertTrue(CdeState::isApproved(CdeState::PUBLISHED, 'A1'));
        $this->assertFalse(CdeState::isApproved(CdeState::PUBLISHED, 'B1'), 'B = approved with comments');
        $this->assertFalse(CdeState::isApproved(CdeState::SHARED, 'A1'), 'shared is issued for review');
        $this->assertFalse(CdeState::isApproved(CdeState::PUBLISHED, null), 'no code is no decision');
        $this->assertFalse(CdeState::isApproved(CdeState::PUBLISHED, ''));
        $this->assertFalse(CdeState::isApproved(CdeState::WIP, 'A1'));
    }

    public function test_release_makes_the_line_buildable(): void
    {
        $line = $this->makeLine();
        $this->attachApprovedEvidence($line);
        $actor = $this->owner();

        $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor));
        $released = $this->asOwner(fn () => $this->service()->release($line->fresh(), $actor));

        $this->assertSame(LineReleaseState::RELEASED, $released->release_state);
        $this->assertTrue($released->isBuildable());
        $this->assertNotNull($released->released_at);
        $this->assertSame($actor->id, $released->released_by);
    }

    public function test_a_draft_line_cannot_be_released_directly(): void
    {
        // Skipping AFC would make the gate optional, which is the same as not
        // having one.
        $line = $this->makeLine();
        $actor = $this->owner();

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->release($line, $actor));
    }

    public function test_a_line_can_be_pulled_back_with_a_reason(): void
    {
        $line = $this->makeLine();
        $this->attachApprovedEvidence($line);
        $actor = $this->owner();

        $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor));
        $this->asOwner(fn () => $this->service()->release($line->fresh(), $actor));

        $reverted = $this->asOwner(fn () => $this->service()->revertToDraft($line->fresh(), $actor, 'Design change, Rev B issued'));

        $this->assertSame(LineReleaseState::DRAFT, $reverted->release_state);
        $this->assertNull($reverted->afc_confirmed_at, 'the approval is cancelled, not merely hidden');
        $this->assertNull($reverted->released_at);
        $this->assertSame('Design change, Rev B issued', $reverted->release_note);
    }

    public function test_pulling_a_line_back_requires_a_reason(): void
    {
        $line = $this->makeLine();
        $this->attachApprovedEvidence($line);
        $actor = $this->owner();
        $this->asOwner(fn () => $this->service()->confirmAfc($line, $actor));

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->revertToDraft($line->fresh(), $actor, '  '));
    }

    public function test_evidence_from_another_project_is_refused(): void
    {
        $line = $this->makeLine();
        $actor = $this->owner();

        $kish = Project::where('code', 'KISH-1')->firstOrFail();
        $foreign = $this->tenant->runAs(
            $kish->owner_company_id,
            fn () => Document::where('project_id', $kish->id)->first(),
            $kish->id,
        );

        if ($foreign === null) {
            $this->markTestSkipped('fixture has no KISH-1 document');
        }

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->attachEvidence($line, $actor, $foreign, LineDocument::ISOMETRIC));
    }

    public function test_attaching_the_same_evidence_twice_is_idempotent(): void
    {
        $line = $this->makeLine();
        $this->attachApprovedEvidence($line);

        $before = $this->asOwner(fn () => LineDocument::where('piping_line_id', $line->id)->count());

        $actor = $this->owner();
        $doc = $this->asOwner(fn () => Document::where('number', 'like', 'PH-14-PIP-ISOMETRIC%')->firstOrFail());
        $this->asOwner(fn () => $this->service()->attachEvidence($line, $actor, $doc, LineDocument::ISOMETRIC));

        $after = $this->asOwner(fn () => LineDocument::where('piping_line_id', $line->id)->count());
        $this->assertSame($before, $after);
    }

    // ------------------------------------------------- database integrity --

    public function test_the_database_rejects_an_unknown_release_state(): void
    {
        $line = $this->makeLine();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->asOwner(fn () => DB::table('piping_lines')->where('id', $line->id)->update(['release_state' => 'SOMEDAY']));
    }

    public function test_the_database_rejects_afc_without_provenance(): void
    {
        // The service always records who and when, but a future import or
        // admin tool might not - so the constraint, not the service, is what
        // makes the audit trail trustworthy.
        $line = $this->makeLine();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->asOwner(fn () => DB::table('piping_lines')->where('id', $line->id)->update([
            'release_state' => LineReleaseState::AFC_CONFIRMED,
            'afc_confirmed_at' => null,
            'afc_confirmed_by' => null,
        ]));
    }

    public function test_an_archived_project_refuses_new_lines(): void
    {
        // ADR-013 joins up: the archive freeze must cover tables that did not
        // exist when it was written.
        $project = $this->project();
        $subsystem = $this->subsystem();
        $class = $this->pipingClass();
        $actor = $this->owner();
        $this->configure();

        app(\App\Services\ProjectArchiveService::class)->archive($project, $actor);

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->service()->create($subsystem->fresh(), $actor, [
            'nominal_size' => '8', 'service_code' => 'P', 'piping_class_id' => $class->id,
        ]));
    }

    public function test_the_rls_policy_hides_lines_from_a_non_participant(): void
    {
        // EPC-ARVAND is the fixture's deliberate outsider - the same company
        // BreakdownTest uses to prove facilities are hidden.
        $line = $this->makeLine();
        $outsider = \App\Models\Company::where('code', 'EPC-ARVAND')->firstOrFail();

        $count = $this->tenant->runAs($outsider->id, fn () => PipingLine::count(), $line->project_id);

        $this->assertSame(0, $count, 'a non-participant must not see the line register');
    }

    public function test_a_participant_can_read_the_register(): void
    {
        // The other half: RLS that hides everything from everyone would also
        // pass the test above.
        $line = $this->makeLine();
        $contractor = \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail();

        $count = $this->tenant->runAs($contractor->id, fn () => PipingLine::count(), $line->project_id);

        $this->assertSame(1, $count, 'the delivering contractor must see its own lines');
    }

    // ------------------------------------------------------------- i18n ---

    public function test_every_line_string_is_bilingual(): void
    {
        $en = require base_path('lang/en/epcc.php');
        $fa = require base_path('lang/fa/epcc.php');

        $this->assertSame(array_keys($en['line']), array_keys($fa['line']));

        foreach ($fa['line'] as $key => $value) {
            if (is_string($value)) {
                $this->assertNotSame('', trim($value), "fa line.{$key} is empty");
            }
        }
    }

    public function test_release_states_are_labelled_in_both_languages(): void
    {
        foreach (LineReleaseState::all() as $state) {
            $label = LineReleaseState::label($state);
            $this->assertNotSame('', trim($label['en']));
            $this->assertNotSame('', trim($label['fa']));
            $this->assertNotSame($label['en'], $label['fa'], "{$state} is not actually translated");
        }
    }
}
