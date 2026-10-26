# Controlled custody source / heat amendments

2026-10-23 · **IMPLEMENTED / TARGETED VERIFIED / NOT DEPLOYED**
[فارسی](PIPING-CUSTODY-AMENDMENTS-FA.md) · [QA](PIPING-QA-EN.md) · [Receiving QC](PIPING-RECEIVING-QC-EN.md) · [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [Evidence](evidence/2026-10-23-piping-custody-amendments/README.md)

## Explicit approval and scope

After the QA delivery, the user selected **`custody_correction_authority=original_preparer_live_grant`**. This authorizes an append-only, reasoned source/Heat amendment by the actual original MRR preparer with a current `custody_mrr` nomination. It does not authorize any other nominee to take over that receipt, create a new role or delegate source/document access. The alternative “any current MRR nominee” was not selected.

This executes the earlier QA guide's recommendation for controlled source/heat corrections. Its statements that those corrections are only proposed are now historical. Previously settled access, three-person separation, manual version-bound QA evidence and open-condition blocking remain unchanged. Structured ITP prerequisite design/implementation remains deferred.

**Arrival record stays byte-for-byte unchanged.** Quantity, unit, receipt time, description, shipment/package, location, arrival-document status, original preparer/company/custodian and original source snapshot are not amended by this screen. The original custody fingerprint remains the hash of the original arrival, not a misleading hash of an overlaid record. The new immutable amendment UUID binds the corrected basis separately in QC and QA.

## Using the screen

From physical custody/MRR detail, open **Custody source / heat amendments**:
`/projects/{projectCode}/materials/piping-custody/{receiptId}/amendments?lang=en|fa`

- The original preparer must still belong to the recorded original company and hold a live independent `custody_mrr` nomination. Current eligibility, company/participant/custodian, plan/pack/project/discipline and archive rules are freshly resolved. Another current MRR nominee, owner/configurator, QC, QA or warehouse nominee cannot take over the correction. No impersonation/tenant switch.
- Each amendment supplies a **complete replacement working basis**: exact source line UUID or an explicit unresolved-source reason, the current Heat/Batch value (or unknown), and a nonblank reason/supporting basis for correction. For a heat-only amendment retain the exact current source; do not accidentally clear it. Linking requires clearing the unresolved-source reason. Clearing an incorrect source/Heat is possible, but does not release the receipt.
- Copy a source line UUID from the independently readable same-unit PIPING catalog (50 per page). Other pages open separately so the reviewed form is preserved. No prices are exposed. A draft source may be identified for reconciliation; it still cannot pass final QA until eligible and issued.
- Exact unit matching is case-sensitive; no conversion, normalization between different unit codes, tolerance or quantity editing is inferred. If the recorded arrival unit/quantity itself is wrong, this feature cannot repair it. Keep the receipt non-releasing pending a separately approved mechanism.
- A current effective-basis panel appears separately from the original arrival on QC/QA. Amendment history paginates20, with a global latest head. Reasons are escaped, server timestamps display UTC, and the GET is private/no-store. No formal correction/certificate PDF numbering policy is introduced.

## Version binding and three-person chain

1. An amendment replaces only the working source/Heat basis and retains earlier amendments/arrival facts.
2. Receiving QC must append a **new review on the exact current amendment UUID**, using the latest previous QC head. All QC results, including INCOMPLETE, are explicitly bound. The preparer cannot be the QC reviewer; the original preparer remains the amendment actor.
3. QA cannot proceed on the old QC. It requires the exact current amendment and latest QC bound to that same amendment; the QA actor remains distinct from the actual original MRR preparer and the actual QC reviewer. A new amendment makes earlier QC/QA non-current, even if the older QA result was ACCEPTED.
4. Final acceptance still requires all six manual QA confirmations, exact readable frozen requirement/evidence file versions, no open conditions, clean current QC, eligible issued matching source/quantity/unit and traceable Heat. Correction is **not** itself evidence of compliance or a waiver. Technical sufficiency remains the explicit human QA assessment; no automatic checklist or certificate authenticity determination.
5. A further source/Heat amendment requires another fresh QC/QA cycle. Old acceptance is never copied, reactivated or transferred to the new basis.

Old events retain `amendment_id=NULL`, meaning ORIGINAL, not “whatever is latest now”. Missing/null amendment input is backward-compatible only for the original basis; once a correction exists it is stale and refused. Existing null-basis QA request hashes retain their legacy canonical structure so genuine historical retries are not silently invalidated by the new field. A successful historical retry acknowledges the original event; it never makes it current again.

## Original-source privacy and no fallback

The original arrival scope is unchanged: current eligible four-responsibility nominees plus independently visible original PO/line for an originally linked receipt. An amendment never unlocks a hidden original PO.

A corrected source must also be independently readable in the original caller context. Core amendment metadata contains no auto-copied PO/line identifiers, descriptions or source snapshot. The immutable source snapshot is in a separate FORCE-RLS child, readable only through the original PO/line scope. If the **latest** amendment points to a source hidden from a reader, the effective-basis resolver returns unavailable. It must not select an older readable amendment, treat the hidden source as unlinked, or silently fall back to the original arrival. QC/QA forms are disabled and new writes are refused; authorized arrival/history metadata can still be read.

For accepted QA decisions on an amended basis, the exact non-price current PO basis is also stored in a separate FORCE-RLS table, not copied into the generally receipt-readable QA header. It is shown in history only when the original source is readable now. The unamended historical QA storage model is unchanged because the original parent already requires that original PO visibility. Version IDs and user-written reasons are audit metadata, not a grant to automatically disclose source contents. No new CDE/document permissions or broad procurement SELECT policy.

Snapshots preserve exact source identifiers, non-price technical line identity, source quantity/unit and current state/issue timestamp at capture. Amendment source captures its registration basis; QA separately captures its current issued basis. A normal PO state/issue-time advance can occur without changing the frozen technical line identity. Physical identity/traceability matching still needs fresh QC and explicit QA confirmation.

## Integrity, retries and migration

Forward-only `2026_10_23_110000_add_piping_custody_amendments.php` creates three empty tables: amendment headers, original-source-scoped amendment snapshots, and original-source-scoped amended QA source bases. It adds nullable amendment FKs to QC/QA and materializes updated invoker guards. There is no automatic amendment, new QC/QA, accepted credit, migration of old physical facts or legacy cutover.

All three tables FORCE RLS with SELECT/INSERT only; immutable guards disallow rewriting or deleting history. An amendment's original preparer, live policy, original parent, company and current head are rechecked after the shared receipt advisory/project lock. Server stamps sequence/name/time/fingerprint. Linked amendments cannot commit without their source child; the child is stamped by SQL, uses the exact original unit and can only be added within the original header transaction. Amended accepted QA source bases are automatically stamped in the QA transaction. Late failures roll back whole events and snapshots. There is no SECURITY DEFINER, new custody UPDATE policy or mutable-parent FOR SHARE workaround.

A new amendment requires explicit present-nullable previous amendment UUID, current policy UUID and request UUID. The original caller/current policy/current parent/source scope plus the same canonical payload returns one historical amendment on an exact retry. Changed payload/caller/target, hidden collisions or stale heads are refused. A fresh key is a new event, not semantic deduplication. A reasoned reassertion of the same values is still a new version and requires fresh review.

The reviewed head and original-basis null are preserved in failed forms scoped to **both** project and receipt. Blank/null source and Heat fields must stay blank on recovery; they are not silently restored from current data. Tests caught and prevent the potential “empty old basis silently rebases to the latest correction” pattern. The trusted application DB-role/GUC boundary is retained, not independent per-human SQL credentials or hostile-DDL/filesystem protection.

## Focused verification

**69 tests /729 assertions PASS,183.96s, zero skipped** (`final-focused.log`): amendment14 + existing QA19 + receiving-QC16 + physical custody20. These are affected tests, not the entire project suite. Earlier runs overlap and are not summed.

Coverage includes original preparer/live grant vs replacement nominees; no arrival/stock/commercial mutation; exact retry/conflict/CAS; source/heat correction to fresh QC/accepted QA and later invalidation; both actual-person separation edges; unit/source/unknown/forged-field refusal; archived/revoked read/write; server stamping and raw SQL denial; linked-child completeness and late-child/late-failure rollback; original-source privacy for both correction and QA snapshots; hidden-latest no-fallback; observed real concurrent duplicate/stale/revoked writes and corrections during QC/QA lock waits; escaped bilingual private HTML; scoped empty-basis recovery; populated archived forward install without backfill.

Development failures retained: an unused Schema import was removed by formatting before its later test was added, and a forward-install fixture initially left a new QA capture trigger attached while simulating the old schema. Both fixture problems were corrected; no business authority was weakened. Existing forward-install fixtures now explicitly reinstall the dependent QC/QA/amendment schema chain rather than assume dropped tables retain later-added columns/FKs. Final affected run is clean. Static formatting/lint16, compiled Blade/routes, original-caller DB role/FORCE-RLS/invoker/UTF8/socket audit, current source hashes, links and round-trip-verified source archive are recorded. EN/FA HTML is an actual synthetic HTTP response, not a browser screenshot. The private test database is stopped at handoff.

## Still outside this increment / recommendations

- **Next recommended:** controlled, idempotent GRN with aggregate source quantity/previous-posting checks and live nomination/source/evidence/QC/QA/amendment validation under appropriate locks. The QA per-receipt ceiling is not aggregate PO availability or an accepted reservation.
- Existing legacy WarehouseService is not cut over or wrapped here. No accepted stock, material movement, ordinary Hold/Rejected issue, return/disposition, waiver or preservation workflow is created.
- Before future stock posting, define how a later amendment/QC/Hold blocks or compensates an already posted receipt. Do not use this pre-GRN correction workflow unchanged to invalidate or silently undo posted stock. This is a future material-control decision, not currently activated stock reversal authority.
- Correction of quantity/unit/arrival facts, reassignment when the original preparer leaves, document-type/checklist automation, formal reports and legacy reconciliation remain separate. Current limits are intentional; do not silently let another nominee take over.

No commit/push/deployment/production migration/current CI, full regression or browser/load claim.
