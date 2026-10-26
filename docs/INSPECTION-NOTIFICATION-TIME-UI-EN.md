# Inspection notification — date/time form usability

> **Latest UI continuation:** user-selected `filter_usability` implemented; [optional filter date/time controls](INSPECTION-NOTIFICATION-FILTER-TIME-UI-EN.md), same offset/overlap/legacy/authority contract. **26 PHP tests /404 assertions +7 Chromium scenario groups /147 checks PASS** (separate nonadditive verification). Offline current rendered fixtures, not live authenticated browser/production; no full suite. Previous register/history counts are historical.

> **Register-filter addendum — 2026-10-20:** user-selected `register_filters` implemented. [Current guide](INSPECTION-NOTIFICATION-REGISTER-FILTERS-EN.md): bound literal number/current-reference search + state + explicit-offset inclusive activity overlap; authorized-only totals/pages, independent source pickers, preserved filters/lang/context and notice-page reset. Legacy unknown windows excluded only by time bounds; no history/note search/new authority/schema/API write. **58 tests /640 assertions PASS**,82.701s, zero skipped; not full suite/production/current CI. Earlier totals historical/nonadditive.

> **Draft-history addendum — 2026-10-20:** user-approved `draft_history` implemented. [Current history guide](INSPECTION-NOTIFICATION-DRAFT-HISTORY-EN.md). Atomic database-captured before/after versioned draft snapshots + recorded editor name/UUID/time/settings; append-only/FORCE RLS, originating-company readers only, no recipient HTML/SQL/PDF history.25-row pages, no-op saves retained; old edits not reconstructed, earliest captured version explicit. Migration120000; **87 tests /1139 assertions PASS**,156.343s, zero skipped, not full suite/production/current CI. Prior “last-editor only” limitation applies to old records, not future captured edits; earlier counts historical/nonadditive.

2026-10-20 · **IMPLEMENTED / TARGETED VERIFIED — NOT PRODUCTION DEPLOYED**
[فارسی](INSPECTION-NOTIFICATION-TIME-UI-FA.md) · [Activity-window contract](INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-EN.md) · [Workflow](INSPECTION-NOTIFICATION-WORKFLOW-EN.md) · [ADR-042](ADR-042-ITP-INSPECTION-NOTIFICATIONS-EN.md)

## Accepted scope

User selected **`next_notification_step=date_time_ui`**: improve date/time entry and readable display without changing the approved `activity_window=replace_time` contract, current grants, issued immutability or historical evidence. No schema, migration, endpoint, service/input contract, role or business-rule change in this increment.

## Using the controls

- Register/edit forms now offer **Gregorian date**, **time with seconds** and a separate **explicit UTC offset** for each activity boundary. Date/time presentation follows native browser locale (a clock may show AM/PM); the exact submitted value remains a 24-hour Gregorian offset-bearing value. No Jalali conversion or named-zone/DST calculation is provided.
- New drafts start blank. The browser's current time/time zone is never used to fill date, time or offset. Existing values populate controls by splitting their saved components, without converting to browser time. Start and end can keep different explicit offsets.
- **Exact value submitted** stays visible/copyable. In helper mode it is readonly; the actual named fields remain only `notification.activity_start_at` / `notification.activity_end_at`. Helper controls have no payload names. Selecting `09:30` supplies explicit seconds `:00`; other entered seconds are retained.
- **Enter the exact value directly** enables the original offset-bearing text input and disables unused helpers, so their validity cannot block a valid direct entry. Switching back preserves valid components/offsets. Malformed restored input opens direct mode unchanged for correction, rather than inventing a date or silently normalizing an impossible day.
- Clearing or partially changing a helper clears the old complete submitted boundary; required helper controls block native form submission until complete. This matters because readonly exact inputs do not participate in browser required validation. No previous complete timestamp is silently sent from an unfinished helper.
- Offset is required and explicit, including `+00:00` for UTC. The sign in that example is isolated for RTL readability. Unknown negative-zero offset (`-00:00`) is not silently converted into UTC. Native/JS checks are usability only; server validation remains authoritative, including **end strictly after start in absolute time**, current access/source and optimistic version checks. The browser does not add new duration/overlap/lead-time rules or certify the range as valid business execution.
- With JavaScript disabled/unavailable (or unsupported native input types), original required exact-value inputs remain editable and usable. Submit the same existing canonical values; server validation still applies. No-JS does not promise the enhanced browser-format/preview validation.

## Readable display and history

Web register/detail retain exact original ISO strings and add a separate readable **date · time · UTC offset** line. No host time-zone conversion, current metadata join or new write is used. Existing legacy single times keep their explicit legacy label and warning, without a fabricated activity range. Issued data are not edited.

PDF continues to show original offset-bearing recorded boundary strings using the shared print renderer, without the new form JavaScript or the duplicate web-only readability line. Read authorization, private/no-store response and post-render visibility recheck are unchanged. This is not byte-identical PDF certification, digital signing, delivery or an actual-execution record.

## Verification

Affected PHP command: **53 tests /740 assertions PASS,96.840s, zero skipped** =4 new UI feature cases +10 activity-window +16 draft-edit +13 PDF +10 input cases. Server workflow/permission logic was unchanged; no routine full regression was run. After a final RTL hint-isolation-only correction, the4 UI feature cases were rerun: **4 /81 PASS,9.151s** (a subset, **not additive**).

Separate real-Chromium/Playwright run: **18 browser scenarios /149 assertions PASS**, no JS errors or external requests. Actual Laravel-rendered synthetic EN/FA pages were loaded offline, with token scrubbing: two browser time zones (Asia/Tehran and America/New_York), Gregorian/seconds/offset serialization and FormData, partial/cleared input safety, direct-mode roundtrip, invalid dates/offsets, saved edit prefill, a390px RTL viewport/no overflow, JavaScript-disabled fallback and invalid restored input in Pacific/Auckland. First mobile widget screenshot was inspected. This is DOM/native-validity/FormData verification, **not browser-driven authenticated end-to-end HTTP submission**, physical-device testing, all-browser acceptance or a complete accessibility audit. PHP tests separately exercise actual routes and canonical server saves/refusals.

Evidence: [directory](evidence/2026-10-20-inspection-notification-time-ui/), `final-focused.log`, `final-ui-feature.log`, `final-browser.log`, `browser-summary.json`, static checks/source hashes. The initial browser harness used an incorrect Playwright keyword for disabling JavaScript; corrected to `java_script_enabled` and the entire browser script rerun. Initial log retained, not represented as passing. Private PHP/PostgreSQL/Chromium dependencies were restored after sandbox reset; app SQL role checked NOSUPERUSER/NOBYPASSRLS, notification tables FORCE RLS, private Unix socket only, verification database stopped afterward. Package installs are not a durable deployment.

No production/current-CI, full suite, load, Safari/Firefox, universal concurrency or user acceptance claim. Earlier71/802 and other totals are historical and nonadditive. Both languages and ADR-042 are synchronized. Existing forward migrations remain prerequisites; this UI increment adds none.

## Decision review

Accepted independent main-contractor permissions, all ITP stages, explicit recipients, exact revision/stage host, `draft_only` and `replace_time` are preserved. The separate draft-history feature now captures future saves with private company access and explicit pre-install gaps. Issued cancellation/replacement/rescheduling, transport/acknowledgment and signatures remain separate. Recommend deciding their authority/retention/numbering rules explicitly before adding those writes; simpler date entry does not grant them.
