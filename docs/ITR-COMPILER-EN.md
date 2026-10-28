# ITR Compiler — ADR-048 A.5 Slice 1 (EN)

> Status: implemented service-only, 2026-10-28. Persian twin: [`ITR-COMPILER-FA.md`](ITR-COMPILER-FA.md).
> Decision record: [`ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md) (ACCEPTED, decision 5 `itr_generation=both`).
> Builds on: [`INSPECTION-RESULTS-EN.md`](INSPECTION-RESULTS-EN.md) (ADR-048 slice 1 — the records this compiler assembles).

## What this is

The Inspection & Test Record (ITR) compiler turns the result records of one
**test pack** into a reviewable dossier and, on demand, into a **frozen,
digest-bound snapshot** for handover — ADR-048 decision 5 (`both`).

- **On-demand compilation** (`ItrCompilerService::compile`) is a pure read:
  pack identity, member lines, every joint with its weld + NDT history, and
  the pack's test results — plus the **completeness report**. Nothing is stored.
- **Freezing** (`freeze`) writes one row to `itr_snapshots` (append-only,
  RLS + FORCE). The dossier, the completeness report and the `sha256` digest
  are derived **by the insert trigger** from the live tables — the same SQL
  bodies the on-demand read uses. A client cannot declare content into a
  snapshot; a snapshot cannot claim results that do not exist.
- **PDF export** (`renderPdf`) is optional at runtime: it uses the house
  renderer (headless Chromium + Vazirmatn, `print.layout` shell). No browser on
  the host → `null`, never a fake. The snapshot itself is data, not bytes.

## The completeness report (explicit, never a certificate)

The dossier `kind` is literally `ITR_COMPILATION_NOT_A_CERTIFICATE`. The
completeness report enumerates every gap instead of hiding it:

| Key | Enumerates |
|---|---|
| `lines_not_released` | member lines whose `release_state ≠ RELEASED` |
| `joints_not_fitted` | joints whose state is not `FITTED` |
| `joints_without_weld_record` | fitted-or-not joints with zero weld records |
| `joints_without_ndt` | joints with welds or not, but no examination result |
| `joints_with_open_rejection` | joints whose **latest per-method** NDT result is REJECTED (cleared by repair + re-examination lineage, never by deletion) |
| `pack_without_passed_result` | the pack has no PASS test result (a FAIL is not a PASS) |

`is_complete` is true only when every list is empty and a PASS exists. Counts
(lines/joints/welds/ndt/test_results) ship alongside so a reader can reconcile.

## Invariants (all in the database)

1. **Trigger-derived content**: dossier + completeness + digest are computed
   server-side on insert; placeholders are overwritten (scope-proposal
   envelope precedent). `digest = sha256(dossier::text)`.
2. **Append-only**: snapshots refuse UPDATE/DELETE (`55000`). New evidence
   after a freeze does NOT rewrite it — corrections are new snapshots with
   new digests.
3. **Premature-freeze refusal**: no snapshot of a DRAFT pack
   (`ITR_PACK_NOT_READY`). On-demand compilation of any visible pack stays
   allowed — review must not be gated.
4. **Session contract**: project GUC + company GUC + `epcc.itr_actor_id`;
   insert requires `compiled_by` = current actor (no frozen record can name a
   stranger). Read = project-member companies (handover evidence is shared).
5. **Idempotency**: `unique(project_id, request_id)` — retries never double-freeze.
6. **Reader re-verification**: `snapshot()` recomputes the digest from the
   stored dossier and reports `digest_valid`; under append-only RLS a false
   would mean database-level tampering, and we surface it rather than trust.
7. **Depth guard + archive freeze**: inserts at depth 1 only
   (`ITR_MANUAL_DIRECT_ONLY`); `epcc_project_is_writable` applies.
8. **Policy freeze**: the migration asserts the exact read-policy name set
   (`{itr_snapshot_read}`) before install completes.

## Declared boundaries of THIS slice (honesty first)

- **Scope is TEST_PACK only.** Subsystem/system and full-dossier scopes are
  slice 2 (TODO **A.5b**), where they meet the ADR-057 turnover dossier.
- **ITP notifications and receipts (GRN) are NOT assembled.** No pack↔
  notification link exists in the schema, and receipt binding needs
  piece-level custody (A.4b lot/heat pairing). Padding the dossier with
  unbound data would be the opposite of the completeness discipline.
- **MTR certificates are not bound to packs** for the same reason; the
  exact-heat reader from slice 1 (`certificatesForHeat`) remains the
  traceability tool until piece→heat links land.
- **No formal signature/approval layer on the snapshot yet** — the digest
  binds content; owner/TPI acceptance semantics arrive with the handover
  dossier (ADR-057) or A.5b, whichever the owner schedules first.
- PDF is **runtime-optional** and stateless: bytes are never stored, the
  frozen row is the source of truth.

## Tests

`tests/Feature/ItrSnapshotTest.php` — 10 tests / 72 assertions (green):
explicit enumeration of every gap; open-rejection cleared only by lineage
(history never pruned — the rejected exam stays in the dossier); FAIL is not
a PASS; draft-freeze refusal + trigger-derived digest (SQL-recomputed) +
reader re-verification; append-only; member-readable / foreign-actor-refused
(42501); duplicate `request_id`; a freeze captures its moment (later evidence
does not rewrite it, new freeze → new digest); unknown packs refused without
leaking; PDF optional-by-host with the blade view always rendering.

Regression: `InspectionResultTest` + `JointTest` + `PipingGrnTest` +
`StockLedgerTest` green together (87 tests / 344 assertions).
