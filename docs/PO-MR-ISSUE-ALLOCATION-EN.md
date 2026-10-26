> **2026-10-19 bounded concurrency follow-up:** [rollback and suspension schedules](PO-ROLLBACK-REVOCATION-EN.md) / [فارسی](PO-ROLLBACK-REVOCATION-FA.md): four additional real-connection cases pass; general crash/revocation/SQL concurrency remains unverified. No authority or runtime changes; prior proof below retained.

> **Follow-on:** the read-only legacy source commitment diagnostic is delivered under [ADR-040 / current guide](PO-SOURCE-REVIEW-EN.md),57/502 PASS. Write-based legacy resolution/release, post-issue changes and remaining release gates are still pending; prior evidence below is historical and unchanged.

# Full-copy MR → priced PO → financial approval → issued allocation

2026-10-19 · [فارسی](PO-MR-ISSUE-ALLOCATION-FA.md) · [accepted ADR-038](ADR-038-MR-PO-PRICING-ALLOCATION-EN.md) · [implementation ADR-039](ADR-039-MR-PO-ISSUE-ALLOCATION-EN.md)

## Delivered now

**Full-copy MR-linked financial approval and three-person issuance with atomic source allocation are implemented and targeted-verified.** This extends, rather than replaces, the manual/unlinked execution delivered under ADR-037. Original-drafter pricing authority and independent per-target-buyer financial/issuer nominees remain as explicitly accepted; no new roles or MTO permissions.

1. A currently nominated PO author copies all lines of an APPROVED, categorized own-buyer MR to DRAFT. Multiple drafts remain possible.
2. The original drafter with current own-buyer draft authority records complete controlled pricing in PO detail. The exact current PO commercial version must have a matching latest pricing revision; raw/trusted prices without that evidence are not sufficient for linked execution.
3. A separate nominated financial reviewer opens the buyer-scoped/category-separated execution workspace. Financial approval retains DRAFT and records the complete PO snapshot plus an opaque current MR source fingerprint. **No source quantity is allocated or reserved.** Multiple drafts may receive financial approval.
4. The independently nominated issuer reviews and issues the exact current PO version/latest financial decision. The source must still be that same approved MR version; the signer must remain eligible and drafter, financial reviewer and issuer must be three actual distinct users. Issuance automatically creates one allocation per active line and transitions to ISSUED in the same transaction.
5. The workspace shows the issued allocation's actual PO/MR line UUIDs, exact quantity/unit and approved source quantity at issue. A duplicate item code is not identity. Superseded PO history is retained but not allocated again.

## Source integrity and capacity

The source must still be a complete exact same-project/buyer/category copy, including original quantities, unit, material identity, subsystem and bilingual commercial descriptions/titles. No partial buying/import, cross-buyer MR import or price-time quantity editing is introduced. Source changes that break the copy require reconciliation rather than silent repair.

The cumulative exact decimal committed quantity for each actual MR-line ID cannot exceed its approved source quantity at issuance. Financial approval is not a capacity hold: two approved drafts can compete, but only one full-copy issue succeeds. A full-copy issue commits all current source capacity; another full copy is refused even if both approvals were previously valid. Allocation evidence is not a stock receipt, usable stock or MTO coverage admission.

MR quantities, signatures, timestamps and state are not changed by approval/issuance; the MR remains APPROVED, not automatically ORDERED. Historical allocations persist if source metadata later evolves. No cancellation-based release, negative allocation, issued amendment or controlled cancellation surface is supplied. The prior issued-commercial freeze remains; forecast and receipt-state progress are separate and do not release capacity.

Unknown prior commitments block linked execution: a non-DRAFT or historically issued order against the same MR (including a line-only source reference without header linkage) must have verifiable new issue evidence, otherwise reconciliation is required. Even an ambiguous cancelled legacy order is not assumed to represent zero consumption. No backfill or legacy-resolution write endpoint is delivered. Legacy primitives remain compatible for unenrolled sources, but header/line guards prevent them issuing new unverified commitments against a source already enrolled in the allocation ledger. They are not human HTTP issuance endpoints.

