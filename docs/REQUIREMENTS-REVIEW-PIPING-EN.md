> **Current delivery — 2026-10-19:** [independent inspection entry](INSPECTION-ENTRY-EN.md) / [فارسی](INSPECTION-ENTRY-FA.md), [ADR-041](ADR-041-INDEPENDENT-INSPECTION-ENTRY-EN.md). **46 affected tests /558 assertions PASS**,14 new cases. Owner versioned nominations/suspension, create-only original-visible PO form, server attribution and immutable new evidence. Existing TPI/PMC/MC/OWN independence, non-PASS findings and IRN numbering retained; no default/inherited writer, PO read delegation, source/lot/financial/receipt/usable/MTO mutation. Migration190000 privately verified only; no full-suite/production claim. Older current notices/counts below are historical and nonadditive.

# Requirements review — piping discipline (first pass, 2026-10-12)

Owner directive (2026-10-12): «چرا نیازمندی‌ها رو درست بررسی نمی‌کنی، تا در
صورت وجود مشکلات یا نواقص پیشنهاد اصلاح یا اضافه شدن قابلیت بدی؟» — the
requirement set must be **reviewed systematically**, not only extended
reactively while building. The P2P gap proves the point: pipe-to-pipe
branching is standard PMS vocabulary, yet no requirement document carried it
until the owner supplied it mid-build.

## Scope and method

- **Scope of this pass:** the piping requirement set — ADR-006, ADR-008,
  ADR-009, ADR-010, the piping roadmap (EN/FA), and `MTO-REQUIREMENTS-EN`.
- **Method:** for each source, read it against (a) what has since been
  decided and built, (b) the domain's standard practice (PMS branch tables,
  ISOGEN/PCF conventions, dia-inch accounting), and (c) the other documents.
  Every mismatch becomes a finding with a disposition: **FIXED** (cheap
  doc/requirement hygiene done on the spot), **PROPOSAL** (registered in
  `IMPROVEMENT-PROPOSALS-EN.md`, needs a decision), or **OK** (checked, no
  defect).
- **Cadence:** this review repeats before every build phase (see the
  "Requirements review protocol" in the proposals register). Each pass gets
  its own dated section here.

## Findings

| # | Source | Finding | Disposition |
|---|--------|---------|-------------|
| R-1 | ADR-008 §4 | The rule definition still reads `→ component_type`, with only TEE/WELDOLET in the examples — stale after the §9.1 addendum, where the outcome can be **P2P** (no component) with a kind and a mandatory pad thickness | FIXED: pointer note added in §4 to §9.1 |
| R-2 | ADR-006 §5.3 | `joints.joint_type` vocabulary (`BUTT_WELD / SOCKET_WELD / FLANGED / SCREWED / …`) has no branch-weld types, and the table connects **two ports** — a stub-in/stub-on weld lands on the header's *wall*, which has no port | Initial disposition (2026-10-12): P-21/P-22 proposed; P-22 decision and P2P wall-joint model are now implemented in ADR-006 §14 (see outcome update); P-21 remains partial pending the full P5 weld-reporting/workflow acceptance |
| R-3 | ADR-009 | Manual modelling assumes a component exists at every branch: the deviation matrix covers TEE↔WELDOLET but says nothing about a class outcome of P2P ("expect NO component"), and the attach flow has no wall-attach geometry path | FIXED: P2P addendum note added; inherits P-22 for geometry |
| R-4 | ADR-010 §1/§6 | Dia-inch is `min(portA, portB)` — for a branch weld the header side is not a port; §6 open questions had nothing on branch welds | FIXED: open question 4 added (branch nominal per "smaller governs", kept separate per P-21) |
| R-5 | MTO requirements (EN/FA) | Zero coverage of branch-connection material: a reinforcement pad is a plate/material item and a stub-in stub end is coped pipe — neither appears anywhere in the MTO requirements | PROPOSAL **P-23** (MTO coverage; decision when the MTO↔piping integration phase is designed) |
| R-6 | Piping roadmap FA | FA document lagged EN: no P2P/stub-in/stub-on/pad content in phase P1, no branch-weld bullet in phase P5 (documentation rule 2: keep docs current) | FIXED: FA synced to EN |
| R-7 | ADR-008 §9.1 vs ADR-010 §4 row 008 | ADR-010 §4 says "the branch table is unaffected" by equipment — still true (a nozzle is never a branch), checked against the §9.1 vocabulary | OK |
| R-8 | ADR-006 §11.2 | Flanged joints unnumbered "for now" — untouched by the P2P decision; re-checked, no conflict | OK |

## What this pass did NOT resolve at its 2026-10-12 cutoff

- P-22's modelling choice (synthesised wall port vs line+station reference)
  was still a design decision at that cutoff.
- P-23 was and remains a scope/commercial item; per rule 5 it waits for the owner.
- Disciplines beyond piping (civil, electrical, procurement) were not reviewed
  in this pass — later passes should take one discipline per session.

## Outcome update — 2026-10-26

R-2/P-22 is no longer an open modelling decision. ADR-006 §14 selects and
implements the frozen header-reference form: a P2P wall joint has one
branch-side port plus header/station/offset, and the header keeps exactly two
ports. The final combined targeted run for PCF parsing/admission/classification,
branch verdicts, wall joints, boundary cases and pinned PMS resolution passed
132 tests / 267 assertions.

The branch-kind/class-verdict slice is present in the joint/service path. The
OLET fabricated RUN/FA-to-header-wall weld is a separate wall-reference joint;
the BRANCH-to-pipe weld remains distinct, and the class verdict is recorded
once on the root weld. The earlier 84-test OLET run used branch ownership for
both welds. The current behavior lets the operator choose `BRANCH` (default) or `HEADER`
per joint. A later ordinary header-owned branch-line joint must link to an
explicit P2P/OLET wall root; a weld on a TEE/CROSS `BRANCH` port can instead
select a same-project header line directly, without inventing a wall root. No
boundary is inferred at a flange or valve. Physical ports and the issued PMS
verdict remain tied to the branch line; register metrics follow each selected
owner, and each physical branch weld is counted once. The line-model page
exposes wall-root creation and per-joint ownership, including independent
header-line selection for TEE/CROSS branch ports. The final combined targeted
ownership, numbering, separation, boundary, HTTP and regression command passed
**117 tests / 384 assertions**; the full suite was not run. This does **not**
complete P-21 or the P5 welding roadmap:
full weld-log/report UI, contract rates, welder qualification, WPS/PQR,
NDT/repair workflow, and a PCF-to-model writer remain open. The PCF
parser/admission remains read-only and warns about taps instead of creating a
joint. The 2026-10-12 findings table is retained as a historical snapshot.
