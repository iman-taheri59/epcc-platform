# ADR-042 — contractor-issued inspection notifications against ITP stages

> **Inherited-stage continuation — 2026-10-20:** user selected `itp_link_granularity=inherited_stage`; [source review and delivery](INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-EN.md). Exact recorded stage/revision now explicit in prerequisite create/current/history context, no live-source query/new access/schema/backfill/PDF or structured sub-items.54/807 + separate legacy1/12 PASS, not full suite/browser/current-CI/deployed. This supersedes only the proposed stage-display refinement, not certified item-link/readiness deferrals.

> **Prerequisite addendum — 2026-10-20:** [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) / [guide](INSPECTION-NOTIFICATION-PREREQUISITES-EN.md): explicitly approved company-reader management, sender current-issuer report, declaring-recipient confirmation/rejection, all original readers see versioned history. No automatic gate or parent/PDF/private-history change. Only prerequisite deferral superseded; earlier choices retained.81/1290 + separate upgrade1/5 PASS, not full-suite/deployed.

> **Current acknowledgment addendum — 2026-10-20:** [ADR-044](ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) / [guide](INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md). Explicit user choice: any authorized selected-recipient reader represents their company; separate original-before-cancel and exact cancellation receipts, first/company/event immutable; all original readers see company/person/time in detail/current PDF. No automatic read tracker, doc/issue/stock/result authority or private-history disclosure. Final61/1040 + separate upgrade1/5 PASS; overlapping final PDF1/22 PASS. Only acknowledgment deferral superseded; cancel-only/replacement/transport/signature boundaries preserved. Not full suite/current CI/production.

> **Current lifecycle addendum — 2026-10-20:** [ADR-043](ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-EN.md) / [cancellation guide](INSPECTION-NOTIFICATION-CANCELLATION-EN.md). User approved current issue grant + **cancel-only** + original-reader reason/actor/time visibility. Independent immutable event; original issued row/snapshot/private history untouched; effective CANCELLED and current PDF banner/status. Prior cancellation deferral superseded **only**; replacement/undo/reissue/acknowledgment remain deferred. Final59/884 + separate upgrade1/5 PASS; overlapping PDF1/22 PASS. No full-suite/90-case/current-CI/production pass.

> **Latest UI continuation:** user-selected `filter_usability` implemented; [optional filter date/time controls](INSPECTION-NOTIFICATION-FILTER-TIME-UI-EN.md), same offset/overlap/legacy/authority contract. **26 PHP tests /404 assertions +7 Chromium scenario groups /147 checks PASS** (separate nonadditive verification). Offline current rendered fixtures, not live authenticated browser/production; no full suite. Previous register/history counts are historical.

> **Register-filter addendum — 2026-10-20:** user-selected `register_filters` implemented. [Current guide](INSPECTION-NOTIFICATION-REGISTER-FILTERS-EN.md): bound literal number/current-reference search + state + explicit-offset inclusive activity overlap; authorized-only totals/pages, independent source pickers, preserved filters/lang/context and notice-page reset. Legacy unknown windows excluded only by time bounds; no history/note search/new authority/schema/API write. **58 tests /640 assertions PASS**,82.701s, zero skipped; not full suite/production/current CI. Earlier totals historical/nonadditive.

> **Draft-history addendum — 2026-10-20:** user-approved `draft_history` implemented. [Current history guide](INSPECTION-NOTIFICATION-DRAFT-HISTORY-EN.md). Atomic database-captured before/after versioned draft snapshots + recorded editor name/UUID/time/settings; append-only/FORCE RLS, originating-company readers only, no recipient HTML/SQL/PDF history.25-row pages, no-op saves retained; old edits not reconstructed, earliest captured version explicit. Migration120000; **87 tests /1139 assertions PASS**,156.343s, zero skipped, not full suite/production/current CI. Prior “last-editor only” limitation applies to old records, not future captured edits; earlier counts historical/nonadditive.

> **Date/time UI addendum — 2026-10-20:** user-selected `date_time_ui` implemented. [Current UI guide](INSPECTION-NOTIFICATION-TIME-UI-EN.md): native Gregorian date/time + explicit offset, visible exact value/direct-entry/no-JS fallback, readable web display. Canonical payload, services/schema/authority/issued evidence unchanged. Related53/740 PASS; after RTL-label-only correction4/81 subset PASS; separate18 browser scenarios/149 checks PASS. No full-suite/production/all-browser/E2E claim; previous counts historical, nonadditive.

> **Activity-window addendum — 2026-10-20:** user selected **`activity_window=replace_time`**. [Current activity-window guide](INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-EN.md). Required planned start/end replace single inspection time on new registration/draft saves; compare absolute instants, retain offsets. Issued legacy single times remain explicitly labelled, never backfilled; legacy drafts must explicitly convert before issue. Current final **71 tests /802 assertions PASS**,105.926s, zero skipped; earlier counts/single-time input statements below are historical. Same authority/immutable issued evidence; no new endpoint or issued rescheduling.

