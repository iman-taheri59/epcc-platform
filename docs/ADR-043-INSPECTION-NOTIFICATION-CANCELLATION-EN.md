# ADR-043 — Independent cancellation of issued inspection notifications

> **Prerequisite addendum — 2026-10-20:** [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) / [guide](INSPECTION-NOTIFICATION-PREREQUISITES-EN.md): explicitly approved company-reader management, sender current-issuer report, declaring-recipient confirmation/rejection, all original readers see versioned history. No automatic gate or parent/PDF/private-history change. Only prerequisite deferral superseded; earlier choices retained.81/1290 + separate upgrade1/5 PASS, not full-suite/deployed.

> **Current acknowledgment addendum — 2026-10-20:** [ADR-044](ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) / [guide](INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md). Explicit user choice: any authorized selected-recipient reader represents their company; separate original-before-cancel and exact cancellation receipts, first/company/event immutable; all original readers see company/person/time in detail/current PDF. No automatic read tracker, doc/issue/stock/result authority or private-history disclosure. Final61/1040 + separate upgrade1/5 PASS; overlapping final PDF1/22 PASS. Only acknowledgment deferral superseded; cancel-only/replacement/transport/signature boundaries preserved. Not full suite/current CI/production.

Status: **ACCEPTED / IMPLEMENTED / TARGETED VERIFIED; NOT DEPLOYED** ·2026-10-20
[فارسی](ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-FA.md) · [Implementation/verification](INSPECTION-NOTIFICATION-CANCELLATION-EN.md) · [Prior ADR-042](ADR-042-ITP-INSPECTION-NOTIFICATIONS-EN.md)

## Decision record

User selected `next_scope=issued_lifecycle`, then explicitly `lifecycle_authority=current_issue_grant`, `lifecycle_transitions=cancel_only`, `lifecycle_visibility=status_reason_scoped_replacement`. These are settled; do not infer separate automatic roles or implement replacement from the visibility option's name.

Use current explicit own-company issue nomination for independent terminal cancellation, with mandatory reason and captured actor/name/time/latest-setting evidence. Do not restrict to original issuer or add a separate default nomination. Retain original body, number, issued version, snapshot and recipients unchanged, adding exactly one append-only forced-RLS event. Effective current status CANCELLED is computed; raw ISSUED records remain historical issuance evidence. No undo or DRAFT rollback. New PDF exports carry current event; concurrent cancellation during rendering requires409/retry.

Authorized original readers, including selected recipients with current scope/entitlement, see status/reason/actor/time. Do not grant private draft history, source/PO read or cross-company writes. Latest main-contractor grant, current project/discipline/participation/module/pack/active checks remain; project archive blocks writes. Withdrawing a retained notice does not require its old source/recipient to qualify for a new issue. Legacy times remain unknown intervals.

## Alternatives and consequences

Original-issuer-only and separate lifecycle nomination were offered, not selected. Atomic replacement and combined cancel+replace were offered, not selected. Status-only external visibility was offered, not selected. Approved visibility for future replacement would still require independent access to that replacement; none is implemented here. Existing five ADR-042 choices and ADR-041/MTO boundaries unchanged. Only previous **cancellation** deferral superseded; replacement/reschedule/reissue/acknowledgment/transport/signatures/result cancellation remain deferred.

Reason is intentionally disclosed to authorized recipients: warn users before submission. Downloaded copies cannot be recalled. Raw-state integrations must account for the event; no external API contract added. SQL/app guards are trusted runtime integrity, not privileged-DDL or cryptographic signature proof. Forward migration130000, no backfill/default grants; populated archived forward upgrade tested. See linked guide/evidence for exact59/884 +1/5 final commands and overlapping final1/22 PDF recheck; no90-case/full-suite/production pass claim.
