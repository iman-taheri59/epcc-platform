# Prerequisite → ITP linkage: source review and proposed scope

> **Read-only tracking continuation — 2026-10-20:** user-selected [project prerequisite register](INSPECTION-PREREQUISITE-REGISTER-EN.md) implemented: status/declaring-company/notice literal filters, authorized-only totals and25-row pagination. Same original readers/authority/history/source boundaries; no write/PDF/schema/new role. Final47/1051 + separate legacy1/12 PASS; earlier combined66/1282 and overlapping boundary4/62 documented. Inherited-stage workspace recovery recorded separately; not full-suite/current-CI/browser/deployed.

2026-10-20 · **ACCEPTED / IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-FA.md) · [ADR-042](ADR-042-ITP-INSPECTION-NOTIFICATIONS-EN.md) · [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md)

## Requested continuation

User selected review/design of precise ITP activity/item linkage, with no new source access and necessary decisions identified before implementation. This does not approve a full ITP builder or a new source-reading grant.

## Source findings

- Migration `2026_10_19_200000_add_itp_inspection_notifications.php` defines `inspection_itp_stages`: exact `revision_id`, stage UUID, sequence, discipline, H/W/R/M point, notification requirement and bilingual descriptions. Each notification has a required `stage_id` FK.
- `InspectionNotificationService::issue()` preserves exact stage UUID, sequence, point, bilingual description, document/revision identity and recorded reference text in `issued_snapshot`. Issued source identity/body cannot be edited through the current workflow.
- Migration150000 binds each prerequisite to an immutable notification UUID and issued version. Therefore the exact stage/revision association already exists transitively: prerequisite → issued notification → recorded stage/revision. It must not be marketed as an entirely missing relationship.
- The existing stage host has no structured sub-item/inspection-object table. `item_reference` is free text. A stage UUID does not identify a specific weld, spool, equipment tag, lot, test package or PO line. No automatic mapping is justified.

## Selected bounded implementation (IMPLEMENTED)

Expose the inherited exact stage/revision association prominently on prerequisite detail/history and creation context, using only the original authorized notice's retained snapshot. Show stage sequence/point/bilingual description and exact document/revision reference; make clear that item reference remains free text. Do not fetch a foreign live stage/document or offer a broader source picker. Prefer the existing immutable relation over redundant editable stage/revision columns or client-selected IDs. Missing/invalid historical snapshot fields must be explicitly unknown or refused where required; never reconstruct hidden source data or infer latest revision.

This changes presentation/validation of inherited context, not ITP approval, source access, prerequisite authority or state transitions. Parent-only binding means all prerequisites in one notice concern its one recorded stage. A different stage requires a notice for that stage under the existing issuance model; no retargeting of issued evidence. No prerequisite PDF, readiness gate or backfill is included.

## Distinct larger alternative (NOT APPROVED)

Structured sub-items within a stage require a separately specified identity/model, provenance and administration authority. Decide whether an item means ITP activity, physical inspection object, lot/tag/weld or external reference, and who may register/correct it. Then separately design notice-to-item cardinality, immutable issue evidence, recipient visibility, legacy treatment and correction history. Do not silently use material/PO/MR/MTO readers or item permissions to fill this gap, and do not label a new catalog as a certified ITP amendment.

## Initial scope alternatives — resolved

Choose (A) explicit inherited exact stage/revision display, retaining free-text sub-item reference, or (B) model genuinely structured sub-items first. These are granularity choices, not a reopening of ADR-045 company authority/reporting/visibility. That initial review alone made no runtime change. The user subsequently explicitly selected `itp_link_granularity=inherited_stage`; the inherited-stage presentation is now implemented as recorded below. The structured-subitem alternative was not selected. Earlier81/1290 +1/5 evidence remains historical, not proof for this continuation.

## Selected acceptance checks

Same-stage identity from immutable notice only; stage/revision from another notice cannot be injected; no new live source/directory query; original readers only; wrong project/notice denied; archived source and legacy missing fields handled without inference; current company/discipline/pack/participation and archive/cancel write rules unchanged; bilingual escaped labels and existing prerequisite/history behavior retained. Focused affected tests only, not routine full regression.

## Delivery and exact verification

User selection `next_prerequisite_scope=itp_link`, followed by `itp_link_granularity=inherited_stage`, is settled. No new ADR number is needed for a new authority model: this is an explicitly selected presentation refinement under ADR-042/045. Existing company scope, live sender issue permission, recipient review and all-original-reader visibility remain.

`InspectionNotificationPrerequisiteStage::fromNotice()` is a pure no-query projection of nine recorded fields. It requires valid recorded document/revision/stage UUIDs, matching parent stage identity, matching project if present, nonblank document number/revision, positive integer sequence, valid H/W/R/M and valid bilingual description shape with a nonblank language. Legacy absence of project snapshot metadata is allowed, without reconstruction. Missing/malformed/inconsistent context returns null; the **new context panel** shows explicit unknown rather than substituting a live source. This does not repair the existing parent-detail parser or introduce a new prerequisite write gate for damaged snapshots.

Shared `prerequisite-stage.blade.php` is included in the notice prerequisite list/create area and dedicated current/history page. Shows exact stage UUID/sequence/point, document UUID/number, revision UUID/label and captured description. Escaped bilingual output; fallback uses only the other recorded language, preserving legitimate `"0"`. No hyperlinks to source files, client selector, new API field, migration, table/column, old-row update, PDF change, authority grant or operation gate. Unknown injected stage/revision/document keys remain rejected by existing create/edit validation; query parameters cannot retarget context.

Final focused command: **54 tests /807 assertions PASS**,47.96s, zero skipped. Includes30 pure projection cases and24 prerequisite feature cases (five newly added; prior19 repeated as affected coverage), including four existing real contention schedules. Separate true legacy-time follow-up **1 test /12 assertions PASS**,4.54s. **55 distinct targeted cases /819 assertions across two commands**, not full regression. Initial53/802 PASS precedes the final language-fallback/zero test; do not add overlapping counts.

Verified read-only parent/event nonmutation, no live documents/revisions/stages/private-draft query on recipient pages, same immutable identity on create/history, strict client retarget refusal, archived source + cancelled notice + archived project reading, unaddressed-user denial, explicit unknown, escaped language fallback and true legacy recorded linkage. Broader unrelated PDF/receipt/full-suite/browser/load/current-CI tests were not rerun. No prerequisite PDF/browser screenshot is claimed. PHP8.4/PostgreSQL17 dependencies were restored from the existing Composer lock after workspace runtime reset; no production database or deployment used.

[Evidence](evidence/2026-10-20-inspection-prerequisite-itp-link/README.md) contains commands, logs, source hashes, style/lint/Blade/link checks, nonbypass socket-only database audit and synthetic scrubbed EN/FA rendered history HTML. The private verification DB was stopped after checks; no commit/push/deployment.

## Recommendations — not enabled

Only if genuinely needed, separately design a structured inspection-object/sub-item model with explicit provenance/administration and legacy rules. It is not supplied by repeating stage UUIDs or interpreting free-text tags as certified lineage. Exporting prerequisite context/PDF or introducing readiness/hold rules remains independently scoped, not implicitly approved here.
