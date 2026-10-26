# Incoming piping material: read-only traceability contract v1

**Date:** 2026-10-23 · **Status:** implemented diagnostic, not an operational release gate.
[Persian counterpart](PIPING-MATERIAL-TRACEABILITY-FA.md)

## 1. Approved scope and decision reconciliation

The user approved continuing the traceability-first roadmap in the [source review](reviews/MATERIAL-RECEIPT-AUTOMATION-REVIEW-2026-10-23-EN.md), not an unrestricted warehouse or MTO implementation. This delivery follows [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md), [QA policy](PIPING-QA-EN.md), [custody amendments](PIPING-CUSTODY-AMENDMENTS-EN.md) and the accepted full-copy/independent purchasing decisions. The original preparer/live grant amendment policy, immutable arrival facts, three distinct user IDs, QC/QA version requirements and explicit responsibility nominations are unchanged.

| Boundary | Current disposition |
|---|---|
| Read-only custody → working basis → exact PO/MR diagnostics | **Implemented here** |
| Material identity and scope | Recorded PO-line IDs only; catalogue/topology validity **not assessed** |
| Controlled effective-MTO demand allocation into this MR/receipt chain | **Not captured** by this path; amount **unknown**, not zero |
| MTO read authority/roles, effective selection/admission | **Deferred / unchanged**; do not infer from procurement or receipt nominations |
| Buyer-visible issued-line evidence | Existing historical ledger only; not purchasing capacity or full-PO certification |
| GRN / accepted usable stock integration | **Not implemented here**; stock quantities remain null |
| Existing WarehouseService / legacy stock | **Not assessed and not disabled**; coexistence risk remains |
| New grants, source delegation, partial buying, stock movement | **Not introduced** |

Recommendation correction: a standalone “GRN next” step is insufficient. First preserve controlled demand provenance and separately approved source access, then design/activate controlled posting and a bounded pilot. This report does not repair the legacy mixed-unit completion, duplicate-code attribution, legacy receipt/issue or effective-MTO summation defects identified by the review. It avoids depending on those calculations.

## 2. Access and navigation

- `GET /projects/{projectCode}/materials/piping-traceability?lang=en` (or `fa`, default).
- `GET /projects/{projectCode}/materials/piping-custody/{receiptId}/traceability?lang=en` — exact UUID, otherwise 404.
- A link is available on the physical custody register/detail. Original MRR/amendment/QC/QA pages remain the place to inspect or perform separately authorized actions.
- Same **current nominated receipt reader** eligibility as custody: active participant/membership, PIPING discipline and current plan/pack; no automatic owner/configuration-administrator reader.
- Original caller company/project RLS applies independently to custody, PO, MR and issue evidence. PO visibility never unlocks MR visibility. No tenant switch to buyer/custodian and no MTO permission inheritance.
- Original linked PO becomes hidden: receipt disappears/404 under the existing reader policy. A latest amended private source becomes hidden while original arrival remains readable: show **unavailable latest basis**, never the original or an earlier correction.
- Live nomination loss is 403. A nomination version change detected across report preparation produces 409/refetch. Archive remains readable when the existing reader policy permits it.
- Both GET routes return `private, no-store, max-age=0`. No POST/export/new grant routes exist.

## 3. Contract: meaning, not authorization

`PipingTraceabilityService::report()` returns a 25-row paginator of original caller-visible receipts, `contractVersion=1`, and explicit limits. It is an internal service/view contract, **not a new JSON API**. No current-source snapshots, private source IDs, fingerprints, financial hashes, prices, document bodies or hidden MR identities are returned.

| Field | Meaning |
|---|---|
| `coverage=CALLER_VISIBLE_RECEIPTS_ONLY` | Diagnostic scope, never all project demand or material coverage |
| `observedAt` | UTC timestamp from the row-data SQL statement; null for an empty page |
| `receipt` | Immutable arrival ID/sequence/shipment/quantity/unit, not accepted or usable stock |
| `basis` | ORIGINAL / AMENDED / UNAVAILABLE, exact latest amendment ID/sequence and readable heat |
| `source_status` | LINKED / CHANGED / UNRESOLVED / UNAVAILABLE; LINKED only means readable exact reference |
| `po` | Exact original-caller-visible same-project PIPING header and line; current state, no finance |
| `arrival_unit_alignment` | Same recorded unit text / unit difference / not assessed; no conversion or quantity allocation |
| `mr_status` | NOT_LINKED, HEADER_ONLY, LINE_ONLY, LINKED, REFERENCE_CONFLICT, UNAVAILABLE_OR_UNRESOLVED, NOT_ASSESSED |
| `mr` | Only independently visible same-project/same-buyer PIPING source; exact line if available |
| `visible_field_alignment` | Checked quantity, unit and material/scope IDs including recorded absence; not full-copy or technical certification |
| `material`, `scope` | Recorded IDs / unresolved / not assessed; no library/topology read or validity assertion |
| `issue_evidence` | RECORDED_LINE_EVIDENCE / UNVERIFIED / VISIBILITY_LIMITED / NOT_ASSESSED |
| `qc`, `qa` | Latest recorded result and exact version-ID binding, not acceptance/release recertification |
| `mto_basis=NOT_CAPTURED`, `effective_demand=null` | No controlled demand basis is recorded by this path; does not assert there are no project MTOs |
| `controlledPosting=NOT_IMPLEMENTED`, `postedQuantity=null`, `usableQuantity=null` | This new chain does not post or count stock; legacy stock is not measured |
| `legacyStockAssessed=false` | No inference about balances, lots, available quantity or historical postings |

