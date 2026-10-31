# The dossier and its scope — A.5b slice 3a (English)

> Status: implemented 2026-10-31, commit `41936319`. Slice of ADR-048.
> Persian twin: [`ITR-SCOPES-FA.md`](ITR-SCOPES-FA.md).
> What this widens: [`ITR-COMPILER-EN.md`](ITR-COMPILER-EN.md); the name on the
> dossier: [`ITR-SIGNATURES-EN.md`](ITR-SIGNATURES-EN.md); the gate that made
> notices load bearing: [`PACK-NOTIFICATION-GATE-EN.md`](PACK-NOTIFICATION-GATE-EN.md).

## The unit a contractor tests is not the unit a client takes over

Since A.5 the platform compiles an ITR dossier, and since slice 2 somebody can
sign it. Both were true of exactly one thing: a test pack.

That is the right unit for one job and the wrong unit for the other. A
contractor tests a pack because a pack is what can be pressurised on one day
with one medium. A client takes over a *subsystem* — the thing that is
energised, handed to operations, and argued about later — and then the plant.
A plant's handover dossier assembled from four hundred pack dossiers, each with
its own completeness report, is not a handover document: it is a filing cabinet
with a promise that the sum of four hundred honest reports is an honest report.
It is not, because no report ever looked at a line that was in **no** pack.

So a dossier now compiles for a scope. Widening it is the easy part; the
interesting part is that a wider scope can see things a pack scope cannot, and
one of them is a gap that had to exist for years without anybody being able to
ask about it.

## Three scopes, because three exist in the data

`itr_snapshots` gained `scope_kind` and `scope_ref`:

| Scope | `scope_ref` | What the dossier is about |
|---|---|---|
| `TEST_PACK` | the pack's id | one test pack — exactly what A.5 compiled |
| `SUBSYSTEM` | the subsystem's id | every line of that subsystem, whatever pack they sit in |
| `PROJECT` | none | every line of the project |

**There is no `SYSTEM` scope**, and this is a deliberate refusal rather than an
oversight. The breakdown is facilities, areas, subsystems (ADR-004) — there is
no system level anywhere in the schema. Adding one to the ITR would be the
platform declaring a hierarchy the project never stated, and every downstream
question ("what is in system 2200?") would then have an answer the project
never gave. When a project wants systems, that is a breakdown decision, not a
dossier decision.

A project scope takes **no** reference, and refuses one. The row already names
the project; accepting a reference beside it would let two rows claim the same
dossier under two names, which is precisely the drift the resolver below exists
to prevent.

## One resolver, and both documents read it

`epcc_itr_scope_lines(project_id, kind, ref)` answers one question — *which
lines does this dossier mean* — and both the dossier body and the completeness
report are built on top of it.

That sounds like tidiness. It is the whole point. A dossier and its completeness
report that were computed by two queries with two notions of "in scope" would
eventually disagree, and the disagreement would take the form of a document
declaring itself complete over work it never listed. One resolver means the
question has one answer.

The old `epcc_itr_test_pack_dossier()` and `epcc_itr_test_pack_completeness()`
were not rewritten in parallel — they are now thin wrappers over the general
bodies for `TEST_PACK`. A preview and a frozen snapshot still agree
byte-for-byte (a property A.5 established and a test asserts), and every caller
written since A.5 keeps working.

## Two gaps a pack scope could never see

**A line that belongs to no test pack has never been tested.** No pack dossier
could report this: the pack is what defines the population, so a line with no
pack is outside every pack's world. A subsystem dossier has a population
defined independently of packs, so it can finally ask the question — and
`lines_without_test_pack` is the answer. A subsystem dossier that stayed silent
about such a line would be the most dangerous kind of green: complete, signed,
and blind.

**A pack with no PASS result is named, not flagged.** The old
`pack_without_passed_result` was a boolean, which is honest when there can only
be one pack. A subsystem with forty packs needs a work list, so
`packs_without_passed_result` is a list of `{pack_code}`. `is_complete` also
requires at least one pack in scope: a subsystem with no packs at all is not
"complete", it is untested.

## A result without its pack is a document its reader has to guess at

