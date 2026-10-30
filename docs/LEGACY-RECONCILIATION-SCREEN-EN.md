# Deciding about legacy material — A.4h slice 4 (English)

> Status: implemented 2026-10-30, commits `c0ea7ab` (the screen) and `8e0c895` (the
> quantity report). Slice of ADR-048.
> همزاد فارسی: [`LEGACY-RECONCILIATION-SCREEN-FA.md`](LEGACY-RECONCILIATION-SCREEN-FA.md).
> The decision itself was specified in A.4f and is documented in
> [`LEGACY-MATERIAL-RECONCILIATION-EN.md`](LEGACY-MATERIAL-RECONCILIATION-EN.md);
> the cutoff it answers is ADR-047.

## The wall, and the gate it should have been

ADR-047 closed the legacy `material_lots` warehouse behind a cutoff and made the
GRN guard refuse any purchase order that still has legacy lots
(`PIPING_GRN_LEGACY_RECONCILIATION_REQUIRED`). The refusal is right: rows from
before the cutoff must not be silently mapped onto the controlled chain, because
a silent mapping is a claim nobody made.

It was also permanent. A.4f made the decision recordable — `TAKE_OVER` or
`RETIRE`, nominated and reasoned — and A.4f's own declared boundary was honest
about the consequences: only SQL could record it. So a project that had used the
legacy warehouse before the cutoff could never post a controlled GRN against
those orders. Not "not yet": never.

This screen is the gate that should have been beside the wall:
`/projects/{projectCode}/quality/legacy` (`quality.legacy`, plus one POST).

## What a decision is

| Decision | What it says |
|---|---|
| `TAKE_OVER` | This custody record re-receives the material. It must attest the lot's own heat — a different heat is different steel, refused as `LEGACY_HEAT_MISMATCH` rather than translated. |
| `RETIRE` | It is not coming across, and here is why. The reason is mandatory: a decision without one is a disposal nobody can review. |

Neither is a migration. No legacy row is rewritten, copied or deleted; the
decision is an append-only, chained fact about a lot that stays where it is.

## The part that is derived, not declared

As everywhere else in this layer, the identity being reconciled is **the lot's
own**. `item_code` and `heat_ref` are read off the lot by the guard; the caller
states neither. If a later decision supersedes an earlier one, it names the
current head (`previous_reconciliation_id`) and both rows stay — a stale
submission that fails to name the head is refused (`LEGACY_RECONCILE_STALE`)
rather than silently forking the chain. The form quotes the head it was looking
at, so this is handled before anyone has to think about it.

## Why the take-over field is short

The one addition this screen makes to the pattern the other quality surfaces set
is that the custody list is **filtered by heat**. A TAKE_OVER must point at
custody attesting the lot's own heat, and the guard refuses anything else — so
the form offers only those.

Offering every custody record and letting the guard refuse the wrong ones would
be asking someone to make a decision in order to be told it was never theirs to
make. It is the same argument that keeps the waiver off a contractor's HOLD
point, applied to a dropdown.

A lot with no heat of its own may be taken over by any controlled receipt: there
is nothing to mismatch, so nothing is filtered.

## The invariants this surface holds

1. **A form appears only for a nominated actor** (`reconcile_legacy`, asked of
   the register's own function).
2. **A non-member of the project is refused at the door** (403).
3. **The identity is the lot's own** — item and heat derived, never declared.
4. **A take-over must attest the same heat.** Different heat, different steel.
5. **Every decision is reasoned.** A blank reason is refused for both decisions.
6. **A later decision supersedes; it never edits.** Both rows stay, and a
   submission that does not name the head is stale.
7. **A resubmission is refused, not duplicated** (`request_id` is unique per
   project).
8. **Nothing is deleted or rewritten.** The legacy warehouse stays closed behind
   its cutoff; this is a record about it, not a change to it.

## What this slice deliberately does not do

- **It does not move quantities.** Reconciling the gate is not reconciling the
  ledger; the stock ledger and ADR-047's `OPENING_COUNT` own that. It does,
  however, *report* the difference — see below.
- **No bulk decision.** One lot, one decision, one reason.
- **No ITR export** (A.5).
- The worklist and the decided list are capped at 200 rows with no pagination,
  as on the other quality screens.

## The quantity reconciliation report

A.4f decided the fate of each legacy lot and was honest about what that decision
is not: evidence. A TAKE_OVER says *this* controlled receipt re-receives *that*
lot; it does not say the quantities agree. That question had no answer anywhere,
so it is now answered on the same screen, under the decisions:

| Column | Where it comes from |
|---|---|
| Legacy balance | the ledger's `OPENING_COUNT` for the lot's own (item, heat, unit) — what was brought across at cutover |
| Receipt | the quantity on the custody record the current TAKE_OVER names |
| Difference | receipt − balance, to three decimals |
| Reading | `MATCH` · `SHORT` · `OVER` · `UNIT_MISMATCH` · `NO_OPENING_COUNT` · `NOT_BROUGHT_ACROSS` |

Three of those readings exist because the alternative is the platform inventing
a fact:

- **Units that differ are not compared** (`UNIT_MISMATCH`). Converting metres to
  each would mean inventing a conversion factor, so the row shows both units and
  declines the arithmetic.
- **A lot with no opening count is reported as one** (`NO_OPENING_COUNT`), not
  treated as zero. *"We never counted it"* and *"we counted it as nothing"* are
  not the same claim, and a report that prints `0.000` for the first one has
  quietly changed the meaning of the row.
- **A retired lot shows the balance it released** (`NOT_BROUGHT_ACROSS`). A RETIRE
  is a decision not to bring material across; the quantity that decision let go
  of is part of the record, not a row to drop.

And one reading exists because a difference is not an error: `SHORT` and `OVER`
are facts somebody must explain. Which side is right is not the platform's to
guess, so the report states the difference and stops.

## Tests

`LegacyReconciliationHttpTest` — 19 tests / 84 assertions, all deciding through
HTTP:

- the worklist shows the lot and reports honestly that nothing is decided, and
  the form is withheld while nobody is nominated;
- nomination opens the form;
- a take-over binds the lot to the custody attesting its heat, and the heat and
  item are asserted as **derived** values; the lot then leaves the pending
  worklist, which is what unblocks a controlled GRN;
- a take-over naming custody with a different heat is refused;
- the form offers the eligible receipt and not the ineligible one;
- a take-over with no custody is refused; a retirement without a reason is
  refused, and with one is recorded;
- deciding without a nomination writes nothing;
- a second decision that does not name the head is refused as stale, and once it
  does name it both rows remain with the right sequence;
- the same `request_id` is refused rather than double-deciding the lot;
- the quantity report: a receipt that matches the legacy balance, one short of
  it, one above it, one whose unit differs and is therefore not compared, one
  with no opening count (reported as such, not as zero), and a retired lot shown
  with the balance it released.

Related regression green together — 136 tests / 769 assertions:
`LegacyMaterialReconciliation`, `PipingGrn`, `StockLedger`, `MtrBindingHttp`,
`MtrCustodyBinding`, `ItpSignOffHttp`, `InspectionResultHttp`, `PipingCustody`,
`TableTools`, `CookielessPreview`.

A note for whoever extends this: `tests/Support/LegacyLotFixture` disables the
cutoff trigger to insert a legacy row and **always restores it in a `finally`**.
A test that disables a guard and forgets to put it back silently weakens every
assertion after it, so keep the handling in the fixture.
