# G01 — Real PostgreSQL persistence proof

> Full journal-source gate NOW GREEN1098/16429,zero failures/errors/skips: `G01-JOURNAL-FULL-SUITE-VERIFICATION-EN.md`. Earlier pending notices below are historical. Effective scope selection and the integrated pilot remain open.


> Next implementation update: real NEW acceptance chronology is now connected (always UNRESOLVED). See `G01-REAL-ACCEPTANCE-JOURNAL-EN.md`:89 related tests/240 assertions +5 app-service contention scenarios; FULL SUITE ON CHANGED SOURCE PENDING. No effective board switch or pilot completion.


Date: 2026-10-18. **PASS:36 cases on isolated PostgreSQL17.11. This is a bounded database prototype, NOT a Laravel migration or a production G01 fix.**
Related: ADR-024 effective-selection design and migration/backfill specification. FA companion available.

## 1. What was built and actually executed

Files:

- `prototype/persistence/schema.sql`: executable synthetic schema, composite source/selection FKs, per-project clock, immutable journal, forced tenant RLS, archive guards, one-scope manifest, deferred completeness and invoker acceptance function.
- `prototype/persistence/test_postgres.py`: actual separate writer/admin connections, SQLSTATE checks, transactional failure injection and deterministic concurrency tests.
- `docs/evidence/2026-10-18-g01-postgres/`: complete successful test log, machine-readable results, mutation probes and source integrity metadata.

The test writer is a real LOGIN role with **NOSUPERUSER, NOBYPASSRLS, NOCREATEDB, NOCREATEROLE**. Six synthetic tables have RLS enabled and forced, including checks of the non-bypass table owner. Participant/context fixtures are minimal and intentionally not a replacement for the application's authority model.

Environment: PostgreSQL17.11, Debian psycopg3.2.6. Fresh expendable cluster under `/tmp/epcc-journal-pg-*`; private `/tmp/epcc-journal-sock-*` Unix socket; no PostgreSQL TCP listener. Local socket authentication is trust **for this isolated test only**, not production-authentication evidence. fsync, synchronous_commit and full_page_writes remained ON; WAL128/32MB. Runtime package installation was restored after reset. No Laravel bootstrap, Composer reinstall, demo seeding or application DB connection was used.

## 2. What36 passing cases prove within this model

- An accepted source tuple, one exact manifest entry and selection event commit together. A deferred DB constraint rejects a batch-only transaction at **COMMIT**, not merely in the caller.
- New head selection preserves prior immutable events. Same-key predecessor and exact source/scope composite references reject foreign/mismatched identities.
- A second root, branch, skipped step, future predecessor/cycle and a genuinely new source with an older unused ordinal are rejected. The chronology case does not rely on a duplicate-batch constraint to pass.
- Failure after inserting a complete group rolls back source, manifest, event and clock. Replaying a revision or supplying a stale expected head leaves no partial write.
- Missing context/foreign company/project cannot read the target project. Cross-project clock UPDATE reaches zero rows. Cross-project source FK rejection is also tested using an admin connection, independently of RLS filtering.
- Restrictive RLS denies history UPDATE/DELETE even when those DML privileges are temporarily granted. Privileged admin UPDATE/DELETE still hits immutable-history triggers. FORCE RLS filters the non-bypass table owner.
- Archived history remains readable, while acceptance/direct clock writes are denied. The writer has no archive-flag edit permission.

### Actual concurrency, not sequential simulation

Each scenario uses two simultaneous connections. The harness holds the first transaction open and queries **pg_blocking_pids** to prove that the second is actually waiting on the first before releasing it:

1. Two normal acceptances serialize and both commit as steps1/2, with one head.
2. First transaction rolls back; the waiting writer commits a clean root without orphaned source/clock changes.
3. A waiting writer with stale expected step loses with SQLSTATE40001, leaving only the winner.
4. Archive holds the project lock first; waiting acceptance fails after archive commits.
5. Acceptance holds the lock first; it commits before the waiting archive, and all later acceptance fails.

Finite event/statement/join deadlines fail the test if overlap or completion cannot be demonstrated. Successful concurrency IDs and observed blocking are recorded in summary.json. This does not establish production throughput, deadlock freedom across every future writer, or crash recovery.

## 3. Mutation probes: three regressions detected

Temporary copies of the proof schema were deliberately changed and run serially in the same disposable database, never against application source:

- Removing the project row lock makes the competing normal writer lose instead of serially committing; the concurrency contract fails.
- Removing the deferred batch-completeness trigger lets an incomplete batch commit; the expected rejection fails.
- Removing the advancing-source chronology guard allows a new source with an older unused ordinal to succeed; the chronology contract fails.

