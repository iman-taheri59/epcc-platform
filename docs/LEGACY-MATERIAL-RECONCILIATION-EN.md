# Legacy Warehouse Reconciliation — ADR-048 A.4f slice 1 (EN)

> Status: implemented service-only, 2026-10-29, commit `182a94d`.
> Persian twin: [`LEGACY-MATERIAL-RECONCILIATION-FA.md`](LEGACY-MATERIAL-RECONCILIATION-FA.md).
> Related records: [`ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md),
> ADR-047 (unified stock ledger), ADR-046 (controlled piping custody).

## The wall this opens

ADR-047 built a controlled custody chain for piping and closed the legacy
warehouse behind a cutoff trigger: no new `material_lots` rows, and **no silent
mapping of old rows onto new ones**. The GRN guard enforces the second half —
`PIPING_GRN_LEGACY_RECONCILIATION_REQUIRED` — by refusing to post a GRN against
a purchase order that still has legacy lots.

That refusal is right. It also had **no way through**. Nothing in the platform
could ever be done about a pre-cutover lot, so a project that used the legacy
warehouse could never post a controlled GRN against those purchase orders — not
"not yet", but never. A gate that cannot be opened is a wall.

## What lands

| Piece | What it is |
|---|---|
| `material_legacy_reconciliations` | Append-only, RLS + FORCE, forward-only. One row = one decision about one legacy lot. Chained per lot, like the custody amendments. |
| `epcc_guard_legacy_reconciliation()` | Derives the identity from the lot, requires the nomination, enforces the chain, and holds a TAKE_OVER to custody that attests the same heat. |
| `epcc_guard_piping_grn()` | Re-declared — byte-for-byte except one IF — so the legacy refusal applies only to lots nobody has decided about. |
| `LegacyMaterialReconciliationService` | `reconcile()`, `reconciliationsForLot()`, `pending()`, `isReconciled()`, bilingual errors. |

Two decisions, and only two:

- **TAKE_OVER** — this custody record re-receives the material. The custody
  record must attest the same heat the lot carried; different heat is different
  steel, and the database refuses rather than translates.
- **RETIRE** — it is not coming across, and here is why. Points at no custody,
  and owes a reason.

## Invariants (all in the database)

1. **The identity is the lot's, never the caller's.** `item_code` and `heat_ref`
   are derived from the legacy lot, so a raw insert that renames the material on
   the way across is corrected to what the lot itself says.
2. **A TAKE_OVER must name custody that attests the same heat**
   (`LEGACY_HEAT_MISMATCH`). Reconciliation is not translation: if the controlled
   receipt is for a different heat, it is different material.
3. **Default-deny authority.** Deciding requires a `result_recorder_policies`
   nomination for the new action `reconcile_legacy`, and the authorising policy
   is stamped on the row.
4. **Every decision is reasoned.** A reason is mandatory (CHECK-enforced), for
   both decisions — a retirement especially, because "we quietly dropped it" is
   the failure mode this whole design exists to prevent.
5. **Chained, never edited.** A later decision must name the current head
   (`LEGACY_RECONCILE_STALE`); the superseded decision stays on the record.
   UPDATE/DELETE are refused by trigger, and no UPDATE/DELETE policy exists.
6. **Idempotent.** `request_id` is unique per project, so a retry cannot
   double-decide. A retry after success is refused by the chain rule, which is
   the same honesty in a different place.
7. **No silent mapping, ever.** The GRN still refuses unreconciled lots with the
   same code and the same meaning. What changed is that the refusal is no longer
   permanent.

## Why the quality register, not the receipt register

The reconciliation is decided through `result_recorder_policies` (action
`reconcile_legacy`), the register A.4b introduced for quality claims, rather
than the receipt register that governs custody/QC/QA/GRN.

The reason is structural: the receipt register **demands a nominee for every
action** — a policy row with an empty action array is refused — so adding
`legacy_reconcile` there would force every project, including the many with no
legacy material at all, to nominate a reconciler before they could configure
receipts. In the quality register, an un-nominated actor is simply refused, which
is exactly the default-deny behaviour we want: no nomination, no decision, and
nothing written.

## The cutover worklist

`pending()` returns the legacy lots nobody has decided about — which are exactly
the ones still blocking a controlled GRN. Reconcile them (or retire them), and
the gate opens. `isReconciled()` answers the per-lot question.

## Declared boundaries of THIS slice

- **This reconciles the gate, not the quantities.** Moving stock into the
  controlled ledger is the ADR-047 `OPENING_COUNT` path, untouched here.
- **No HTTP/UI** — service-only, like every slice so far.
- **The decision is not evidence by itself.** A TAKE_OVER says which receipt
  stands for the old material; whether that receipt's quantity matches the legacy
  balance is a reconciliation report this slice does not compute.

## Tests

- `tests/Feature/LegacyMaterialReconciliationTest.php` — 11 tests / 46
  assertions, green twice.
- `tests/Feature/PipingGrnTest.php` gains the integration proof: the legacy gate
  holds while the lot is undecided and opens once it is reconciled (22 tests /
  123 assertions). The pre-existing test that asserts the gate blocks, and that
  the legacy writer stays closed, is unchanged and still green.
- Related regression green together — 440 tests / 2786 assertions across the
  Piping, material, stock-ledger, warehouse, results, sign-off and MTR-binding
  suites.
