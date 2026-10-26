> **Approved PDF continuation — 2026-10-20:** [current prerequisite appendix](INSPECTION-PREREQUISITE-PDF-EN.md) / [فارسی](INSPECTION-PREREQUISITE-PDF-FA.md). Selected prerequisite_pdf implemented: all records/full history/retained stage, separate from issuance snapshot; same scope, no new schema/authority/writes.409 on changes during render.20/1102 + separate4/60 PASS; not full-suite/current-CI/deployed. Only prerequisite-PDF deferral and contradictory historical prose below are superseded; other decisions retained.

# Inspection notifications — company acknowledgments

> **Prerequisite addendum — 2026-10-20:** [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) / [guide](INSPECTION-NOTIFICATION-PREREQUISITES-EN.md): explicitly approved company-reader management, sender current-issuer report, declaring-recipient confirmation/rejection, all original readers see versioned history. No automatic gate or parent/PDF/private-history change. Only prerequisite deferral superseded; earlier choices retained.81/1290 + separate upgrade1/5 PASS, not full-suite/deployed.

2026-10-20 · **ACCEPTED / IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md) · [ADR-044](ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) · [ADR-042](ADR-042-ITP-INSPECTION-NOTIFICATIONS-EN.md) · [Cancellation](INSPECTION-NOTIFICATION-CANCELLATION-EN.md) · [Workflow](INSPECTION-NOTIFICATION-WORKFLOW-EN.md)

## Explicit approved decisions

User selected `next_scope=recipient_acknowledgment`, then:

| Decision | Selected | Meaning |
|---|---|---|
| Authority | `ack_authority=any_authorized_company_reader` | Any currently authorized reader **of an explicitly selected recipient company** may confirm on behalf of that company. No separate nomination, managerial/issuer role or personal-only receipt. |
| Target | `ack_target=issue_and_cancellation_separate` | Original issue and the specific cancellation event have independent company receipts. Original can be acknowledged only before cancellation. |
| Visibility | `ack_visibility=all_authorized_notice_readers` | All currently authorized original readers see recorded company/person/time, including other selected recipient companies. |
| Export | `ack_pdf_scope=detail_and_current_pdf` | Detail and current PDF include those receipts; recheck event-set changes during rendering. |

These choices are settled. Separate representatives, personal-only receipts, sender/own-recipient-only visibility and detail-only export were offered but not selected. The user explicitly approved this limited new write authority from notice reading **for acknowledgment only**; no inference of ITP/result/PO/MR/MTO rights or generic read-to-write permission. Existing ADR-042's five choices and ADR-043's **cancel-only** remain. Only the acknowledgment deferral is superseded; external transport/signatures and replacement remain deferred.

## Use and semantics

A selected recipient company's active/currently entitled project reader opens an issued notice and explicitly presses **Confirm receipt of original notice for my company**. The warning explains company representation and disclosure of company/name/time to all notice readers and PDFs. Opening a page, listing notices, reading or downloading PDF does **not** create a receipt. A valid explicit POST is the in-system declaration; it is not independently observed proof of actual reading or external delivery.

The first valid receipt **per notice + recipient company + event kind** is retained. A second person of the same company does not create another company receipt or replace the first person's name/time; duplicate requests are refused. Different selected companies can each record their own receipt. No edit/delete/undo, extra note, uploaded evidence or backdated client time exists. A sender who is not an explicitly selected recipient cannot acknowledge on behalf of its recipients. If the sender company was explicitly selected too, its authorized reader may acknowledge **only for itself**. An unaddressed administrator gains no notice/receipt access from configuration authority.

After cancellation, the form targets **Confirm receipt of cancellation for my company** and carries the exact cancellation UUID. Late original-issue receipts are refused, including a request waiting behind a committed cancellation. Original receipts remain unchanged; receipt of cancellation does not fabricate receipt of the original and original receipt is not receipt of cancellation. Cancellation with no prior company receipt can be acknowledged on its own. No global ACKNOWLEDGED state is assigned to the notice: raw ISSUED evidence and effective CANCELLED rules remain as before; receipt is per company/event, not universal delivery.

UI shows recorded events with captured company code/bilingual name, actor name and server UTC time. Absence is labelled **no company acknowledgment recorded**, not proof of unread/non-delivered. Names are event-time snapshots, not a live people/company-directory lookup. Historical receipt remains visible to other authorized original readers even if that recipient later departs; departed/unentitled users themselves lose current access. Reasons/private old draft bodies, commercial data and unrelated people are not added to the receipt.

## Access boundaries

Current user active/company, project membership + participation, discipline, QAQC module and standards pack must still qualify. No document module or doc.view/issuer/drafter privilege is required **for the selected recipient's purpose-limited notice receipt**; none is granted. A role-less member who already qualifies as an original notice reader can acknowledge under the user's selected model. Revoking issue/draft nomination alone does not remove this separately approved reader-based authority; revoke the reader's actual eligibility to remove it.

Project must be writable. Archived/nonwritable projects retain receipts/read access but forbid fresh original **and cancellation** receipts; no archive exception was authorized. Archived source documents do not prevent receiving an already issued notice and do not become accessible through it. Real legacy single-time notices and their cancellation can be acknowledged without inventing activity windows, backfilling old receipts or updating old issue snapshots. Current scope revalidation also runs in SQL after a waiting transaction acquires the common lock.

## API, SQL and integrity

POST `/projects/{projectCode}/inspection-notifications/{notificationId}/acknowledge`, existing authentication/web CSRF, payload `acknowledgment` contains **only**:

- `version`: exact immutable issued version, integer >=2;
- `event_kind`: ISSUE or CANCELLATION;
- `cancellation_id`: required exact UUID for CANCELLATION; absent/empty for ISSUE.

