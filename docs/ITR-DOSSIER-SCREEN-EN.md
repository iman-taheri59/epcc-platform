# The ITR screen — A.4h slice 6 / A.5 slice 1 (English)

> Status: implemented 2026-10-30, commit `f41c1ed`. Slice of ADR-048.
> همزاد فارسی: [`ITR-DOSSIER-SCREEN-FA.md`](ITR-DOSSIER-SCREEN-FA.md).
> The compiler itself (the SQL functions, the freeze trigger and the digest) was
> specified in A.5 slice 1 and is documented in
> [`ITR-COMPILER-EN.md`](ITR-COMPILER-EN.md); the decision record is
> [`ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md).

## The compiler had no door

Since A.5 slice 1 the platform could compile an ITR dossier, freeze it under a
server-side digest, and render it — from SQL. Every other quality surface in
A.4h got a screen: nominations, results, sign-offs, MTR binding, the legacy
gate. The ITR, which is the artefact a client actually asks for at the end of
the job, was the one thing still reachable only by `psql`.

This slice gives it a door:
`/projects/{projectCode}/quality/itr` (`quality.itr`, plus `quality.itr.freeze`
and `quality.itr.pdf`). Nothing about the compiler changed. The dossier is still
built by `epcc_itr_test_pack_dossier()`, the digest is still computed by the
trigger, and the snapshot table is still append-only: the screen is a way in,
not a second implementation.

## What the screen shows

| Region | What it is |
|---|---|
| Test packs | the project's packs, each with state and subsystem; opening one compiles it |
| Dossier | the compiled document, marked `ITR_COMPILATION_NOT_A_CERTIFICATE` by the function that built it |
| Completeness | an **enumeration** of gaps, not a verdict — see below |
| Freeze | one button, only when freezing is legitimate |
| Snapshots | every frozen snapshot, each **re-verified on every page load** |

Two of those deserve the space.

### Completeness enumerates rather than judges

`epcc_itr_test_pack_completeness()` returns `is_complete`, but the screen does
not stop there. Every reason it is *not* complete is listed as its own row, with
the line and joint numbers that caused it:

- lines not released;
- joints not fitted;
- joints with no weld record;
- joints with no examination record;
- joints with an open rejection;
- a pack with no passed result.

A single *"not complete"* would be a complaint. A list of exactly which joints
are missing what is a work list, and it is the honest version: the compiler
already computed it, so collapsing it back into a boolean is the platform
throwing away the answer in order to look decisive.

### Every snapshot is re-verified, every time

The snapshot table is append-only, so a row whose `dossier` no longer matches
its `digest` means someone has been at the database — the one thing this layer
exists to make visible. The screen therefore re-hashes each snapshot as it
renders the page (capped at 20) and says so per row: `Valid`, or
`MISMATCH — the stored dossier no longer matches its digest`.

The digest is 64 hex characters taken on the dossier as the database stores it,
so a preview compiled on demand and a snapshot frozen at the same moment are the
same document byte for byte. The screen shows the first 16 characters; the full
digest is what a client's own tooling would verify against.

## What the screen refuses

**No freeze button on a DRAFT pack.** The guard refuses it
(`ITR_PACK_NOT_READY`), and it is right: a frozen snapshot of a pack nobody has
prepared is premature evidence. Offering the button and letting it fail is
discovery-on-submit — the pattern the earlier A.4h slices argued against
repeatedly, most recently for the legacy heat filter. The screen instead says
plainly that there is nothing to freeze yet.

**The PDF route never returns an empty document.** Rendering needs a headless
browser, which is optional by host. Where it is missing the route answers `503`
with an explanation rather than a zero-byte file that looks like a successful
export. The snapshot *is* the deliverable; the bytes are a courtesy. When the
renderer is present the route returns the bilingual `print.itr-dossier` view
inline, with `private, no-store`.

**A double submit is refused, not duplicated.** `request_id` is unique per
project, so the second click on a slow connection fails instead of producing a
second snapshot of one intent.

## The invariants this surface holds

1. **A non-member of the project is refused at the door** (403) — on the screen,
   on freeze, and on export.
2. **The dossier is trigger-derived.** `dossier`, `completeness` and `digest` are
   overwritten by the guard; the client sends a placeholder and cannot influence
   the result.
3. **The kind is not ours to choose.** It says compilation, not certificate.
4. **Snapshots are append-only; a correction is a new snapshot**, never an edit.
5. **Every listed snapshot is re-verified** against its digest on render.
6. **DRAFT packs are not offered a freeze.**
7. **The PDF is optional at runtime and says so** when it is unavailable.
8. **Freezing is idempotent on `request_id`.**

## What this slice deliberately does not do

- **It does not certify anything.** The dossier's own `kind` field forbids the
  reading; the signature layer that would make a certificate meaningful is A.5b
  and is still open.
- **It does not assemble ITP notifications, receipts (GRN) or certificates
  (MTR).** No pack↔notification link exists in the schema, and binding receipts
  needs piece-level custody. Enumerating unbound data would be padding, not
  honesty — the boundary was declared in A.5 slice 1 and is unchanged.
- **Scope is `TEST_PACK` only.** System- and project-level dossiers come later.
- **No pagination** — packs are capped at 200 rows and snapshots at 20, as on
  the other quality screens.

## Tests

`ItrDossierHttpTest` — 14 tests / 61 assertions, all through HTTP:

- the empty state says no pack is defined, and opening a pack shows the dossier,
  the completeness report and the gap table;
- a DRAFT pack carries the note instead of the freeze button;
- the screen switches to English; a guest is sent to login; someone outside the
  project is refused;
- freezing stores a snapshot with a 64-hex digest, state `FROZEN`, and a
  trigger-derived dossier whose `kind` and `test_pack_id` the test asserts as
  **derived** values; the status message quotes the digest it was given;
- the frozen snapshot appears on the page, re-verifies as valid, and the empty
  state is gone;
- freezing a DRAFT pack writes nothing;
- the same `request_id` twice freezes once;
- a non-UUID `request_id` is refused and writes nothing;
- someone outside the project cannot freeze or export;
- the PDF route never hands back an empty document: real bytes where the
  renderer exists, `503` where it does not.

Related regression green together — 85 tests / 379 assertions:
`ItrSnapshot`, `InspectionResult` (unit and HTTP), `ResultRecorderNominations`,
`TableTools`, `CookielessPreview`.

A note for whoever extends this: `tests/Support/ItrDossierFixture` forces the
pack's state with a direct `UPDATE` rather than `transition()`. A transition
demands the evidence the fixture is about to fake — a weld, an examination, a
passed result — and these tests need to control completeness precisely,
including the case where it is *not* complete. Keep the forcing in the fixture,
and keep the reason for it in the fixture's docblock.
