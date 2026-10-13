# Cited responses to captured material findings

Date: 2026-09-29. User explicitly selected **finding_resolution: cited explanations, Spec references and not-applicable rationale, without automatically changing approval rules**.

## Delivered user interaction

Under each finding in **Screening captured at submission**, the page now has **Cited responses and reviewer notes / پاسخ مستند و یادداشت بازبین**:

- `EXPLANATION`: a source clarification from the submitting company's eligible team.
- `NA_REQUEST`: a **proposal** that the finding does not apply. Requires rationale, cited document/Spec number, actual revision, clause/table and a nonblank source excerpt.
- `REVIEW_NOTE`: a note by the currently assigned independent reviewer, recorded with the actual review step.

All notes require a nonblank explanation and structured textual citation. An excerpt is optional for ordinary clarification/reviewer notes. Citations may refer to another actual document/revision; they do not overwrite the declaration's original Spec. Source excerpts and messages are escaped in HTML, not executed. These are text references, **not authenticated document attachments or automatic source verification**.

The UI explicitly states that notes do not clear a finding, accept an exception or grant engineering approval. To correct a note, append a new one referencing its sequence number. To change material data, create a corrected declaration instead. There is no edit/delete action, resolution checkbox, ACCEPT_NA action or new approval gate.

Notes attach only to **immutable submission-time findings**, never to the changing live screen. A finding identity is SHA-256 of `material-finding-v1:{captured-result-sha256}:{zero-based-index}`, scoped to the run/project. The result hash binds the index to the exact captured report, not a current rule ordering.

## Authority and lifecycle

- All writers must be active project members of participating companies, with PIPING or unrestricted discipline scope.
- In IN_REVIEW, the currently assigned independent reviewer can write REVIEW_NOTE only. Current activity, company-scoped role or named-person assignment, author-chain exclusion and previous-signature exclusion are rechecked.
- An otherwise eligible member of the submitting company can write EXPLANATION or NA_REQUEST in IN_REVIEW or CHANGES_REQUESTED. This permits a clarification after return without reopening the review.
- Reviewer mode takes precedence if an eligible current reviewer belongs to the submitting company. They cannot post in author mode at that step.
- Other reviewers cannot write a reviewer note before their step or after it has passed. Eligible source-company members may still act in author-response mode when they are not the current reviewer; this is not a reviewer signature.
- No writes to approved, superseded or archived versions. Existing notes remain readable subject to normal project access.
- Unsubmitted versions and legacy runs without a captured snapshot have no captured finding to annotate.
- A corrected version does not inherit the old discussion. Its own thread becomes available after its new submission; old notes remain with the old run.
- Notes do not advance stages, add signatures, change reviewer eligibility/authorship, alter findings or replace formal decisions. Existing independent-signing rules still concern definition/correction-chain authors; note writing is not automatically reclassified as source authorship.

Per-family review policies and captured workflow versions remain authoritative. Future policy settings do not silently give someone authority over an existing run. No generic document-review permission or new cross-family authority is inferred.

## Storage and implementation

Migration 54: `2026_09_29_210000_add_material_finding_responses.php`.

`material_finding_responses` stores immutable note UUID, snapshot run/project, actual actor/company/name, actor kind, applicable reviewer stage, finding index/key/code/field, per-finding sequence, request token, kind, message and citation/excerpt. A composite FK binds to the existing captured run/project. The table has forced RLS, participant reads, actor-company/project-writable inserts and **no UPDATE/DELETE policies**.

The SQL trigger independently checks finding identity/code/field, current active membership and PIPING scope, source-company/current-reviewer role, captured selector/stage, author-chain and prior-signer exclusion for reviewer mode, returned/completed/superseded state, and assigns sequence and actor-name snapshot. Source length/nonblank and N/A excerpt conditions also have DB checks. Application identity comes from the authenticated user; DB checks validate that claimed actor's eligibility within the existing company-scoped RLS architecture, not a new per-user database authentication scheme.

Writes use the same project advisory lock as decisions/corrections. A unique `(project_id, created_by, request_key)` prevents duplicate notes on repeated form submission; it does not merge or overwrite notes. Sequence is assigned under the lock, per captured finding.

`MaterialFindingResponses` provides the authorized dashboard and append operation. The controller uses a dedicated nested `finding_response[...]` payload to isolate note validation/old input from the declaration form. A malformed nested field is rejected without breaking page rendering or prefilling unrelated material fields. Server-owned attribution/role/stage/sequence cannot be set through the request.

New endpoint under existing authenticated, tenant-bound, CSRF-protected routes:
`POST /projects/{projectCode}/piping/project-fittings/{definitionId}/finding-responses`.

