> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

# Final piping QA decisions

2026-10-23 · **IMPLEMENTED / TARGETED VERIFIED / NOT DEPLOYED**
[فارسی](PIPING-QA-FA.md) · [Receiving QC](PIPING-RECEIVING-QC-EN.md) · [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [Evidence](evidence/2026-10-23-piping-qa/README.md)

## Approved decisions and historical corrections

The user explicitly approved `manual_qa_versioned_evidence` and `open_comments_block_acceptance`. These close the two QA-policy questions previously left pending in the receiving-QC delivery. The existing independently nominated `acceptance_qa` action is now executable; no new role, selector default, document grant or source delegation is introduced. Earlier dated statements that QA is unimplemented or that these two policies remain open are historical, not the current status. Structured checklist/ITP prerequisite design and implementation remain deferred.

| Status | Scope |
|---|---|
| Previously approved, now executable | Final QA by a third actor, exact immutable custody and latest QC binding, explicit owner-configured QA nomination. |
| Newly approved, implemented | Manual technical requirements/evidence version binding; explicit QA attestations; open conditions block acceptance. |
| Still not implemented | Controlled GRN, accepted-stock posting, movements/issues, waiver/disposition, preservation, legacy cutover and formal QA PDFs. |

**This is human technical assessment, not automatic technical sufficiency verification.** QA identifies applicable requirements and the evidence coverage in mandatory assessment text and selects precise file versions. QA explicitly confirms source identity, quantity/unit matching, heat/batch traceability, completeness of all applicable mandatory documents, resolution of applicable nonconformities, and applicability/satisfaction of the cited requirements. The system does not derive the applicable MTC/IRN/ITP checklist, read a certificate to determine technical compliance, or verify physical-human identity across multiple accounts. The responsibility of a false attestation is not replaced by the mere existence of attachments.

## Use and gates

From physical custody/MRR detail, open **Final piping QA decision**, `/projects/{projectCode}/materials/piping-custody/{receiptId}/qa?lang=en|fa`. Current eligible nominees in the four existing receipt responsibilities can read under the unchanged parent/PO scope. A current independent QA nominee can append only if distinct from both the actual MRR preparer and the actual latest QC reviewer. Comparison is pairwise `User.id`, not company, role or display name. Owner/configurator status does not itself grant QA/receipt read or write. Archive is read-only.

Choose explicitly (no preselected outcome):
- **HOLD:** records unresolved/missing evidence or technical conditions; may have no citations and unchecked attestations. A current QC record is still needed to bind the decision. Physical receipt does not need QC merely to exist.
- **REJECTED:** records a reason, not a disposition/return transaction. No stock or issue permission results.
- **ACCEPTED:** all six explicit attestations true, no open-condition text, at least one requirements citation and one evidence citation; current QC must be `NO_NONCONFORMITY_OBSERVED`. These prerequisites do not automate technical sufficiency.

Conservative acceptance preconditions also require a linked independently readable PIPING source in ISSUED/PARTIALLY_RECEIVED/RECEIVED, exact frozen non-price line identity/unit, recorded heat/batch reference and receipt quantity no greater than that source line quantity. Draft/unlinked source, unknown heat, changed line or over-delivery stays non-releasing. PO operational state/issue timestamp may advance without changing its technical line identity; the decision records the exact current non-price source basis separately from the historical custody snapshot. Initial `document_status=MISSING` is an arrival fact, not a permanent veto: subsequently available versioned evidence can support a fresh assessment without rewriting that fact.

No unit conversion, over-delivery tolerance, aggregate quantity reservation, line repair or retrospective custody editing is implied. The per-receipt ceiling is **not** proof of cumulative PO availability across receipts. A future GRN increment must perform atomic aggregate/previous-posting checks before any accepted credit.

Open conditions require Hold, not a releasing Accepted with Comment. Resolve the matter, append a fresh QC review if needed and then append an unconditional QA acceptance. There is no narrative waiver, release-with-comment or technical exception route. Hold/Rejected remain ordinary non-issuing conditions. Acceptance does not move the original quarantine location or change commercial/stock tables.

## Exact evidence and unchanged access

1. Use existing document permissions and the CDE to publish/freeze the relevant file versions. QA does not publish documents or grant `doc.view`.
2. The catalog lists only already readable PUBLISHED/ARCHIVED files (50 per page). Other pages open separately so the reviewed form is not silently reset. Enter exact file UUIDs, one per line; up to20 per group. JSON API uses explicit UUID arrays. One bundle can serve both groups only if QA actually establishes that coverage; the system does not invent document-type completeness rules.
3. Cite requirements and supporting evidence explicitly; explain applicability and coverage in the assessment. Publication freezes attachment editing in the existing document service; it is not technical acceptance. ARCHIVED is historical, not automatically a current specification. Neither published B suitability nor an arbitrary A code is automatically certified by this QA screen.
4. The service checks existing `doc.view` entitlement/member/RBAC and ordinary project/document/revision/file RLS; it verifies stored SHA-256 **and size** before creating a new decision. SQL binds document/file/revision IDs, code/ordinal, name, hash, size and CDE state/suitability as a server-generated snapshot. No storage paths are displayed.
5. Citation rows are separate from decision metadata. Reading a decision does not make its citations readable: existing document capability and all original source visibility are checked again. Revoking document access or hiding an original revision hides its citation snapshot too. Empty displayed citations do not prove an old decision lacked evidence. No broadening of existing CDE policies.

The byte check proves integrity against the stored hash, not certificate authenticity, signature verification or technical suitability. Published-file immutability relies on the existing application document lifecycle, not new hostile-DDL guarantees or filesystem locking. Future GRN must revalidate exact source/evidence integrity and all live permissions; historical acceptance is not permanent posting eligibility.

## Audit, retry and stale reviews

Dedicated append-only decision and citation tables use FORCE RLS, invoker guards, server actor/company/name/time/sequence/custody fingerprint/current source basis, same-receipt previous-head chain and exact QC FK. Shared receipt advisory/project locks serialize policy/head writes and force fresh authority after waits. No UPDATE/DELETE policies, source impersonation or SECURITY DEFINER lookup hatch. Citations must be inserted within the same transaction as their decision; deferred completeness validation prevents accepted headers without both citation groups. A late failure rolls back header and citations atomically. Forward-only migration creates empty tables; no legacy decisions/backfill.

A new decision requires the exact latest QA head and exact latest QC UUID, plus the exact current nomination policy. Stale input is refused, never silently rebased. Failed form input is scoped to both project and custody. Identical request UUID/payload/current caller/current policy/current source visibility returns the historical ID once, even after another review; it does not reactivate that decision. Changed payload/caller/target or hidden collisions are refused generically. Canonical payload hash sorts citation IDs and normalizes text/booleans; it is not an evidence-content hash.

History20 and global QA head are separate. A newer QC causes the older QA decision to be displayed as not current; it is not rewritten. The original trusted application DB role/GUC security boundary is retained, not independent per-human SQL credentials. No earlier QC, physical custody, PO-inspection or warehouse path is silently replaced.

## Verification

- Final QA-only run: **19 tests /142 assertions PASS,58.46s**, zero skipped (`final-qa-only.log`).
- Affected batch:73 cases (QA19, receiving-QC16, custody20, DocumentFile18);72 passed and one QA **test-fixture function-name collision** failed. Existing54 dependent tests passed unchanged. The fixture was made repeatable and cleaned up; the failed case reran PASS1/7,5.63s, then the complete QA19 ran clean. Do not call the initial batch an all-green73 run; logs are retained. No routine full-suite rerun.
- Includes real observed advisory-lock wait workers for duplicate idempotency, stale QA/QC head and revocation; both SoD edges; explicit attestations/open conditions/evidence groups; issued-source/heat/overdelivery guards; unpublished/corrupt/hidden files; current document read capability and original RLS on historical citations; immutable/late evidence; deferred missing-evidence refusal; atomic rollback; scoped old input; HTML form parsing; archive; populated forward migration; unchanged stock/commercial snapshots; bilingual escaped private HTTP pages.
- Synthetic EN/FA HTTP HTML is retained, not a browser screenshot or production certificate. PHP style/lint, Blade, routes, DB role/FORCE-RLS/invoker/UTF8/socket checks, source hashes, link checks and a round-trip-verified archive are recorded in the evidence directory. Private test database is stopped at handoff.

## Recommendations / remaining conflicts

1. **Recommended next:** controlled custody/source/heat correction or supersession, preserving the arrival record and requiring fresh QC/QA. Currently incomplete identifiers cannot be repaired by a QA narrative; those receipts remain Hold. This recommendation is not a newly activated correction authority.
2. Before controlled GRN, specify/verify aggregate quantity accounting, duplicate posting, live nomination/source/evidence checks and stale-QA handling under locks. After future posting, newer QC/Hold must use an explicit blocked/compensating material-control workflow, not silently undo posted stock.
3. Existing legacy WarehouseService is not cut over by this increment. Do not present the entire product as controlled receipt-to-stock; legacy paths need an explicit cutover/reconciliation plan. No new ordinary issue permission for Hold/Rejected.
4. Technical checklist automation, waivers, preservation and reports remain separate. Do not reopen settled access/three-person/manual-evidence/open-condition decisions as if still pending.

No commit, push, production migration, deployment, current-CI, browser/load or full-regression claim.
