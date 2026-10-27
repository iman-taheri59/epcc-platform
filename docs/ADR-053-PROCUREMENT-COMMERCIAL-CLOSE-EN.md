# ADR-053 — Procurement back end: PO change orders, invoicing, three-way match, payment certificates

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). Completes the money end of the procurement cycle. `PaymentCertificate` already exists as a model but lives only inside the 6-gate demo pipeline (`GateService`), not as a commercial process.

## Context

Existing assets: versioned PO execution with financial/issue authority (ADR-036/037), BigDecimal pricing allocation per MR line (ADR-038), controlled GRN with accepted-stock history (ADR-046 + forward-only migration), QC/QA acceptance decisions, custody with heat identity. Missing: changing an issued PO (quantity/price/scope/delivery), vendor invoices, matching invoice↔PO↔receipt, and payment certification as a real workflow. In EPCC practice this is where disputes live — the platform's digest/append-only discipline is exactly the right instrument.

## Decision points (explicit owner choices required)

1. `change_order_model=po_version_amendment | separate_change_order_entities | both_kinds_quantity_price_scope`
   Recommended: **po_version_amendment** — the versioned-execution machinery (ADR-037) already versions POs; a change order is a *reason-coded, authority-routed transition* between versions (never a silent re-issue), with before/after diff snapshots like draft-history in notifications (ADR-042 pattern).
2. `invoice_entry=vendor_portal_self_entry | buyer_entry_from_documents | both_with_provenance`
   Recommended: **both_with_provenance** (ADR-031 two-mode precedent again): vendor-entered via portal (ADR-058) or buyer-registered from paper/email, each marked with its provenance; external invoices are never described as platform-issued.
3. `three_way_match=automatic_tolerant | automatic_strict | manual_with_prefill`
   Recommended: **automatic_tolerant** — match invoice lines against PO version prices (BigDecimal exactness exists) and accepted GRN quantities within project-configured tolerances (price ±x%, quantity −0/+y%); out-of-tolerance lines become **held** with reason codes and route to a human decision (ADR-049 approval chain once available). No auto-blocking of compliant invoices.
4. `payment_certificate=platform_certifies | platform_prepares_owner_signs_externally | both_modes`
   Recommended: **both_modes** — the platform can certify within its authority (owner-company finance roles) or prepare a certificate package for external signature (registered afterwards per ADR-031 EXTERNAL_REGISTER semantics). Retention/advance/set-off rules are project-configured percentages, append-only changes.
5. `currency_and_tax=multi_currency_with_rate_snapshots | single_currency`
   Recommended: **multi_currency_with_rate_snapshots** — POs already carry `currency`; rates are dated snapshots (append-only) so any recomputation is reproducible; tax (VAT) lines are explicit, never folded into unit prices.

## Sketch of the decision

- Tables (RLS + FORCE): `po_change_orders` (version-pair bound, reason codes, authority chain refs, diff snapshot digest), `vendor_invoices` + `invoice_lines` (provenance mode, PO-line/GRN refs), `match_results` (per line: matched/tolerant/held/rejected with inputs frozen at match time), `payment_certificates` (real process: period, matched invoices, retention, advance recovery, net, approval chain, optional external-signature register).
- Match determinism: a match result freezes the PO version, GRN set and rate snapshot it consumed (digest-bound) — re-running after new receipts produces a NEW result, never mutates the old (house append-only epistemics).
- The existing demo `PaymentCertificate` migrates to the real entity or the demo is retired (decision inside cutover notes).

## Consequences

- Procurement becomes end-to-end measurable: committed (PO) → received (GRN) → invoiced → matched → certified → paid; feeds ADR-054 cost actuals directly.
- Dispute-resistance: every number is reproducible from frozen inputs.
- Test surface: tolerance boundaries, held-line routing, match immutability under concurrent GRN posting, certificate arithmetic (retention/advance) in BigDecimal, provenance honesty, RLS across vendor/buyer/owner.

## Out of scope

General ledger/treasury integration, bank payment execution, subcontractor progress billing outside POs (construction contracts — future ADR with ADR-055), claims/dispute case management (candidate later).
