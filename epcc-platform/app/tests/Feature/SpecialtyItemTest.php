<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\Project;
use App\Models\SpecialtyItem;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The speciality (SP) register — ADR-008 §11.9, as CORRECTED by the user on
 * 2026-09-24: «نیازی به ثبت special items در pms نیست». Special items are not
 * recorded in the PMS.
 *
 * So the register exists on its own. What the user decided about it stands
 * unchanged and is what this file defends: an SP number is unique across the
 * whole project, it is recorded on the MTO and on the datasheet, and it is
 * validated rather than allocated. The PMS body knows nothing about it.
 */
final class SpecialtyItemTest extends TestCase
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

    /** @param array<string,mixed> $attrs */
    private function sp(array $attrs = []): SpecialtyItem
    {
        return $this->tenant(fn (): SpecialtyItem => SpecialtyItem::create(array_merge([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'sp_number' => 'SP-'.Str::random(4),
            'description_en' => 'Pig trap, barrel type',
            'kind_as_written' => 'PIG TRAP',
        ], $attrs)));
    }


    // ------------------------------------------------------ the register

    public function test_an_sp_number_is_unique_across_the_whole_project(): void
    {
        // The user's decision, exactly: one register, referenced by many
        // classes. Two SP-014s in one project would make every citation
        // ambiguous.
        $this->sp(['sp_number' => 'SP-014']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->sp(['sp_number' => 'SP-014']);
    }

    public function test_the_same_sp_number_may_exist_in_another_project(): void
    {
        // Uniqueness is per project, not global: two clients both numbering
        // from SP-001 is normal and must not collide.
        $this->sp(['sp_number' => 'SP-014']);

        $other = app(TenantContext::class)->forIdentityLookup(
            fn (): Project => Project::where('code', '!=', 'PH-14')->firstOrFail(),
        );

        $made = app(TenantContext::class)->runAs(
            $other->owner_company_id,
            fn (): SpecialtyItem => SpecialtyItem::create([
                'project_id' => $other->id,
                'company_id' => $other->owner_company_id,
                'sp_number' => 'SP-014',
                'description_en' => 'A different special entirely',
            ]),
            $other->id,
        );

        self::assertSame('SP-014', $made->sp_number);
    }

    public function test_a_blank_sp_number_is_refused(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->sp(['sp_number' => '   ']);
    }

    public function test_the_pms_body_carries_no_reference_to_a_special(): void
    {
        // User instruction, 2026-09-24: special items are not recorded in the
        // PMS. The register exists for the MTO and the datasheet; the class
        // body is component type x qualifier x size and nothing else.
        $columns = array_column(\Illuminate\Support\Facades\DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = 'piping_class_items'"
        ), 'column_name');

        self::assertNotContains('specialty_item_id', $columns);
    }

    public function test_a_special_may_be_registered_without_any_piping_class(): void
    {
        // The register stands alone. Nothing about an SP item depends on a
        // class mentioning it, because no class ever will.
        $sp = $this->sp(['sp_number' => 'SP-101']);

        self::assertTrue($sp->exists);
        self::assertTrue($sp->isDescribed());
    }

    public function test_a_bare_sp_number_is_not_yet_describable(): void
    {
        // A number with nothing behind it cannot be put on an MTO line or a
        // datasheet, which are the two places the user said it must appear.
        $sp = $this->sp(['description_en' => null, 'kind_as_written' => null]);

        self::assertFalse($sp->isDescribed());
    }

    public function test_a_datasheet_can_be_attached_to_an_sp_number(): void
    {
        // The user's instruction: one number, two uses - the MTO and the
        // datasheet. This is the second use.
        $doc = $this->tenant(fn () => \App\Models\Document::query()->first());

        if ($doc === null) {
            self::markTestSkipped('The demo seeder registered no documents.');
        }

        $sp = $this->sp(['datasheet_document_id' => $doc->id, 'is_tagged' => true]);

        self::assertSame($doc->id, $this->tenant(
            fn () => $sp->fresh()->datasheet_document_id
        ));
    }

    public function test_nothing_allocates_an_sp_number(): void
    {
        // Validated, never allocated. The number is born in the client's
        // document, exactly like a piping class code.
        $methods = get_class_methods(SpecialtyItem::class);

        foreach ($methods as $m) {
            self::assertStringNotContainsStringIgnoringCase(
                'next', $m,
                "SpecialtyItem::{$m}() looks like an allocator."
            );
            self::assertStringNotContainsStringIgnoringCase('allocate', $m);
        }
    }

    // ------------------------------------------------ the second row kind







    // --------------------------------------------------------- findings





}
