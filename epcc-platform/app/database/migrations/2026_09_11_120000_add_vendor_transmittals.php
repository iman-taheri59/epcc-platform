<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor transmittals as a SEPARATE STREAM (FR-ENG-05b).
 *
 * WHY THIS IS NOT JUST A FILTER
 *
 * On an EPCC contract there are two different letters that happen to share a
 * shape:
 *
 *   EPC -> Employer/Consultant   the contractual submission. This is the
 *                                letter that starts a review clock and is
 *                                evidence in a delay claim.
 *   Vendor -> EPC                a supplier sending drawings, datasheets or
 *                                an ITP to the contractor that bought the
 *                                equipment. Internal to the contractor's
 *                                supply chain; the Employer is not a party.
 *
 * Mixing them corrupts the record the product exists to defend. "How many
 * transmittals have we issued to the client" must not silently include 40
 * vendor letters, and a vendor letter must never appear to the Employer as a
 * contractual submission from the EPC.
 *
 * The user chose: separate number series AND a separate screen.
 *
 * SEPARATE NUMBER SERIES
 *   {PROJECT}-TR-{SEQ:4}    contractor transmittals
 *   {PROJECT}-VTR-{SEQ:4}   vendor transmittals
 * Two independent counters. `sequence` is therefore only unique WITHIN a kind,
 * so the old unique index on (project_id, sequence) - if any - would be wrong;
 * uniqueness is on (project_id, number), which already encodes the kind.
 *
 * WHO RECORDS IT
 * The user's decision: the vendor does NOT log in. The EPC contractor's
 * document controller records what the vendor sent. So `company_id` (the
 * tenant/sender column, and the RLS anchor) stays the EPC - which keeps every
 * existing RLS policy correct without modification - and the actual originating
 * vendor is named in `vendor_company_id`.
 *
 * That distinction matters: `company_id` answers "whose row is this, for
 * isolation purposes", `vendor_company_id` answers "who wrote the letter".
 * Conflating them would have handed a vendor tenant visibility of the
 * contractor's whole register the day vendors do get logins.
 *
 * ROLL-UP TRACEABILITY
 * A vendor document is usually forwarded to the Employer inside a later EPC
 * transmittal. `transmittal_items.source_item_id` records which vendor item a
 * contractor item came from, so the chain
 * "vendor letter -> EPC letter -> client" survives. It is a self-reference on
 * the items table, nullable, and set only when the roll-up actually happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transmittals', function (Blueprint $t): void {
            // CONTRACTOR | VENDOR. Defaulted so every existing row keeps its
            // present meaning: everything issued before today was a
            // contractor transmittal.
            $t->string('kind', 16)->default('CONTRACTOR');

            // The originating vendor. NULL for contractor transmittals.
            // Not the tenant column - see the class note.
            $t->uuid('vendor_company_id')->nullable();

            // The vendor's own letter reference, as printed on their paperwork.
            // Free text on purpose: a vendor's numbering is their business and
            // will not match ours. Recorded so the contractor can reconcile
            // against the supplier's correspondence.
            $t->string('vendor_reference', 128)->nullable();

            $t->foreign('vendor_company_id')->references('id')->on('companies')->nullOnDelete();
            $t->index(['project_id', 'kind', 'status']);
        });

        Schema::table('transmittal_items', function (Blueprint $t): void {
            // Which vendor transmittal item this item was rolled up from.
            $t->uuid('source_item_id')->nullable();

            $t->foreign('source_item_id')
                ->references('id')->on('transmittal_items')
                ->nullOnDelete();

            $t->index('source_item_id');
        });

        /*
         * Integrity, enforced by the database rather than trusted to the
         * service: a VENDOR transmittal must name its vendor, and a CONTRACTOR
         * transmittal must not. A CHECK constraint is the right home for this -
         * it holds for a seeder, a console command or a future import job, none
         * of which go through TransmittalService.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE transmittals
            ADD CONSTRAINT transmittals_vendor_fields_match_kind
            CHECK (
                (kind = 'VENDOR'     AND vendor_company_id IS NOT NULL)
             OR (kind = 'CONTRACTOR' AND vendor_company_id IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE transmittals
            ADD CONSTRAINT transmittals_kind_is_known
            CHECK (kind IN ('CONTRACTOR', 'VENDOR'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transmittals DROP CONSTRAINT IF EXISTS transmittals_vendor_fields_match_kind');
        DB::statement('ALTER TABLE transmittals DROP CONSTRAINT IF EXISTS transmittals_kind_is_known');

        Schema::table('transmittal_items', function (Blueprint $t): void {
            $t->dropForeign(['source_item_id']);
            $t->dropIndex(['source_item_id']);
            $t->dropColumn('source_item_id');
        });

        Schema::table('transmittals', function (Blueprint $t): void {
            $t->dropForeign(['vendor_company_id']);
            $t->dropIndex(['project_id', 'kind', 'status']);
            $t->dropColumn(['kind', 'vendor_company_id', 'vendor_reference']);
        });
    }
};
