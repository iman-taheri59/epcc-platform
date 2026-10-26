# Register-filter verification — 2026-10-20

Implemented user-selected read-only `register_filters`; no migration, authority, write endpoint, commit/push or deployment.

- `final-focused.log`: **58 tests /640 assertions PASS**,82.701s, zero skipped. 8 register feature +3 parser +22 workflow +11 window +4 time UI +10 input. Final functional run; later view-only RTL count isolation validated by Blade cache, no additional functional rerun.
- `static-checks.log`: fresh-cache Pint8 PHP files PASS,8 PHP lint PASS, Blade cache/clear PASS.
- `guide-links.log`: EN/FA guide local links resolve.
- `database-audit.log`: private `epcc` NOSUPERUSER/NOBYPASSRLS, forced RLS on four relevant tables, no TCP listen address.
- `database-shutdown.log`: pg_ctl status after process shutdown; no server running.
- `source-sha256.txt`: affected application and test source hashes; these are workspace artifact fingerprints, not cryptographic business-history tamper evidence.
- `diff-check.log`: final git diff whitespace validation.
- `initial-focused.log`: retained failed initial test expectation for `0` (also matches number zeros and50%); corrected expected set, not weakened SQL search. Initial generator backslash syntax corrected before test execution.
- `focused-fixed.log`: earlier11/210 passing increment before additional malformed-input/HTML checks; nonadditive.

See [English guide](../../INSPECTION-NOTIFICATION-REGISTER-FILTERS-EN.md) / [راهنمای فارسی](../../INSPECTION-NOTIFICATION-REGISTER-FILTERS-FA.md) for exact overlap/legacy/navigation/privacy rules and recommendations. Scoped totals include all currently visible notices only, not global records; no history/note search. Existing source selectors unaffected; notice paging25. Explicit-offset optional from/to, inclusive intersection, point/one-sided queries allowed. Unknown legacy windows excluded only from time-bounded searches. GET snapshot and recipient-history query checks included.

No full-suite/current-CI/production, browser automation/user acceptance, concurrency, load, or search-index certification. Historical prior increment totals are not this run and must not be added. Private runtime stopped; no MTO delegation or issued cancellation/replacement/reschedule/acknowledgment authority added.
