# ADR-059 — Guard SQL has one home: `database/guards/*.sql`

Status: **ACCEPTED** (2026-10-29). Implemented by migration
`2026_10_29_200000_consolidate_guard_definitions.php`, helper
`app/Support/GuardSql.php`, and `tests/Feature/GuardDefinitionsTest.php`.
Decision taken on the agent's own recommendation after the owner delegated the
choice ("هر چه نظر خودت هست") on 2026-10-29.

## Context

Forty-eight `epcc_guard_*` trigger functions carry the invariants of this
platform: that a piping custody record is not rewritten, that a QA decision is
bound to the evidence it cites, that a result is recorded by someone the project
nominated, that a legacy lot is not silently translated into a controlled one.
They are the part of the system that refuses.

Each was declared inline in the migration that introduced it. When a later slice
needed to change one IF of an existing guard, the only available move was to
re-declare the **whole function body** in a new migration — byte-for-byte
identical except the change. That has now happened often enough to be a
maintenance hazard:

- `epcc_guard_notification` — declared in three migrations
  (`2026_10_19_200000`, `2026_10_20_100000`, `2026_10_20_110000`).
- `epcc_guard_piping_grn` — two (`2026_10_25_110000`, and again in A.4f
  `2026_10_29_140000`, re-declared whole to change one IF).
- `epcc_guard_material_certificate`, `epcc_guard_joint_result`,
  `epcc_guard_piping_qa`, `epcc_guard_piping_receiving_review`, and a dozen more —
  two copies each.

Copies must be kept in step by hand, and **drift is silent**: the database ends
up saying something that no single file in the repository says. Reading a
migration tells you what the guard said at that point in history, not what it
says now; the only way to know the current text is to ask a running database.
This is the opposite of how the rest of the repository behaves.

Two things made it survivable so far, and both are about to stop being true:

1. The re-declarations were verified byte-for-byte against `pg_proc.prosrc`
   before being trusted. That is a manual discipline, not a guarantee, and it
   scales with the number of copies.
2. The A.4g HTTP/UI work will add more surfaces, more invariants, and therefore
   more guards. Duplication grows monotonically from here.

The blocking consideration until today was that consolidating means replacing
function bodies in an installed database. The owner has now stated (2026-10-29)
that **no project has ever been registered with this software**, so there is no
installed database whose guards must be carried forward untouched. The window for
this change is open now and closes the moment the first real project is onboarded.

## Decision

**`database/guards/<name>.sql` is the only place the current text of a guard
lives.** One file per guard, each a complete `CREATE OR REPLACE FUNCTION`
statement — the text `pg_get_functiondef()` reports, i.e. exactly what the
database is running today.

1. **The consolidated pass.** Migration `2026_10_29_200000_consolidate_guard_definitions.php`
   runs after every historical declaration and applies all 48 files. The inline
   copies stay in their migrations — they are how a database gets built in the
   first place, and triggers bind functions at their own point in the timeline —
   but from that migration onwards they are history, not truth. The pass also
   refuses to continue if a file fails to install or is not idempotent.

2. **Changing a guard means editing its file.** No re-declaration in a new
   migration. Because the consolidated pass applies the files, editing the file
   *is* the change.

3. **Binding rule.** A migration whose `CREATE TRIGGER` binds a guard must call
   `GuardSql::apply($name)` immediately before it, so the trigger binds the
   current text even when the file was edited in the same change. A new guard is
   added as a file, then applied and bound the same way.

4. **A guard never changes silently again.** The helper `App\Support\GuardSql`
   exposes `names()`, `body()`, `apply()`, `installed()` and `definitionOf()`.
   `GuardDefinitionsTest` asserts, on every run:
   - the set of files equals the set of installed guards (no orphan file, no
     unregistered function);
   - re-applying every file changes nothing — i.e. a freshly migrated database
     already agrees with the registry;
   - each file is valid SQL and idempotent on its own;
   - **no migration dated after the consolidated pass declares a guard inline**;
   - every registered guard is actually bound by some trigger.

   The fourth assertion is the one that enforces the rule: an inline
   re-declaration in a later migration fails CI instead of quietly taking
   precedence over the file.

5. **Naming is identity.** Guard names are validated against
   `^epcc_guard_[a-z0-9_]+$` because a name becomes a path.

## Consequences

**Positive**

- One file to read, one file to change, one file to review. A reviewer sees the
  whole guard in a diff instead of a 200-line blob whose only visible change is
  one line.
- Drift becomes a test failure rather than a difference between two files nobody
  compares.
- A.4g (and every slice after it) inherits the clean pattern instead of adding
  another copy.
- `pg_get_functiondef` means the files were extracted from the running database,
  not transcribed: the consolidated pass is a no-op by construction. Behaviour is
  unchanged, which the regression run confirms.

**Negative / accepted**

- Two places still mention a guard: its file (current) and its historical
  migration (past). This is deliberate — deleting history would break
  `migrate:fresh` ordering — but it means "search for the guard" returns several
  hits, of which exactly one is authoritative. The test tells you which.
- The consolidated pass runs at a fixed point in the timeline. A guard edited
  later is applied by that slice's own `GuardSql::apply()` call (binding rule 3);
  without it, a trigger created by a later migration would bind the text as of
  the consolidated pass. Forgetting the call is caught by the test, not silently.
- `down()` throws, as everywhere else in this schema.

**Deliberately out of scope**

- Non-guard functions (`epcc_piping_receipt_policy`, `epcc_post_piping_stock_credit`,
  …) and RLS policies stay where they are. Guards are the duplicated, invariant-
  carrying part; widening this to all SQL would be a much larger change for no
  immediate gain.
- Squashing the 100 migrations into a baseline. Now that there is no production
  data it is *possible*, but it would destroy the reviewable history of how each
  invariant was arrived at, and nothing currently requires it.

## Verification

- `GuardDefinitionsTest` — 6 tests / 150 assertions green.
- Related regression (Piping, StockLedger, Warehouse, Material, Legacy,
  MtrCustody, InspectionResult, ItpSignOff, ItrSnapshot, ResultAuthority,
  ResultUnits, GuardDefinitions) green — the consolidated pass changes no
  behaviour.
- Full suite left to CI, per standing practice.
