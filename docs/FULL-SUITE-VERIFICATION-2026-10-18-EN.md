# Full-Suite Verification and PDF Repair — 2026-10-18

> Historical report; the subsequent complete final-source run is now GREEN (1083 tests /16385 assertions /0 failures/errors/skips). Current evidence: `FINAL-SOURCE-VERIFICATION-2026-10-18-EN.md`. Earlier pending-rerun statements below are historical.


Baseline: `main@0c54c7f84d1e343bf1d58833977ac0834dcfdcc0` plus local uncommitted baseline repairs. Working branch: `arena/baseline-verification-2026-10-18`. No commit, push, PR or customer deployment was performed.

## Executive result

The entire configured PHPUnit suite completed serially against PostgreSQL 17 using the non-superuser/non-BYPASSRLS application role:

| Check | Result |
|---|---|
| Full suite before the PDF layout repair | **1,083 tests; 16,380 assertions; 1 failure; 0 errors; 0 skipped** |
| Passing cases in that full run | **1,082** |
| Full-run duration | Approximately 24m 40s |
| PDF/certificate suite after the repair | **29 tests; 108 assertions; 0 failures; 0 errors; 0 skipped** |
| Standards/reference validator, conformance, independent screening, five prototype suites | Passed |
| Composer manifest validation and platform requirements | Passed |
| PHP syntax checks | **359 files passed** |
| Actual Laravel browser smoke | **8 FA/EN routes returned 200; no page-level JavaScript exceptions** |
| FA document register at 430px | RTL; page scrollWidth=430 |
| Synthetic print-layout probe | Summary label remains one line in EN and FA |

**Do not report a single clean 1,083-test run on the final source.** The complete run was red by one test; that identified failure was then fixed and all 29 related tests passed. A complete repeat on the final source (ideally in CI before merge) remains an acceptance step. No failing test was removed, skipped or weakened for the PDF repair.

## Sole full-run failure: MCC PDF summary label wraps

Test: `Tests\Feature\PdfExportTest::test_an_issued_certificate_does_not_rewrite_its_own_evidence`.

The extracted PDF text read:

```text
Open — category 2
A:
```

instead of keeping `Open — category A:` together before its value. The 34mm key column in the shared print metadata layout was narrower than that English label. Text wrapped and PDF reading order interleaved the value. The full run stopped this test at its initial PDF label assertion, before its later snapshot-invariance assertions. It did not establish a frozen-data regression.

### Repair

- Added `punch-summary` class only to the MCC punch-summary table.
- Added `.meta.punch-summary td.k { width: 48mm; white-space: nowrap; }` to the print shell.
- Other metadata tables and transmittal layouts retain their previous sizing.
- Certificate lifecycle, snapshot content, owner exception authorization, RLS and release gates are unchanged.
- The failing test remains unchanged. After the repair it passes through the later assertions: closing a real punch item changes the live register but not the issued certificate's frozen counts.

All `PdfExportTest` and `CertificateScreenTest` cases were rerun serially: 29/29 passed, including Persian shaping/font embedding, English/Persian certificates, immutable issued counts, live draft counts, timeouts, scratch cleanup, invalid/truncated PDF rejection, transmittal sheets/ZIPs, and unauthorized export refusal.

The unsaved synthetic print probe also measured the final label/value layout in real Chromium print media: one label line in both languages; English value `2`, Persian value `۲`. The English summary crop was visually inspected. The probe is not a valid certificate or customer record.

## Environment restoration and persistence

At session start, system PHP/PostgreSQL remained available, but vendor contents were incomplete, the local branch was absent and several previously reported source changes/new files were missing. Restoration was based on the previous regression evidence and documented fixes, not a new architecture decision. The earlier 56-test result remains historical evidence, not an assertion that every restored byte was already present at session start.

- Recreated local working branch without touching the remote.
- Restored three CREATE OR REPLACE trigger-function declarations, snapshot UUID model metadata, component DOM assertion and the repeated-fresh regression.
- Restored executable bits only where Git records mode 100755; no script logic changed for this.
- Reinstalled locked Composer dependencies into ignored `.cache/vendor` with `app/vendor` as a symlink. `tests/bootstrap.php` already pins APP_BASE_PATH to support this layout.
- Recreated Node 22/npm 11, frontend assets and root prototype dependencies. No dependency manifests or lock versions were changed.
- Kept all database suites serial; independent static/browser checks did not invoke the root runner's Laravel stage.
- Development role posture and seeded app database checked: `epcc|false|false`, 8 companies, 3 projects. The test database is separate.

A portable source-only recovery bundle is written outside the repository at `/home/user/epcc-recovery/`. It contains the tracked diff and explicitly selected untracked source/docs/evidence, not vendor, node_modules, .env, APP_KEY, database data or credentials. Verify its checksums and inspect/apply it only to its stated baseline; it is not an installer or a database backup.

## Evidence and scope

Compact evidence lives in `docs/evidence/2026-10-18-full/`: full JUnit report, full console output, post-repair JUnit/output, independent check output, browser/layout probe JSON and summary/source manifests. Raw runtime artifacts remain under `/home/user/epcc-evidence/full-suite-2026-10-18/`.

The full run demonstrates executed coverage of the configured Unit and Feature suites, not completeness of the EPCC product, penetration/load testing, backup recovery, Docker image/compose jobs or all browser interactions. `app/tests/Browser` JavaScript scripts are not part of PHPUnit's configured suites; the separately executed browser smoke is explicitly limited. TQ/NCR capture still is not issue/signature verification/disposition; deferred MTO permission design remains deferred.

## Next gate

1. Repeat the complete suite on the final source/CI before treating the baseline as fully green for merge.
2. Review the bounded changes and decide when to commit/push; no remote action is implied by this report.
3. Define the piping pilot acceptance matrix against the current implementation ledger; resolve authority and usable-material requirements before implementing their missing slices.
