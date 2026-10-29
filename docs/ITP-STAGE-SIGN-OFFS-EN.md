# ITP Hold / Witness Stage Sign-offs — ADR-048 A.4d slice 1 (EN)

> Status: implemented service-only, 2026-10-29, commit `d7d5ce8`.
> Persian twin: [`ITP-STAGE-SIGN-OFFS-FA.md`](ITP-STAGE-SIGN-OFFS-FA.md).
> Decision record: [`ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md) (ACCEPTED, decision 3).
> Sibling guides: [`INSPECTION-RESULTS-EN.md`](INSPECTION-RESULTS-EN.md) (results), [`ITR-COMPILER-EN.md`](ITR-COMPILER-EN.md) (dossiers).

## The gap this closes

An ITP notification records that a stage *was notified*: who was asked to attend, when,
where (ADR-042…045). It never recorded what happened at the stage. So in the data:

- a hold point nobody attended, and
- a hold point the client's inspector attended and accepted,

looked **identical** — both are simply "a notice was issued". The one thing a quality
record must never lose is the difference between those two, because the first is a
missing control and the second is evidence. This slice adds the record that says what
actually happened at the stage.

## What was added

| Piece | What it is |
|---|---|
| `inspection_stage_signoffs` | append-only, RLS + FORCE, forward-only migration. One row = one company's position on one stage of one notification. |
| `epcc_guard_stage_signoff()` | BEFORE INSERT trigger: derives the stage and its point, resolves and stamps the authorising nomination, enforces reason pairing, holds the employer's hold point, validates correction lineage. |
| `epcc_reject_stage_signoff_mutation()` | UPDATE/DELETE are refused (`55000`): a correction is a *new* chained sign-off, never an edit. |
| `epcc_stage_signoff_insert_path()` | Refuses inserts nested inside another trigger (`SIGNOFF_MANUAL_DIRECT_ONLY`), so the record cannot be written as a side effect of something else. |
| `stage_read_for_notified_parties` | Additive SELECT policy on `inspection_itp_stages` — see "The visibility change". |
| `ItpSignOffService` | `signOff()`, `signOffsFor()` (oldest first), `latestPerCompany()` (current position per company), bilingual field errors. |

Session contract, unchanged from the results slice: project GUC + company GUC +
`epcc.result_actor_id`. The service is deliberately thin — it shapes input and turns
database refusals into bilingual messages; every rule lives in the database.

Outcomes: `ACCEPTED`, `ACCEPTED_WITH_COMMENTS`, `REJECTED`, `WAIVED_NOT_ATTENDED`.

## Invariants (all in the database)

1. **Derived, never declared.** `stage_id` and `point` are taken from the notification's
   own stage by the guard. A caller cannot state them, so a sign-off cannot quietly
   answer a witness point when the stage is a hold point.
2. **Default-deny authority.** Signing requires a `result_recorder_policies` nomination
   for the new action `sign_off_stage` (the same register A.4b introduced), and the
   authorising policy is stamped on the row as `signer_policy_id`. Without a nomination
   nothing is written (`SIGNOFF_AUTHORITY_NOT_NOMINATED`).
3. **Only an ISSUED notice can be signed.** A draft is not yet a request for attendance
   (`SIGNOFF_NOTIFICATION_NOT_ISSUED`); a notice you cannot see is simply not found.
4. **An outcome that changes the work must say why.** `REJECTED` needs a cause,
   `WAIVED_NOT_ATTENDED` needs why nobody was there, `ACCEPTED_WITH_COMMENTS` needs the
   comments — and a plain `ACCEPTED` carries none. Enforced by CHECK, so it holds for
   direct SQL too.
5. **A HOLD ('H') point can be waived only by the project employer.**
   `SIGNOFF_HOLD_WAIVER_EMPLOYER_ONLY`. A contractor waiving the client's hold is not a
   waiver — it is the deletion of a control the employer owns. Witness ('W'), review and
   monitor points can be waived by the notified party.
6. **Corrections chain, they never edit.** UPDATE/DELETE are refused; a correction is a
   new row whose `previous_signoff_id` must point at a **REJECTED** sign-off of the same
   notification and company. The rejection stays on the record.
7. **Idempotency is real.** `request_id` is unique, so a retried POST cannot double-sign
   (`stage_signoff_request_unique`).
8. **Append order is the record.** `recorded_at` is `timestamptz(6)`: two sign-offs
   recorded in the same second are still ordered, because the order in which they
   happened is exactly what an auditor walks through.
9. **Nobody signs in another name.** The guard requires `signed_by` = the session actor
   and `company_id` = the session company (`SIGNOFF_AUTHORITY`), and only a project
   member company can read the record.

## The visibility change, and why it is not a hole

Reading an ITP stage was gated on read access to the ITP **document revision**. A company
that had been formally notified of a stage — the notice names it — still could not see
that stage, and therefore could not sign it. The guard hit `SIGNOFF_STAGE_NOT_FOUND` for
exactly the parties the notice was sent to.

The fix is additive, not a bypass: a new permissive SELECT policy lets a **project
member** read a stage that **a notification it can already read** refers to.

- It leaks nothing new: the notice already names the stage and the notice is already
  readable by that company.
- It opens no write path: SELECT only.
- It is not a `SECURITY DEFINER` function. `FORCE ROW LEVEL SECURITY` is on, so a definer
  function would not have bypassed RLS anyway — the rule had to be stated as a rule.

## Declared boundaries of THIS slice (honesty first)

- **Sign-offs are RECORDED, not yet GATED.** There is no notification↔test-pack link, so
  a test pack can still be accepted without a hold sign-off. That gate is tracked as
  **A.5b** (which also owns the ITR binding), not claimed here.
- **No HTTP/UI.** Service-only, as with every slice so far; routes, forms and the
  walk-down view are A.4e work.
- **A sign-off is an attributed company decision, not a wet signature.** It records who,
  for which company, under which nomination, at what time, with what outcome. There is no
  signature image, no e-signature certificate and no approval workflow.
- **History is allowed.** Nothing limits a company to one sign-off per stage; the current
  position is `latestPerCompany()`, and the full chain is the audit trail.
- **Forward-only migration.** `down()` throws: quality records are not un-recorded. The
  pre-A.4d upgrade fixture in `InspectionNotificationTest` drops this table and the
  additive policy before it re-runs the original notification migration.

## Tests

- `tests/Feature/ItpSignOffTest.php` — 11 tests / 48 assertions, green twice (the sandbox
  produces flaky process crashes; a single red run is re-run before it is believed).
- Related regression green together — 245 tests / 2198 assertions: `InspectionNotification`,
  `InspectionResult`, `ItrSnapshot`, `ResultAuthorityAndGating`,
  `ResultUnitsAndPolicyAuthorship`. Per the owner's 2026-10-28 instruction the full suite
  is not run per change; it runs in CI on every push (RUNBOOK §12).
- Notable fixture facts a future test must respect: only the issuing company and the
  notified recipients can see a notice, so a party that must sign has to be on the
  recipient list; and notification settings are versioned, so a fixture that re-issues
  must bump the version.
