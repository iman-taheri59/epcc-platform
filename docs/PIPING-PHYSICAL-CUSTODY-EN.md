> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **Receiving QC delivered — 2026-10-22:** [guide](PIPING-RECEIVING-QC-EN.md) / [فارسی](PIPING-RECEIVING-QC-FA.md). Immutable parent-bound receiving observations, independent current QC nomination/original source scope; actual MRR preparer cannot self-review.50/578 PASS (16 QC +20 custody +14 PO inspection). Only MRR≠QC executable: no QA/GRN/stock release or automatic technical checklist/accepted-with-comment policy. Earlier no-QC-action statements are historical for this increment. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

# Piping physical custody / initial MRR register

2026-10-22 · **IMPLEMENTED / TARGETED VERIFIED / NOT DEPLOYED**
[فارسی](PIPING-PHYSICAL-CUSTODY-FA.md) · [Configuration guide](PIPING-RECEIPT-ACCESS-EN.md) · [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [Evidence](evidence/2026-10-22-piping-physical-custody/README.md)

## Newly approved boundaries — accepted, not proposals

- `piping_custody_record_visibility=current_nominated_source_visible_readers`: a current eligible person nominated in any of custody/MRR, receiving QC, QA acceptance, GRN may read; a linked record also requires independently visible exact PO/header/line. Configuration/owner status alone is not custody read authority. Unlinked physical observations have no fabricated source-read condition.
- `piping_custody_recorder_company=any_explicitly_nominated_participant`: current custody/MRR nominee may be from any eligible participating company, not necessarily the custodian. Original actor/company and selected custodian are separately recorded. No runtime owner/buyer/custodian impersonation.

Existing MRR→QC→QA→GRN matrix, independent nominations and three actual MRR/QC/QA people stay accepted. No new generic role capability, automatic inspector company-type restriction, source PO/document/MTO read grant or advanced waiver is introduced.

## Using this increment

1. Owner-company current project.configure administrator configures explicit custodian and four responsibility sets on the existing nomination page.
2. An eligible nominee sees **Warehouse → Physical custody / MRR register**, `/projects/{projectCode}/materials/piping-custody?lang=en|fa`. GET register/detail private/no-store;25 records per page. Any current nominee can read permitted records; only current custody/MRR nominee sees the create form.
3. Choose an exact caller-visible PIPING PO line if resolved. Picker bounded to200 nonpriced line references; it does not choose a default. An unavailable restored source ID is retained without revealing its label, never silently changed to unlinked. A known caller-visible line can be submitted directly; all POST sources are rechecked. Older exact line versions are retained as evidence, not automatically replaced by a new head. Link to draft/cancelled history is **not** certification of an authorized delivery.
4. Otherwise leave the source unresolved and give a reason. Record shipment/package, actual timestamp with explicit offset, observed description, positive quantity as an exact decimal string (18,3, no float/unit conversion), actual unit, known heat/batch, receiving/quarantine class and physical location reference. Blank heat stays unknown. One record is one quantity/unit/heat split. Example timestamp `2026-10-22T14:30:00+03:30` is shown as `2026-10-22T11:00:00+00:00`; original textual offset is not separately stored.
5. Explicitly declare documents MISSING or PRESENT_UNVERIFIED, not approved. Damage, shortages and reported failed inspection go in escaped observations. Missing evidence/failed inspection does not gate physical arrival. No attachments/IRN/MTC verification or receiving-QC decision is manufactured.
6. Save a **CUSTODY_ONLY** immutable record. Server stamps recorder, company, time, actual selected custodian, policy UUID and technical project-local `CUST-000001` reference. This is not a formal MRR certificate/GRN numbering policy or PDF. Corrections/reversals, grouping multiple lines into a formal MRR and document attachments are not implemented here; no edit or delete UI.

Linked sources store exact original PO/line UUID, line number/supersedes lineage, ordered quantity/unit, observed PO state and issue time, code/descriptions and SHA-256 of PostgreSQL jsonb source snapshot; no price. This is a content fingerprint at custody registration, not a new source revision, financial approval, waiver or verified MTC.

## Authorization, idempotency and SQL integrity

- Current active identity, unchanged company binding, project membership/participation, PIPING/unrestricted discipline, live company-role membership, actor module/pack and custodian participation/module/pack are re-resolved. Suspension/replacement affects current action/read immediately; it never deletes historical custody facts. An archived project remains readable for current eligible nominees but cannot receive a new record. If all nominations are suspended, ordinary read closes too; retained data is not destroyed or delegated to the owner by default. Separate compliance/archive access would need separate approval.
- Owner-only canonical nomination history remains private. A new FORCE-RLS current-only derived index exposes only own-company nomination selectors/policy/custodian IDs to participant runtime (owner scope for maintenance); no foreign directory labels/reasons/old nomination history. Canonical-policy INSERT trigger refreshes the projection atomically, without changing old source SELECT policies. Normal direct index mutation is refused; no SECURITY DEFINER/broad receipt-policy lookup flag.
- Migration forward-projects the latest already-approved unsuspended configuration using a bounded, restored administrative owner context. It adds no nominees/receipts/stock/defaults; populated installation is tested. Runtime never switches to owner/custodian. Trusted application DB role/GUC contract remains the existing platform trust boundary, not independently authenticated per-human SQL identities or hostile DDL resistance.
- Shared advisory/project lock domain with configuration change; fresh action check after waiting. SQL guard rechecks after source-row lock waits too. Exact source header/line SHARE locks preserve the snapshot. Archive, stale expected policy and live source/unit checks are enforced; server metadata/state/snapshot cannot be supplied through the strict nested payload.
- UUID request key is scoped to project. Same current caller + same validated values + same current policy returns the original ID once. Changed payload/caller or stale policy is refused, not overwritten. A hidden-record collision produces localized generic validation, not a SQL error or leaked snapshot. New keys mean new physical entries; no shipment-text deduplication or idempotency claim across policy changes.
- FORCE-RLS append-only custody facts: missing actor/project scope, nonnominees, cross-project and independently hidden linked sources fail closed. UPDATE/DELETE unavailable. Transactional technical counter is internal-trigger-only and does not read hidden receipt facts; rolled back with late failures. Project serial gaps/count inference are not a confidentiality promise or a fiscal numbering contract.

## Implemented versus remaining

**Implemented now:** explicit responsibility configuration, fresh custody/read resolver, physical registration/unresolved sources, exact lineage snapshot, bilingual register/detail/form, immutable audit, retry protection, archive read-only and targeted SQL/concurrency checks.

**Not implemented:** receiving QC actions/checklists, QA acceptance, actual-actor QC/QA separation checks (no such action exists yet), GRN/accepted stock, reservation/ordinary consumption enforcement for this new flow, corrections/waivers, preservation/storage qualification, formal MRR/PDF, attachments, legacy cutover. Existing WarehouseService and old warehouse behavior remain unchanged; new custody records cannot become material_lots or consumption credits. GRN overlap still requires independent nomination when that action is built. Advanced accepted-with-comment/applicability/quantity tolerance/returns/disposition/legacy policies remain open; structured prerequisite design and implementation remain deferred.

Next increment: receiving-QC evidence against the exact custody identity, independent current receiving nomination and refusal of the actual MRR preparer as reviewer; do not call existing ADR-041 PO observations acceptance. Pending checklist/acceptance rules must be settled before their dependent automated decisions, not invented here.

## Executed verification — not full-suite certification

| Run | Result | Meaning |
|---|---|---|
| Final custody suite |20 tests /242 assertions PASS,37.16s | final code, `final-verified.log` |
| Related milestone before final localized retry/form/source-wait tightening |60 /438 PASS,99.46s | then17 custody +14 receipt-configuration +29 existing warehouse; `final-focused.log` |
| Configuration status-text adjustment subset |2 /43 PASS,3.82s | overlapping existing tests, `config-status-subset.log` |

Runs overlap and must **not** be added. No full suite, interactive-browser, load, current-CI, production migration, commit/push/deployment claim. Final20 include real separate PHP/PG worker connections: two concurrent identical keys create one record; nomination suspension during advisory wait denies new action; role revocation while blocked on a source PO row is rechecked and denied. Other coverage: no defaults/owner inheritance, live company/role/discipline/plan/participation, cross-company real recorder, original-source hidden read/write denial, precise units/heat splits, safe scoped old-input restoration, replay conflicts, SQL projection/counter guards, late-failure rollback, forward installation and archive behavior. Actual sanitized EN/FA rendered record HTML retained, not browser screenshots.

Initial focused failures were empty outer-envelope validation occurring before authority and test fixtures with no new-discipline PO line; authority gate ordering/localization fixed and source fixture created through the actual PO service. Supplementary fixture restoration needed the original project_company row and the existing administrative identity hatch for simulated user transfer; worker barriers switched from cached stats to exact pg_locks and explicit isolated child DB environment. These were harness corrections, not relaxed RLS. Private PG17 non-superuser/no-BYPASSRLS audit and stopped-server handoff retained. Source/docs hashes and checked archive included.
