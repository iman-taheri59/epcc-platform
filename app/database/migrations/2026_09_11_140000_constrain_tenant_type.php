<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pin companies.tenant_type to the nine types in SRS §3.1.
 *
 * The column has been string(8) with a comment saying "9 tenant types" since
 * the first migration. A comment is not a constraint: any string fitted, and
 * the role rules in PunchListService (VERIFIER_TYPES / RAISER_TYPES) match on
 * exact strings, so a typo or an invented type produces a company that quietly
 * cannot verify or raise anything, with no error naming the cause.
 *
 * This came out of correcting MC, which had been mislabelled "Mechanical
 * Completion body" instead of Management Contractor (مدیریت پیمان). The label
 * was wrong for months; nothing failed, because nothing checked. The set of
 * legal values is now asserted in two places - App\Support\TenantType for the
 * application, and here for anything that reaches the table another way
 * (importers, seeders, a DBA at a psql prompt).
 */
return new class extends Migration
{
    /** @var list<string> Keep in sync with App\Support\TenantType::ALL. */
    private const TYPES = ['OWN', 'EPC', 'PMC', 'SUB', 'VEN', 'MC', 'TPI', 'CB', 'INT'];

    public function up(): void
    {
        $list = implode(', ', array_map(fn (string $t) => "'{$t}'", self::TYPES));

        DB::statement(
            "ALTER TABLE companies
             ADD CONSTRAINT companies_tenant_type_is_known
             CHECK (tenant_type IN ({$list}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_tenant_type_is_known');
    }
};
