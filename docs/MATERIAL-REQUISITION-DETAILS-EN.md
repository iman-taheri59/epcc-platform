> **2026-10-19 follow-on:** [contextual MR→PO action](MR-TO-PO-CONTEXT-ACTION-EN.md) / [فارسی](MR-TO-PO-CONTEXT-ACTION-FA.md). An eligible own-buyer independent PO author can now submit the existing full-copy draft form from MR details. GET remains nonmutating; the POST creates a DRAFT, not a reservation or issue. Earlier “no action controls” statements below describe the prior sheet increment. No authority expansion.

# Material requisition detail sheet

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED**

## Use

Open Procurement and select an MR number, or follow the visible source-MR number from the read-only PO source review. The EN/FA detail sheet shows:

- Stored MR number/title, requesting company, discipline, state and material category; unclassified legacy records are explicitly identified.
- Stable source-line UUIDs, exact stored decimal quantities, retained original quantity spelling where available, units, descriptions, visible same-project subsystem and specification. Unlike units are never added or converted.
- Recorded raiser, technical/commercial approver and rejection UUID/date fields plus escaped rejection reason. These are stored fields, **not** a verified legal signature, a reconstructed user directory or complete immutable event history.
- Related visible **same-buyer** POs, 25/page, using real header or source-line references. Headerless/mismatched-header line relationships are labeled. Coincident item codes do not establish relationships. Links lead to existing PO details.

There are no MR/PO transition, edit, signature, allocation or reconciliation controls on this page. A related DRAFT is not a source reservation; no official remaining quantity, complete commitment assessment or issuance certificate is inferred.

## Authority and decision references

This is a read-only navigation/detail increment under the existing MR visibility and project access contract. It preserves independent draft nomination, single-category ADR-034, exact-copy provenance ADR-035, restricted pricing ADR-038, atomic issue-allocation ADR-039 and ADR-040's hidden-evidence discipline. It is not a new role or amendment to the deferred MTO/admission design.

`MaterialRequisitionDetailsService` checks active actor, company participation, current personal membership, proc/module/pack entitlement and current discipline scope, then reads inside the **original caller company/project RLS context**. Null discipline scope means unrestricted disciplines; an empty array means none. Both the source MR and related POs are discipline-filtered. A direct known UUID from another company/project cannot widen scope. Read access does not require a draft-author nomination and remains available for an archived project under existing read rules.

Existing employer/PMC oversight MR reads are retained. A named vendor's PO visibility does not confer MR access; competing EPCs likewise gain none. The service neither impersonates a buyer nor opens an identity-lookup hatch to resolve hidden actor names. It does not query pricing/execution/allocation journals. Hidden relations may be absent: an empty list is never authoritative proof of no commitments.

Legacy and manual source records are not reclassified, backfilled or retro-certified. Approval columns are presented as recorded metadata; future controlled MR history/signature semantics require their own accepted workflow, not inference from this sheet.

## Implementation

- GET `/projects/{projectCode}/procurement/requisitions/{requisitionId}`; UUID route constraint.
- New read service and `materials.requisition` view with `lang/{en,fa}/requisition.php`.
- MR register and visible source-review links; shell composer registration, responsive tables, escaped fields, Jalali/Persian-digit display.
- Related orders use a bounded `orders_page` paginator, deterministic sequence/UUID ordering and actual source-line identifiers. Both project and same-buyer boundaries are explicit.
- No schema migration, new role, write endpoint, source/PO state change, MTO rights or issue admission gate.

## Verification and limits

**30 affected tests / 240 assertions PASS, 54.085 seconds**, including ten new detail-sheet cases plus existing PO source-review and PO-detail cases. Evidence: `docs/evidence/2026-10-19-mr-details/summary.json`, `related-final.log`.

Coverage includes EN/FA navigation/shell, exact quantities/original spelling, unlike units, escaped titles/descriptions/rejection text, stored actor IDs, seven-table no-mutation snapshots, positive oversight reads, vendor-visible-PO/hidden-MR mirror, competing EPC isolation, live membership and empty/restricted discipline scope, draft nomination suspension not blocking reads, guest/invalid UUID/module/pack refusal, real cross-project IDs, 25/2 pagination with actual line-only and non-matching code relationships, related-order discipline filtering, archived reads and legacy rejected/unclassified records.

Initial fixture errors (membership has no company_id, case-sensitive label, category/fillable fields on a synthetic legacy PO) were corrected without disabling guards. Earlier `focused.log` / `related.log` failures and the intermediate eight-case `focused-final.log` are superseded by the final 30-case result; they are not extra test totals. Seven PHP lint checks, targeted Pint, diff checks and ten implementation hashes are recorded. Private PostgreSQL17/PHP8.4, epcc NOSUPERUSER/NOBYPASSRLS, UTF8, Unix socket; stopped after verification.

No routine full suite, current CI, browser acceptance, production migration/deployment, commit/push or broad concurrency certification. Prior evidence folders are unchanged. Physical receipt, authoritative remaining procurement capacity, write-based legacy reconciliation, post-issue amendments and general legacy/manual/MTO authority remain outside this feature.
