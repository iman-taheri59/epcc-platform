# Personal discipline closure for three CDE read routes

**2026-10-25 · implemented and affected-tested, not deployed.**

[فارسی](CDE-READ-DISCIPLINE-FA.md) · [Evidence preflight](SCOPE-EVIDENCE-PREFLIGHT-EN.md) · [Underlying CDE byte diagnostics](G01-CDE-EVIDENCE-INTEGRITY-EN.md)

## Correction, not a new grant or evidence approval

The previous preflight increment identified that DocumentController used project-level doc.view without the actual document discipline. Normal company/project RLS alone cannot implement a person's discipline_scope. This change closes that gap for **only the existing register, review sheet and file download GET/HEAD routes**. It applies the existing AuthorizationPipeline ABAC meaning; it does not create a new business policy, default role or access nomination.

Changed production file: `app/app/Http/Controllers/DocumentController.php`. Added: `app/tests/Feature/CdeReadDisciplineTest.php`. No migration, RLS/policy/role/membership write, route/view change, file copy, new endpoint or source-owner impersonation. Proposals/declarations/opinions, CDE integrity/preflight services, snapshots, buying/stock/receipt records and all admission/readiness flags are untouched. SA-01/02 and DA-01..04 remain settled.

## Exact route behavior

| Route | Added gate before disclosure |
|---|---|
| GET/HEAD `/projects/{projectCode}/documents` | Fresh original user/plan and existing doc.view. Reload personal membership; apply exact discipline whitelist in SQL **before** document rows and eager-loaded revisions/files/comments/company are loaded. |
| GET/HEAD `/projects/{projectCode}/documents/{documentId}/review` | Existing project/doc.view gate; normal original-tenant/project RLS reads only id+actual discipline. Pass that discipline to doc.view ABAC before current revision, company, comments/people or statistics. |
| GET/HEAD `/projects/{projectCode}/documents/{documentId}/files/{fileId}` | Same minimum-header/actual-discipline gate; exact original-project/document/file match before Storage::disk/exists/stream response. Invalid UUIDs, mismatched tuple and denied header stop before Storage. |

`discipline_scope=null` retains existing unrestricted discipline access, **subject to all other gates/RLS**. An array is an exact whitelist; `[]` yields an empty register and zero counters. A malformed non-null/non-array scope is not treated as unrestricted. No uppercasing, guessed department, company role default or scope widening. Query strings such as discipline/as/discipline_scope do not alter server-derived scope or actor.

The register's state counters use the same post-RLS/post-personal-filter collection as the table; hidden documents do not contribute titles, numbers, file names, dependent models or counters. This is a list of documents currently visible to the user, not a complete project inventory. Fixed form discipline/type choices and global create/issue button capability flags remain existing UI; they are not extra documents and are **not certification of discipline-specific write permission**.

The three read actions reload the authenticated User by actual id+company with fresh company.plan rather than trusting cached caller models/relationships. Membership/role checks use the existing pipeline. Next requests see restricted personal scopes or an inactive account even when an internal test reuses a cached actor. No new user provisioning or tenant identity lookup shortcut is introduced.

Missing/hidden/out-of-scope point resources return opaque404 before detailed payload/storage. Baseline project/participation/membership, authentication and general capability/entitlement denial semantics remain existing behavior (including general403 or login redirect). Standard English/Persian renderers and allowed-file inline/attachment, MIME, nosniff and CSP response behavior are preserved. No custom error message needed a new translation file.

Foreign private WIP remains hidden under existing CDE RLS. Normal published participant visibility remains possible **only** when the original person also has doc.view and the actual document discipline. Publication/company participation does not bypass personal scope, and ownership does not bypass it for one's own documents. Null-scope readers retain their normal company/project visibility, not another tenant's private records.

## Scope deliberately not claimed

- This is **not global CDE access closure**. Existing store/update/upload/deleteFile/comment/dispose methods are byte-identical, as are their capability semantics. Their actual-discipline authorization, and any Filament/admin/alternative read surfaces, need a separate focused audit/correction; no write-path permission was silently broadened or redesigned here.
- No RLS broadening, SECURITY DEFINER, global AuthorizationPipeline modification, new grant or inherited QA/purchase/source-reader rights. A technical-review/source nomination still does not grant doc.view or expand CDE personal scope.
- Gates are normal request-time checks, not a revocation lease or cancellation of an already-started stream. No simultaneous-worker revocation, already downloaded copy/cache removal, filesystem/DB atomicity or malicious administrator resistance is certified.
- Normal pipeline denial auditing remains best effort and can be rolled back by the enclosing request transaction. No new durable audit guarantee.
- An allowed ordinary download is not a byte-integrity/retention/current-validity/engineering-purpose certificate. The unchanged preflight still emits BYTES_CHECKED_UNRETAINED only and issues no permanent package identity/attachment. Evidence composition, authoritative historical coverage, positive review/admission/lifecycle/effective selection/complete commitments and DA-03 reservation remain gated.

## Verification

- Initial **12 new tests/106 assertions PASS,23.35s**; overlaps the final affected run.
- Final affected **72 tests/299 assertions PASS,151.03s**: new discipline HTTP tests plus DocumentFileTest, DocumentRegisterTest and ScopeEvidencePreflightTest.
- Additional affected **ReviewCycleTest:19 tests/79 assertions PASS,36.71s**, disjoint from those72. Thus91 distinct targeted tests/378 assertions have passing results across the two final runs, **not the full application regression**. No failure/error in these application runs.

New cases cover SQL filtering before eager loads and post-filter counts; empty/null scopes; allowed review/download and safe headers; hidden detail stops before revisions/comments; out-of-scope GET/HEAD no Storage; wrong file/document/project/malformed IDs; query actor/scope forgery; cached-user scope change/inactive-account refusal; English/Persian masking; foreign WIP/published original-reader discipline; and no document-row mutation. Disk fake uses actual local scratch data; no mocked successful authorization. This is application HTTP/render testing, not a browser run or new HTML capture.

Two PHP Pint/lint and whitespace PASS. Initial format command found no PHP runtime after workspace restoration; runtime/dependencies/private socket-only PG were restored, then all recorded format/test runs passed. This tooling failure is retained in format.log and is not an application test failure. Ordinary epcc role NOSUPERUSER/NOBYPASSRLS/NOCREATEDB, existing FORCE RLS and enabled immutable guards audited. The earlier pipeline, file/register/review/preflight code, routes, review view and all six write-action bodies were byte-verified unchanged. The register view was not edited: its pre-existing hash differs from the Oct18 manifest, was preserved and recorded rather than reset or claimed identical to that baseline. Controller behavior changes are limited to the three read actions and two private helpers, with imports/formatting (including Pint compaction of the empty constructor). No migration/customer data/grant, CI/full regression/browser, deployment/commit/push. Private PG stopped after verification.

Evidence: `docs/evidence/2026-10-25-cde-read-discipline/`. Bilingual preflight status/ledger/index updated so its earlier missing-read-discipline observation is historical **for these three routes only**. Dependent source archive builds on the previous preflight increment, excludes runtime/vendor/DB and does not regenerate older archives.

## Next recommendation

Audit actual-document discipline on CDE mutations and admin/alternative surfaces against the existing entitlement/role/membership/RLS contract before claiming global closure. Treat UI action flags as hints, not authority. In parallel, retain the previous permanent-composition gate: no controlled evidence package until original-caller dependency visibility, retained bytes, purpose relationship and current-validity/revocation controls exist. Do not invent retention operators/duration or treat old pair-bound opinions as evidence review.