## Evidence and security

- Forced-RLS append-only `po_mr_allocations`: project/buyer, real PO/MR header and line IDs, issue decision, exact quantity/approved quantity/unit, source fingerprint and issue time. There is no UPDATE/DELETE policy.
- `po_execution_decisions.source_hash` retains a server-generated source-version digest. It covers the current MR header/line records, including approval metadata/timestamps; changing source metadata without changing the PO therefore requires renewed financial approval. This is an opaque integrity digest, not new general MR read delegation or PKI certification. The complete PO snapshot is retained as before.
- Existing manual PO hashes/snapshots are unchanged. Historical financial decisions gain only a nullable source-hash column, with no fabricated source evidence. Populated-upgrade tests preserve existing controlled pricing, source signatures and manual financial history.
- Project PO-entry locking, PO/source header locks and source-line locks enclose issue validation. An issue trigger inserts all allocations, validating identity and exact cumulative quantity. Deferred constraints require complete allocation coverage/current source and the applied ISSUED transition. A partial-ledger failure, header failure or missing ledger aborts the entire transaction.
- **Corrected prior defect:** deferred issue checks can run after HTTP middleware has restored the caller's tenant/project. They now temporarily bind only the immutable decision's buyer/project for validation and restore the prior context on success/error. A competing-EPC issuer is tested for both linked and manual POs. Generic PO detail and MR/warehouse RLS are not widened; financial/issuer grants do not grant pricing or warehouse access.

## Verification

**108 related tests /746 assertions PASS**, 190.431s. Includes 19 new allocation workflow cases, 1 new populated-upgrade case and 1 manual cross-company deferred-commit regression, plus prior pricing, source-copy, detail, financial/issue, upgrade, Procurement and OrderGap cases. Earlier tests describing the previous MR execution block now assert controlled-pricing admission/non-reserving finance; historical evidence files remain untouched.

One targeted **real two-PDO-connection scenario passed** under the non-superuser/NOBYPASSRLS application role: connection A holds an uncommitted issue/allocation; connection B hits PostgreSQL lock timeout55P03, rolls back, then after A commits retries and is refused by the exact source ceiling. Separate fault injection proves second-row allocation rollback, post-allocation header rollback and deferred rejection of omitted allocations even after caller scope is cleared. This is not exhaustive concurrency/revocation/crash certification.

Other coverage: exact source UUIDs with duplicate codes/different units, no MR mutation, no finance reservation, repeated issue, repricing/source-version invalidation, unknown legacy/headerless commitments, enrolled-source legacy bypass refusal, append-only scope, successor-only allocation, current authority regressions and EN/FA workspace. Final lint/Pint/diff and source hashes: `docs/evidence/2026-10-19-po-mr-issue-allocation/summary.json`; runtime log `related.log`.

No routine full-suite/recovery run, production migration/deployment, browser acceptance, current CI, commit or push claim. The private UTF8 PostgreSQL test server uses a Unix socket only and is stopped after verification. Existing DocumentRegister numbering defect, G01–G07 physical receipt/admission gates and MTO authority deferrals remain. In particular, legacy warehouse float/mixed-unit aggregate state calculation is not certified by this source-allocation increment.

## Recommended next steps

1. A read-only legacy commitment reconciliation report using existing scoped visibility; any write-based resolution or capacity release needs explicit controlled semantics, not guessed quantities.
2. Align legacy OrderGap line-first locking with project-first execution locks; add issue-versus-amend, rollback-release and authority-revocation races. The two-issuer test above does not close these release gates.
3. Design controlled post-issue amendment/cancellation/reapproval and exact physical receipt attribution separately. Do not weaken frozen issued contents or infer stock receipt from an allocation.
