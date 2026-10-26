> **Current review / user deferral — 2026-10-20:** [core gaps and priorities](CORE-GAP-REVIEW-2026-10-20-EN.md) / [فارسی](CORE-GAP-REVIEW-2026-10-20-FA.md). Structured prerequisite item design AND implementation explicitly deferred; free text retained. Source/document review, not a test run or new authority/schema approval. Recommended next: focused document allocation review, then warehouse provenance/arithmetic diagnostics; next user-visible target is exact-line PO receipt after needed decisions. Conflicting older status/“current full source green” statements below are historical evidence, not this review’s current verification.

> **Approved PDF continuation — 2026-10-20:** [current prerequisite appendix](INSPECTION-PREREQUISITE-PDF-EN.md) / [فارسی](INSPECTION-PREREQUISITE-PDF-FA.md). Selected prerequisite_pdf implemented: all records/full history/retained stage, separate from issuance snapshot; same scope, no new schema/authority/writes.409 on changes during render.20/1102 + separate4/60 PASS; not full-suite/current-CI/deployed. Only prerequisite-PDF deferral and contradictory historical prose below are superseded; other decisions retained.

# Inspection notifications — recipient prerequisites

> **Original-notice lifecycle filter — 2026-10-20:** user-selected `active_notice_filter` [implemented](INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-EN.md): optional All(default)/ACTIVE(no cancellation)/CANCELLED, independent of prerequisite status; counts and company-picker visibility unchanged, pagination/lang preserved, no writes/authority/schema/PDF.36/2008 PASS, not full-suite/browser/current-CI/deployed. Only this filter deferral is superseded; archived ACTIVE is still read-only, not readiness/proceed.

> **Readable company picker — 2026-10-20:** user selected `company_picker`; [current guide](INSPECTION-PREREQUISITE-COMPANY-PICKER-EN.md). Search/select captured name/code from visible prerequisite records only, one representative/company,25 option pages independent of25 register rows; pinned/unavailable selections preserved. No live foreign directory/new access/schema/write/PDF. Final56/1793 PASS, not full-suite/browser/current-CI/deployed. Earlier UUID-input/picker-deferral prose below is historical.

> **Read-only tracking continuation — 2026-10-20:** user-selected [project prerequisite register](INSPECTION-PREREQUISITE-REGISTER-EN.md) implemented: status/declaring-company/notice literal filters, authorized-only totals and25-row pagination. Same original readers/authority/history/source boundaries; no write/PDF/schema/new role. Final47/1051 + separate legacy1/12 PASS; earlier combined66/1282 and overlapping boundary4/62 documented. Inherited-stage workspace recovery recorded separately; not full-suite/current-CI/browser/deployed.

> **Inherited-stage continuation — 2026-10-20:** user selected `itp_link_granularity=inherited_stage`; [source review and delivery](INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-EN.md). Exact recorded stage/revision now explicit in prerequisite create/current/history context, no live-source query/new access/schema/backfill/PDF or structured sub-items.54/807 + separate legacy1/12 PASS, not full suite/browser/current-CI/deployed. This supersedes only the proposed stage-display refinement, not certified item-link/readiness deferrals.

2026-10-20 · **ACCEPTED / IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](INSPECTION-NOTIFICATION-PREREQUISITES-FA.md) · [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) · [Acknowledgment](INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) · [Workflow](INSPECTION-NOTIFICATION-WORKFLOW-EN.md)

## Approved scope and use

The user requested recipient-declared prerequisites for inspected operations/items and explicitly selected:

- `prerequisite_authority=any_reader_company_scope`: any **currently eligible reader of an explicitly selected recipient company** manages that company's prerequisites, including records created by another company member. Not another company's records; no extra representative nomination.
- `prerequisite_effect=report_and_recipient_confirm`: a current issue-grant holder of the originating/sender company reports completion; the declaring recipient company confirms or rejects it. **No automatic work, inspection, stock or readiness gate.**
- `prerequisite_visibility=all_notice_readers`: all currently authorized original readers, including other selected recipients, see text, company/person/time and complete prerequisite history.

