# ADR-EPCC-015 — Purchase-order attribution and the order gap (MTO Slice 6)

**Status:** IMPLEMENTED — awaiting runtime verification (code, routes, screen and `OrderGapTest` landed 2026-10-11; `php artisan test` on the owner's Laragon machine is the verification step)
**Date:** 2026-10-11
**Depends on:** Slice 4 accepted immutable MTO rows (built), the Material Library (built), ADR-014 (units)
**Relates to:** ADR-005 (engineering data ingestion — PCF import will later feed the net side), ADR-010 (dia-inch is a progress metric, not a procurement one)
**Supersedes nothing.** The domain rules below were accepted in `docs/MTO-REQUIREMENTS-EN.md` §3.2 and §A5 (2026-10-06 reconciliation); this ADR carries them forward as the standing requirements record per the 2026-10-11 operating rules — requirements live in English ADRs and are kept current.

**Trigger (user):** «Slice 6 — تخصیص خطوط سفارش خرید و شکاف سفارش» — the next slice after the gross/net comparison, asked about and confirmed as the next build target on 2026-10-11.

---

## 1. Context

Slices 2–5 are built: the Material Library (`material_items`), MTO document/revision/staging, immutable accepted MTO snapshots (`accepted_mto_rows`), and net engineering estimates with a gross/net comparison (`engineering_estimate_rows`). The accepted MTO is the **gross procurement-planning quantity**. What the project has *actually ordered* is not yet connected to it at all:

- `purchase_order_items` identifies material by a free-text `item_code` string — there is **no FK to `material_items`**, so a PO line cannot be rolled up onto the catalogue identity the MTO side uses.
- There is no amendment lineage on POs and no defined effective-state vocabulary (`PurchaseOrderState` exists only as a migration comment; no enum class, no seeded values).
- Scope attribution on a PO line is at most `subsystem_id` plus a free-text `line_no`; nothing links a PO line to an accepted MTO row or to a scope split.

Without this slice, "we have ordered enough" is a sentence nobody can check inside the system.

## 2. Accepted domain rules (from MTO-REQUIREMENTS §3.2 — do not relitigate)

1. **Order Gap and Physical Material Gap are two separate indicators.** This ADR covers only the order gap. The usable-material (receipt) gap is Slice 7 and is blocked on a user decision about usable states.
2. **Definition:**

   ```text
   Order Gap = Accepted Gross MTO Quantity − Valid Issued/In-force Net PO Quantity
   ```

3. Draft and cancelled POs are **excluded**. Amendments **replace** the previous quantity (lineage, not accumulation).
4. PO quantity above the accepted MTO is **over-plan / over-ordered and is shown separately** — never as a negative shortage.
5. No percentage tolerance. No inference of cause (waste factor, contingency, over-ordering) unless explicit data says so.

## 3. Requirements

### R1 — PO line material attribution

Every `purchase_order_items` row must carry a `material_item_id` FK into the Material Library, resolved by **exact commodity-code identity** (the alias-is-not-identity rule from `MaterialCatalogueService`). A PO line whose material cannot be resolved stays **unresolved** and appears in an explicit unmatched bucket — the system never invents an identity.

Unit agreement follows the Slice 3 rule: a unit that does not match the catalogue identity for that material is a validation failure, not a conversion.

### R2 — Effective quantity and state vocabulary

Define `PurchaseOrderState` (proposed: `DRAFT → ISSUED → AMENDED → CANCELLED`, plus `IN_FORCE` semantics for an issued order under execution). Effective ordered quantity for the gap computation counts **ISSUED/IN_FORCE lines only**; DRAFT and CANCELLED contribute zero. Tests must pin both exclusions.

### R3 — Amendment replaces, with lineage

An amendment records the new quantity and links to the superseded line (`supersedes_id` or an amendments table). The gap computation reads **only the head of each lineage**; summing the chain double-counts. The superseded rows remain readable for audit — deletion is not the mechanism.

### R4 — Scope allocation is explicit, never prorated

A PO line may serve several scopes (lines / subsystems). Model this as explicit **allocation rows** (`po_item_allocations`: `purchase_order_item_id`, scope target, allocated quantity) rather than adding more nullable columns to the item:

- allocations sum to **at most** the item quantity; the remainder is `unallocated`;
- **ambiguous scope → unallocated**, reported as its own bucket;
- **no automatic proration** — the system never splits a quantity the user did not split.

### R5 — The order gap board

A per-project screen rolling up, per material (and per scope when allocated):

- accepted gross quantity (from `accepted_mto_rows`),
- effective ordered quantity (R2/R3),
- gap state: `SHORTAGE` (gross > ordered), `COVERED` (equal), `OVER_PLAN` (ordered > gross, shown as its own sign, never negative shortage),
- plus the unmatched/unallocated buckets from R1/R4.

Rows without an accepted MTO counterpart (ordered but never in the MTO) are over-plan by construction and must be visible, not dropped.

### R6 — Tenancy, immutability, language

- Everything sits under the existing PostgreSQL RLS project-participant policies; the board shows nothing to a non-participant (fail-closed, mirror-tested per HANDOFF rule: isolation must be asserted together with visibility).
- `accepted_mto_rows` stays immutable; the gap is **computed**, never written back onto the snapshot.
- All labels bilingual FA/EN via `lang/`; dates Jalali; quantities rendered through the existing localised-number rules.

## 4. Options considered

**Option A — attribution columns on `purchase_order_items`.** Add `material_item_id` and scope columns directly. Simpler, but a single PO line serving three subsystems becomes three nullable-column hacks or a JSON blob that cannot be aggregated in SQL. Rejected.

**Option B — allocation table (chosen).** `material_item_id` on the item (identity), `po_item_allocations` for scope splits (quantity). Matches the "no proration" rule structurally — an allocation that exceeds the item quantity is a constraint violation, not a runtime surprise. Mirrors the `accepted_mto_batches/rows` split pattern already proven in Slice 4.

**Option C — reuse `material_requisition_items` as the bridge** (PO → requisition item → MTO). Rejected: late additions are ordered without a requisition by design (the existing nullable `requisition_item_id` is deliberate), and the requisition quantity is itself a planning artefact, not an accepted gross. The gap must stand on the accepted MTO directly.

## 5. Open questions — ALL CLOSED by the user 2026-10-11

1. **Scope granularity of allocations — CLOSED: subsystem level.** Allocations
   target subsystems; line-level attribution arrives later as data (nullable
   `piping_line_id`), not as schema surgery in this slice. `subsystem_id`
   already exists on `purchase_order_items` and matches how material is issued
   to site.
2. **Effective-state vocabulary — CLOSED: `DRAFT / ISSUED / AMENDED / CANCELLED`,
   with "in-force" a predicate, not a state.** Effective = *issued and not
   cancelled*. No explicit IN_FORCE transition exists on site, so none is
   invented here. Tests must pin DRAFT-excluded, CANCELLED-excluded,
   ISSUED-included.
3. **Who may record allocations — CLOSED: deferred, same policy as MTO
   acceptance authority.** The EPC procurement role records; no new permission
   model is built before the access design lands. The deferral is recorded
   here so it stays visible, not implicit.
4. **Amendment shape — CLOSED: new row + `supersedes_id`.** Superseded rows
   stay readable for audit; the gap computation reads only lineage heads.
   Consistent with "archive rather than overwrite" used by document revisions
   and accepted MTO rows.

With all four closed, Slice 6 has a complete requirements record. Building it
still requires the full PHP 8.4 + PostgreSQL runtime (migrations, RLS policies,
`EXCLUDE`-free but transaction-heavy services); the ROADMAP §3.1 rule stands —
no tenant-scoped feature merges without the real test environment.

## 6. Falsifiers

This design is wrong if:

- a real client's PO workflow needs **partial acceptance of an amendment** (accept line 3, reject line 5 of one amendment) — lineage-per-item then needs revision-level grouping;
- scope in real projects is tracked at **spool or joint level** — allocation targets must widen before build;
- the effective quantity in a client's commercial practice includes **committed-but-unissued** orders — then the gap definition itself (§2.2) must be reopened with the user, not quietly widened here.

## 7. Test plan (what green means)
- attribution: exact-identity resolution; alias rejected; unknown code lands in unmatched;
- states: DRAFT and CANCELLED excluded from effective quantity; ISSUED included;
- amendment: replaced quantity counted once; superseded rows auditable;
- allocation: over-allocation refused by constraint; unallocated remainder visible; no proration path exists to test;
- gap states: SHORTAGE / COVERED / OVER_PLAN each asserted with fixtures, including an ordered-without-MTO row;
- RLS: participant sees the board; non-participant sees nothing (mirror pair);
- immutability: accepting a gap computation cannot mutate `accepted_mto_rows`.

## 8. UX validation — done before build (2026-10-11)

The screen language was validated as a static, air-gap-safe prototype before
any Laravel work, following the pattern that caught localisation and layout
defects in the Piping Workspace and MTO acceptance previews:
`prototype/order-gap-board.html` — bilingual FA/EN, RTL/LTR, five synthetic
PH-14 rows covering every gap state (two shortages, covered, over-plan with an
unallocated remainder, and an unmatched-material bucket), explicit
draft-exclusion and never-guessed notes, pinned by
`prototype/test/order-gap-board.test.js` (19 assertions). The service build
starts against a screen already reviewed with the user.

## 9. Implementation record (2026-10-11)

- **Schema:** migration `2026_10_11_100000_add_po_attribution_and_order_gap` — `purchase_order_items.material_item_id` (FK `material_items`), `purchase_order_items.supersedes_id` (self-FK, unique ⇒ superseded at most once), `po_item_allocations` (`quantity > 0` CHECK, unique `(item, subsystem)`, FORCE RLS with the accepted-MTO participant policy template).
- **Service:** `app/app/Services/OrderGapService.php` — `attribute` / `amend` / `allocate` / `board`; integer milli-unit arithmetic; bilingual validation failures.
- **Surface:** `OrderGapController`, routes `procurement.order-gap` (+ `.attribute` / `.amend` / `.allocate`), view `procurement/order-gap.blade.php`, sidebar item `project.ordergap` (module `proc`).
- **Effective predicate:** `ISSUED` ∨ `PARTIALLY_RECEIVED` — issued and not cancelled (same membership as `ProcurementState::poAcceptsReceipts`); no new state vocabulary was introduced, and `AMENDED` exists only as a lineage relation, not a state.
- **Tests:** `tests/Feature/OrderGapTest.php` (10 tests) implements §7 in full. Status: written 2026-10-11; runtime verification pending on the owner's Laragon machine.
