# ADR-060 — Inspection Release Notes (تأییدیهٔ آزادسازیِ بازرسی)

2026-11-01 · **ACCEPTED — IMPLEMENTED, THEN AMENDED BY THE ADR-041 MERGE**
Companion: [فارسی](ADR-060-INSPECTION-RELEASE-NOTES-FA.md). Schema: `2026_11_01_120000_add_inspection_release_notes.php`.

## The gap this closes

The platform could record that an inspector **looked** at material
(`material_inspections`: PASS / FAIL / CONDITIONAL) and it could record that the
material **arrived** (the custody chain). It could not record the act that joins
them: the inspector's release, which is the thing that turns "an inspector
attended" into "this may ship" and then "this may be received".

ADR-041 made inspection advisory **on purpose**, and that was the right call for
the slice it was written in. But the consequence was left unattended: a FAIL
verdict had nowhere to land. Nothing downstream ever asked whether anyone had
released the material, so the effective answer was always yes. An order could
carry a failed pre-shipment inspection and still be received, put into stock and
built into a line — with no refusal anywhere in the system.

This ADR gives the verdict somewhere to land. It changes ADR-041's stance in
exactly one respect, and says so: **an inspection is still advisory, except that
a FAILED one now holds the material until a release covers it.**

## The merge: one record, not two (2026-11-02)

The owner's reading of the original was that `material_inspections` — the
ADR-041 "inspection entry" — was always meant to BE the release note, and was
built wrong. The evidence supports it: that record carried the `-IRN-` number
series, came from a third-party inspector (TPI), had a PRE_SHIPMENT stage, and
had no effect on anything — which is what "built wrong" looks like from
outside.

So the observation is retired and its duties move onto this record. A straight
deletion was considered and rejected, because it would have taken two real
capabilities with it:

- **ON_ARRIVAL inspection** — inspecting goods AFTER they arrive, the only way
  the platform could inspect received material at all.
- **FINDINGS** — mandatory for a refusal, because a vendor handed "not
  released" with nothing stated has no way to act on it.

Both are carried across. `material_inspections`, `inspection_author_policies`,
`InspectionEntryService` and `InspectionAccessService` are gone; the number
series is now `-IRN-` on this record; `material_lots` points here instead, and
the lot verdict is derived from the outcome so the warehouse screens keep the
vocabulary they were built around.

## Decisions

- `release_authority = separate_register`: releasing material for shipment is a
  third authority, not a reuse of the results register or of ADR-041's
  inspection register. The engineer who records an observation is not
  automatically the party who may release goods. Default-deny, nominated per
  project by the owner, versioned, replace-never-edit.
- `release_granularity = purchase_order_line`: a release is per line, not per
  order. `material_inspections` attaches to the order header, which is why the
  platform cannot answer "has *this line* been released" — and an order whose
  valves were released says nothing about its gaskets. The order-level view is
  **derived** by `epcc_po_release_status()`, never stored.
- `release_numbering = -IRN-`: the series the owner has always used for this document. It used to belong to the observation; the observation is gone, so the series now belongs to the record it always named. `-IRL-` (briefly introduced, never released) is withdrawn.
- `third_party_attribution = user_or_named_person_at_a_firm`: the inspector may
  be a platform user or a person at an inspection firm who will never hold an
  account. Both are representable; **neither may be anonymous** — the guard
  refuses a release with no attribution.
- `gate_shape = one_sided`: **absence of inspection does not block.** Material
  nobody inspected is a planning gap, not a quality hold, and blocking it would
  stop every project that has not switched inspection on. A FAILED inspection
  blocks; an explicit refusal blocks; a release clears. PASS and CONDITIONAL
  never block.
- `enforcement_point = custody_creation`: the gate fires when a custody record
  is created against a purchase order line, because that is the moment material
  becomes usable and the last point at which refusing costs nothing. A receipt
  recorded without a resolved line carries `unlinked_reason` and is **not**
  gated — the platform cannot check a release for an order it was not told
  about.
- `findings = required_for_a_refusal`: a refusal must state its findings in at
  least one language — ADR-041's own rule, carried forward. A release WITH
  HOLDS does not also need findings: the holds note IS its statement, and
  demanding both would be demanding the same thing twice.
