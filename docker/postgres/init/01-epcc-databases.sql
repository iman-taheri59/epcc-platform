-- ---------------------------------------------------------------------------
-- EPCC Platform - PostgreSQL initialisation
--
-- Runs ONCE, the first time the `db-data` volume is initialised. Editing this
-- file later does nothing to an existing volume; run `scripts/dev-down.sh
-- --volumes` (or `docker compose down -v`) and bring the stack up again.
--
-- ---------------------------------------------------------------------------
-- WHY NOT SIMPLY `POSTGRES_USER: epcc`?
--
-- The official postgres image makes POSTGRES_USER a SUPERUSER, and a superuser
-- bypasses row-level security unconditionally - even with the
-- `FORCE ROW LEVEL SECURITY` that every migration in this schema applies.
--
-- The entire tenancy model of this product is RLS (ADR-011, ADR-012,
-- docs/Spike-RLS-Results.md). With a superuser role the suite does not quietly
-- pass: assertions that exist precisely to prove isolation, such as
-- SeedPathTest's "unbound read must be fail-closed", start seeing every row and
-- go red. Someone then "fixes" the test and the platform loses its only
-- multi-tenancy guarantee.
--
-- So: superuser `postgres` exists for administration only, and the application
-- role `epcc` is a plain LOGIN role that OWNS both databases. That is exactly
-- the shape restore.sh builds on a bare sandbox, and it is why
-- 2026_09_12_100000_secure_identity_tables.php can note that CREATE ROLE is
-- denied to `epcc` and still be correct.
-- ---------------------------------------------------------------------------

-- Application role. Non-superuser, no BYPASSRLS, no CREATEROLE.
CREATE ROLE epcc LOGIN PASSWORD 'epcc_dev';

-- Development database (compose.yaml -> app/.env DB_DATABASE) and the test
-- database that phpunit.xml pins. Both owned by `epcc` so that migrations can
-- create tables, enable RLS and install triggers without a superuser, and so
-- that `migrate:fresh` in a test run cannot touch the development data.
CREATE DATABASE epcc OWNER epcc;
CREATE DATABASE epcc_test OWNER epcc;

-- PostgreSQL 15 removed the implicit CREATE privilege on `public` for
-- non-owner roles. `epcc` owns these databases so it already has it, but the
-- grant is spelled out because restore.sh does the same and because a future
-- second role (a read-only reporter, a migration user) must not inherit
-- write access by accident.
\connect epcc
GRANT ALL ON SCHEMA public TO epcc;

\connect epcc_test
GRANT ALL ON SCHEMA public TO epcc;
