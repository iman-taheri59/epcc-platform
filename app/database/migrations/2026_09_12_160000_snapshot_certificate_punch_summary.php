<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the punch-list summary onto an issued MC certificate.
 *
 * THE DEFECT. The certificate PDF computed "total / closed / open category-A"
 * live from the punch list every time it was rendered. Those three numbers are
 * the technical justification for the handover, and on an ISSUED certificate
 * they were drifting: download it the day it is issued and it reads
 * "closed 2, open A 2"; download the SAME certificate, same number, same URL a
 * week later after the contractor finishes the work and it reads
 * "closed 3, open A 1".
 *
 * Both cannot be evidence of the same event. A certificate is a statement
 * about a moment - what was accepted, and on what basis - and the moment does
 * not change because the site moved on afterwards. Worse, the drift always
 * runs in the flattering direction: the record of a certificate issued over
 * two open A-items quietly repairs itself into one that looks nearly clean,
 * erasing exactly what an auditor or an arbitrator would be looking for.
 *
 * `exception_items` already does this correctly for the waived items. This
 * extends the same treatment to the summary, so the whole page is a snapshot
 * rather than a snapshot next to a live query.
 *
 * Nullable: DRAFT certificates have no snapshot and must keep showing live
 * numbers, because a draft's job is to tell you where the subsystem stands
 * right now. The freeze happens at issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mc_certificates', function (Blueprint $table): void {
            $table->jsonb('punch_summary')->nullable()->after('exception_items');
        });
    }

    public function down(): void
    {
        Schema::table('mc_certificates', function (Blueprint $table): void {
            $table->dropColumn('punch_summary');
        });
    }
};