On issued-notice detail, an eligible recipient adds a free-text **operation/item reference** (1–1000 characters) and **requirement** (1–4000). Multiple independent records per company/notice are permitted, including duplicate text: there is no semantic item deduplication or certified item link. Dedicated tracking shows current projection, eligible action forms and immutable before/after history. References are text, not access to source items, PO lines or a revised ITP. No uploads, private prerequisite drafts or externally verified completion evidence.

Disclosure appears before writes: the whole requirement and follow-up history are shared with all original notice readers; do not paste private draft/commercial data. Opening pages is read-only. Empty list means no record, not absence of contractual prerequisites. The current PDF intentionally **does not include prerequisites** in this increment; existing issued text, receipt/cancellation PDF behavior and private old draft history stay unchanged.

## Implemented transitions

| Action | Who | Before → after | Explanation |
|---|---|---|---|
| CREATE | Declaring company's eligible recipient reader | none → OPEN, version1 | Reference + requirement |
| EDIT | Same recipient company, not necessarily creator | OPEN → OPEN | Required reason; reference/body revision retained |
| WITHDRAW | Same recipient company | OPEN or REPORTED → WITHDRAWN | Required reason; no deletion or technical waiver |
| REPORT | Sender company's current issue holder | OPEN or REPORTED → REPORTED | Required completion explanation; correction retains previous report |
| REJECT | Declaring recipient company | REPORTED → OPEN | Required rejection explanation |
| CONFIRM | Declaring recipient company | REPORTED → FULFILLED | Optional note |

Every update increments exactly one version and appends one event. FULFILLED/WITHDRAWN are terminal: a new requirement needs a new record. To revise a reported requirement, reject the report first, then edit the open requirement, and require a fresh report. Concurrent/stale versions are refused, not silently rebased or merged. Legitimate note `"0"` is preserved. No mandatory different-human rule was approved: this is company-based authority, and an explicitly selected sender-recipient may exercise each qualifying role for its own records.

This implementation detail makes the approved history/report/confirmation policy concrete; it is **not** a new inspection result, acknowledgment, contract amendment, inspection acceptance, hold release, permission to proceed, stock change or technical sign-off. A withdrawn prerequisite is not a blanket waiver. No current receipt is required to declare a prerequisite, and no global notice readiness/approval state is assigned.

## Eligibility, storage and API

All current active-user/company, membership, participation, discipline, QAQC module and standards-pack gates remain. Recipient management needs no doc module, doc.view, drafter or issuer role; it grants none. Sender reporting requires the sender's **current** existing issue nomination/permission, not original authorship or a new reporter role. Losing that grant stops reports but does not erase prior evidence or a recipient's independently approved reader authority. Source archive does not rewrite or invalidate already issued evidence or grant source access. Cancelled notices and archived/nonwritable projects retain readable records/history but forbid all fresh prerequisite writes. Old single-time issued notices work without inventing an activity window.

Existing authenticated web routes and CSRF:

- POST `/projects/{projectCode}/inspection-notifications/{notificationId}/prerequisites`, nested `prerequisite` only `item_reference`, `requirement`.
- GET and POST the same path plus `/{prerequisiteId}` (UUID). POST nested `prerequisite` only `version`, `action`, `note`; `item_reference`, `requirement` required only for EDIT and prohibited otherwise. `version` is the **prerequisite** version, not issued-notice version.
- Required reasons for all updates except CONFIRM; max4000. Whitespace-only, malformed/array/oversize/unknown actor/company/time/state fields refused. Form restoration scoped to same notice/prerequisite/action. Success redirects with language. Stale/invalid lifecycle/archive actions return validation422; invalid authority403, hidden/wrong parent/company-update scope404. No successful duplicate-update acknowledgment is implied.

