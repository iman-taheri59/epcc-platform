# ADR-056 — Field capture and offline sync (promotion of the persistence prototype)

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). `prototype/persistence/schema.sql` and `prototype/demand-atomicity/` already sketch the offline pattern; ARC specifies offline sync as a platform property; the field (site) is exactly where connectivity is worst and value is highest.

## Context

Site processes that need capture where the network is unreliable: daily reports per work package (ADR-055), inspection/test results and sign-offs (ADR-048), punch walks, receipt/custody events at laydown yards, joint fit-up/weld progress. The platform's existing disciplines translate unusually well to offline: append-only journals with ordinal clocks (MTO acceptance journal, `epcc_mto_clock_step`), optimistic versions, digest-bound snapshots, fail-closed CLIs. The prototype schemas show the team already intends a journal-based sync rather than a mutable-row merge.

## Decision points (explicit owner choices required)

1. `capture_surface=offline_first_pwa | native_app | responsive_web_with_queue`
   Recommended: **offline_first_pwa** — one codebase, installable, works in the existing bilingual/RTL front-end stack; native apps deferred until a proven field demand.
2. `sync_model=append_only_client_journal_server_admits | row_level_merge_crdt | snapshot_diff`
   Recommended: **append_only_client_journal_server_admits** — the client records signed entries (actor, device, local ordinal, captured-at, evidence refs); the server **admits** them into the domain journals through the normal authority/validation path (exactly like scope admission's envelope pattern: hash-bound, replay-protected, server time governs record time, client time stays visible as capture time). No CRDTs, no silent merges.
3. `conflict_semantics=server_wins_with_visible_divergence | first_writer_wins | manual_reconciliation_queue`
   Recommended: **server_wins_with_visible_divergence** — an entry that fails admission (authority lapsed, version moved, constraint changed) is never dropped: it lands in a reconciliation queue for the originator, mirroring the "honest divergence" pattern used in daily-report reconciliation (ADR-055).
4. `evidence_attachments=photos_and_signed_files_hashed | metadata_only`
   Recommended: **photos_and_signed_files_hashed** — content-addressed blobs (digest-bound at capture), queued for upload, referenced by journal entries; storage via the existing DocumentFileStore discipline.
5. `device_and_identity=per_user_pinned_devices_with_revocation | shared_devices_with_pin`
   Recommended: **per-user_pinned_devices_with_revocation** — field identity must stay non-repudiable (separation of duties depends on it); shared-device mode is a later, explicitly-bounded decision.

## Sketch of the decision

- Client journal schema per prototype: entries are immutable, device-ordinal, signed (HMAC per device key); server admission endpoint verifies signature → authority (current at admission time, not capture time — the house "freshness" rule) → domain invariants → appends to the domain ledger with both times (`captured_at` client, `recorded_at` server) and the entry digest.
- Offline reads: bounded snapshots of what the field worker needs (their packages, joints, ITPs, punch) pushed to the device (digest-versioned); staleness is always visible ("data as of T").
- Transport: the same signed-link discipline as ADR-050; sync works over intermittent connectivity with resumable uploads.

## Consequences

- Field processes (ADR-055 daily reports, ADR-048 results, punch) become usable where work actually happens; adoption flywheel.
- Security surface grows: device keys, replay windows, capture-time honesty (never backdate silently), photo chain-of-custody — all must follow existing digest/append-only discipline.
- Test surface: admission under authority change between capture and sync, replay/rejection, divergence queue, resumable upload integrity, offline snapshot staleness honesty; the prototype's Python/Postgres harnesses should be promoted into the CI runner (closes review debt B7).

## Out of scope

Native mobile apps, push notifications (transport ADR-050 channels), indoor positioning/geo-fencing, biometric attendance, barcode/RFID hardware integration (candidate later increment).