- `received_goods = first_class`: `stage` includes `WORKS` and `ON_ARRIVAL`,
  not just release points. Inspecting at the vendor's works and inspecting
  goods that have already landed are both real inspections of real material,
  and the merge was not allowed to lose either.
- `lot_verdict = derived`: `material_lots.inspection_result` is derived from the
  outcome (RELEASED → PASS, RELEASED_WITH_HOLDS → CONDITIONAL, NOT_RELEASED →
  FAIL) rather than retired, because a live warehouse screen and
  `uninspectedLots()` speak that vocabulary.
- `holds = stated_not_tracked`: `RELEASED_WITH_HOLDS` must state its holds in at
  least one language, and the material may move but the hold stands. **Tracing a
  hold to its clearance is the TQ/NCR slice (ADR-031), which is the honest home
  for a defect that has to travel.** This ADR deliberately does not build it.

## Existing rules preserved, not silently replaced

ADR-041's company restrictions (TPI / PMC / MC / OWN), its findings requirement
for a non-PASS verdict, its `%s-IRN-%04d` observation series and its
"nomination never widens PO visibility" boundary are all unchanged. Release
notes are additive: an order with no release note behaves exactly as it did
before, which is why nothing in the existing suite needed to change.
`WarehouseService::receive` (the legacy, non-piping path) is untouched by this
slice; the gate is wired into the controlled piping custody path.

## Implemented scope

Two append-only registers, guarded and tenant-scoped:

- `irn_issuer_policies` — who may release. Mirrors `inspection_author_policies`:
  versioned, reason required, selectors are users or company-scoped project
  roles, no UPDATE/DELETE policy at all. Resolved by `epcc_irn_nomination()`,
  keyed by **project** (the owner writes one register naming people across the
  participating companies) with the caller's company checked against their own
  user row, membership and role selectors.
- `inspection_release_notes` — the release. Per line; `stage ∈ {PRE_SHIPMENT,
  ON_ARRIVAL}`; `outcome ∈ {RELEASED, RELEASED_WITH_HOLDS, NOT_RELEASED}`;
  optional `source_inspection_id` naming the inspection it answers; third-party
  attribution; append-only with a supersession chain. The order id is **derived
  from the line**, never declared by the caller.

Guard-enforced invariants: not nominated → refused; line outside the project →
refused; release with holds and no stated holds → refused; refusal with no
inspection → refused; anonymous inspector → refused. Numbering is derived
inside the guard under a project-scoped advisory lock.

One reader answers the gate for every caller:
`epcc_po_release_status(order_id) → BLOCKED | RELEASED | NOT_REQUIRED`, used by
`InspectionReleaseNoteService::isBlocked()` and enforced at custody creation
with the bilingual `piping_custody.release_blocked` message.

## Evidence and deferred work

**21 targeted tests / 55 assertions PASS** after the merge, including two
end-to-end cases that prove the point of the slice: a refusal now **blocks
physical receipt**, and a later release **lets the receipt through**. Coverage
also includes nomination refusal and withdrawal, numbering in the `-IRN-`
series, the per-line order derivation, refusal-then-supersession, the guard
invariants both through the service and around it, append-only behaviour,
versioned nomination, ON_ARRIVAL and WORKS inspection, the derived lot verdict,
and that a release on one line does not release another.

Deferred, deliberately:

- **Holds are not tracked to clearance** — ADR-031 (TQ/NCR), still unimplemented.
- **Equipment release** — there is no equipment or nozzle entity in the platform
  (see the process assessment); an IRN for equipment is blocked behind that.
- **Heat-level release** — a release is per line. `component_material_links`
  (A.4b) can now name the heat a piece came from, so a release could be made
  heat-precise; that is a follow-on, not part of this slice.
- **Shipment-side enforcement** — the gate fires at receipt, which is where the
  platform's authority ends. Refusing the truck is a vendor-portal concern
  (ADR-058, PROPOSED).
- **UI** — the nomination screen and the per-line issuance form ship with this
  slice. Naming an EXTERNAL inspector (a person at an inspection firm who will
  never hold an account) is supported by the service and the guard but needs a
  company picker the screen does not have yet.
