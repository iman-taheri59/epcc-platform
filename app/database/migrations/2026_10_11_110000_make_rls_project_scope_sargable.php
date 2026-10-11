<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-012 (P-17) — remove the non-sargable project disjunction from every
 * tenant policy. THIS IS THE HIGH-SEVERITY FIX; READ ADR-012 FIRST.
 *
 * THE DEFECT
 * Every tenant policy began with
 *
 *     ( NULLIF(current_setting('epcc.project_id', true), '') IS NULL
 *       OR t.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid )
 *     AND (membership branches)
 *
 * `A IS NULL OR col = A` is not sargable: the planner cannot use any
 * project_id index, so every register read seq-scans the whole table. At the
 * ADR-012 benchmark (200 projects, 2.64 M punch rows) that was 1,690 ms for
 * a 25-row page, scaling with the size of OTHER customers' data.
 *
 * THE FIX (semantic-preserving)
 * The disjunction is replaced by one sargable semi-join predicate:
 *
 *     t.project_id IN (
 *         SELECT pc.project_id FROM project_company pc
 *         WHERE pc.company_id = GUC_company
 *           AND (bound IS NULL OR pc.project_id = bound)
 *         UNION
 *         SELECT p.id FROM projects p
 *         WHERE p.owner_company_id = GUC_company
 *           AND (bound IS NULL OR p.id = bound)
 *     )
 *
 * i.e. "a project this company participates in (or owns), narrowed to the
 * bound project when one is bound". The membership branches are UNCHANGED.
 * The OR now applies to a handful of project_company rows, never to the fact
 * table, so the outer predicate is a semi-join that uses the project index —
 * measured in ADR-012 §5 as flat 0.1–0.2 ms regardless of table size.
 *
 * SCOPE TIGHTENING (deliberate, recorded per ADR-012 §6.1)
 * The old preamble alone did not demand participation; the membership
 * branches did the filtering, and some of them admit roles other than
 * participant (a named vendor, a named recipient, a gate actor). Those roles
 * are only ever granted to companies ON the project (the procurement service
 * refuses a non-participant vendor; recipients are picked from participants;
 * WITH CHECK requires participation for writes), so the new preamble changes
 * no legitimate access — it only makes "on the project" database-enforced
 * for reads too, per standing rule 7 (scope by participation, not company).
 *
 * NOT TOUCHED
 * Identity tables (users, project_memberships, companies, plans) carry no
 * project disjunction. Child delegation policies
 * (material_requisition_items, purchase_order_items) and all archive-freeze /
 * no_update / no_delete policies are unchanged. WITH CHECK clauses keep their
 * exact text — they run only against the single row being written, so their
 * disjunction was never the defect.
 */
