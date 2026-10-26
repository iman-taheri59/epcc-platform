> **Current review / user deferral — 2026-10-20:** [core gaps and priorities](CORE-GAP-REVIEW-2026-10-20-EN.md) / [فارسی](CORE-GAP-REVIEW-2026-10-20-FA.md). Structured prerequisite item design AND implementation explicitly deferred; free text retained. Source/document review, not a test run or new authority/schema approval. Recommended next: focused document allocation review, then warehouse provenance/arithmetic diagnostics; next user-visible target is exact-line PO receipt after needed decisions. Conflicting older status/“current full source green” statements below are historical evidence, not this review’s current verification.

> **Approved PDF continuation — 2026-10-20:** [current prerequisite appendix](INSPECTION-PREREQUISITE-PDF-EN.md) / [فارسی](INSPECTION-PREREQUISITE-PDF-FA.md). Selected prerequisite_pdf implemented: all records/full history/retained stage, separate from issuance snapshot; same scope, no new schema/authority/writes.409 on changes during render.20/1102 + separate4/60 PASS; not full-suite/current-CI/deployed. Only prerequisite-PDF deferral and contradictory historical prose below are superseded; other decisions retained.

# ADR-045 — Recipient-declared inspection prerequisites

> **Original-notice lifecycle filter — 2026-10-20:** user-selected `active_notice_filter` [implemented](INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-EN.md): optional All(default)/ACTIVE(no cancellation)/CANCELLED, independent of prerequisite status; counts and company-picker visibility unchanged, pagination/lang preserved, no writes/authority/schema/PDF.36/2008 PASS, not full-suite/browser/current-CI/deployed. Only this filter deferral is superseded; archived ACTIVE is still read-only, not readiness/proceed.

> **Readable company picker — 2026-10-20:** user selected `company_picker`; [current guide](INSPECTION-PREREQUISITE-COMPANY-PICKER-EN.md). Search/select captured name/code from visible prerequisite records only, one representative/company,25 option pages independent of25 register rows; pinned/unavailable selections preserved. No live foreign directory/new access/schema/write/PDF. Final56/1793 PASS, not full-suite/browser/current-CI/deployed. Earlier UUID-input/picker-deferral prose below is historical.

> **Read-only tracking continuation — 2026-10-20:** user-selected [project prerequisite register](INSPECTION-PREREQUISITE-REGISTER-EN.md) implemented: status/declaring-company/notice literal filters, authorized-only totals and25-row pagination. Same original readers/authority/history/source boundaries; no write/PDF/schema/new role. Final47/1051 + separate legacy1/12 PASS; earlier combined66/1282 and overlapping boundary4/62 documented. Inherited-stage workspace recovery recorded separately; not full-suite/current-CI/browser/deployed.

> **Inherited-stage continuation — 2026-10-20:** user selected `itp_link_granularity=inherited_stage`; [source review and delivery](INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-EN.md). Exact recorded stage/revision now explicit in prerequisite create/current/history context, no live-source query/new access/schema/backfill/PDF or structured sub-items.54/807 + separate legacy1/12 PASS, not full suite/browser/current-CI/deployed. This supersedes only the proposed stage-display refinement, not certified item-link/readiness deferrals.

2026-10-20 · **ACCEPTED / IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-FA.md) · [Implementation, transitions and exact evidence](INSPECTION-NOTIFICATION-PREREQUISITES-EN.md)

## Decision

Explicit selections: `prerequisite_authority=any_reader_company_scope`; `prerequisite_effect=report_and_recipient_confirm`; `prerequisite_visibility=all_notice_readers`. Current eligible selected-recipient readers create/manage their company's separate notice/item-text requirements, including each other's records; sender company's current issue holder reports completion; declaring recipient confirms/rejects. All authorized original readers see full prerequisite text and immutable history. No new source reads or automatic operational gate.

Separate current projection + append-only versioned before/after events, forward-only migration150000, no historical backfill or parent mutation. Server actor/company/time snapshots and common lifecycle lock; current eligibility and issue authority revalidated, event capture atomic. CREATE OPEN; reasoned EDIT OPEN; reasoned WITHDRAW OPEN/REPORTED→WITHDRAWN; REPORT OPEN/REPORTED→REPORTED; reasoned REJECT REPORTED→OPEN; CONFIRM REPORTED→FULFILLED. Terminal records are not reopened. No mandatory distinct-human policy inferred. Detail and dedicated history only, existing PDF unchanged.

## Alternatives and consequences

Separately nominated representatives, sender-only confirmation, operational blockers and restricted own-company-only history were offered, not selected. Do not substitute or re-ask them. Sharing history is intentional and disclosed, not private-draft disclosure. Free-text references are not certified ITP amendments or item lineage. Confirming fulfillment is not acknowledgment, inspection acceptance, result, release or work permission. No MR/PO/MTO/stock authority/write or default role. Runtime integrity is not privileged-DDL tamper proof/legal signature.

ADR-042's five choices, ADR-043 cancel-only, ADR-044 receipt/PDF and ADR-041 boundaries retained. Only recipient-prerequisite deferral superseded. Related source-archive/legacy/read-only filters/private draft controls preserved. Replacement/reissue/reschedule/transport/uploads and PDF prerequisite export remain deferred; separately governed ITP linking/readiness policy are recommendations, not enabled features.

## Verification

Affected81/1290 PASS + distinct historical workflow upgrade1/5 PASS. Four two-connection schedules, late rollback, cross-company, SQL/event guards, current entitlement, legacy and bilingual rendering covered. See guide/evidence for initial fixture expectation correction and exact limitations. No full-suite/current-CI/browser/load/production claim, commit, push or deployment.