All three mutated runs failed as expected. The original schema was restored and all36 cases passed afterward. Evidence does not claim the deliberately failing mutations are production bugs.

## 4. Deliberate limitations — do not promote this schema directly

1. **Synthetic source frontier:** projects/memberships/batches are minimal fixture tables, not the real documents, revisions, accepted rows, participant policies or model observers. Document/revision IDs are fixture identities; no DRAFT validation or real engineering workflow is implemented.
2. **One covered scope per batch:** unique batch manifest/event and deferred completeness prove only this bounded shape. Multi-scope manifests/transaction-group sealing, hierarchy topology validation, changed/retired scopes and full gross arithmetic need the real design adapter. No arbitrary scope can be admitted by treating this function as an approved application endpoint.
3. **NEW lineage only:** no legacy reconciliation package, controlled evidence admission, additivity grant/revoke lifecycle, actor nomination or resolver authority is implemented here. The offline backfill planner remains review-only.
4. **Trusted internal writer:** direct INSERT and clock UPDATE privileges exist to exercise negative DB constraints. This is not a hardened public SQL/HTTP write boundary; production capability design must restrict writes and enforce clock/provenance coupling. Session context GUCs are supplied by the trusted harness, not authenticated from a human session; resistance to a client forging those settings is not proved.
5. **Immutable DML, not malicious-admin resistance:** triggers reject privileged UPDATE/DELETE, but a superuser could disable triggers/change schema/TRUNCATE. Production backup/admin controls remain separate.
6. **Functional persistence only:** no production data, deployment migration, migration rollback, backup restore, crash/durability experiment, performance target, Docker job or current CI result. Integrity settings ON do not turn this into crash-recovery certification.
7. **No board integration:** OrderGapService, existing net-estimate links, stock semantics, MTO permissions and ADR-032 remain unchanged. The unresolved-state UI/API contract is still required before switching the board.

## 5. Safe reproduction

Prerequisites on disposable Debian17-PG tooling environment:

```sh
sudo apt-get install postgresql-17 python3-psycopg
```

As a non-root user in a supervised terminal, create a **fresh** temporary cluster and socket (never application paths):

```sh
DATA=$(mktemp -d /tmp/epcc-journal-pg-XXXXXX)
SOCK=$(mktemp -d /tmp/epcc-journal-sock-XXXXXX)
/usr/lib/postgresql/17/bin/initdb -D "$DATA" -U postgres \
  --auth-local=trust --auth-host=reject -E UTF8
printf 'Data:%s\nSocket:%s\n' "$DATA" "$SOCK"
/usr/lib/postgresql/17/bin/postgres -D "$DATA" -k "$SOCK" -p5544 \
  -c listen_addresses='' -c max_wal_size=128MB -c min_wal_size=32MB
```

In another terminal, from repo root, substitute the printed socket path:

```sh
EPCC_PROOF_SOCKET=/tmp/epcc-journal-sock-REPLACE_WITH_PRINTED_VALUE \
EPCC_PROOF_ALLOW_RESET=epcc_journal_proof \
EPCC_PROOF_EVIDENCE=/path/to/new-summary.json \
  /usr/bin/python3 prototype/persistence/test_postgres.py
```

**Destructive in the proof DB only:** the runner drops/recreates `epcc_journal_proof`, uses two dedicated fixture roles and creates the proof schema. Guards require an explicit reset confirmation, expected socket/data-directory prefixes, and no TCP listener; still inspect all commands and use only an expendable cluster. It refuses normal app connection settings and does not read app .env. Stop the isolated server after tests. Packages, processes and `/tmp` data may disappear after reset; source/evidence are preserved.

## 6. Current gate and next implementation slice

**Closed for this bounded prototype:** real-PG composite constraints, forced RLS, immutable DML, archive-write guards, atomic commit/rollback and observed contention. These are now executed facts, not only a proposed test plan.

**Still open:** application persistence adapter/migration, multi-scope manifest/topology/evidence/legacy interfaces, human authority, real application participant/archive/RLS regression tests and unresolved-state consumers. The next useful slice is integrating the bounded NEW-acceptance journal with real accepted snapshots under an explicitly fail-closed scope/evidence contract; keep the production board switch off until missing interfaces are handled. Do not label the entire ADR-024 or pilot complete.

Existing443 baseline source hashes remain unchanged; the previous full application1083/16385 result is historical and was not rerun this turn. No commit/push/PR, production migration or customer deployment.
