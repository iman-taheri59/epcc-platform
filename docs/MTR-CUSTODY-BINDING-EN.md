# MTR ↔ Custody Bindings — ADR-048 A.4e slice 1 (EN)

> Status: implemented service-only, 2026-10-29, commits `bb744e0` + `815a1ab`.
> Persian twin: [`MTR-CUSTODY-BINDING-FA.md`](MTR-CUSTODY-BINDING-FA.md).
> Decision record: [`ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md) (ACCEPTED, decision 4 `mtr_binding=lot_and_heat`).
> Sibling guides: [`INSPECTION-RESULTS-EN.md`](INSPECTION-RESULTS-EN.md), [`ITP-STAGE-SIGN-OFFS-EN.md`](ITP-STAGE-SIGN-OFFS-EN.md), [`ITR-COMPILER-EN.md`](ITR-COMPILER-EN.md).

## The gap this closes

Three parts of this platform spoke about the same steel in three vocabularies,
and nothing joined them:

| World | Speaks | Example |
|---|---|---|
| `material_certificates` (A.4) | free-text heat, optional free-text lot | `heat_ref = 'HEAT-77'`, `lot_ref = 'LOT-3'` |
| `piping_custody_records` + amendments (ADR-046) | what was actually received, by **heat** | effective receipt reports `heat_ref` |
| `material_lots` (legacy warehouse) | (lot_code, heat_number) | `QA-PIPE` / `HEAT-77` |

Because nothing joined them:

- a certificate could be registered for a heat **nobody ever received** — the
  database could not tell, and a client reading the MTR registry could not
  either;
- the turnover question — **which heats we hold have no certificate** — had no
  answer at all;
- A.5b could not bind receipt/MTR evidence into an ITR dossier, because there
  was no binding to bind.

## What was added

| Piece | What it is |
|---|---|
| `piping_custody_amendments.lot_ref` | Additive and nullable: the custody chain can finally attest a lot as well as a heat. Old amendments know no lot — shown as NULL, never back-filled with a guess. |
| `epcc_piping_effective_receipt()` | Now also reports the effective **lot**, so "what does custody say about this material" is one question and one call. |
| `material_certificate_bindings` | Append-only (RLS + FORCE, forward-only migration). One row = one certificate bound to one custody record, or recorded as unresolved. |
| `epcc_guard_certificate_binding()` | Derives the heat, the lot and the item; resolves custody; stamps the authorising nomination; decides the binding mode. |
| `MaterialCertificateBindingService` | `bindCertificate()`, `bindingsForCertificate()`, `coverage()`, bilingual field errors. |

Binding modes:

| Mode | Means |
|---|---|
| `CUSTODY_LOT_HEAT` | Custody attests the exact (lot, heat) pair the certificate claims. |
| `CUSTODY_HEAT` | Custody attests the heat and knows no lot; the certificate claimed no lot either. |
| `UNRESOLVED` | Custody attests nothing that matches — kept, with a mandatory reason. |

## Invariants (all in the database)

1. **Derived, never declared.** `heat_ref` and `lot_ref` are read off the
   certificate; `custody_id`, `custody_amendment_id` and `item_code` come from
   custody. A caller cannot state any of them — a raw SQL insert that claims a
   different heat is silently corrected to the certificate's own claim.
2. **Exact, never fuzzy.** Heat and lot match by trimmed string equality.
   `HEAT-7` never merges with `HEAT-77`.
3. **Default-deny authority.** Binding requires a `result_recorder_policies`
   nomination for the new action `bind_certificate`, and the authorising policy
   is stamped on the row (`bound_policy_id`). Asserting that a certificate
   covers received material is a quality claim, not a data-entry task.
4. **A lot custody does not attest is refused, not downgraded.** If the
   certificate claims a lot and custody only knows the heat, the bind is
   refused (`MTR_LOT_NOT_ATTESTED`) instead of being recorded at heat level —
   the latter would make the data say "this lot is covered" when custody only
   ever knew the heat. The two honest ways out: amend the custody chain with the
   lot (now possible), or keep the certificate unresolved and say why.
5. **A certificate for material that is not here can still be kept — as an
   unresolved one, with a reason.** `UNRESOLVED` requires `unlinked_reason` and
   points at no custody. It is how you record the everyday fact that the mill
   sent the paperwork before the steel.
6. **Append-only.** No UPDATE/DELETE policy exists, so a write matches nothing;
   the mutation trigger (`55000`) is the second line of defence. A correction is
   a new binding.
7. **Idempotency and no double coverage.** `request_id` is unique per project,
   and (certificate, custody) is unique — the same certificate cannot be bound
   to the same custody twice.
8. **The mode and the claimed lot must agree** — written as implications, not an
   equality, because an unresolved certificate may still claim a lot (that is
   exactly why it could not be bound).

## Two things a reader should notice

- **The binding session carries two session contracts.** The binding itself is
  gated by the results register (`epcc.result_actor_id`), but the custody chain
  is readable only by a **receipt** actor (`epcc.receipt_actor_id`). A binder
  who cannot see the custody record could never bind anything to it, so the
  service sets both and restores both.
- **The lot entered the custody chain as an amendment field, not as a rewrite.**
  Existing amendments keep their payload hashes and their NULL lot; only new
  amendments can attest a lot. Additive keys also had to be *defaulted* in the
  service (`array_replace(['lot_ref' => null], $input)`) because the payload is
  validated with a strict `array:` rule and the callers that predate A.4e must
  keep working.

## The turnover question, answered

`coverage()` returns, in one call:

- **attested** — every (heat, lot, item) custody attests, with how many custody
  records and how many certificates are bound to it. A heat with `certificates
  = 0` is reported as uncovered, never omitted.
- **unresolved** — every binding that could not be tied to custody, with its
  reason.

## Declared boundaries of THIS slice (honesty first)

- **PIPING custody only.** The legacy `material_lots` warehouse
  (`lot_code`/`heat_number`) is still a separate world; reconciling it with the
  custody chain is the ADR-047 cutover item, not claimed here.
- **No HTTP/UI.** Service-only, like every slice so far.
- **Coverage does not judge.** It counts. Whether a heat *needs* a certificate
  (material class, client requirement, standards pack) is a policy question this
  slice does not answer for you.
- **Forward-only migration.** `down()` throws: a binding is a quality record.

## Tests

- `tests/Feature/MtrCustodyBindingTest.php` — 11 tests / 48 assertions, green
  twice (this sandbox produces flaky process crashes; a single red run is
  re-run before it is believed).
- Related regression green together — 273 tests / 1632 assertions: `Piping*`
  (custody, amendments, GRN, QA, traceability), `StockLedger`, `Warehouse`, and
  the MTR binding suite itself. Per the owner's 2026-10-28 instruction the full
  suite is not run per change; it runs in CI on every push (RUNBOOK §12).
