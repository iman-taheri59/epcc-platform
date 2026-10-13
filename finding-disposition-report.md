# Formal finding dispositions — 2026-09-29

## Authorized scope and delivered behavior

User selected current independent reviewer authority, record-only effect and documented N/A acceptance. Migration 55 adds `material_finding_dispositions`; the service, scoped POST route, FA/EN partial and translations expose:

- EXPLANATION → ACCEPT_EXPLANATION or REQUEST_EVIDENCE.
- NA_REQUEST → ACCEPT_NA, REJECT_NA or REQUEST_EVIDENCE.
- REVIEW_NOTE is not a target.

Every decision requires rationale and document/revision/clause. ACCEPT_NA additionally requires a source excerpt. This records a human decision; it does not authenticate source documents or validate their engineering truth. N/A acceptance applies only to that response, captured finding and version. It does not authorize a general standards deviation, material use or downstream eligibility.

## Authority, integrity and lifecycle

The independent reviewer of the currently active captured-policy stage must still be eligible. Membership, role/discipline eligibility, definition-author-chain and prior-signer separation are rechecked. Nobody can dispose their own response. Closed, returned, approved, superseded and archived versions refuse new decisions.

Forced project/company RLS and SQL guards complement service authorization. Original responses, snapshots and disposition events are immutable. The database stamps sequence, actor/company/name, stage and time. A replacement appends a new event, requiring the actual latest predecessor and a reason; stale predecessor and duplicate requests are refused. Shared review locking prevents competing lifecycle writes. New responses and corrected definitions inherit no disposition.

`effect=RECORD_ONLY` is server-owned and SQL constrained. Existing approval gates are unchanged: outstanding evidence requests or rejected N/A may coexist with normal stage advancement. Tests explicitly advance ordinary approvals while REQUEST_EVIDENCE remains. Captured screening does not become engineering-approved.

Nested `finding_disposition[...]` payloads isolate old input from response/declaration forms; scalar guards and escaped evidence protect rendering. UI shows current decision, append-only history, a blank initial choice, and mandatory replacement reason.

## Verification performed on current sources

| Check | Result |
|---|---|
| Targeted PHP regression, including new disposition and migration tests | 138 tests / 1,322 assertions passed |
| Read-only screening core | 60 assertions passed |
| Python reference tests | 21 passed |
| Standards/reference validators | 3 standards + 16 reference packs valid |
| Three-user browser workflow | Author N/A → reviewer evidence request → documented N/A acceptance; author/future-stage reviewer have no decision form |
| Active form/history browser checks | FA/EN × 1440/430 px; RTL/LTR correct, no horizontal overflow, submit unobstructed, no page errors |
| Migration 54→55 | Existing approved definition, policies, run, approval, snapshot and cited response unchanged; no fabricated dispositions |

The PHP run is targeted, not the full application suite. Evidence lives in `/home/user/organization-report/finding-disposition-final.{log,xml}`, `finding-disposition-{core,reference,packs,lint}.log`, and `finding-disposition-browser/results.json` plus screenshots. Source preflight checks presence only, not deployment correctness.

An initial regression execution timed out; a subsequent overlapping execution hit shared test-database migration conflicts. After confirming no competing PHPUnit process remained, the serialized final run passed all 138 tests. The failed overlap log is retained separately; it is not represented as a product pass.

## Demo runtime and handover

Fresh synthetic database migrated and seeded. PG process `project-database-366a7555`; app `epcc-platform-e57c795b` on port 8000 with debug disabled. `/projects/PH-14/piping/project-fittings?lang=fa&family=PIPE` contains one synthetic definition, one author N/A response and two decisions, latest ACCEPT_NA. The definition deliberately remains in review. Browser fixture ID: `finding-disposition-demo.json`. Credentials: author `rezaei@pars.example`, current reviewer `tehrani@pmc.example`, future-stage reviewer `karimi@nioc.example`; demo password `password`.

Runtime processes and excluded dependencies may need restoration after workspace resume. `.env` is retained locally but excluded from the deliverable archive. Demo setup/browser scripts require `EPCC_ALLOW_DEMO_WRITES=yes`; browser write script intentionally refuses replay on an already-populated fixture. Do not rerun blindly.

B16.9 NPS ½–48 scope, 99 reference rows, cap rules, checker behavior and final approval workflow are unchanged. Out-of-scope sizes remain project-Spec definitions. No PMS/model/MTO integration, reference replay archive, production/load certification or automatic engineering approval is included.
