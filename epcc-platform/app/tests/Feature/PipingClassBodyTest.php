<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\PipingClassNote;
use App\Models\PipingClassRule;
use App\Models\Project;
use App\Services\PipingClassBody;
use App\Support\Piping\PipingRuleKind;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The PMS BODY — ADR-008 §11.4, §11.12 step 5.
 *
 * The axis under test is component type x qualifier x size. Two things this
 * file is really here to pin down: that one size can carry several rows of the
 * same component type (a flange sheet lists WN and BL), and that a row with no
 * qualifier cannot be entered twice — which a plain UNIQUE would have allowed
 * in PostgreSQL and nobody would have seen.
 */
final class PipingClassBodyTest extends TestCase
{
    private Project $project;

    private PipingClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EpccDemoSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\AuthSeeder', '--force' => true]);

        $this->project = app(TenantContext::class)->forIdentityLookup(
            fn (): Project => Project::where('code', 'PH-14')->firstOrFail(),
        );

        $this->class = $this->tenant(fn (): PipingClass => PipingClass::create([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'code' => 'A1A-'.Str::random(5),
            'pressure_rating' => '150#',
            'corrosion_allowance_mm' => 3.0,
            'fluid_service_category' => 'NORMAL',
        ]));
    }

    private function tenant(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs(
            $this->project->owner_company_id,
            $fn,
            $this->project->id,
        );
    }

    private function body(): PipingClassBody
    {
        return app(PipingClassBody::class);
    }

    /** @param array<string,mixed> $attrs */
    private function item(array $attrs): PipingClassItem
    {
        return $this->tenant(fn (): PipingClassItem => PipingClassItem::create(array_merge([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'piping_class_id' => $this->class->id,
        ], $attrs)));
    }

    // ------------------------------------------------------------- the axis

    public function test_one_size_carries_several_rows_of_the_same_component(): void
    {
        // A real flange sheet lists a weld neck AND a blind at the same size.
        // If the qualifier were not part of the key, the second would collide
        // with the first and the class would silently lose its blinds.
        $this->item([
            'component_type' => 'FLANGE', 'qualifier' => 'WN', 'dn' => 150,
            'material_group_code' => 'FORGINGS', 'material_spec_code' => 'A105',
        ]);
        $this->item([
            'component_type' => 'FLANGE', 'qualifier' => 'BL', 'dn' => 150,
            'material_group_code' => 'FORGINGS', 'material_spec_code' => 'A105',
        ]);

        $count = $this->tenant(fn (): int => PipingClassItem::where(
            'piping_class_id', $this->class->id
        )->count());

        self::assertSame(2, $count);
    }

    public function test_a_qualifierless_component_cannot_be_entered_twice(): void
    {
        // THE TRAP THIS KEY EXISTS FOR. In PostgreSQL two rows with
        // `qualifier IS NULL` do not collide under a plain UNIQUE, so PIPE at
        // DN 150 would have been accepted twice and neither the database nor a
        // reader would have noticed. UNIQUE NULLS NOT DISTINCT is the fix.
        $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);
    }

    public function test_the_same_component_at_a_different_size_is_a_different_row(): void
    {
        $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);
        $this->item([
            'component_type' => 'PIPE', 'dn' => 200,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);

        self::assertSame(2, $this->tenant(
            fn (): int => PipingClassItem::where('piping_class_id', $this->class->id)->count()
        ));
    }

    public function test_a_row_that_says_nothing_at_all_is_refused(): void
    {
        // Neither a material nor a description. An MTO line from this row
        // would be blank, so it is arithmetic-grade wrong rather than a
        // judgement call, and the database refuses it.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->item(['component_type' => 'PIPE', 'dn' => 150]);
    }

    // ------------------------------------------------------------- lookup

    public function test_a_size_the_class_does_not_list_is_not_permitted(): void
    {
        // Silence in a PMS means NOT PERMITTED. It must not fall back to the
        // nearest size, and classes do not inherit.
        $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);

        $this->tenant(function (): void {
            self::assertNotNull($this->body()->itemFor($this->class, 'PIPE', null, 150));
            self::assertNull($this->body()->itemFor($this->class, 'PIPE', null, 200));
        });
    }

    public function test_absence_from_the_body_means_the_class_forbids_it(): void
    {
        $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);

        $this->tenant(function (): void {
            self::assertSame(['PIPE'], $this->body()->permittedComponentTypes($this->class));
        });
    }

    // ---------------------------------------------------------- qualifiers

    public function test_a_qualifier_the_template_does_not_offer_is_reported(): void
    {
        $item = $this->item([
            'component_type' => 'FLANGE', 'qualifier' => 'SPECTACLE', 'dn' => 150,
            'material_group_code' => 'FORGINGS', 'material_spec_code' => 'A105',
        ]);

        $codes = array_column($this->body()->findingsForItem($item), 'code');

        self::assertContains(PipingClassBody::QUALIFIER_UNKNOWN, $codes);
    }

    public function test_a_qualifier_on_a_component_that_has_no_axis_is_reported(): void
    {
        // PIPE has no qualifiers. Naming one means the transcriber put
        // something in the wrong column.
        $item = $this->item([
            'component_type' => 'PIPE', 'qualifier' => 'WN', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);

        $codes = array_column($this->body()->findingsForItem($item), 'code');

        self::assertContains(PipingClassBody::QUALIFIER_NOT_APPLICABLE, $codes);
    }

    public function test_a_missing_qualifier_is_incomplete_not_a_discrepancy(): void
    {
        // Half a transcription, not a conflict.
        $item = $this->item([
            'component_type' => 'FLANGE', 'dn' => 150,
            'material_group_code' => 'FORGINGS', 'material_spec_code' => 'A105',
        ]);

        $f = $this->findingFor($item, PipingClassBody::QUALIFIER_MISSING);

        self::assertSame(PipingClassBody::SEVERITY_INCOMPLETE, $f['severity']);
    }

    public function test_a_well_formed_row_produces_no_findings(): void
    {
        $item = $this->item([
            'component_type' => 'FLANGE', 'qualifier' => 'WN', 'dn' => 150,
            'material_group_code' => 'FORGINGS', 'material_spec_code' => 'A105',
            'dimensional_standard' => 'ASME B16.5',
            'pressure_rating' => '150#', 'facing' => 'RF',
        ]);

        self::assertSame([], $this->body()->findingsForItem($item));
    }

    // ------------------------------------------------------------ material

    public function test_a_spec_the_library_does_not_carry_is_recorded_and_flagged(): void
    {
        // Recorded, not refused: the PMS is transcribed from outside.
        $item = $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A999',
            'description_as_written' => 'A999 SMLS PIPE',
        ]);

        $codes = array_column($this->body()->findingsForItem($item), 'code');

        self::assertContains(PipingClassBody::MATERIAL_UNKNOWN, $codes);
        self::assertSame('A999', $item->material_spec_code);
    }

    public function test_a_prose_only_row_is_incomplete_not_wrong(): void
    {
        $item = $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'description_as_written' => 'CS SMLS PIPE TO BE ADVISED',
        ]);

        $f = $this->findingFor($item, PipingClassBody::MATERIAL_UNRESOLVED);

        self::assertSame(PipingClassBody::SEVERITY_INCOMPLETE, $f['severity']);
    }

    public function test_a_product_form_the_spec_does_not_come_in_is_reported(): void
    {
        // A106 is a seamless pipe spec. Ordering it as a casting is a
        // transcription error that would otherwise reach a purchase order.
        $item = $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
            'material_grade_key' => 'B', 'product_form' => 'CAST',
        ]);

        $codes = array_column($this->body()->findingsForItem($item), 'code');

        self::assertContains(PipingClassBody::PRODUCT_FORM_MISMATCH, $codes);
    }

    public function test_the_transcribers_line_survives_a_resolved_material(): void
    {
        $item = $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
            'material_grade_key' => 'B', 'product_form' => 'SMLS',
            'schedule' => '40',
            'description_as_written' => 'PIPE, ASTM A106 GR.B, SMLS, SCH 40, BE',
        ]);

        self::assertTrue($item->hasResolvedMaterial());
        self::assertSame('PIPE, ASTM A106 GR.B, SMLS, SCH 40, BE', $item->label());
    }

    public function test_a_row_with_no_prose_describes_itself_from_its_references(): void
    {
        $item = $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
            'material_grade_key' => 'B', 'schedule' => '40',
        ]);

        self::assertStringContainsString('A106', $item->label());
        self::assertStringContainsString('SCH 40', $item->label());
    }

    // ------------------------------------------------- end preparation policy

    public function test_a_row_contradicting_the_class_end_policy_is_reported(): void
    {
        // §11.4: END_PREPARATION is the one genuine class-level rule. If the
        // class says butt weld at DN 150 and a flange row says socket weld,
        // the joint cannot be made and one of the two was transcribed wrongly.
        $this->tenant(fn () => PipingClassRule::create([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'piping_class_id' => $this->class->id,
            'rule_kind' => PipingRuleKind::END_PREPARATION,
            'dn' => 150, 'nps' => '6',
            'value' => ['end_type' => 'BW'],
        ]));

        $this->item([
            'component_type' => 'PIPE', 'dn' => 150, 'end_type' => 'SW',
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);

        $conflicts = $this->tenant(
            fn (): array => $this->body()->endPreparationConflicts($this->class->fresh())
        );

        self::assertCount(1, $conflicts);
        self::assertSame(PipingClassBody::END_TYPE_AGAINST_POLICY, $conflicts[0]['code']);
    }

    public function test_a_row_obeying_the_end_policy_is_silent(): void
    {
        $this->tenant(fn () => PipingClassRule::create([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'piping_class_id' => $this->class->id,
            'rule_kind' => PipingRuleKind::END_PREPARATION,
            'dn' => 150, 'nps' => '6',
            'value' => ['end_type' => 'BW'],
        ]));

        $this->item([
            'component_type' => 'PIPE', 'dn' => 150, 'end_type' => 'BW',
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
        ]);

        $conflicts = $this->tenant(
            fn (): array => $this->body()->endPreparationConflicts($this->class->fresh())
        );

        self::assertSame([], $conflicts);
    }

    // ---------------------------------------------------------------- notes

    public function test_a_row_citing_a_note_the_header_does_not_have_is_reported(): void
    {
        $item = $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
            'note_numbers' => [4],
        ]);

        $codes = array_column($this->body()->findingsForItem($item, [1, 2]), 'code');

        self::assertContains(PipingClassBody::NOTE_NOT_FOUND, $codes);
    }

    public function test_a_row_citing_a_real_note_is_silent(): void
    {
        $this->tenant(fn () => PipingClassNote::create([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'piping_class_id' => $this->class->id,
            'number' => 4,
            'text_en' => 'Stress relieve all welds.',
        ]));

        $item = $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
            'note_numbers' => [4],
        ]);

        $codes = array_column($this->body()->findingsForItem($item, [4]), 'code');

        self::assertNotContains(PipingClassBody::NOTE_NOT_FOUND, $codes);
    }

    // ----------------------------------------------------------- consumable

    public function test_a_body_with_a_discrepancy_is_not_consumable_by_mto(): void
    {
        $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A999',
            'description_as_written' => 'unknown',
        ]);

        $this->tenant(function (): void {
            self::assertFalse($this->body()->isConsumable($this->class->fresh()));
        });
    }

    public function test_a_clean_body_is_consumable(): void
    {
        $this->item([
            'component_type' => 'PIPE', 'dn' => 150,
            'material_group_code' => 'PIPE', 'material_spec_code' => 'A106',
            'material_grade_key' => 'B', 'product_form' => 'SMLS',
        ]);

        $this->tenant(function (): void {
            self::assertTrue($this->body()->isConsumable($this->class->fresh()));
        });
    }

    /** @return array{code:string,severity:string,field:string,message:string} */
    private function findingFor(PipingClassItem $item, string $code): array
    {
        foreach ($this->body()->findingsForItem($item) as $f) {
            if ($f['code'] === $code) {
                return $f;
            }
        }

        self::fail("No finding {$code}.");
    }
}
