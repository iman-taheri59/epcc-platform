# Reproduction (isolated disposable DB only)

From app/:

```sh
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --filter 'CdeEvidenceIntegrityTest|DocumentFileTest|DocumentRegisterTest|MtoScopeObservationTest|RlsSargabilityTest' --log-junit ../docs/evidence/2026-10-18-cde-integrity/related.xml
```

Tests run migrate:fresh; never use customer/shared/demo data or concurrent DB suites. Actual matched classes/counts are in related.xml. New CDE test cases use real local scratch streams; metadata drift is test-injected, not actual worker contention. No new complete-suite run. Initial one fixture lookup error is retained in initial.*; final related.exit=0 and related.xml is the passing result.
