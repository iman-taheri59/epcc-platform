# Traceability phase 1 — evidence index (2026-10-23)

- Contract/user guide: `../../PIPING-MATERIAL-TRACEABILITY-{EN,FA}.md`.
- `predecessor-check.log`: 38 prior amendment-delivery source hashes verified before this phase changed routes/view.
- `initial-focused.log`, `focused.log`: retained diagnostic fixture failures; superseded by final affected run. `forward.log`: two fixed fixture cases, 2/19 pass. No RLS or authority weakening.
- `final-affected.log`: authoritative current run, 47 tests / 505 assertions / 130.18 seconds PASS, report + custody + amendments. No full-suite claim.
- `format.log`, `static.log`: formatting, six-file Pint/lint, diff whitespace, Blade compile/clear, two GET routes.
- `rls-audit.log`: actual test role `epcc` NOSUPERUSER/NOBYPASSRLS; all 11 joined domain tables ENABLE/FORCE RLS; both existing SQL helpers STABLE, invoker, not SECURITY DEFINER.
- `http-en.html`, `http-fa.html`: synthetic archived-receipt HTTP responses, CSRF fields redacted. Not browser screenshots; no production records. Shared shell external resources might not render in the offline viewer.
- `source-sha256.txt`: delivery source/docs manifest; excludes itself/logs/runtime. Verify from repository root with `sha256sum -c docs/evidence/2026-10-23-piping-traceability/source-sha256.txt`.

Reproduce behavior with the three-file command in the guide, PostgreSQL UTF8, migrations, `epcc` ordinary RLS role and the project's testing environment. DB/runtime/vendor are not shipped. No migration added. Private socket-only test DB stopped after verification.

Package is an increment over the existing project and the preceding custody-amendment delivery, not a full backup/runnable distribution. Predecessor archive SHA256: `485a090bf7883d2ec0cb05c8f3737b482ee0b389aa06d215c1342b26d10bed56`. Extract only into the matching source baseline; preserve unrelated local changes. No deploy/CI/commit/push or production audit is represented.
