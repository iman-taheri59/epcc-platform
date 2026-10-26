# Notification register — optional date/time controls

> **Current lifecycle addendum — 2026-10-20:** [ADR-043](ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-EN.md) / [cancellation guide](INSPECTION-NOTIFICATION-CANCELLATION-EN.md). User approved current issue grant + **cancel-only** + original-reader reason/actor/time visibility. Independent immutable event; original issued row/snapshot/private history untouched; effective CANCELLED and current PDF banner/status. Prior cancellation deferral superseded **only**; replacement/undo/reissue/acknowledgment remain deferred. Final59/884 + separate upgrade1/5 PASS; overlapping PDF1/22 PASS. No full-suite/90-case/current-CI/production pass.

2026-10-20 · **IMPLEMENTED / TARGETED VERIFIED; NOT DEPLOYED**
[فارسی](INSPECTION-NOTIFICATION-FILTER-TIME-UI-FA.md) · [Register semantics](INSPECTION-NOTIFICATION-REGISTER-FILTERS-EN.md) · [Existing write UI](INSPECTION-NOTIFICATION-TIME-UI-EN.md) · [ADR-042](ADR-042-ITP-INSPECTION-NOTIFICATIONS-EN.md)

## Approved continuation

User selected **`next_scope=filter_usability`** after register-filter delivery: align filter date/time entry with existing controls and check EN/FA browser behavior, without new write authority/workflow. No permission, schema, migration, service/controller/query/parser, cancellation/replacement/acknowledgment change. Previous register-filter decisions remain accepted/implemented; new workflow alternatives remain unselected.

## Using the controls

Each **From / To** filter now has Gregorian date, time (seconds) and explicit UTC-offset controls, canonical exact-value preview and optional direct-entry mode. Native controls may display date order/12-hour clock according to browser locale; the submitted text remains `YYYY-MM-DDTHH:MM:SS±HH:MM`, e.g. `2026-10-25T23:30:45+03:30`. No Jalali conversion, browser-zone conversion or guessed/default offset is performed. Exact values and previews are LTR within Persian RTL pages; labels, optional hints and fallback text are translated.

- Leave **all three helper components** empty to omit a bound. Both bounds may be omitted, or just one supplied. Empty bounds continue to be empty query values, handled as absent by the unchanged server parser.
- Once any helper component is entered, all three must be completed. Partially editing/clearing a populated helper clears its canonical value and blocks submission until completed or fully cleared. This prevents stale dates being sent or an incomplete intended bound silently becoming an unbounded search. The preview distinguishes omitted from incomplete.
- Explicit offsets are required for a populated bound; `+00:00` means UTC, not `-00:00`. Seconds entered are retained; native clock values containing only hours/minutes serialize with `:00` seconds. Invalid direct Gregorian values/offsets are blocked by the helper; server validation is authoritative for all clients and supported normalized year/range checks.
- Direct-entry checkbox disables helpers and unlocks the exact value. Clearing that exact value deliberately omits this bound. Switching back loads valid exact components without altering the date, seconds or offset. Incomplete helper edits are not retained as a hidden alternate query value when changing modes.
- Without JavaScript/native supported date/time input, helpers stay hidden and optional exact fields remain usable. The translated fallback tells the user that either bound can be omitted. Invalid direct values and reversed **absolute** range still receive the existing server422 (HTML localized, JSON validation details); the UI does not silently swap bounds. Equal bounds remain valid instant searches; touching endpoints count as overlap. Client code does not duplicate authoritative interval-order/UTC-normalized-year validation.
- GET keeps only canonical named bounds; unnamed helper controls never leak into draft POST fields. Apply/Clear, language/stage links, scope,25-row paging and visible-only counts are unchanged. Source selectors are not date-filtered. Legacy unknown activity intervals are still excluded only when a temporal bound is supplied; never inferred.

## Shared component boundary

`time-field.blade.php` has internal optional name/legend/optional-mode parameters; existing draft/edit consumers default to required activity start/end and unchanged `notification[activity_*_at]` payloads. Filter consumers explicitly use unique `filter-from` / `filter-to` DOM IDs and `activity_from` / `activity_to` names. Shared script initializes all widgets once, isolates state per fieldset, makes optional helpers required only when any component is present, and preserves mandatory write behavior. Filter input never populates or changes the draft activity interval.

## Verification and evidence

**26 focused PHP tests /404 assertions PASS,24.695s, zero skips**:9 register-filter feature (one new UI contract/DOM/read-only test),4 existing time UI,3 filter parser,10 input. No full regression rerun; previous58/640 result is historical and must not be added. New feature checks both languages, empty/equal-bound prefills, canonical GET names, optional vs required write fields, unique IDs, and unchanged notification row after GET; existing register access/privacy/query/paging tests rerun.

**Real Chromium via Playwright:7 scenario groups /147 assertions PASS.** Current Laravel-rendered synthetic pages, EN/FA × Asia/Tehran/America/New_York, plus EN/FA JavaScript-disabled fallback and Persian390px mobile. Checks blank/partial/complete/clear, one-sided/equal/cross-midnight serialization, explicit UTC/invalid offsets and dates, manual roundtrip, GET FormData separation/context/page reset, language/clear links, required draft/edit isolation, RTL/LTR and mobile control fit. Submissions are captured locally, network blocked: this is not a live authenticated end-to-end HTTP/production journey or native calendar-popup/all-browser certification. Server GET behavior is separately feature-tested. No Safari/Firefox, load/concurrency claim. Missing installed skill instructions path was not available; browser checks used installed Playwright/Chromium directly.

[Evidence](evidence/2026-10-20-inspection-notification-filter-ui/README.md): reproducible commands, synthetic HTML with scrubbed CSRF placeholders, browser summary/log, mobile screenshot, PHP output, fresh-cache Pint/lint/Blade/diff, source hashes, private RLS audit/shutdown. No actual tokens or production dataset are captured. Private PostgreSQL17 uses `epcc` NOSUPERUSER/NOBYPASSRLS, Unix socket only and FORCE RLS; stopped after verification. No commit/push/deployment.

## Recommendation / deferred

Native date/time controls vary with browser locale; canonical text remains the reliable inspection/fallback path. A future Jalali UI or browser support expansion requires explicit design/testing, not an implicit change to stored Gregorian values or offsets. Measure large-register search workloads before adding indexes; that recommendation is unchanged. Issued lifecycle, receipt/acknowledgment, signatures/transport and business hold/release rules remain separate decisions; no new MTO/PO/MR authority or history visibility.