> **Draft-edit addendum — 2026-10-20:** user selected `draft_only`. [Bounded edit guide](INSPECTION-NOTIFICATION-DRAFT-EDIT-EN.md): same-company current draft grant, fixed source/creation evidence, versioned content changes, latest editor/time/settings evidence (not full draft history). Latest-version issuance and immutable issued data/PDF retained. Forward migration100000; **58 tests /631 assertions PASS**,87.559s, zero skipped; not full suite/production. Earlier create-only/version2-only limitations below are historical; issued correction/cancellation/rescheduling remain deferred.

> **PDF addendum — 2026-10-20:** issued-only EN/FA export now implemented from immutable recorded data with unchanged readers and post-render visibility recheck; no signature/transport/new authority. New issues capture project metadata; legacy unknowns are explicit, no backfill. Final42/501 plus separate shared-PDF21/85 PASS (63/586 across two commands, no full suite). [Current guide](INSPECTION-NOTIFICATION-WORKFLOW-EN.md). Earlier PDF-deferred/contract-only text below is historical.

> **Implementation addendum — 2026-10-19: OPERATIONAL BOUNDED SLICE IMPLEMENTED / TARGETED VERIFIED.** [Workflow guide](INSPECTION-NOTIFICATION-WORKFLOW-EN.md). Exact-revision immutable stages, explicit main contractor, delegated draft/issue resource permissions, create-only drafts, atomic issue, immutable snapshot, purpose-limited recipient inbox/detail and quality navigation now exist. Final separate commands:29/167 feature+input and17/83 navigation PASS (46/250 across two runs). Earlier40 existing document/result cases passed in a related run that had a new concurrency-harness error; do not claim that command fully passed. Historical contract-only status/next-step statements below refer to the earlier increment and are superseded by this addendum.

Stage administration is now concretely bounded by existing same-company doc.issue plus original doc.view/discipline; it does not approve the ITP. Existing project.configure (not owner-only) administers separate named-user main-contractor draft/issue grants; no default role/capability grant. Recipient in-system quality/pack entitlement is required/rechecked, without granting doc.view. Numbering uses a distinct project/company IN series, preserving old IRN observations. Source CDE state is recorded without inventing an ITP approval gate. H/W party-matrix enforcement, notice periods, waiver/proceed, editing/cancellation/reissue, external transport and full ITP approval remain open/deferred. Migration200000 tested on a populated archived project without old-data changes or defaults, not deployed to production.


2026-10-19 · **ACCEPTED / OPERATIONAL BOUNDED SLICE IMPLEMENTED; TARGETED VERIFIED**
[فارسی](ADR-042-ITP-INSPECTION-NOTIFICATIONS-FA.md)

## Requirement and five explicit selections

The owner requires the contractor to register and issue inspection notifications for **every work stage requiring inspection under the ITP**, including material inspections. This is not restricted to purchase orders or materials.

1. `inspection_notification_scope = all_itp_stages`.
2. `inspection_notification_authority = contractor_existing_permission`: a separate permission for contractor personnel, delegated by an authorized project administrator. **Not** owner-only independent nomination copied from inspection-result entry; no default inheritance from purchasing or inspection-result authority.
3. `inspection_notification_recipients = explicit_project_recipients`: the issuer explicitly selects authorized project participating parties. The alternative of automatically deriving/fixing all recipients from ITP assignments was not selected.
4. `itp_notification_foundation = document_linked_stages`: a specific existing ITP document revision and structured stage list; not the full author/review/approve/publish ITP builder.
5. `notification_contractor_scope = main_contractor_only`: only the project's main contractor, not subcontractor issuance. Never issue on behalf of another company.

These choices are settled; do not re-ask them or silently switch to the rejected alternatives.

## Requirement review and conflicts

- SRS FR-PRC-06 already calls for ITP-linked inspection coordination/notification; FR-QA-01 covers discipline/activity ITP and H/W/R/M points. This increment implements a bounded prerequisite of these requirements, not the entire quality module.
- Current code supports `doc_type=ITP` in DocumentController, but has no active structured ITP/stage model or notification workflow. The earlier ITP pause and ADR-019 citation deferral are superseded **only for the selected document-linked stage host**. Do not import the archived parallel ITP implementation or claim the full ITP builder/citation/disposition workflow is authorized.
- ADR-041 is a **result/observation** workflow. Contractor notification is a **request/notice of planned inspection**, not PASS, verified attendance or third-party sign-off. EPC may issue the latter notice when authorized while still being prohibited from self-certifying its inspection result. Existing TPI/PMC/MC/OWN result-entry independence remains intact.
- Existing Warehouse/ADR-041 observations already use project IRN numbers. SRS also overloads IRN for notification/release. Use distinct entities/number series; never reinterpret/backfill existing inspection results as notices. A final notification prefix is a technical naming follow-up, not an implemented rename.
- Multiple EPC companies already participate in demo PH-14. EPC type or generic CONTRACTOR participation alone cannot establish “the main contractor.” Require an explicit bounded project/contract main-contractor designation; do not select the first EPC or grant all EPCs rights.

## Required operational acceptance contract — not yet shipped

### ITP host