return new class extends Migration
{
    /** Tables whose membership branch is plain participation. */
    private const STANDARD_TABLES = [
        'subsystems',
        'material_inspections',
        'material_lots',
        'material_movements',
        'facilities',
        'areas',
        'piping_lines',
        'components',
        'ports',
        'joints',
        'piping_test_packs',
        'mto_documents',
        'mto_revisions',
        'mto_staging_rows',
        'accepted_mto_batches',
        'accepted_mto_rows',
        'engineering_estimate_rows',
    ];

    private const STANDARD_CHECK = <<<SQL
        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
        AND EXISTS (
            SELECT 1 FROM project_company pc
            WHERE pc.project_id = {T}.project_id
              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
        )
    SQL;

    /** The sargable replacement preamble (ADR-012 §5 shape). */
    private function scopePreamble(string $table): string
    {
        return <<<SQL
            {$table}.project_id IN (
                SELECT pc.project_id
                FROM project_company pc
                WHERE pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                  AND (
                      NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                      OR pc.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                  )
                UNION
                SELECT p.id
                FROM projects p
                WHERE p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                  AND (
                      NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                      OR p.id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                  )
            )
        SQL;
    }

    /** The original defective preamble, restored verbatim by down(). */
    private function oldPreamble(string $table): string
    {
        return <<<SQL
            (
                NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
            )
        SQL;
    }

    /**
     * Every rewritten policy: membership USING branch (preamble excluded)
     * and the exact WITH CHECK text from the original migration.
     *
     * @return array<string, array{using:string, check:string}>
     */
    private function policies(): array
    {
        $participation = <<<SQL
            EXISTS (
                SELECT 1 FROM project_company pc
                WHERE pc.project_id = {T}.project_id
                  AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            )
        SQL;

        $ownOnProject = <<<SQL
            (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = {T}.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            OR EXISTS (
                SELECT 1 FROM projects p
                WHERE p.id = {T}.project_id
                  AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            )
        SQL;

        $policies = [];

        foreach (self::STANDARD_TABLES as $table) {
            $policies[$table] = ['using' => $participation, 'check' => self::STANDARD_CHECK];
        }

        // work_packages / payment_certificates (migration 2026_09_09_110000).
        foreach (['work_packages', 'payment_certificates'] as $table) {
            $policies[$table] = ['using' => $ownOnProject, 'check' => self::STANDARD_CHECK];
        }

        // po_item_allocations has no company_id column (Slice 6); its write
        // check pins participation plus the parent PO line's project.
        $policies['po_item_allocations'] = [
            'using' => $participation,
            'check' => <<<SQL
                EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = {T}.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM purchase_order_items poi
                    WHERE poi.id = {T}.purchase_order_item_id
                      AND poi.project_id = {T}.project_id
                )
            SQL,
        ];

        $policies['gate_evaluations'] = [
            'using' => <<<SQL
                (
                    actor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {T}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL,
            'check' => <<<SQL
                actor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            SQL,
        ];

        $policies['documents'] = [
            'using' => <<<SQL
                (
                    (
                        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = {T}.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    OR (
                        EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = {T}.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                        AND EXISTS (
                            SELECT 1 FROM document_revisions r
                            WHERE r.document_id = {T}.id
                              AND r.cde_state <> 'WIP'
                        )
                    )
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {T}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL,
            'check' => self::STANDARD_CHECK,
        ];

        $policies['document_revisions'] = [
            'using' => <<<SQL
                (
                    (
                        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = {T}.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    OR (
                        {T}.cde_state <> 'WIP'
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = {T}.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {T}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL,
            'check' => self::STANDARD_CHECK,
        ];

        $policies['document_files'] = [
            'using' => <<<SQL
                (
                    (
                        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = {T}.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    OR (
                        EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = {T}.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                        AND EXISTS (
                            SELECT 1 FROM document_revisions r
                            WHERE r.id = {T}.revision_id
                              AND r.cde_state <> 'WIP'
                        )
                    )
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {T}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL,
            'check' => self::STANDARD_CHECK,
        ];

        $policies['document_review_comments'] = [
            'using' => <<<SQL
                (
                    EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {T}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {T}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL,
            'check' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR originator_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = {T}.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            SQL,
        ];

        $policies['transmittals'] = [
            'using' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        {T}.issued_at IS NOT NULL
                        AND (
                            EXISTS (
                                SELECT 1 FROM transmittal_recipients tr
                                WHERE tr.transmittal_id = {T}.id
                                  AND tr.recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                            OR (
                                {T}.kind = 'CONTRACTOR'
                                AND EXISTS (
                                    SELECT 1 FROM projects p
                                    WHERE p.id = {T}.project_id
                                      AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                                )
                            )
                        )
                    )
                )
            SQL,
            'check' => self::STANDARD_CHECK,
        ];

        $policies['transmittal_recipients'] = [
            'using' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        {T}.issued_at IS NOT NULL
                        AND (
                            recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            OR EXISTS (
                                SELECT 1 FROM projects p
                                WHERE p.id = {T}.project_id
                                  AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                    )
                )
            SQL,
            'check' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = {T}.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            SQL,
        ];

        $policies['transmittal_items'] = [
            'using' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        {T}.issued_at IS NOT NULL
                        AND (
                            EXISTS (
                                SELECT 1 FROM transmittal_recipients tr
                                WHERE tr.transmittal_id = {T}.transmittal_id
                                  AND tr.recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                            OR EXISTS (
                                SELECT 1 FROM projects p
                                WHERE p.id = {T}.project_id
                                  AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                    )
                )
            SQL,
            'check' => self::STANDARD_CHECK,
        ];

        $policies['punch_items'] = [
            'using' => $participation,
            'check' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR responsible_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {T}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = {T}.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            SQL,
        ];

        $policies['mc_certificates'] = [
            'using' => $participation,
            'check' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {T}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = {T}.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            SQL,
        ];

        $typedParticipant = <<<SQL
            EXISTS (
                SELECT 1 FROM project_company pc
                JOIN companies c ON c.id = pc.company_id
                WHERE pc.project_id = {T}.project_id
                  AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                  AND c.tenant_type IN ('OWN', 'PMC', 'MC')
            )
        SQL;

        $policies['material_requisitions'] = [
            'using' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR {$typedParticipant}
                )
            SQL,
            'check' => <<<SQL
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                OR {$typedParticipant}
            SQL,
        ];

        $policies['purchase_orders'] = [
            'using' => <<<SQL
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR vendor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR {$typedParticipant}
                )
            SQL,
            'check' => <<<SQL
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            SQL,
        ];

        return $policies;
    }

    private function createPolicy(string $table, string $preamble, array $body): void
    {
        $using = str_replace('{T}', $table, $body['using']);
        $check = str_replace('{T}', $table, $body['check']);

        DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        DB::statement(<<<SQL
            CREATE POLICY {$table}_tenant_isolation ON {$table}
            USING (
                {$preamble}
                AND {$using}
            )
            WITH CHECK (
                {$check}
            )
        SQL);
    }

    public function up(): void
    {
        foreach ($this->policies() as $table => $body) {
            $this->createPolicy($table, $this->scopePreamble($table), $body);
        }

        /*
         * The semi-join preamble is only as good as the index it lands on.
         * Most fact tables already lead with project_id (a rule the piping
         * migration even names after this ADR); these did not have one.
         * Idempotent on purpose.
         */
        DB::unprepared(<<<'SQL'
            CREATE INDEX IF NOT EXISTS punch_items_project_state_idx ON punch_items (project_id, state);
            CREATE INDEX IF NOT EXISTS subsystems_project_idx ON subsystems (project_id);
            CREATE INDEX IF NOT EXISTS mc_certificates_project_idx ON mc_certificates (project_id);
            CREATE INDEX IF NOT EXISTS material_inspections_project_idx ON material_inspections (project_id);
            CREATE INDEX IF NOT EXISTS material_lots_project_idx ON material_lots (project_id);
            CREATE INDEX IF NOT EXISTS material_movements_project_idx ON material_movements (project_id);
            CREATE INDEX IF NOT EXISTS mto_staging_rows_project_idx ON mto_staging_rows (project_id);
            CREATE INDEX IF NOT EXISTS gate_evaluations_project_idx ON gate_evaluations (project_id);
        SQL);

        // material_items keeps its global-catalogue arm; only the scoped arm
        // is rebuilt around the sargable preamble.
        DB::statement('DROP POLICY IF EXISTS material_items_tenant_isolation ON material_items');
        DB::statement(<<<SQL
            CREATE POLICY material_items_tenant_isolation ON material_items
            USING (
                project_id IS NULL
                OR (
                    {$this->scopePreamble('material_items')}
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = material_items.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                project_id IS NOT NULL
                AND (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR material_items.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = material_items.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS punch_items_project_state_idx;
            DROP INDEX IF EXISTS subsystems_project_idx;
            DROP INDEX IF EXISTS mc_certificates_project_idx;
            DROP INDEX IF EXISTS material_inspections_project_idx;
            DROP INDEX IF EXISTS material_lots_project_idx;
            DROP INDEX IF EXISTS material_movements_project_idx;
            DROP INDEX IF EXISTS mto_staging_rows_project_idx;
            DROP INDEX IF EXISTS gate_evaluations_project_idx;
        SQL);

        foreach ($this->policies() as $table => $body) {
            $this->createPolicy($table, $this->oldPreamble($table), $body);
        }

        DB::statement('DROP POLICY IF EXISTS material_items_tenant_isolation ON material_items');
        DB::statement(<<<'SQL'
            CREATE POLICY material_items_tenant_isolation ON material_items
            USING (
                project_id IS NULL
                OR (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR material_items.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = material_items.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                project_id IS NOT NULL
                AND (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR material_items.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = material_items.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }
};
