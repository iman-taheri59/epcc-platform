> **2026-10-19 integrity repair:** [PO-GUARD-ISOLATION-EN](PO-GUARD-ISOLATION-EN.md) / [فارسی](PO-GUARD-ISOLATION-FA.md). Migration180000 fixes the confirmed hidden-journal bypass for non-buyer-visible PO content, without changing journal read policies or business authority. Original guard definitions are superseded only by this technical repair.

# ADR-037 — first version-bound PO financial/issue surface

2026-10-19. Implements accepted ADR-036: three distinct drafter/financial/issuer people and independent per-target-buyer nominations, including explicitly nominated counterparties. No re-opening of those decisions.

First operational scope is human-attributed, fully priced, categorized **manual/unlinked** PO drafts. Linked-MR execution stays blocked pending controlled pricing and official source-quantity allocation; no implicit consumption, remaining quantity or duplicate-source-order rule is invented. Legacy unattributed POs are not retro-certified.

Financial approval is an append-only journal decision while the PO remains DRAFT, not ordered coverage. A server-canonical versioned JSON snapshot of the complete commercial header and all line/history rows (excluding workflow timestamps/state and expediting forecast) is retained with SHA-256, actor, current buyer policy, reason and server timestamp. Issuance requires the exact current fingerprint, the expected latest financial decision, the same current nomination-policy version, a still-active/currently nominated financial signer and an issuer distinct from both signer and drafter. Policy changes require renewed financial approval. Header totals must match exact decimal rounded active-line totals; no partial/unknown prices, conversion or silent total repair.

Delegated reading uses a dedicated execution workspace and buyer context only after fresh scoped nomination, membership, discipline and module checks. Existing PO detail/RLS remains unchanged; grants do not unlock warehouse, MR, MTO or other buyer data. Both action and scoped-read context are restored, including exception paths. Owner configuration alone does not authorize signing.

New journal INSERT guards validate eligibility, snapshot, nomination and three-person separation. Human-attributed DRAFT→ISSUED cannot use the legacy primitive without a matching issue decision. Issue journal and PO transition commit atomically; controlled issued commercial content is frozen while forecast and receipt progress retain their separate meanings. Legacy issued amendments remain legacy behavior; controlled post-issue amendments/cancellation/re-approval need a separate surface. Journal records cannot be rewritten/deleted. No claims of universal SQL per-user RBAC, ERP budget enforcement or production certification.

Targeted authority/stale-content/SoD/transaction/SQL/upgrade/scoped-read/UI regressions, not routine full-suite/recovery. Real multi-connection contention and release verification remain separate milestone gates.


Implementation status: bounded surface targeted-verified; current results, limits and remaining gates are in [the delivery guide](PO-VERSIONED-EXECUTION-EN.md). No permanent manual-only business rule is introduced.
