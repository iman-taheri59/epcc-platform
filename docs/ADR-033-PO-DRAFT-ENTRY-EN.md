> Follow-on 2026-10-19: [approved own-company MR → PO draft copying](PO-MR-DRAFT-LINK-EN.md) is now available under independent PO nomination, with exact source IDs and **no MR consumption/issuance**. Prior verification below belongs to its earlier source; current targeted result is131/1067.

# ADR-033 — human PO draft entry

2026-10-19. User selected independent project PO nomination: owner-company administrator with existing project.configure configures named people or company-scoped roles of participating buyer companies. No default authors; no inheritance from MR/PMS. Nomination permits DRAFT entry only, never issue/approval. Existing MTO authority deferral remains unchanged.

Implement independent append-only policy/version/reason/actor; live membership, discipline, proc/pack entitlement and archive checks. Server chooses project/company/actor/state/number; atomic bounded multi-line draft and policy attribution. Reuse ProcurementService as trusted primitive, not a new human authorization gate by itself. No new role grants.

First surface is manual draft entry (POs without MR are already supported). MR import/linking, final issuance, financial approval, receipt and RFQ are separate; do not mark any MR ORDERED from this draft form. Preserve exact quantities/units, validate vendor membership and same-project subsystem. Counter must be project-wide across companies and transactionally allocated; no company-visible MAX collision.

Verification is targeted entry/permission/rollback/migration/related UI tests under the user's faster-delivery instruction, not a full-suite gate per edit. Companion: ADR-033-PO-DRAFT-ENTRY-FA.md.