A subsystem dossier draws results from every pack in its scope, so a hydrotest
row saying `result: PASS, pressure: 21.75 BAR` is now ambiguous in a way it was
not before — which pack was this? So:

- every result carries `test_pack_code`;
- `test_packs[]` lists every pack the dossier draws on, with its state and its
  subsystem;
- every line carries its `subsystem_code`, and every joint its `line_number`,
  because a joint numbered `J-12` is meaningless once there is more than one
  line in the document.

The printed dossier shows the pack column only when there is more than one pack
in scope: on a pack dossier the column would be a constant, and a constant
column is noise.

## What a wider scope does not inherit

**No "not ready" rule.** A pack in `DRAFT` cannot be frozen, because freezing a
pack is a claim about a test campaign that has not been assembled yet, and A.5
refused it. A subsystem is not a claim anybody declares ready — it is a slice of
the plant — so there is no premature-freeze refusal for `SUBSYSTEM` or
`PROJECT`. There is a completeness report instead, which says what is missing
rather than refusing to be asked.

**No new authority.** Signing a subsystem dossier is the same act as signing a
pack one: default-deny behind `sign_itr_snapshot`, chained, append-only, and
refused over enumerated gaps. The signature table's `scope_kind`/`scope_ref`
filter means a company's signature history for a subsystem and for a pack inside
it are two different histories, as they should be — signing the subsystem is not
signing the pack, and neither stands in for the other.

**The advisory lock is per scope.** Two sessions freezing the same subsystem in
the same second serialise on `'itr-snapshot:SUBSYSTEM:<id>'`; two different
scopes do not block each other, because they are not competing over the same
document.

## Declared boundaries

- **The dossier is still not a certificate.** `kind` is unchanged, and a
  subsystem dossier is no more an acceptance than a pack one was.
- **The scope is a slice of the plant, not a claim about discipline.** A
  subsystem dossier still assembles the piping evidence that A.5 assembled; it
  gathers nothing new.
- **Not yet:** receipts (GRN) and material certificates (MTR) inside the
  dossier, which needs piece-level custody — that is **A.5b slice 3b**.
- **Visibility is RLS, as everywhere:** a scope is only a scope for a session
  that can see the subsystem or the pack it names; a subsystem from another
  project is refused, and so is an unknown scope kind.

## What is enforced where

| Rule | Enforced in |
|---|---|
| `scope_kind` ∈ {TEST_PACK, SUBSYSTEM, PROJECT} | CHECK `itr_snapshots_check`, and `epcc_guard_itr_snapshot()` (`ITR_SCOPE_UNKNOWN`) |
| a pack/subsystem scope references an existing row of that kind in this project | `epcc_guard_itr_snapshot()` (`ITR_PACK_NOT_FOUND` / `ITR_SUBSYSTEM_NOT_FOUND`) |
| the project scope takes no reference | `epcc_guard_itr_snapshot()` (`ITR_SCOPE_REF_MISMATCH`) |
| lines, joints, results and gaps all come from one resolver | `epcc_itr_scope_lines()`, read by both bodies |
| a pack in `DRAFT` cannot be frozen (pack scope only) | `epcc_guard_itr_snapshot()` (`ITR_PACK_NOT_READY`) |
| one freeze per scope at a time | advisory lock keyed `itr-snapshot:<kind>:<ref>` |
| dossier and completeness derived, never declared | trigger, as in A.5 |

The guard is a file — `database/guards/epcc_guard_itr_snapshot.sql` — applied by
`GuardSql::apply()` before the trigger binds it, per ADR-059.

## Tests

`tests/Feature/ItrScopeTest.php`, 19 tests / 71 assertions:

- a subsystem dossier is about every line of that subsystem and does not reach
  into another one; a project dossier is about all of them;
- the wrapper and the general body produce the same document, byte-for-byte;
- a subsystem dossier names the pack each result belongs to;
- a line with no pack is an enumerated gap, and every pack with no PASS is named;
- a subsystem dossier is complete only when every pack in scope passed;
- a draft pack still cannot be frozen, a subsystem can, and an unknown scope, a
  foreign subsystem and a project scope carrying a reference are all refused
  before anything is stored;
- the screen offers all three scopes, opens the one chosen, and shows the pack
  with no PASS as a work list rather than a flag.
