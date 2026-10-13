<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security for the IDENTITY tables: users and project_memberships.
 *
 * WHY THIS EXISTS
 * ---------------
 * The original RLS migration protected the tables that carry project data -
 * documents, transmittals, punch items, certificates - and its own comment
 * warned about "a Filament table that someone wires up carelessly". It was
 * right about the mechanism and wrong about the scope: `users`,
 * `project_memberships` and `companies` were never covered, because at the
 * time isolation for those was a `->where('company_id', ...)` in
 * ProjectSetupController and nothing else queried them.
 *
 * Building the admin Users resource made that measurable. Bound as EPC-PARS:
 *
 *     documents visible: 5      <- correctly narrowed by RLS
 *     users visible:     9      <- EVERY user of ALL EIGHT companies
 *
 * A staff directory is exactly the kind of thing a competitor wants: on a
 * shared EPCC project the contractor would have read the client's
 * representatives, the PMC's inspectors, and two rival EPCs' engineers, with
 * names and email addresses. No admin screen existed yet, so nothing had
 * exposed it - the hole was in the database, waiting.
 *
 * THE HARD PART: LOGIN HAPPENS BEFORE A TENANT EXISTS
 * ---------------------------------------------------
 * `users` is not like `documents`. The authentication path must read a user
 * row to discover which company they belong to, so at that moment
 * `epcc.company_id` is necessarily unset - and an unset GUC means "see
 * nothing" (fail-closed, by construction, rule 3). A naive policy therefore
 * does not leak; it locks every single person out of the product, including
 * the session middleware that resolves `$request->user()` on every request.
 *
 * So the policy carries a SECOND, deliberately narrow escape hatch:
 * `epcc.identity_lookup`. When that GUC is on, a user row is visible by
 * identity alone. `TenantContext::forIdentityLookup()` turns it on for the
 * duration of one closure and clears it afterwards, and the only callers are
 * the authentication path and the login screen's demo-account list.
 *
 * This is a real trade-off and worth stating plainly: the escape hatch is a
 * bypass, and anything that can set that GUC can read the user table. What it
 * buys is that the bypass is (a) one named thing that can be grepped, (b)
 * transaction-scoped like every other GUC here, and (c) testable - the suite
 * asserts that it is OFF during ordinary requests. The alternative designs
 * were worse: a second database role needs CREATE ROLE (denied to `epcc`),
 * and a SECURITY DEFINER function hides the bypass inside a function body
 * where no grep will ever find it.
 *
 * COMPANIES IS DELIBERATELY NOT COVERED
 * -------------------------------------
 * Company identity is not confidential the way a staff roster is - the
 * participant list of a project is on the contract, every party knows who the
 * others are, and the project team screen already shows it. More decisively,
 * `companies` is read while resolving plans and participants on paths where no
 * tenant is bound, so covering it would need a second escape hatch for a row
 * whose leak is "the contractor learns the consultant's name". Left open, on
 * purpose, and recorded here so the next person does not assume it was missed.
 *
 * NULLIF ON EVERY GUC READ
 * ------------------------
 * `current_setting(..., true)` returns '' (not NULL) once a GUC has been set
 * and then cleared within the same transaction, and `''::uuid` is a hard
 * error rather than an empty result. That is standing rule 3, and it already
 * cost this project one fix-migration (2026_09_09_100200_fix_rls_empty_guc).
 * The first draft of this file reproduced the bug exactly: closing the
 * identity hatch left `epcc.company_id` as '' and the next query died with
 * "invalid input syntax for type uuid". Hence NULLIF on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- users -------------------------------------------------------
        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE users FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY users_tenant_isolation ON users
            USING (
                -- Colleagues: same company.
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid

                -- The authentication path, which must read a user row before
                -- it can possibly know which tenant that user belongs to.
                OR current_setting('epcc.identity_lookup', true) = 'on'

                -- Counterparties: staff of a company that shares a project
                -- with mine. A document says "reviewed by", a transmittal says
                -- "acknowledged by", a punch item says "raised by" - all of
                -- those render a name, and without this clause they would
                -- render blank for the other side while the row itself is
                -- visible.
                --
                -- NOTE ON THE SHAPE OF THIS CLAUSE. The tighter rule - "users
                -- who hold a MEMBERSHIP on a project my company participates
                -- in" - is what the rest of the schema means by "participation,
                -- not company", and it was written that way first. It cannot be
                -- used here: it makes the users policy read project_memberships
                -- while the project_memberships policy reads users, and
                -- PostgreSQL rejects the cycle outright with
                -- "infinite recursion detected in policy for relation users".
                --
                -- So this clause joins project_company to itself instead. That
                -- table has no RLS of its own, which is what breaks the cycle.
                -- The rule is slightly wider - it includes employees of a
                -- participating company who are not on the project team - and
                -- that is the deliberate trade. The alternative was a
                -- SECURITY DEFINER function to read the other table with RLS
                -- suspended, which hides a bypass inside a function body where
                -- no grep will find it.
                OR EXISTS (
                    SELECT 1
                    FROM project_company pc_them
                    JOIN project_company pc_mine
                      ON pc_mine.project_id = pc_them.project_id
                    WHERE pc_them.company_id = users.company_id
                      AND pc_mine.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                OR current_setting('epcc.identity_lookup', true) = 'on'
            )
        SQL);

        // ---- project_memberships ----------------------------------------
        // No company_id column of its own: a membership is a (user, project)
        // pair, so its tenant is the user's. Isolation routes through that.
        DB::statement('ALTER TABLE project_memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE project_memberships FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            -- These clauses must NOT read `users`: that table's own policy
            -- reads project_company, and a policy pair that reads each other
            -- is rejected by PostgreSQL as infinite recursion. Everything here
            -- therefore resolves through project_company / projects, neither
            -- of which carries RLS.
            CREATE POLICY project_memberships_tenant_isolation ON project_memberships
            USING (
                current_setting('epcc.identity_lookup', true) = 'on'

                -- A membership on a project my company participates in.
                OR EXISTS (
                    SELECT 1
                    FROM project_company pc
                    WHERE pc.project_id = project_memberships.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )

                -- The employer administers its own projects' teams, and the
                -- team screen lists every participant's members.
                OR EXISTS (
                    SELECT 1
                    FROM projects p
                    WHERE p.id = project_memberships.project_id
                      AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                current_setting('epcc.identity_lookup', true) = 'on'

                OR EXISTS (
                    SELECT 1
                    FROM project_company pc
                    WHERE pc.project_id = project_memberships.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS project_memberships_tenant_isolation ON project_memberships');
        DB::statement('ALTER TABLE project_memberships NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE project_memberships DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS users_tenant_isolation ON users');
        DB::statement('ALTER TABLE users NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE users DISABLE ROW LEVEL SECURITY');
    }
};
