# Controlled piping GRN and accepted-stock credit

Date: 2026-10-25. **Implemented and targeted-tested in the workspace; not deployed, committed or pushed.** This increment follows the user's authorization to implement accepted QA → independently authorized warehouse GRN → atomic stock posting. [فارسی](PIPING-GRN-FA.md).

## What is closed, implemented, and still gated

- **Previously accepted, retained:** physical custody is a fact even with missing documents/failed inspection; explicit owner-configured four-duty nominations; independent original-caller source reading; distinct actual MRR preparer, receiving QC reviewer and final QA acceptor. GRN may overlap another person only with a separate current `post_grn` nomination. No default warehouse/PO/inspection/QA inheritance.
- **Implemented here:** a whole accepted physical receipt gets one immutable GRN and one positive, exact-decimal credit in a **separate controlled accepted-stock ledger**. No conversion to `material_lots`; no inherited usable-stock certification of legacy data.
- **Implementation limits, not new blanket workflow approval:** no partial receipt posting/split, tolerance, unit conversion, debit, issue, return, automatic reversal, waiver or reconciliation. Any legacy lot on the actual source PO blocks a new GRN pending a separately governed reconciliation. Cancellation/replacement or evidence loss never silently rewrites a posted credit.
- **Still gated:** controlled issue/consumption/disposition, preservation obligations/actions, reviewed correction/reversal and legacy reconciliation/cutover. Permanent technical-scope evidence composition/admission and DA-03 demand writers remain unchanged and inactive. GRN is not MTO acceptance, a reservation, engineering admission, payment approval or permission to consume.

Earlier guides saying “GRN/stock not implemented” are superseded **only for this bounded separate ledger**. The legacy stock estate has not been reconciled or fully migrated.

## Operator path

1. Retain the physical arrival/MRR. Resolve source/Heat through the original-preparer/current custody grant amendment workflow if necessary. Quantity, unit and physical arrival are not silently edited.
2. Obtain distinct receiving QC and manual final QA on the exact effective basis. Only the latest `ACCEPTED` QA, exact latest QC with `NO_NONCONFORMITY_OBSERVED`, six affirmative declarations, no open conditions and known Heat can support GRN. An amendment requires fresh matching QC/QA; the old approval is not carried forward.
3. Open **Controlled piping GRN / accepted-stock credit** from the physical receipt. English/Persian pages retain original actor/company. The form pins the current warehouse policy and exact QA ID/hash. Enter accepted location and reason and explicitly confirm the whole receipt quantity/location.
4. Submit once. An identical request retry under current warehouse authority returns the same record, not another credit. A changed intent under the same request or a different request for the same receipt is rejected. Historical retry is not a fresh evidence certification; it adds nothing. Source access/current grant/archive gates still apply.
5. A successful record displays immutable GRN metadata and its separate accepted credit. It does **not** update the old PO aggregate state or appear as legacy issueable stock. An unavailable/hidden credit is shown as unavailable, never inferred as zero or a new available balance.

Routes under the existing authenticated project surface:
`GET/POST /projects/{projectCode}/materials/piping-custody/{receiptId}/grn?lang=en|fa`.
GET/HEAD is private/no-store; POST uses existing web authentication/CSRF. No new user nomination is installed by the migration.

## Posting gates and evidence discipline

`PipingGrnService` uses `PipingCustodyService::scoped`: original actor, original company and project GUC, transaction and common receipt advisory/project lock. No source-owner or custodian impersonation. Recorder company can differ from configured custodian; the current configured custodian must still equal the arrival's custodian.

Before byte access, current independent warehouse nomination, visible receipt/effective linked source and all independently authorized evidence are required. The service reconstructs the **original canonical QA input** from stored decision metadata, fixed declaration order and complete sorted visible REQUIREMENT/EVIDENCE UUID lists. Its SHA-256 must equal the immutable QA input commitment. A partly hidden citation set cannot become a smaller positive inventory, even if each purpose still has a visible file. Both purpose sets must be nonempty.

Every unique citation is checked against current project/file/document/revision RLS and actual-discipline `doc.view` authorization using a freshly loaded actor/plan. IDs, names, revision/ordinal/number/suitability and stored hash/size must match the assessed snapshot. PUBLISHED or ARCHIVED is only an eligible retained version, **not automatic engineering sufficiency or permanent retention**. All citation authorization precedes any file read. Actual retained bytes are hash/size verified before insert and checked again before return. Failure rolls back all posting effects. Noncanonical historical QA inputs (for example unusual UUID spelling that cannot be reconstructed exactly) fail closed; obtain a fresh correctly pinned QA decision, never weaken the commitment.

The invoker SQL trigger independently validates nomination/GUC/writable project, exact current QA/QC/amendment/physical fingerprint, three-person separation, six declarations, no conditions, effective Heat/source and current same-unit issued-source snapshot. The transient canonical QA payload must match both hash and exact metadata/visible role lists. It is erased before storage, so GRN readers do not inherit certificate UUID lists. SQL also validates citation metadata/discipline; **raw SQL alone does not hash filesystem bytes or enforce the application's full `doc.view` pipeline**. The supported positive writer is the service/HTTP adapter, not a client SQL interface.

