<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Support\TenantType;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The nine company types, and specifically what MC means.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * `MC` was labelled "Mechanical Completion body" across the seeder, two Blade
 * views, the SRS role table and the coverage map. It actually means
 * MANAGEMENT CONTRACTOR - مدیریت پیمان - the firm the employer engages to
 * administer the contract with the contractor.
 *
 * The mistake survived because a tenant type only has to be a matching string.
 * Every behavioural test passed: MC-KARAN raised, verified and was refused
 * where expected, because the ROLE RULES were right even though the NAME was
 * wrong. Only a human reading the screen could see the error.
 *
 * So these tests assert the thing the behaviour tests structurally cannot: the
 * words shown to the user, and the closed set of codes.
 */
final class TenantTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_mc_means_management_contractor_not_mechanical_completion(): void
    {
        $label = TenantType::label(TenantType::MC);

        $this->assertSame('مدیریت پیمان', $label['fa']);
        $this->assertSame('Management contractor', $label['en']);

        // The specific wrong answer, named so the regression is unmissable.
        $this->assertStringNotContainsStringIgnoringCase('mechanical', $label['en']);
        $this->assertStringNotContainsString('تکمیل مکانیکی', $label['fa']);
    }

    public function test_no_user_facing_surface_calls_a_company_mechanical_completion(): void
    {
        /*
         * Mechanical Completion is a real term in this product - it is the
         * milestone the certificate certifies, and punch/index, the MCC print
         * view and PunchCategory all legitimately say it.
         *
         * What must never happen again is a COMPANY TYPE being labelled with
         * it. These four files are the places that render a tenant type to a
         * human; the milestone vocabulary does not belong in any of them.
         */
        $surfaces = [
            'resources/views/projects/create.blade.php',
            'resources/views/projects/team.blade.php',
        ];

        foreach ($surfaces as $rel) {
            $source = file_get_contents(base_path($rel));
            $this->assertIsString($source);

            // The MC row of the contract-role table specifically.
            $this->assertMatchesRegularExpression(
                "/'MC'\s*=>\s*\['fa'\s*=>\s*'مدیریت پیمان'/u",
                $source,
                "{$rel} must label the MC contract role مدیریت پیمان.",
            );
        }
    }

    public function test_the_fixture_company_is_a_contract_manager(): void
    {
        $mc = Company::where('code', 'MC-KARAN')->firstOrFail();

        $this->assertSame(TenantType::MC, $mc->tenant_type);
        // The company NAME described the wrong business too.
        $this->assertStringNotContainsString('Completion', $mc->name_en);
        $this->assertStringNotContainsString('تکمیل', $mc->name_fa);
        $this->assertSame('مدیریت پیمان کاران', $mc->name_fa);
    }

    public function test_every_seeded_company_has_a_known_tenant_type(): void
    {
        $types = Company::pluck('tenant_type')->unique();

        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            $this->assertTrue(
                TenantType::isValid($type),
                "Seeded tenant_type '{$type}' is not one of the nine SRS types.",
            );
        }
    }

    public function test_every_type_has_a_translated_label_in_both_languages(): void
    {
        foreach (TenantType::ALL as $type) {
            $label = TenantType::label($type);

            // A missing entry falls through to `default`, which echoes the
            // code back - so a label equal to its own code is a hole.
            $this->assertNotSame($type, $label['fa'], "{$type} has no Persian label.");
            $this->assertNotSame($type, $label['en'], "{$type} has no English label.");
        }
    }

    public function test_the_database_refuses_an_unknown_tenant_type(): void
    {
        /*
         * Straight to the driver: the point is that the CHECK constraint holds
         * even when the application layer is bypassed, which is exactly how a
         * bad type would arrive (an importer, a migration, a psql session).
         */
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('companies')->insert([
            'id' => (string) Str::uuid(),
            'code' => 'BOGUS-1',
            'tenant_type' => 'MCC',   // plausible typo for the milestone
            'name_en' => 'Bogus',
            'name_fa' => 'نامعتبر',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_accepts_every_type_the_application_believes_in(): void
    {
        // The mirror of the test above: a CHECK that is too tight is just as
        // broken as one that is too loose, and silently blocks a legal type.
        foreach (TenantType::ALL as $i => $type) {
            DB::table('companies')->insert([
                'id' => (string) Str::uuid(),
                'code' => 'TYPECHK-'.$i,
                'tenant_type' => $type,
                'name_en' => 'Type check '.$type,
                'name_fa' => 'بررسی نوع '.$type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            count(TenantType::ALL),
            DB::table('companies')->where('code', 'like', 'TYPECHK-%')->count(),
        );
    }
}