Forward-only `2026_10_20_150000_add_inspection_notification_prerequisites.php` adds `inspection_notification_prerequisites` and append-only `inspection_notification_prerequisite_events`; no old row/backfill, role, parent RLS or retained issued body change. Both FORCE RLS. Root identity/creator/declaring company/issued version immutable; mutable state/content is guarded by exact version and allowed transitions. Actor/company code/bilingual names and UTC time are server snapshots, not a foreign people-directory lookup. Event schema1 is an explicit before/after allowlist, not a dump of parent/private drafts or future columns.

Writes share the existing project advisory/header lock with issue, cancel, receipt and settings; root lock follows it. Receiver never needs UPDATE or row-lock permission on the sender's immutable parent. SQL rechecks actor membership/discipline, participation and company/plan entitlement after waiting, and rechecks current issue grant for REPORT. Invoker guards/capture trigger atomically append one event; late capture failure rolls back root changes. Direct event insert/update/delete and root delete are refused. Reader policy delegates visibility to original parent/root, not generic recipient-company isolation: this is the user's explicit cross-recipient disclosure. App rechecks current reader eligibility. Trusted app-supplied actor IDs/tenant settings and database runtime integrity are **not cryptographic identity, privileged-DDL tamper resistance or legal signature**.

Notice list and per-record history paginate25 using independent `prerequisite_page` / `prerequisite_history_page`, preserve language, escape text and display server UTC. No global search/filter/counter or existing document/material/PO/MR/MTO write authority added.

## Verification and evidence

[Evidence](evidence/2026-10-20-inspection-notification-prerequisites/README.md): final affected suites **81 tests /1290 assertions PASS**,176.73s, no skips; separate historical workflow-upgrade **1/5 PASS**,2.12s. **82 distinct targeted cases**, not full regression or current CI. The final81 includes19 prerequisite cases,20 acknowledgment cases and cancellation/PDF/activity-window coverage, including a new true legacy-time follow-up case. Initial12/193 passed; expanded79 had one erroneous fixture expectation (archive422, not403); corrected before final81. Do not add overlapping runs.

New coverage: separate item records, issue/business-data nonmutation, other same-company member edits, full rejection/correction/confirmation/terminal lifecycle, live issue revocation, cross-recipient read-only history/no private draft query, strict/Unicode/array/actor injection, SQL metadata/identity/state/version/history guards, current reader entitlement/discipline/participation/pack, archive/cancel/source-archive, late trigger rollback, escaped text/form recovery, pagination and no-backfill archived install. Four real two-connection schedules: simultaneous CONFIRM version2 → exit[0,2]/one event; revoke-ahead REPORT[2]; cancellation-ahead REPORT[2]; discipline-change-ahead CONFIRM[2]. Each uses observed advisory-lock waits, not assumed delays.

Pint/lint/Blade, local doc links, source hashes/diff and private role/eight-table FORCE-RLS/invoker/socket audit recorded separately. Synthetic scrubbed EN/FA rendered history HTML included; no interactive/all-browser/E2E/load/full-suite/production certification. Existing real bilingual PDF affected suites passed, but no prerequisite PDF feature is claimed. Private verification PostgreSQL stopped after checks. No commit, push, production migration or deployment.

## Review of prior decisions / recommendations

ADR-042's original five choices, ADR-043 cancel-only, ADR-044 receipt and ADR-041 inspection/MTO boundaries remain. Only the recipient-prerequisite deferral is now superseded; neither “reader” nor “confirmed” should be generalized to technical authority. Older document status notices remain historical beneath current addenda.

Proposed, **not enabled**: certified ITP/activity-item linking instead of free text; an independently approved readiness/hold policy if blockers are required; separately versioned prerequisite PDF/current export with render-race revalidation. They need explicit scope and authority decisions, not silent tightening or re-asking these settled choices. Replacement/reissue/rescheduling, notifications/outbox, external delivery/signatures, uploads and new roles remain deferred.
