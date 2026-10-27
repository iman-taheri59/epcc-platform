# ADR-050 — Notification transport (email/SMS) with an append-only delivery ledger

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). Addresses finding 3.5 of the process assessment: the multi-company processes (ITP notifications, acknowledgments, receipt decisions, approvals) currently live **only inside the system** — there is not a single Mailable/email/SMS call in the codebase.

## Context

ADR-042…045 built the notification register with explicit deferrals: "no transport/signature/approval". The company acknowledgment design assumes readers *come to the system*. In EPCC practice, an inspection notification that does not reach the subcontractor by email/SMS is operationally dead, and approval requests without reminders stall. Meanwhile the platform already has everything transport needs: immutable issued notices with bilingual PDFs (Chromium+Vazirmatn renderer), recipient rosters (notice readers, company acknowledgers), and the honesty discipline a delivery ledger must keep (an email is *delivery evidence*, never *attendance confirmation* — the same boundary the PDF copies already state).

## Decision points (explicit owner choices required)

1. `transport_channels=email_first | email_and_sms | pluggable_provider_gateway`
   Recommended: **email_first** with a provider-agnostic sender interface (SMTP now; SMS/push as later drivers). Laravel Mail with a queued driver; `MAIL_MAILER` stays `array` in tests.
2. `delivery_record=append_only_ledger_with_digests | simple_sent_flag`
   Recommended: **append_only_ledger** — `notification_deliveries`: channel, recipient identity, rendered-content digest, provider message id, accepted/rejected/bounced/complained events (webhook-fed where the provider supports it), ordinal clock. An email's meaning is bounded: *transmitted copy of record X at time T to address A* — never receipt, never attendance.
3. `trigger_set=issue_cancel_ack_approvals | issue_only | everything_including_reminders`
   Recommended: **issue_cancel_ack_approvals** first (the four flows with rosters already modelled), reminders/aging after ADR-049 deadlines.
4. `privacy_boundary=no_business_content_in_body | summary_and_link | full_pdf_attached`
   Recommended: **summary_and_link + optional PDF attachment per project policy** — bodies carry number/title/time/place and a signed link; cross-company content boundaries (RLS) are enforced on click, not relaxed by the email. Attachments only where the project enables them (some owners forbid emailing ITP detail).
5. `unsubscribe_and_addresses=platform_managed_recipient_directory | free_text_addresses`
   Recommended: **platform_managed** — transport addresses belong to registered identities/company contacts (verified, append-only changes); no free-text recipients, matching the identity discipline.

## Sketch of the decision

- `NotificationTransportService` renders from the **same immutable issued snapshot** the PDF uses (no re-read of mutable state at send time), queues per recipient, records ledger entries on accept/reject, consumes provider webhooks for bounce/complaint (webhook endpoints CSRF-exempt with signature verification, hash_equals per house style).
- Bilingual templates (EN/FA) per recipient preference; Jalali+Gregorian dates in bodies.
- Delivery ledger is company-scoped for reads (a vendor sees its own deliveries).
- Failure semantics: transport failures never block or mutate the underlying business record (fail-open on delivery, fail-closed on record — the notice stays issued even if email bounces; the ledger says so).

## Consequences

- The multi-company processes become operationally real; acknowledgment latency drops; ADR-049 SLA reminders have a channel.
- New attack surface: webhook authenticity, link signing (time-boxed, identity-bound), address verification; all must follow existing discipline (signed envelopes, hash_equals, RLS).
- Test surface: ledger append-only, no business mutation on transport failure, roster boundaries (no leakage across companies in body/attachment), webhook replay protection.

## Out of scope

Push notifications/mobile (ADR-056), calendar invites (.ics — candidate later increment), transport of legacy warehouse events (post-cutover only, ADR-047).
