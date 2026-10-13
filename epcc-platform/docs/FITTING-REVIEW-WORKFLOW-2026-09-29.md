# Configurable project fitting review and audited corrections

Date: 2026-09-29 (user-local date).

> Later same-day extension: see [PROJECT-MATERIAL-REVIEW-2026-09-29.md](PROJECT-MATERIAL-REVIEW-2026-09-29.md). The workflow now also supports five other material families with independent policy scopes. Fitting behavior remains; runtime/counts in this older report are historical.

## User decision

The user selected **project-configurable review authority and stage order**. Do not hard-code an employer-only or consultant-then-employer workflow. Until a project configures a workflow, submission and approval are blocked.

## Delivered user workflow

The existing project → piping → project-defined fittings page now includes:

1. Project workflow configuration, one to five ordered stages. Each stage names an individual, or a role scoped to a specific participating company. Only active PIPING-eligible project members are offered. Configuration requires an employer-company member with `project.configure` authority, checked through the existing authorization pipeline on save.
2. Submission of a creator-company definition for review. Submission captures an immutable policy revision; changing the project's policy only affects later submissions.
3. Ordered APPROVE or RETURN decisions with mandatory comments. An assigned reviewer must still be active, participating, a member and within PIPING scope when signing. Role assignment is rechecked at decision time.
4. Corrections that append a new definition with predecessor/root IDs and a mandatory reason. Only members of the creator company may correct; the original values and prior decisions remain intact. Internal corrections do **not** automatically alter the source Spec revision.
5. Derived status display: UNREVIEWED, IN_REVIEW, CHANGES_REQUESTED, APPROVED and SUPERSEDED, with stage history, signers, policy version and predecessor/successor references.

Every stage requires a distinct signer. **All authors in the correction chain are excluded**, not merely the latest author. The service checks that enough eligible independent people exist before submission, and that a signature does not leave the remaining steps impossible to staff. It never substitutes the author or a previous signer for a missing reviewer.

A return ends that review cycle; a corrected version needs a new submission and fresh decisions. Correcting an approved definition also creates an unreviewed replacement: approval is not inherited, and the prior version becomes superseded while its historical signatures remain visible.

## Data architecture and invariants

Migration: `2026_09_29_090000_add_project_fitting_review.php` (51st application migration).

- Definition rows remain immutable. New nullable lineage/reason fields leave existing rows unchanged. Original root declarations retain the source-key uniqueness constraint; correction rows can retain the same Spec revision and source key. A unique predecessor reference prevents branching corrections.
- `fitting_review_policies`: immutable per-project configuration versions and selector/label snapshots.
- `fitting_review_runs`: one immutable submission per definition version, referencing the policy revision used at submission.
- `fitting_review_decisions`: immutable, stage-numbered decisions, unique per stage and signer within a run.
- All three tables have forced RLS, explicit project scope, participant reads and actor-company insert attribution. There are no UPDATE/DELETE policies. Composite FKs stop cross-project review references and cross-company correction lineage.
- The PostgreSQL `epcc_guard_fitting_decision` trigger independently guards stage order, closed/returned/superseded reviews, author-chain separation of duties, prior signers, current role/individual assignment, account activity and discipline scope. Service checks additionally enforce configuration authority and remaining-reviewer feasibility.
- Workflow writes are serialized per project using a transaction-scoped advisory lock, not FOR UPDATE on tables intentionally lacking update policies. Posted stage numbers and unique keys reject stale/replayed decisions. Corrections use the same lock as decisions.
- Inner savepoints preserve tenant-context cleanup on SQL errors. All server-owned identifiers, states and lineage are assigned by services rather than trusted from POST fields.

**Important consumer contract:** the old `project_fitting_definitions.review_state` remains the immutable initial UNREVIEWED marker. Current approval is derived from the review events and successor state in `FittingReviewService`, not from that legacy column. No application code should treat a manually supplied APPROVED field or a stale historical decision as current approval.

## Scope boundaries

This implements review of project-specific declarations, not ASME certification. Universal B16.9 reference data is unchanged. No approved declaration is automatically applied to PMS, piping, MTO or cut lengths. Explicit reviewed-selection/consumption is the next implementation boundary.

Not built in this increment:

- Automatic detection/reconciliation of conflicting independent declarations or choosing a winner.
- Workflow rerouting, withdrawal, approval revocation, or resubmission of an unchanged version. If an individually assigned reviewer becomes unavailable, the run blocks; it does not silently adopt a newer policy. A deliberate audited recovery workflow is future scope.
- Manufacturer/purchaser agreement capture for in-range large caps; two-size fitting definitions.
- Digital-signature certificates or external notification delivery.

The migration is intentionally forward-only: rollback refuses to silently destroy review history. A deliberate backup restore or disposable-demo reset is required instead. Project deletion/retention tooling must account for the new restrictive audit-history FKs before deleting a reviewed project; archival remains supported and blocks writes.

## Verification

- Final combined PHP run: **78 tests / 822 assertions passed**, no failures/errors/skips. Includes **14 new workflow tests / 76 assertions**; counts are not additive. Covers review, existing registration, B16.9 scope/caps/reader and PMS body/compliance.
- New tests cover missing configuration, stage order, self-approval and author lineage, independent staffing, policy snapshots, invalid/unauthorized configuration, role revocation, returned/approved corrections, immutable history, raw SQL forged signatures, wrong-project actions, archived projects and FA/EN HTTP flows.
- Nine changed/new PHP files passed syntax checks.
- **21 Python reference regressions passed**, all **3 project packs + 16 reference packs** valid.
- Actual Chromium flow passed: configure two stages → author submits → consultant returns → author corrects while keeping Spec revision A → submits new version → policy v2 configured → consultant and employer complete the submitted v1 run. Policy v2 did not remove the pending consultant stage.
- Four final page cases: FA/EN × desktop/mobile (1440/430), HTTP 200, correct text direction, no root overflow, no recorded JS page errors. Persian mobile screenshot visually inspected.
- No full PHP-suite rerun, concurrency load test or comprehensive production security audit is claimed.

Evidence under `/home/user/organization-report/`: `fitting-review-final.{log,xml}`, `fitting-review-reference-tests.log`, `fitting-review-packs.log`, `fitting-review-browser/results.json` and screenshots. Browser write script requires `EPCC_ALLOW_DEMO_WRITES=yes`; do not rerun it over existing evidence.

## Current demo/runtime

Runtime dependencies and PostgreSQL state were absent at entry and were rebuilt; disposable-demo reset was already authorized by the user. Demo has **51 migrations**, **two linked synthetic definition versions**, **two policy versions**, **two review runs**, and **three decisions** (one return, two approvals). All are clearly marked synthetic; none is an engineering recommendation or construction authorization.

In PH-14, the latest demo policy (v2) has one named employer stage. The corrected test definition is approved under its previously captured two-stage v1 policy; this intentional difference is the snapshot acceptance evidence, not a display defect.

Keep PostgreSQL process `epcc-caafc8aa` and application `epcc-f904a537` alive. App is on 0.0.0.0:8000 with runtime debug disabled. Current local-browser acceptance does not resolve the previously recorded external Arena gateway verification limitation. Demo accounts/password remain unchanged.