These sequential checks do not establish an ABA-free revocation lease or filesystem retention lock. Trusted database/filesystem administrators and arbitrary DDL changes are outside the ordinary-caller boundary. Future issue authorization must independently define and recheck its own validity/availability obligations.

## Atomic ledger and completeness

- `piping_grn_records`: unique receipt and unique project/request; server-derived whole receipt quantity/unit/source/Heat/custodian, exact QA/QC/amendment references, recorder identity, location/reason and creation time. UUID-based GRN number avoids hidden `MAX` numbering. Immutable.
- `piping_accepted_stock_entries`: one unique positive credit per GRN, server-derived only through the GRN AFTER trigger; no direct credit/update/delete or debit writer.
- `piping_grn_posted_index`: derived minimal project/receipt/GRN marker. Its read scope follows the already-visible physical receipt, independently of visibility of an amended PO. Thus hiding the amended source cannot make an already-posted receipt editable. It does not grant source metadata, file identity or bytes.
- A deferred completion constraint, explicitly flushed inside the service actor context, requires matching credit and posted marker. Any failure/rollback removes all three. Project-level serialization protects fresh head checks and cumulative arithmetic. The SQL guard takes the same lock for supported raw guard tests too.
- For the **exact project/source PO line**, sum all existing controlled GRN quantities plus this whole receipt and reject above the ordered decimal quantity. No floating-point aggregate, cross-unit total, line substitution, second reservation debit or hidden historical-row undercount.
- GRN read scope is current four-duty nomination plus independent **actual posted source PO/line** visibility, not the original recorder company/original pre-amendment PO. This allows a source-visible nominee to see the complete same-line GRN sum even when an earlier receipt's original source differs.
- Before freezing metadata digests the migration parses/checks the accepted legacy `material_lots` participation/archive SELECT policy bodies, roles, commands and permissiveness, and the uniform parent-bound QA/QC/amendment read policies. Unknown extra or changed baseline policy aborts installation. Subsequent SELECT/ALL policy changes on legacy inventory, GRN, credit, posted index and QA/QC/amendment heads fail new posting closed, not silently “rebaseline as safe”. This is a bounded known-policy contract, not arbitrary SQL-policy equivalence proof. Approved future policy migrations need explicit completeness review/rebaseline.

## Legacy and post-posting cutoffs

Known PIPING-linked legacy lots and movements cannot be inserted, changed or deleted through the old operational stock path. Hidden linked PO classification also fails closed; unknown/unlinked legacy lots are not thereby certified as outside piping. The old receive service gives a controlled validation failure for recognized PIPING POs. Existing non-PIPING behavior is retained.

Existing legacy lots on the actual source **PO**, even a different/unknown line, block GRN rather than infer receipt allocation or reconcile a historic balance. Gross controlled credit has no inferred releases or reversals. Legacy balances and controlled credits must not be added into a purported certified availability total.

After GRN, the old QC, QA and source/Heat amendment writers are closed by both application validation/dashboard gating and SQL INSERT cutoffs. A reasoned independently governed correction/reversal must be designed before changing that basis. The minimal posted marker keeps this boundary effective even if amended source details subsequently become hidden. No source or certificate reading is inherited from that marker.

## Verification and delivery

Final affected run: **133 tests / 1,066 assertions PASS, 443.78 s**, including **21 new GRN tests** and the existing QA, receiving QC, amendments, custody, receipt-access and warehouse suites. Evidence: `docs/evidence/2026-10-25-piping-grn/final-affected.log`. Includes bilingual HTTP success/escaping/no-store, whole credit/retry, raw SQL derived fields/cutoffs, post-amendment fresh acceptance, hidden citation inventory/source, metadata-policy drift, byte tampering, independent doc.view loss, separate recorder/custodian, cumulative exact 5.000/0.001 ceiling, rollback and two-connection lock/overdelivery checks. Earlier exploratory failures were corrected; earlier runs overlap and are not additive. Ordinary test role has neither superuser nor BYPASSRLS; all three new tables use ENABLE/FORCE RLS.

No full regression, interactive browser, customer data migration, operational acceptance test, current CI or deployment is claimed. Migration is **forward-only**; backup/reconcile typed legacy PIPING sources and validate policy baseline before any planned rollout. Do not disable cutoffs or edit migration history to import old stock.

### Recommended next steps — proposed, not implemented/approved by this delivery

1. Define the independent authority/evidence and reversal/reconciliation matrix for posted corrections and legacy balances; no automatic PO cancellation release or guessed legacy line allocation.
2. Define controlled issue/consumption, Hold/Rejected disposition and preservation tracking before treating this credit as consumable. A credit is a historical accepted receipt, not perpetual usable-stock certification.
3. Plan a real operational acceptance exercise with nominated personnel, retained evidence, cutover inventory and backup/restore checks. Review unit-entry/correction ergonomics without introducing unapproved conversions or mutating physical facts.

Final presentation-only controller formatting was followed by the bilingual HTTP case again: **1 PASS / 13 assertions / 5.22 s**, overlapping the 133-test affected run, not additive (`final-http-after-format.log`). Selected ten-file Pint check, PHP syntax and whitespace checks pass.
