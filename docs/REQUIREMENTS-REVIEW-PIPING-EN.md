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
| R-2 | ADR-006 §5.3 | `joints.joint_type` vocabulary (`BUTT_WELD / SOCKET_WELD / FLANGED / SCREWED / …`) has no branch-weld types, and the table connects **two ports** — a stub-in/stub-on weld lands on the header's *wall*, which has no port | FIXED (vocabulary pointer to P-21); PROPOSAL **P-22** (wall-attach modelling, decision needed); ADR-006 §13 forward note added |
| R-3 | ADR-009 | Manual modelling assumes a component exists at every branch: the deviation matrix covers TEE↔WELDOLET but says nothing about a class outcome of P2P ("expect NO component"), and the attach flow has no wall-attach geometry path | FIXED: P2P addendum note added; inherits P-22 for geometry |
| R-4 | ADR-010 §1/§6 | Dia-inch is `min(portA, portB)` — for a branch weld the header side is not a port; §6 open questions had nothing on branch welds | FIXED: open question 4 added (branch nominal per "smaller governs", kept separate per P-21) |
| R-5 | MTO requirements (EN/FA) | Zero coverage of branch-connection material: a reinforcement pad is a plate/material item and a stub-in stub end is coped pipe — neither appears anywhere in the MTO requirements | PROPOSAL **P-23** (MTO coverage; decision when the MTO↔piping integration phase is designed) |
| R-6 | Piping roadmap FA | FA document lagged EN: no P2P/stub-in/stub-on/pad content in phase P1, no branch-weld bullet in phase P5 (documentation rule 2: keep docs current) | FIXED: FA synced to EN |
| R-7 | ADR-008 §9.1 vs ADR-010 §4 row 008 | ADR-010 §4 says "the branch table is unaffected" by equipment — still true (a nozzle is never a branch), checked against the §9.1 vocabulary | OK |
| R-8 | ADR-006 §11.2 | Flanged joints unnumbered "for now" — untouched by the P2P decision; re-checked, no conflict | OK |

## What this pass does NOT resolve

- P-22's modelling choice (synthesised wall port vs line+station reference)
  is a genuine design decision with schema consequences; it is registered,
  not guessed.
- P-23 is a scope/commercial item; per rule 5 it waits for the owner.
- Disciplines beyond piping (civil, electrical, procurement) are not yet
  reviewed — next passes, one discipline per session, oldest ADR first.