GET only reads notes and renders fresh submission tokens; it creates no audit records. The historical panel now combines fixed captured findings with a separately labeled append-only discussion. Its whole rendered text is therefore not immutable; the underlying captured `result_json` and result hash remain immutable and are tested as such.

Migration is forward-only. No existing declarations, policies, runs, decisions or screening evidence are rewritten; no old discussion is fabricated. Retention/deletion tooling must respect restrictive audit-history FKs.

## Current verification

- **123 targeted PHP tests / 1,218 assertions passed**. Includes **15 new tests / 100 assertions** (14 response tests, one populated migration test); these counts are not additive.
- Covers source/snapshot/review-state preservation, quoted N/A proposals, forbidden acceptance kind, current reviewer/stage authority, frozen policy paths, same-company independent reviewer mode, revoked role/account/discipline, returned and corrected versions, approved/archive lock, duplicate requests, append-only corrections, immutable/tenant-hidden records, forged identity/role/sequence, finding/run/key binding, raw SQL finding/stage/self-review/excerpt rejection, bilingual escaping and scoped malformed form input.
- Migration test starts at schema 53 with an approved declaration and captured evidence, applies 54, compares all five pre-existing history records byte-for-value, confirms no fabricated notes and shows approved history without write controls.
- Initial browser-facing tests exposed an implementation bug: Laravel `collect($paginator)` included pagination metadata rather than just records. Dashboard normalization was corrected to iterate model records. Final tests pass; no weakened assertion.
- Screening core **60 assertions**, **21 Python reference tests**, all **3 project + 16 reference packs** passed. Nine changed PHP files lint clean. Reference data and engineering rules were not changed.
- Actual three-actor browser scenario exercised N/A quote validation, author clarification, assigned consultant note, return, correction without inherited notes, captured policy isolation, new-version consultant and owner notes, and separate final approval. Captured result hashes did not change.
- Final scoped-payload browser run additionally posted a new author N/A proposal and current-reviewer note to an open synthetic run, with unchanged review state/result hash.
- Final **24 page cases** (six families × FA/EN × 1440/430) and **four active mobile form cases** (author/reviewer × FA/EN) passed: HTTP 200, correct direction/options, no horizontal overflow/JS errors and unobstructed submit buttons. Expanded histories also checked. Fresh mobile capture with animations disabled avoids an intermediate desktop-to-mobile sidebar transition obscuring a screenshot. Persian active form and history visually inspected.

No full PHP-suite rerun, concurrency/load test or comprehensive production security/engineering certification claimed.

Evidence in `/home/user/organization-report/`: `finding-response-final.{log,xml}`, `finding-response-core.log`, `finding-response-reference.log`, `finding-response-packs.log`, `finding-response-browser/results.json` (initial three-actor phase), `finding-response-browser/final-results.json` (final nested payload and 28 display cases), screenshots named `final-active-*`, source manifest/archive and lint/preflight logs. Browser write fixtures refuse repeated execution; do not rerun them over existing evidence. Earlier counts and intermediate scripts are historical, not a replacement for final test evidence.

## Current runtime/demo

Dependencies/PostgreSQL were absent and rebuilt. Current user-authorized disposable demo initialized; PHP tests use separate `epcc_test` under non-superuser/NOBYPASSRLS role. `.env` unchanged; vendor remains an excluded-cache symlink.

Current DB: **54 migrations, 7 synthetic definitions, 2 PIPE policies, 3 runs, 3 decisions, 3 captured screening records, 7 finding-response records**.

- Original PIPE: returned/superseded, three notes retained (N/A proposal, clarification, consultant note).
- Corrected PIPE: approved independently under captured two-stage v1, two reviewer notes retained. Latest policy v2 remains one owner stage for future submissions.
- Additional `SYNTHETIC-OPEN-FINDING-DISCUSSION`: in review under v2, one author proposal and one current-reviewer note. This deliberately open record makes the new controls available in the demo without reopening old approvals.
- FLANGE/VALVE/GASKET/BOLTING roots remain unreviewed/unconfigured. All examples are QA-only, not engineering instructions or construction authorization.

PG: `project-database-3d7885a3`. App: `epcc-platform-665a5e9f`, 0.0.0.0:8000, APP_DEBUG=false. Local-browser success is not independent external Arena gateway verification.

## Boundaries still pending

Formal disposition/acceptance or rejection of N/A requests, exception authority and blocking approval policies; authenticated document/attachment links; post-approval addenda; pagination/retention for very large discussions; full historical reference/code replay; broader verified engineering checks; explicit PMS selection/conflict resolution/consumption; audited review rerouting/withdrawal/revocation; unchanged-version resubmission; large-cap agreements and two-size fitting declarations.

This increment does **not** change B16.9 ½–48 scope, infer missing dimensions, modify original Spec revisions, weaken independent approval, or apply any data to PMS/model/MTO.