**Exact references only:** no code/description matching. Repeated item codes cannot merge or inherit another line's MR. Missing visible PO→MR references are known unlinked; unreadable/unresolved referenced MR is not treated as absent. Conflicting readable header/line references are flagged, not normalized into a complete chain. A live technical source differing from its frozen working snapshot is flagged CHANGED; existing state/issued-at bookkeeping differences alone are excluded consistently with current receipt/QA source checks.

**Issue evidence:** only under existing original-buyer RLS. Match allocation PO/header/line/project/buyer, exact `issue` decision ID and source hash, exact MR header/line, quantity/unit, and recorded approved quantity bound. Other readers receive VISIBILITY_LIMITED, not “missing/zero allocations.” Even a consistent row is historical line evidence only, not complete PO provenance, present MR capacity, GRN eligibility or a claim that full-copy policy has been freshly re-certified. No allocation writer was added.

**Quality binding:** latest QC's amendment ID must match the working amendment; latest QA's amendment and QC IDs must match both current heads. NONE means no recorded result; STALE means old IDs; an unavailable working basis never displays current binding. CURRENT means matching recorded IDs **only**. Document version/hash integrity, technical sufficiency, quantities, source freshness, nominations/SoD and current acceptance eligibility are not re-evaluated by this report. In particular, an old ACCEPTED result is not current usable stock.

## 4. Consistency and exclusions

The row-data read is **one SQL SELECT** with lateral latest-amendment/QC/QA heads, exact private amendment child, PO/MR joins and issue evidence joins. `epcc_piping_source_snapshot` is the existing invoker/STABLE source reader; the volatile effective-receipt helper is not called per row. Latest amendment selection never falls back when its private child is hidden. This avoids combining independently changing row-by-row reads into a false coherent report. Original RLS remains active; no isolation-setting change or new database function/migration is introduced.

Pagination count is a separate statement. Its count, other pages and later requests are **not a frozen project/audit snapshot**. An empty/hidden set is not “project complete,” “no need” or “zero stock.” No project completion percentage or mixed-unit quantity sum is produced. This report cannot list a needed item with no caller-visible receipt; it is not the future MTO-wide requirements report.

No queries of MTO, CDE documents/evidence, material catalogue/scope details, material lots or movements. No schema change, export, mutation, acceptance writer, warehouse behavior change or legacy cutover.

## 5. Verification and evidence

Focused/affected command:

```sh
cd app
EPCC_TRACE_EVIDENCE=1 php artisan test --compact \
 tests/Feature/PipingTraceabilityTest.php \
 tests/Feature/PipingCustodyTest.php \
 tests/Feature/PipingCustodyAmendmentTest.php
```

**47 tests / 505 assertions PASS, 130.18 seconds.** Includes 13 new report cases and existing affected custody/amendment tests. New cases exercise independent hidden MR despite visible PO, latest hidden amendment/no fallback, live reader loss/no administrator inheritance, exact duplicate-code references, buyer evidence/financial non-disclosure, legacy header-only/line-only/conflicting references, source drift, exact QC/QA head binding, pagination/mixed units, unknown demand/stock, archive/bilingual HTTP escaping/private caching/no POST, unknown/cross-project targets, non-mutation and one row-data statement/no MTO-stock queries.

Evidence: `docs/evidence/2026-10-23-piping-traceability/`. `http-en.html` / `http-fa.html` are synthetic archived HTTP responses, **not browser screenshots**. Earlier failed logs reflect fixture fixes (an unlinked setup receipt remained visible; an owner cannot write the buyer's PO; a missing fixture import); no RLS was weakened. Prior predecessor hashes were checked before work. Formatting, route/view compilation, PHP lint and RLS/invoker audits are separate evidence, not additional behavior tests.

No full regression, production audit, browser/load test, CI, production migration, deployment, commit or push was performed. The source package is a dependent increment over the existing project, not a full project backup or runnable distribution.

## 6. Next sequence — proposed, not enabled

1. Preserve exact effective-demand provenance/validity and reconciliation semantics before introducing a demand writer; respect already accepted selection/lifecycle decisions and deferred access boundaries. Read authority needed for a real cross-MTO report requires separate approval.
2. Design controlled QA → warehouse GRN posting, idempotency, exception boundaries, and legacy coexistence/cutover without altering the accepted preparer/QC/QA separation or inheriting warehouse permission.
3. Execute a bounded synthetic/site pilot and only then implement/activate separately approved posting and issuing. This delivery does **not** authorize those writers.
