<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ItpTemplate;
use App\Models\Project;
use App\Models\ProjectStandard;
use App\Models\Standard;
use App\Models\StandardCitation;
use App\Models\User;
use App\Services\ItpService;
use App\Services\StandardsLibrary;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\ItpTemplateSeeder;
use Database\Seeders\StandardsLibrarySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The library of codes and standards.
 *
 * The defect it exists to fix: a standard was a free-text string, so
 * 'ASME B31.3' and 'ASME B 31.3' were two different things, and "which
 * inspections cite B31.3?" could not be answered at all.
 */
final class StandardsLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(StandardsLibrarySeeder::class);
        $this->seed(ItpTemplateSeeder::class);
    }

    private function lib(): StandardsLibrary
    {
        return app(StandardsLibrary::class);
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return app(TenantContext::class)->forIdentityLookup(
            fn () => User::where('email', 'karimi@nioc.example')->firstOrFail()
        );
    }

    /** @return mixed */
    private function asOwner(callable $fn)
    {
        $project = $this->project();

        return app(TenantContext::class)->runAs(
            $project->owner_company_id,
            $fn,
            $project->id,
        );
    }

    // =====================================================================
    //  The catalogue
    // =====================================================================

    public function test_the_same_standard_written_four_ways_is_one_entry(): void
    {
        $before = Standard::count();

        // Every spelling an engineer plausibly types, as (body, designation)
        // pairs - which is how a form submits them.
        $spellings = [
            ['ASME', 'B31.3'],
            ['asme', 'b31.3'],
            ['ASME', ' B 31.3 '],
            ['ASME', 'B-31.3'],
        ];

        foreach ($spellings as [$body, $designation]) {
            $this->lib()->define($body, $designation, 'Process Piping');
        }

        // The seeder already holds B31.3, so none of the four may add a row.
        $this->assertSame($before, Standard::count());
    }

    public function test_normalisation_strips_what_humans_vary(): void
    {
        $this->assertSame('ASMEB313', Standard::normalise('ASME', 'B31.3'));
        $this->assertSame('ASMEB313', Standard::normalise('asme', ' b 31.3 '));
        $this->assertSame('ASMEB313', Standard::normalise('ASME', 'B-31.3'));

        // ...but genuinely different standards must not collide.
        $this->assertNotSame(
            Standard::normalise('ASME', 'B31.3'),
            Standard::normalise('ASME', 'B31.4'),
        );
    }

    public function test_a_standard_needs_a_body_and_a_designation(): void
    {
        try {
            $this->lib()->define('', 'B31.3', 'Process Piping');
            $this->fail('a standard with no issuing body should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('designation', $e->errors());
        }
    }

    public function test_an_unknown_category_is_refused(): void
    {
        try {
            $this->lib()->define('XYZ', '1', 'Nonsense', category: 'PLUMBING');
            $this->fail('an unknown category should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category', $e->errors());
        }
    }

    public function test_the_catalogue_is_shared_by_every_project(): void
    {
        // Reference data: visible without any tenant binding at all, which is
        // the difference between the catalogue and the applicability list.
        $this->assertGreaterThan(40, Standard::count());
        $this->assertNotNull(Standard::where('normalised', 'ASMEB313')->first());
        $this->assertNotNull(Standard::where('normalised', 'API5L')->first());
    }

    public function test_the_catalogue_carries_persian_titles(): void
    {
        $b313 = Standard::where('normalised', 'ASMEB313')->firstOrFail();

        $this->assertSame('لوله‌کشی فرآیندی', $b313->title_fa);
        $this->assertStringContainsString('لوله‌کشی', $b313->label('fa'));
        $this->assertStringContainsString('Process Piping', $b313->label('en'));
    }

    // =====================================================================
    //  Resolving free text - the migration path for the data we already have
    // =====================================================================

    public function test_free_text_resolves_to_a_catalogue_entry(): void
    {
        $this->assertSame('ASME B31.3', $this->lib()->resolve('ASME B31.3')?->code());
        $this->assertSame('ASME B31.3', $this->lib()->resolve('asme b 31.3')?->code());

        // A trailing clause must not defeat the match.
        $this->assertSame('ASME B31.3', $this->lib()->resolve('ASME B31.3 §323')?->code());
    }

    public function test_an_unknown_citation_resolves_to_null_rather_than_throwing(): void
    {
        // Not an error: the free-text column exists precisely for this.
        $this->assertNull($this->lib()->resolve('Project preservation procedure'));
        $this->assertNull($this->lib()->resolve(''));
    }

    public function test_a_multi_part_reference_splits_into_separate_citations(): void
    {
        $parsed = $this->lib()->parseReference('EN 10204 · ASME B31.3 §323');

        $this->assertCount(2, $parsed['matched']);
        $this->assertSame([], $parsed['unmatched']);
        $this->assertSame('EN 10204', $parsed['matched'][0]['standard']->code());
        $this->assertSame('ASME B31.3', $parsed['matched'][1]['standard']->code());
        $this->assertSame('§323', $parsed['matched'][1]['clause']);
    }

    public function test_the_issuing_body_carries_across_a_list(): void
    {
        // 'ASME B36.10M · B16.9 · B16.5' means ASME three times - how every
        // engineer writes it, and worthless if only the first one resolves.
        $parsed = $this->lib()->parseReference('ASME B36.10M · B16.9 · B16.5');

        $this->assertSame([], $parsed['unmatched']);
        $this->assertSame(
            ['ASME B36.10M', 'ASME B16.9', 'ASME B16.5'],
            array_map(fn ($m) => $m['standard']->code(), $parsed['matched']),
        );
    }

    public function test_what_does_not_resolve_is_returned_not_dropped(): void
    {
        $parsed = $this->lib()->parseReference('NACE SP0490 · project coating spec');

        $this->assertCount(1, $parsed['matched']);
        // Losing a reference during migration would be worse than leaving it
        // in the free-text column.
        $this->assertSame(['project coating spec'], $parsed['unmatched']);
    }

    // =====================================================================
    //  Project applicability, and the edition question
    // =====================================================================

    public function test_a_project_adopts_a_standard_at_its_own_edition(): void
    {
        $this->asOwner(function (): void {
            $b313 = Standard::where('normalised', 'ASMEB313')->firstOrFail();

            $adoption = $this->lib()->adopt($this->project(), $b313, '2020');

            $this->assertSame('2020', $adoption->edition);
            $this->assertSame('ASME B31.3-2020', $adoption->citation());
        });
    }

    public function test_updating_the_catalogue_does_not_move_a_pinned_project(): void
    {
        $this->asOwner(function (): void {
            $b313 = Standard::where('normalised', 'ASMEB313')->firstOrFail();
            $this->lib()->adopt($this->project(), $b313, '2020');

            // The platform tidies the catalogue forward.
            $b313->update(['edition' => '2026']);

            $adoption = ProjectStandard::where('standard_id', $b313->id)->firstOrFail();

            // The project's contract says 2020. It must still say 2020.
            $this->assertSame('2020', $adoption->fresh()->effectiveEdition());
            $this->assertTrue($adoption->fresh()->isPinnedBehindCatalogue());
        });
    }

    public function test_an_unpinned_adoption_follows_the_catalogue(): void
    {
        $this->asOwner(function (): void {
            $b313 = Standard::where('normalised', 'ASMEB313')->firstOrFail();
            $adoption = $this->lib()->adopt($this->project(), $b313);

            $this->assertNull($adoption->edition);
            // Honest for the many standards nobody has taken a position on.
            $this->assertSame($b313->edition, $adoption->effectiveEdition());
            $this->assertFalse($adoption->isPinnedBehindCatalogue());
        });
    }

    public function test_adopting_twice_updates_rather_than_duplicating(): void
    {
        $this->asOwner(function (): void {
            $b313 = Standard::where('normalised', 'ASMEB313')->firstOrFail();

            $this->lib()->adopt($this->project(), $b313, '2020');
            $this->lib()->adopt($this->project(), $b313, '2022');

            // "Which edition are we bound to" must have exactly one answer.
            $this->assertSame(1, ProjectStandard::where('standard_id', $b313->id)->count());
            $this->assertSame('2022', ProjectStandard::where('standard_id', $b313->id)->firstOrFail()->edition);
        });
    }

    public function test_a_withdrawn_standard_cannot_be_adopted(): void
    {
        $this->asOwner(function (): void {
            $s = Standard::where('normalised', 'ASMEB313')->firstOrFail();
            $this->lib()->withdraw($s);

            try {
                $this->lib()->adopt($this->project(), $s->fresh());
                $this->fail('a withdrawn standard should not be adoptable');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('standard_id', $e->errors());
            }
        });
    }

    public function test_withdrawing_never_deletes(): void
    {
        $s = Standard::where('normalised', 'ASMEB313')->firstOrFail();
        $this->lib()->withdraw($s);

        // A record signed in 1998 cites what it cites: history must stay
        // readable, so the row survives and only leaves the picker.
        $this->assertNotNull(Standard::find($s->id));
        $this->assertFalse(Standard::find($s->id)->is_active);
        $this->assertFalse($this->lib()->catalogue()->contains('id', $s->id));
    }

    // =====================================================================
    //  Citations from an ITP row
    // =====================================================================

    public function test_citing_a_standard_also_adopts_it_for_the_project(): void
    {
        $this->asOwner(function (): void {
            $awsd11 = Standard::where('normalised', 'AWSD11')->firstOrFail();
            $itp = app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $stage = $itp->stages->first();

            $this->assertSame(0, ProjectStandard::where('standard_id', $awsd11->id)->count());

            $this->lib()->cite(
                $this->project(), $awsd11, StandardCitation::ITP_STAGE, $stage->id, 'Clause 6'
            );

            // Citing IS adopting: otherwise an engineer writing an ITP gets
            // refused and has to go and edit a settings page first.
            $this->assertSame(1, ProjectStandard::where('standard_id', $awsd11->id)->count());
        });
    }

    public function test_citing_the_same_standard_twice_is_idempotent(): void
    {
        $this->asOwner(function (): void {
            $s = Standard::where('normalised', 'AWSD11')->firstOrFail();
            $itp = app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $stage = $itp->stages->first();

            $this->lib()->cite($this->project(), $s, StandardCitation::ITP_STAGE, $stage->id, 'Clause 6');
            $this->lib()->cite($this->project(), $s, StandardCitation::ITP_STAGE, $stage->id);

            $citations = StandardCitation::where('citable_id', $stage->id)
                ->where('standard_id', $s->id)->get();

            $this->assertCount(1, $citations);
            // A second call without a clause must not erase the one somebody typed.
            $this->assertSame('Clause 6', $citations->first()->clause);
        });
    }

    public function test_an_unknown_citable_type_is_refused(): void
    {
        $this->asOwner(function (): void {
            $s = Standard::where('normalised', 'ASMEB313')->firstOrFail();

            try {
                $this->lib()->cite($this->project(), $s, 'SPACESHIP', (string) \Illuminate\Support\Str::uuid());
                $this->fail('an unknown citable type should be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('citable_type', $e->errors());
            }
        });
    }

    // =====================================================================
    //  The ITP integration - the user's actual request
    // =====================================================================

    public function test_an_itp_built_from_a_template_arrives_already_structured(): void
    {
        $this->asOwner(function (): void {
            $itp = app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            $stage = $itp->fresh(['stages.standardCitations.standard'])
                ->stages->firstWhere('clause', '1.1');

            // 'EN 10204 · ASME B31.3 §323' became two library citations.
            $this->assertCount(2, $stage->standardCitations);
            $this->assertSame(
                ['EN 10204', 'ASME B31.3'],
                $stage->standardCitations->map(fn ($c) => $c->standard->code())->all(),
            );
            $this->assertSame('§323', $stage->standardCitations->last()->clause);
        });
    }

    public function test_the_printed_column_is_unchanged_by_structuring_it(): void
    {
        $this->asOwner(function (): void {
            $itp = app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            $stage = $itp->fresh(['stages.standardCitations.standard'])
                ->stages->firstWhere('clause', '1.1');

            // The engineer must see what they always saw.
            $this->assertSame('EN 10204 · ASME B31.3 §323', $stage->printedStandards());

            // Fully consumed by the library, so the free-text column must be
            // NULL - not an empty string. '' would print a stray separator and
            // would make "has a free-text remainder" untestable.
            $this->assertNull($stage->reference);
        });
    }

    public function test_a_reference_the_library_does_not_know_stays_as_free_text(): void
    {
        $this->asOwner(function (): void {
            $itp = app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            $stage = $itp->fresh(['stages.standardCitations.standard'])
                ->stages->firstWhere('clause', '1.5');

            // 'Project preservation procedure' is not a standard, and the row
            // must not lose it. Refusing the row until an administrator adds
            // it to the library would stop inspection work.
            $this->assertCount(0, $stage->standardCitations);
            $this->assertSame('Project preservation procedure', $stage->reference);
            $this->assertNotSame('', $stage->reference);
            $this->assertSame('Project preservation procedure', $stage->printedStandards());
        });
    }

    public function test_a_row_that_is_half_known_keeps_both_halves(): void
    {
        $this->asOwner(function (): void {
            $itp = app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            // The mixed case, and the one the template library happens not to
            // contain: a row citing a real standard AND something only this
            // project knows about. Neither half may be lost - the citation
            // must be structured, and the remainder must stay readable.
            $stage = app(ItpService::class)->addStage($itp, $this->owner(), [
                'clause' => '9.9',
                'title_en' => 'Hydrotest',
                'title_fa' => 'آزمون هیدرواستاتیک',
                'reference' => 'ASME B31.3 §345 · client hydrotest procedure HT-7',
                'parties' => ['OWN' => 'W'],
            ]);

            $stage = $stage->fresh('standardCitations.standard');

            $this->assertCount(1, $stage->standardCitations);
            $this->assertSame('ASME B31.3', $stage->standardCitations->first()->standard->code());
            $this->assertSame('§345', $stage->standardCitations->first()->clause);

            // The unmatched half survives, and is neither null nor an empty
            // string - it is the text somebody typed.
            $this->assertSame('client hydrotest procedure HT-7', $stage->reference);
            $this->assertSame(
                'ASME B31.3 §345 · client hydrotest procedure HT-7',
                $stage->printedStandards(),
            );
        });
    }

    public function test_the_project_list_is_built_from_what_the_itps_cite(): void
    {
        $this->asOwner(function (): void {
            app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            $adopted = $this->lib()->forProject($this->project())
                ->map(fn ($ps) => $ps->standard->code())->all();

            // Nobody typed these in: building the ITP produced the list.
            $this->assertContains('ASME B31.3', $adopted);
            $this->assertContains('EN 10204', $adopted);
            $this->assertContains('API RP 578', $adopted);
        });
    }

    public function test_the_printed_citation_uses_the_projects_edition(): void
    {
        $this->asOwner(function (): void {
            $itp = app(ItpService::class)->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $b313 = Standard::where('normalised', 'ASMEB313')->firstOrFail();
            $this->lib()->adopt($this->project(), $b313, '2020');

            $stage = $itp->fresh(['stages.standardCitations.standard'])
                ->stages->firstWhere('clause', '1.3');
            $citation = $stage->standardCitations->first();
            $adoption = ProjectStandard::where('standard_id', $b313->id)->firstOrFail();

            // Two projects citing the same standard can legitimately print
            // two different years.
            $this->assertSame('ASME B31.3-2020 §323.1', $citation->printed($adoption));
            $this->assertSame('ASME B31.3 §323.1', $citation->printed());
        });
    }

    // =====================================================================
    //  Isolation and the screens
    // =====================================================================

    public function test_one_projects_applicability_list_is_invisible_to_another(): void
    {
        $tenant = app(TenantContext::class);
        $ph14 = $this->project();
        $bb2 = Project::where('code', 'BB-2')->firstOrFail();

        $tenant->runAs($ph14->owner_company_id, function (): void {
            $s = Standard::where('normalised', 'ASMEB313')->firstOrFail();
            $this->lib()->adopt($this->project(), $s, '2020');
        }, $ph14->id);

        $seen = $tenant->runAs(
            $bb2->owner_company_id,
            fn () => ProjectStandard::count(),
            $bb2->id,
        );

        // The catalogue is shared; the applicability list is not.
        $this->assertSame(0, $seen);
    }

    public function test_the_standards_screen_renders_in_both_languages(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => app(ItpService::class)->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        ));

        // Escaped, because Blade renders the ampersand as an entity.
        foreach (['en' => 'Codes &amp; standards', 'fa' => 'کدها و استانداردها'] as $lang => $heading) {
            $res = $this->actingAs($owner)->get("/projects/{$project->code}/standards?lang={$lang}");

            $res->assertOk();
            $res->assertSee($heading, false);
            $res->assertSee('ASME B31.3', false);
            $res->assertSee('EN 10204', false);
        }
    }

    public function test_the_usage_screen_lists_every_row_citing_a_standard(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => app(ItpService::class)->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        ));

        $b313 = Standard::where('normalised', 'ASMEB313')->firstOrFail();

        $res = $this->actingAs($owner)->get("/projects/{$project->code}/standards/{$b313->id}?lang=fa");

        $res->assertOk();
        // The list needed on the day the edition changes.
        $res->assertSee('§323', false);
        $res->assertSee('§323.1', false);
    }

    public function test_a_standard_can_be_adopted_over_http(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $awsd11 = Standard::where('normalised', 'AWSD11')->firstOrFail();

        $this->actingAs($owner)
            ->post("/projects/{$project->code}/standards/adopt?lang=fa", [
                'standard_id' => $awsd11->id,
                'edition' => '2015',
            ])
            ->assertRedirect();

        $adoption = app(TenantContext::class)->runAs(
            $project->owner_company_id,
            fn () => ProjectStandard::where('standard_id', $awsd11->id)->first(),
            $project->id,
        );

        $this->assertNotNull($adoption);
        $this->assertSame('2015', $adoption->edition);
    }

    public function test_a_non_participant_cannot_open_the_standards_screen(): void
    {
        $outsider = app(TenantContext::class)->forIdentityLookup(
            fn () => User::where('email', 'hosseini@arvand.example')->firstOrFail()
        );

        $this->actingAs($outsider)
            ->get('/projects/PH-14/standards?lang=fa')
            ->assertNotFound();
    }
}
