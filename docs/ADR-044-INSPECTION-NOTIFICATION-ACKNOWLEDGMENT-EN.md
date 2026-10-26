> **Approved PDF continuation — 2026-10-20:** [current prerequisite appendix](INSPECTION-PREREQUISITE-PDF-EN.md) / [فارسی](INSPECTION-PREREQUISITE-PDF-FA.md). Selected prerequisite_pdf implemented: all records/full history/retained stage, separate from issuance snapshot; same scope, no new schema/authority/writes.409 on changes during render.20/1102 + separate4/60 PASS; not full-suite/current-CI/deployed. Only prerequisite-PDF deferral and contradictory historical prose below are superseded; other decisions retained.

# ADR-044 — Company acknowledgment of issue and cancellation

> **Prerequisite addendum — 2026-10-20:** [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) / [guide](INSPECTION-NOTIFICATION-PREREQUISITES-EN.md): explicitly approved company-reader management, sender current-issuer report, declaring-recipient confirmation/rejection, all original readers see versioned history. No automatic gate or parent/PDF/private-history change. Only prerequisite deferral superseded; earlier choices retained.81/1290 + separate upgrade1/5 PASS, not full-suite/deployed.

2026-10-20 · **ACCEPTED / IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md) · [Implementation and exact evidence](INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) · [ADR-042](ADR-042-ITP-INSPECTION-NOTIFICATIONS-EN.md) · [ADR-043](ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-EN.md)

## Approved decisions

User chose `next_scope=recipient_acknowledgment`; `ack_authority=any_authorized_company_reader`; `ack_target=issue_and_cancellation_separate`; `ack_visibility=all_authorized_notice_readers`; `ack_pdf_scope=detail_and_current_pdf`.

Any current selected-recipient notice reader may explicitly record receipt **for that company**, not only themselves and without separate nomination/manager/issuer/doc role. This reader-to-write authority is expressly approved only for this action. First receipt per notice/company/event is append-only and unique. Not a page-open/download tracker or observed delivery. No edit/retraction/backdate/note. Actor/company names/time captured by server, no client attribution/authority selector.

ISSUE receipt only before cancellation; CANCELLATION receipt references the exact cancellation event. Old issue receipt retained, neither implies the other; cancellation can be acknowledged without prior issue receipt. No global ACKNOWLEDGED state and no parent state/version/body/snapshot/recipient/number change. Sender cannot act for another company; selected sender can act only for itself. Current active/project membership/participation/discipline/QAQC/pack gates; project archive blocks new receipt, source archive does not invent access or invalidate receiving old evidence. No doc module/read/issue privilege required or delegated for recipient receipt.

All authorized original readers see captured company/person/time for all receipts. This broader cross-recipient disclosure was explicitly chosen; no private draft history, contact directory or source/commercial reads. Detail/current PDF include records; PDF rechecks current reader, cancellation and ordered immutable receipt IDs after rendering,409/retry if changed. Old downloaded files cannot be recalled. Runtime app/SQL integrity is not a cryptographic/legal receipt/signature or privileged-DDL protection.

## Alternatives / scope

Separate nominated representatives, personal-only model, sender+own-recipient-only visibility and detail-only export offered but not selected. Do not re-ask or silently substitute them. Existing five ADR-042 choices, ADR-043 cancel-only and ADR-041/MTO boundaries retained. Only bounded acknowledgment deferral superseded. Replacement/reissue/reschedule/undo, external transport/outbox, signature, attendance, technical acceptance/result/hold/release/proceed remain deferred; no generic read-to-write grant.

Forward-only migration140000, unchanged parent RLS and old migrations, no default roles or synthetic old events. Common project lock + live SQL eligibility and immutable unique event; recipient does not need sender-row UPDATE rights. Tests: final61/1040 + separate forward-upgrade1/5 PASS, overlapping final bilingual PDF1/22 PASS; no full-suite/current-CI/production claim. See guide for actual coverage, original fixture error/correction, trust boundary and future policy/contract recommendations. No commit/push/deployment.
