<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the scope-proposal review clock to microsecond precision.
 *
 * Laravel writes `timestampTz` columns as timestamptz(0) — second precision.
 * Reviews recorded inside one second therefore tie, and the bounded read
 * display (ScopeProposalEnvelopeReader: orderBy created_at, then id) fell
 * back to ordering by random v4 UUIDs: which opinion the 20-row bound drops
 * became arbitrary instead of "the newest beyond the bound". The insert
 * trigger already stamps NEW.created_at := clock_timestamp(), so widening
 * the column is enough to make the order deterministic.
 *
 * Scope: only scope_proposal_reviews is order-sensitive today (the bounded
 * opinion display). Envelope recency is read through `version`, not the
 * clock, so envelopes keep the project-wide timestamptz(0) convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE scope_proposal_reviews ALTER COLUMN created_at TYPE timestamp(6) with time zone');
    }

    public function down(): void
    {
        // Reverting loses sub-second precision again; acceptable for a down().
        DB::unprepared('ALTER TABLE scope_proposal_reviews ALTER COLUMN created_at TYPE timestamp(0) with time zone');
    }
};
