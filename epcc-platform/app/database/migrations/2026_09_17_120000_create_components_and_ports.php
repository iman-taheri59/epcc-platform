<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006 phase 2 — components and ports.
 *
 * A line stops being a label and becomes a model here: the components on it,
 * and the ports through which they connect. Joints (phase 3) join two ports;
 * this migration deliberately stops short of them.
 *
 * Two decisions are baked into the shape and are worth stating plainly.
 *
 * ONE ports table, discriminated by owner_kind. A nozzle port and a component
 * port are the same thing to a joint, and ADR-010 section 3.2 rejected sibling
 * tables because every joint query would then need a two-branch COALESCE and
 * the compatibility engine would need parallel code paths that drift. The
 * nozzle side arrives in phase 3b; the column and the CHECK exist now so that
 * arrival is an insert, not a migration.
 *
 * PORT COUNT IS NEVER TYPED BY A USER. It comes from the component template
 * (ADR-009 section 2): a tee has three ports because the template says so. The
 * service creates them; nothing in the schema lets a caller invent a fourth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('components', function ($table): void {
            $table->uuid('id')->primary();

            // Tenancy, same shape as every other project-scoped table so the
            // RLS policies below can be copied rather than reasoned about.
            $table->uuid('project_id');
            $table->uuid('company_id');
            $table->uuid('piping_line_id');

            // Which template this was placed from. A STRING, not an FK: the
            // templates are reference data in a JSON pack (ADR-007), not rows.
            // Validated by the service against PipeReference.
            $table->string('template_code', 32);

            // Per-line ordinal. Not a global identity: engineers say "the
            // third fitting on line X", and an import that renumbers globally
            // would make every existing reference wrong.
            $table->integer('sequence');

            // The size that identifies the component as a whole. Individual
            // ports carry their own size (a reducer has two), so this is the
            // governing/largest one - what a material take-off would list.
            $table->string('nominal_size', 16)->nullable();
            $table->decimal('nominal_size_decimal', 8, 4)->nullable();

            $table->string('schedule', 16)->nullable();
            $table->string('material_code', 32)->nullable();

            // Pipe has a length; a tee does not. The template says which.
            $table->decimal('length_mm', 12, 3)->nullable();

            // Shop or field. PCF carries this per weld (CATEGORY FABRICATION
            // vs ERECTION) and it drives who is responsible for the joint.
            $table->string('fabrication', 16)->nullable();

            $table->string('tag', 64)->nullable();
            $table->text('remark')->nullable();

            // Where it came from: MANUAL today, PCF/PLANT3D at phase 6. Kept
            // from the start because "did a human type this?" is the first
            // question asked of any suspect row.
            $table->string('source', 16)->default('MANUAL');
            $table->string('external_ref', 128)->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('piping_line_id')->references('id')->on('piping_lines')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['piping_line_id', 'sequence'], 'components_line_sequence_unique');
            $table->index(['project_id', 'piping_line_id'], 'components_project_line_idx');
            $table->index(['project_id', 'template_code'], 'components_project_template_idx');
        });

        Schema::create('ports', function ($table): void {
            $table->uuid('id')->primary();

            $table->uuid('project_id');
            $table->uuid('company_id');

            // The discriminator. Exactly one of the two owner columns is set,
            // enforced by CHECK below.
            $table->string('owner_kind', 16);
            $table->uuid('component_id')->nullable();
            $table->uuid('nozzle_id')->nullable();   // equipment_nozzles, phase 3b

            // Position within its owner. P1/P2/P3 on a tee - stable, because
            // the branch is always seq 3 and rules refer to it by role.
            $table->integer('seq');
            $table->string('role', 16);

            // Size per port: a reducer's two ports differ, and dia-inch
            // (ADR-010) is min(portA, portB), so the port is where size has to
            // live. Both spellings for the same reason as piping_lines:
            // '2 1/2' sorts wrong, 2.5 computes right.
            $table->string('nominal_size', 16)->nullable();
            $table->decimal('nominal_size_decimal', 8, 4)->nullable();

            $table->string('end_type', 8)->nullable();   // BW, SW, FL, SC, PL...
            $table->string('schedule', 16)->nullable();

            // Denormalised, and deliberately so: "every open end on this line"
            // is the single most-used query in the system (ADR-009 section 2.3)
            // and it drives the worklist, the progress metric and the import
            // validator. Derivable from joints, but not cheaply, and not
            // before joints exist at all.
            $table->boolean('is_connected')->default(false);

            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('component_id')->references('id')->on('components')->cascadeOnDelete();

            $table->index(['project_id', 'component_id'], 'ports_project_component_idx');
            $table->index(['project_id', 'owner_kind'], 'ports_project_owner_kind_idx');
        });

        // A component's ports are numbered once. Without this, a retry of a
        // half-failed create could give a tee two port 3s.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ports_component_seq_unique
            ON ports (component_id, seq)
            WHERE component_id IS NOT NULL
        SQL);

        // The open-ends query, which is the whole modelling UI. Partial,
        // because a connected port is never the answer to it.
        DB::statement(<<<'SQL'
            CREATE INDEX ports_open_ends_idx
            ON ports (project_id, component_id)
            WHERE is_connected = false
        SQL);

        // Exactly one owner. This is the constraint that makes the single
        // table safe: a row claiming to be a COMPONENT port while carrying a
        // nozzle_id is unrepresentable rather than merely discouraged.
        DB::statement(<<<'SQL'
            ALTER TABLE ports ADD CONSTRAINT ports_exactly_one_owner CHECK (
                (owner_kind = 'COMPONENT' AND component_id IS NOT NULL AND nozzle_id IS NULL)
                OR
                (owner_kind = 'NOZZLE' AND nozzle_id IS NOT NULL AND component_id IS NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ports ADD CONSTRAINT ports_seq_positive CHECK (seq >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE components ADD CONSTRAINT components_sequence_positive CHECK (sequence >= 1)
        SQL);

        // A length on a tee is meaningless; a negative length is nonsense.
        // Which components may have one is the template's business, but the
        // sign is the database's.
        DB::statement(<<<'SQL'
            ALTER TABLE components ADD CONSTRAINT components_length_not_negative CHECK (
                length_mm IS NULL OR length_mm > 0
            )
        SQL);

        // Both size spellings travel together or not at all. A decimal with no
        // NPS string cannot be displayed; an NPS string with no decimal cannot
        // be compared - and dia-inch needs the comparison.
        foreach (['components' => 'nominal_size', 'ports' => 'nominal_size'] as $table => $col) {
            DB::statement(<<<SQL
                ALTER TABLE {$table} ADD CONSTRAINT {$table}_size_pair_complete CHECK (
                    ({$col} IS NULL AND {$col}_decimal IS NULL)
                    OR ({$col} IS NOT NULL AND {$col}_decimal IS NOT NULL)
                )
            SQL);
        }

        // ------------------------------------------------------------------
        // RLS. Copied from create_piping_lines rather than reinvented: there
        // is no epcc_can_read_project() helper, the read rule is spelled out
        // from current_setting, and getting that wrong fails closed in a way
        // that looks like "my data vanished".
        // ------------------------------------------------------------------
        foreach (['components', 'ports'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

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

            // ADR-013: an archived project is read-only. Both halves are
            // needed - WITH CHECK catches INSERT/UPDATE, and a separate
            // FOR DELETE policy is required because WITH CHECK is never
            // evaluated for DELETE (HANDOFF section 8).
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_freeze ON {$table}
                AS RESTRICTIVE
                FOR ALL
                USING (true)
                WITH CHECK (epcc_project_is_writable(project_id))
            SQL);

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_no_delete ON {$table}
                AS RESTRICTIVE
                FOR DELETE
                USING (epcc_project_is_writable(project_id))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ports');
        Schema::dropIfExists('components');
    }
};
