# ADR-040 — read-only source commitment diagnostics

2026-10-19. Implementation of ADR-039's recommended diagnostic step, not a new authority/allocation decision. Existing ADR-036/038/039 choices remain settled.

Use a dedicated GET report anchored to an already-visible source-linked PO, through the caller's original tenant/project RLS and current membership/discipline/procurement/pack access. Never switch to the target buyer to obtain legacy commitments. Original buyer staff may see their existing journals/ledger; existing OWN/PMC/MC PO oversight does not confer buyer-only allocation evidence. Counterparty nomination alone does not delegate generic source review. A named vendor cannot acquire MR contents or another vendor's orders.

Show only visible related same-buyer/project orders, including visible line-only links, in pages of25. Classify DRAFT/no-issuance-marker as non-reserving; non-draft/issuance-marker without visible verified source issue evidence as legacy review only when buyer evidence is actually visible; otherwise classify evidence as unknown. Matching complete active-line allocations are recorded evidence, not a new certificate. Incomplete evidence needs review. Cancelled legacy records are not assumed released.

Display current visible MR quantities separately from recorded allocations. Aggregate visible allocations by actual MR-line UUID AND retained unit using exact decimal SQL; never convert units, sum unlike units, subtract a remaining quantity or infer legacy consumption. Discipline filters can omit commitments; oversight/vendor evidence is explicitly partial. The report is a live scoped observation, not a locked point-in-time clearance or exhaustive reconciliation.

No new schema, permission, write endpoint, reservation, allocation, approval, source mutation, legacy resolution, capacity release or MTO right. Report links appear in existing PO detail; execution workspace links are conditional on the same original generic-read/discipline visibility. Archive review remains read-only. Any actual resolution/cancellation/amendment workflow needs its own explicit controlled semantics.

Implemented and targeted-verified: [delivery guide](PO-SOURCE-REVIEW-EN.md),57 related tests/502 assertions PASS. No full/recovery/production certification claim.