Client actor/company/names/time/status/authority fields and unknown payload keys are refused. Company/actor come from the authenticated current reader, not a selector. Successful POST redirects to the same notice/language. Stale/duplicate/invalid target is refused; it does not report another success. No GET mutation, sending queue or mailbox evidence.

Forward-only migration **`2026_10_20_140000_add_inspection_notification_acknowledgments.php`** adds one append-only table/index with unique company/event identity, restricting FKs, FORCE RLS and an invoker trigger; no prior migration, user role or nomination is rewritten. SQL rechecks project/company, writable project, visible immutable issued parent/version and original recipient membership; locks current actor, membership/discipline, project participation, company/plan entitlement through commit. Captures/overrides actor name, company code/bilingual names, discipline, authority model `AUTHORIZED_RECIPIENT_READER`, server clock. CANCELLATION must reference that parent's actual immutable cancellation. UPDATE/DELETE/duplicates, foreign-scope/actor/version/target are refused; late insert failure rolls back all event effects.

All issue/cancel/receipt paths share the project advisory/header lock. Receipt requires **no recipient UPDATE/row-lock grant on sender-owned notice rows**: those rows are already immutable; common project lock serializes lifecycle decisions. Parent RLS policies are unchanged. Read policy requires the same visible parent/project; application applies current actor discipline/active/entitlement/participation. A receipt does not delegate generic source/PO/person reads. This is trusted app/SQL runtime integrity, **not** privileged-DDL tamper proof, signature or end-user database identity authentication; the app supplies authenticated actor identity as in earlier issuance/history design.

## Current PDF

PDF retains original issuance/cancellation context and adds company receipt records as **current in-system** context, not rewritten issued snapshot text. All authorized readers see the approved full receipt set; recorded names are escaped and no live foreign directory joins occur. Footer now distinguishes retained issue evidence and current lifecycle/receipt records from a signature, external-delivery proof, attendance, acceptance or release; it no longer says receipt records are absent.

After rendering, the endpoint rechecks current reader/entitlement, cancellation UUID and the ordered immutable receipt IDs. A newly committed receipt during rendering gives **409**, no PDF body/disposition, asking for retry. Cancellation-during-render and departed-reader safeguards remain. This is a check at the final read, not a promise that state cannot change afterward. Previously downloaded/printed PDFs cannot be recalled or updated. No email/SMS/push/delivery guarantee, legal receipt, independent signature, inspection result, hold/release/proceed or mandatory ITP-party rule is implied.

## Verification — exact and nonadditive

**Final affected command:61 tests /1040 assertions PASS,135.191s, zero skips**:20 new acknowledgment cases +15 cancellation +13 existing PDF +13 activity-window (including one new actual pre-window-schema issue/cancellation receipt case). **Separate existing workflow forward-upgrade case:1 test /5 assertions PASS,1.862s**. These are62 distinct cases in two commands, not a full-suite run.

After a presentation-only footer correction, reran the real bilingual receipt PDF case: **1 test /22 assertions PASS,3.754s**, overlapping the61 and not added. Real Chromium render + pdftotext verifies both original/cancellation receipt labels, current body/number, no private old draft marker, current footer, no-store and unchanged parent/events. Persian receipt page visually reviewed. No new interactive-browser/all-browser/current-CI/production/load certification.

Coverage: any-reader/no issue/draft/doc role and QAQC-only plan, first-person/company uniqueness, separate companies/events, selected sender self-only, unaddressed admin/foreign readers, all-reader cross-recipient visibility without private-history queries, company/person snapshot stability/HTML escaping, version/target/client metadata forgery, SQL server captures and immutable guards, active/membership/discipline/module/pack/participation/archive revocation, retained source/legacy evidence, no business mutations and rollback, populated archived forward upgrade without fabricated events. Independent worker lock barriers verify same-company race yields one receipt, separate companies each succeed, waiting original receipt loses to committed cancellation, waiting reader loses to committed discipline revocation. Independent committed insertion inside a test renderer proves stale PDF409. These are targeted races, not arbitrary deadlock/concurrency/load certification.

Initial14-case run had13 passes and one fixture error: test plan code exceeded the existing24-character column. Shortened the synthetic fixture code, without weakening schema/application validation; extended19/252 then passed. Final61 covers corrected case plus escaping/membership cases. Existing old-schema test fixtures were updated only to remove/install new dependents in correct order (ack before cancellation/parent; current-schema upgrade after old-window reconstruction). No old production migration was rewritten. Earlier60-case cancellation/other increment totals are historical/nonadditive.

[Evidence](evidence/2026-10-20-inspection-notification-acknowledgment/README.md): exact commands/logs, synthetic scrubbed HTML, bilingual receipt PDFs/text and Persian receipt-page image, source hashes, fresh-cache Pint/lint/Blade/diff/link validation, private six-table RLS/invoker audit and database shutdown. PHP8.4/PostgreSQL17 private app role NOSUPERUSER/NOBYPASSRLS, Unix socket only; stopped after verification. No commit/push/production migration/deployment.

## Recommendations and deferred scope

The selected model deliberately lets any qualifying recipient reader speak for the company and discloses their recorded identity/time to all authorized original readers. If contractual representation, narrower disclosure, retraction or certified external receipt is required later, revise the policy explicitly—do not quietly invent managerial roles or call this signed delivery evidence. Archive exceptions require separate approval. Receipt dashboards/search/export APIs, reminders, attendance, approval, replacement/reissue/rescheduling, outbox/transport and signatures remain outside this increment. Original filters/counts/paging/source pickers and private draft-history boundaries stay unchanged; no MR/PO/MTO/result/stock mutation or authority is inferred.
