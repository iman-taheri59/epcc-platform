<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 amendment — the breakdown gains a SYSTEM level, and a subsystem must
 * declare the system it belongs to.
 *
 * ADR-004 chose a *physical* breakdown: project → facility → area (optional) →
 * subsystem, and `subsystem` is the unit that gets mechanically completed — a
 * pump set, a skid, a section of line. It also left a loose end, in its own
 * words: "a project of 4,000 subsystems has no intermediate level". The answer
 * it chose for that was `areas`, which is physical, and `areas.unit_code`
 * ("one process unit") — a nullable free-ish string, which is to say a level
 * the platform knew about and did not model.
 *
 * That left two different questions sharing one answer. "Where is it?" is
 * physical and `areas` answers it. "What does it do?" is functional and crosses
 * physical boundaries: the firefighting pumps sit in AR-22 and the deluge
 * valves in AR-33, and the client asking for the firefighting dossier is asking
 * a question no area can answer. So:
 *
 *   project ──1:N── systems ──1:N── subsystems        (functional: what it does)
 *   project ──1:N── facilities ──1:N── areas(opt) ──1:N── subsystems
 *                                                     (physical: where it is)
 *
 * Two parallel trees over the same subsystems, because they are two different
 * questions and collapsing them is what produced `unit_code` in the first
 * place. Systems hang off the PROJECT and not off a facility, deliberately: a
 * system is allowed to span facilities, and ADR-004's own lesson was that every
 * query walking a two-branch `COALESCE` upward is a query that will eventually
 * answer wrong.
 *
 * `subsystems.system_id` is NOT NULL. A subsystem that names no system is a
 * subsystem nobody can commission, and the cost of insisting is payable now —
 * no project has been run on this platform yet — while the cost of not
 * insisting grows with every subsystem anybody creates.
 *
 * `areas.unit_code` is DROPPED, not kept as a deprecated alias: the system is
 * the real thing now, and a second column silently answering the same question
 * is exactly the drift this amendment exists to remove.
 *
 * Backfill, for any database that already holds subsystems (none in production;
 * the seed is the only source today): one system per area — named by its unit
 * code where it had one — plus one per facility for the subsystems ADR-004 lets
 * sit directly on a facility, and a catch-all for anything left.
 *
 * Forward-only: there is no `down()`. The platform does not un-break a
 * breakdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('systems', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // Who is accountable for the system as a whole. Not "who owns the
            // rows" — it is the RLS tenant column, as everywhere.
            $t->uuid('company_id');

            $t->string('code', 64);
            $t->string('name_en');
            $t->string('name_fa');

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            // System codes are unique per PROJECT, not per facility, because a
            // system is allowed to span facilities. (Subsystem codes stay
            // unique per facility — ADR-004's decision, left untouched.)
            $t->unique(['project_id', 'code']);
        });

        foreach (['systems'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            // Identical clause to subsystems_tenant_isolation and
            // facilities/areas: readable by every project participant, writable
            // by the company accountable for it. The breakdown is the map the
            // whole project works from; hiding systems from the consultant or
            // the MC body would break the workflow they exist to serve.
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL);
        }

        DB::unprepared('CREATE INDEX IF NOT EXISTS systems_project_idx ON systems (project_id)');
        DB::unprepared('ALTER TABLE subsystems ADD COLUMN IF NOT EXISTS system_id uuid');

        // ---- backfill ------------------------------------------------------
        DB::unprepared(<<<'SQL'
WITH cand AS (
    SELECT DISTINCT s.project_id,
           COALESCE(a.unit_code, f.code || '-' || a.code) AS code_base,
           COALESCE(a.name_en, f.name_en) AS name_en,
           COALESCE(a.name_fa, f.name_fa) AS name_fa
      FROM subsystems s
      JOIN facilities f ON f.id = s.facility_id
      LEFT JOIN areas a ON a.id = s.area_id
     WHERE s.area_id IS NOT NULL
    UNION
    SELECT DISTINCT s.project_id,
           f.code || '-NO-AREA' AS code_base,
           f.name_en, f.name_fa
      FROM subsystems s
      JOIN facilities f ON f.id = s.facility_id
     WHERE s.area_id IS NULL
), ranked AS (
    SELECT cand.*, row_number() OVER (PARTITION BY project_id, code_base ORDER BY name_en) AS rn
      FROM cand
)
INSERT INTO systems (id, project_id, company_id, code, name_en, name_fa, created_at, updated_at)
SELECT gen_random_uuid(), r.project_id, p.owner_company_id,
       r.code_base || CASE WHEN r.rn > 1 THEN '-' || r.rn ELSE '' END,
       r.name_en, r.name_fa, now(), now()
  FROM ranked r
  JOIN projects p ON p.id = r.project_id;

UPDATE subsystems s
   SET system_id = COALESCE(
       (SELECT sy.id FROM systems sy
         WHERE sy.project_id = s.project_id
           AND sy.code = COALESCE(
                 (SELECT a.unit_code FROM areas a WHERE a.id = s.area_id),
                 (SELECT f.code || '-' || a.code FROM facilities f, areas a
                   WHERE f.id = s.facility_id AND a.id = s.area_id))),
       (SELECT sy.id FROM systems sy
         WHERE sy.project_id = s.project_id
           AND sy.code = (SELECT f.code || '-NO-AREA' FROM facilities f WHERE f.id = s.facility_id))
   );

-- Anything the derivation could not place gets the project's catch-all, so the
-- NOT NULL below can never fail: a subsystem with no system is not a state a
-- database is allowed to hold, even mid-migration.
INSERT INTO systems (id, project_id, company_id, code, name_en, name_fa, created_at, updated_at)
SELECT gen_random_uuid(), p.id, p.owner_company_id, p.code || '-SYSTEMS',
       p.name_en || ' — undeclared systems', p.name_fa || ' — سیستم‌های اعلام‌نشده', now(), now()
  FROM projects p
 WHERE EXISTS (SELECT 1 FROM subsystems s WHERE s.project_id = p.id AND s.system_id IS NULL);

UPDATE subsystems s
   SET system_id = (SELECT sy.id FROM systems sy
                     WHERE sy.project_id = s.project_id AND sy.code = (SELECT p.code || '-SYSTEMS' FROM projects p WHERE p.id = s.project_id))
 WHERE s.system_id IS NULL;
SQL);

        DB::unprepared('ALTER TABLE subsystems ALTER COLUMN system_id SET NOT NULL');
        DB::unprepared(<<<'SQL'
ALTER TABLE subsystems
    ADD CONSTRAINT subsystems_system_id_foreign
    FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE RESTRICT
SQL);
        DB::unprepared('CREATE INDEX IF NOT EXISTS subsystems_system_idx ON subsystems (system_id)');

        // The unit is the system now; a second column answering the same
        // question is the drift this amendment removes.
        DB::unprepared('ALTER TABLE areas DROP COLUMN IF EXISTS unit_code');
    }
};
