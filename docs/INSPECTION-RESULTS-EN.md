# Inspection & Test Result Records — ADR-048 Slice 1 (EN)

> Status: implemented service-only, 2026-10-28. Persian twin: [`INSPECTION-RESULTS-FA.md`](INSPECTION-RESULTS-FA.md).
> Decision record: [`ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md) (ACCEPTED).

## What this is

The platform could announce inspections (ITP notifications, ADR-042…045) and accept
deliveries (MRR→QC→QA→GRN, ADR-046), but it could not record **results**: whether a
weld passed RT, whether a hydro test held pressure, which heat a pipe piece's MTR came
from. This slice closes that gap with four append-only record families, each RLS +
FORCE, each guarded by database triggers in house style:

| Table | Binds to | Records |
|---|---|---|
| `joint_weld_records` | a **FITTED** joint | WELD / REPAIR_WELD (reason mandatory for repairs), welder stamp code, process, consumable lot |
| `joint_ndt_results` | a welded joint | one examination per method (RT/UT/MT/PT/VT): ACCEPTED/REJECTED, defect text on rejects, re-examination lineage |
| `test_pack_results` | a **READY** test pack | HYDROSTATIC/PNEUMATIC: medium, pressure+unit, hold minutes, time window, PASS/FAIL, retest lineage |
| `material_certificates` | a project | MTR / EN 10204 3.1 / 3.2 bound to an exact heat (+ optional lot), named issuer, optional document |

`InspectionResultService` is the only intended write path (session contract:
project GUC + company GUC + `epcc.result_actor_id`), with bilingual validation errors.

## Invariants (all in the database)

1. **Append-only**: every table refuses UPDATE/DELETE by trigger (`55000`). A correction
   is a new record — with `previous_result_id` lineage where re-examination applies.
2. **Honest ordering**: weld/NDT results only on a joint in state `FITTED` (a result on
   an unfitted joint is fiction); NDT only after a weld record exists; pack results only
   when the pack left `DRAFT` (state ≠ DRAFT).
3. **Lineage integrity**: a re-examination must reference a REJECTED result of the *same
   joint and method*; a retest must reference a FAILED result of the *same pack*.
4. **Paired honesty of reasons**: REJECTED ⟺ defect description; FAIL ⟺ cause
   description; REPAIR_WELD ⟺ reason. Accepted/passed records carry no negative text.
5. **Two provenance modes (ADR-031 precedent)**: `IN_SYSTEM` or `EXTERNAL_REGISTER`.
   An external record must name `performer_org` + `performer_name` + `report_ref`
   (CHECK-enforced pairing in both directions) — external signatures are registered
   evidence, never presented as platform-collected.
6. **Stamp-code identity**: welders/NDT technicians are recorded by personnel stamp code
   (like `joints.fitted_by_code`) with an optional `users` FK — site workers have no
   logins, but every record still names a person.
7. **Idempotency**: `unique(project_id, request_id)` per family — retries never double-post.
8. **RLS**: read = project-member companies (quality evidence is shared: a TPI company
   reads the EPC's results); insert = matching project/company GUCs **and**
   `recorded_by = epcc.result_actor_id` — no session can write a record naming another
   actor. Archive freeze (`epcc_project_is_writable`) applies.
9. **Depth guard**: inserts only at depth 1 (`RESULT_MANUAL_DIRECT_ONLY`) — records are
   posted by the service, never as a side effect of nested triggers.
10. **Heat exactness**: `certificatesForHeat` matches the heat string exactly; `HEAT-7`
    never merges with `HEAT-77`. Traceability piece→heat→certificate is exact or nothing.

## Declared boundaries of THIS slice (honesty first)

- **ITP hold/witness sign-offs are NOT here.** Structured sign-off records on
  notification stages (decision point 3) = slice 2, tracked as TODO **A.4b** (and G.5
  for TPI witnesses). This slice records results on joints/packs, not on ITP stages.
- `joint.state` stays `FITTED` — weld/NDT progress is **derived** from records, never
  stamped back onto the joint (joint states remain the ADR-006 contract).
- Test-pack transitions (READY→TESTED→ACCEPTED) are **not yet gated** on recorded
  results; that coupling arrives with the ITR compiler (A.5) or an explicit A.4b rule.
- `pressure_unit` is a required string but not yet unit-registry-validated (ADR-014
  `units->knows`) — slice 2.
- MTR binding is heat-first with optional lot (decision point 4 recommended lot+heat;
  exact (lot, heat) pairing lands with A.4b when lot custody links are joined in).
- ITR compilation (decision point 5) = **A.5**, unchanged.
- No role-scoped authority policies yet: any project-member user of the recording
  company may post (person-level separation only). Inspector ≠ notifier ≠ acceptor at
  *role* level follows the A.2b pattern in slice 2.

## Tests

`tests/Feature/InspectionResultTest.php` — 10 tests / 69 assertions (green):
FITTED-only gate, repair-reason pairing, append-only on all four tables, NDT
weld-first + reject-reason + method whitelist, re-examination lineage (incl.
cross-method refusal), external-mode identity in both directions, member-readable /
foreign-actor-refused (42501), DRAFT-pack refusal + time window + positive pressure,
retest lineage, MTR required fields + dangling-document refusal + heat exactness,
duplicate `request_id` refused on welds, packs and certificates.

Regression: `JointTest` + `PipingGrnTest` + `StockLedgerTest` green together
(77 tests / 275 assertions).