Resolve a same-project, original-caller-visible document with type ITP and an explicit revision UUID. Store structured stage identity/order, discipline/activity, description, H/W/R/M information and whether notification is required. Do not force a PO on site/erection/testing stages. A PO may be a supplemental original-visible reference for material stages, never a delegated read hatch. Merely linking an issued CDE revision does **not** establish engineering approval of its content.

A notice binds an exact stage and revision, not “latest ITP.” At issue retain the reference and immutable stage/reference snapshot so later document or stage edits cannot rewrite the issued notice. Document/PMS standard references must not be silently promoted into new approved ITP criteria. Detailed Fit-up/NDT/hold-release verification remains outside this host.

### Authorization

Use separately assignable notification draft/issue permission(s), with no seed/default grant and no new globally privileged role. Only an actually authorized project access administrator may assign them; a role merely named project_manager does not acquire project.configure. Sender is the actor's actual explicitly designated main-contractor company and current project membership/discipline scope. Recheck active identity, current grant, company/participation, plan/module/standards entitlement and archive status on each write/issue. Final permission identifiers and their integration/administration UI still require implementation; no permission has been granted by the input class.

### Draft and issue

Draft records reference exact ITP revision/stage, work item/reference, proposed inspection date/time with explicit timezone, location, selected recipients and optional bilingual notes; source PO is optional. Point/inspection requirement comes from the stage, not user-supplied PASS or arbitrary point text. Stage must actually belong to the referenced revision/project and require notification. Unknown/hidden source or malformed/unauthorized recipients must fail closed.

An issue command uses an optimistic draft version, server-assigned number, authenticated issuer/company/time and immutable recipient/reference/body snapshot. Lock and recheck at issue: revoked permission, stale draft/stage, changed project main contractor, archive, missing/hidden references or inactive recipient participation cannot issue. Issue atomically exactly once; retries must not duplicate the issue or enqueue delivery twice. Issued evidence is not silently editable/deletable. Corrections/cancellation/reissue need an explicit traceable design, not DRAFT rollback.

Recipients acquire only purpose-limited notification access. Selecting a party does not give that party generic ITP files, PO/MR prices, financial records or other contractor's data. No SECURITY DEFINER/buyer-context lookup to fill a hidden reference. Free text is escaped on display; application authority must validate recipient selection, not just UUID syntax. Do not expose an unbounded global people/company directory.

Initial issuance is a recorded in-system document event. **Issued ≠ email delivered ≠ read ≠ attendance confirmed ≠ inspection done ≠ accepted/released.** No email/SMS/push service, delivery guarantee or external communication is currently implemented/approved by choosing recipients. Technical outbox/idempotency and actual transport status must remain distinct.

### Existing behavior preserved

No automatic inspection result, lot cache update, stock usable/hold/release, receipt block, PO approval/issue, source allocation, MR mutation or MTO access. Do not turn H/W/R/M labels into a universal hard receipt/work gate. Stage-specific hold, notice period, attendance waiver/no-show, NCR and release rules need their own explicit decisions.

## Open recommendations, not owner-approved policy

- Reconcile existing document authority/CDE states with who may enter/freeze the stage list. Do not call the list “approved ITP” just because an uploaded revision exists; full ITP approval was explicitly not selected.
- Explicit project main-contractor configuration is necessary when multiple EPCs participate; do not infer it from PO buyer or document originator.
- Validate chosen recipients against project authorization. Whether omitting an ITP-mandatory H/W party is a blocking rule or a warning is **not yet decided**; the selected manual-recipient model must not silently become automatic ITP-party assignment.
- Model lead time/timezone per project/stage rather than inventing a global24/48-hour rule. No permission to proceed is inferred from elapsed notice or absent response.
- Keep old IRN observation identifiers unchanged and choose a distinct notification series/type.

## Initial contract-only code and evidence (historical)

`InspectionNotificationInput` defines **shape only** for a proposed draft and versioned issue request. It rejects client actor/company/result/number/point/status/time-of-issue fields; requires exact revision/stage UUIDs, work reference, offset-qualified scheduled time, location and unique recipient company UUIDs; PO is optional and notes bounded. It does not query the DB, authorize anyone, freeze a stage, create a draft, issue a notice or deliver messages. It is not wired to a route and has no security effect on live privileges.

Unit `InspectionNotificationInputTest`: **7 tests /24 assertions PASS,0.034s**, no database started. Evidence `docs/evidence/2026-10-19-inspection-notification-contract/`. These are input-shape tests, **not** tenant/RLS/authority/issuance/workflow verification. No migration/controller/form/register/permission administration/transport/PDF has shipped for this feature yet. Previous46/558 evidence belongs to inspection **results**, not this feature.

## Original implementation plan (historical; see current addendum)

1. Structured document-revision/stage host and explicit main-contractor reference, with project/discipline/original-document-RLS tests; reconcile stage-edit authority first.
2. Notification permission assignment/revocation and draft/form/register with original source visibility and explicit authorized recipients.
3. Atomic versioned issue, numbering, immutable snapshots and purpose-limited recipient inbox/detail, including two-connection double-issue/rollback tests.
4. Only after those gates, evaluate response/cancellation/reissue and optional transport/print/export; do not label mere registration as issued or delivered.
