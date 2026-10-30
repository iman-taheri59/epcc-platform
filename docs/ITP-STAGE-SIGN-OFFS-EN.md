# ITP Hold / Witness Stage Sign-offs — ADR-048 A.4d slice 1 + A.4h slice 2 (EN)

> Status: service implemented 2026-10-29, commit `d7d5ce8`; **screen implemented
> 2026-10-30, commit `24a1374`** (A.4h slice 2 — see [The surface](#the-surface-admittedly-late)).
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
- **No HTTP/UI** — *closed by A.4h slice 2, see below*. At the time of writing
  this was service-only, as with every slice so far.
- **A sign-off is an attributed company decision, not a wet signature.** It records who,
  for which company, under which nomination, at what time, with what outcome. There is no
  signature image, no e-signature certificate and no approval workflow.
- **History is allowed.** Nothing limits a company to one sign-off per stage; the current
  position is `latestPerCompany()`, and the full chain is the audit trail.
- **Forward-only migration.** `down()` throws: quality records are not un-recorded. The
  pre-A.4d upgrade fixture in `InspectionNotificationTest` drops this table and the
  additive policy before it re-runs the original notification migration.

## The surface (admittedly late)

On 2026-10-30 the sign-off got a screen: `/projects/{projectCode}/quality/sign-offs`
(`quality.sign-offs`, plus one POST), served by `ItpSignOffController` over a new
`ItpSignOffService::dashboard()`. It is the third quality surface and follows the rule
the first two established — **the screen is built around the register and the guards,
not around the forms.**

Two refusals are therefore built into the screen rather than left for the submit,
because both would otherwise be an inspector filling in a form to be told afterwards
that the answer was always no:

| Refusal | Why it is decided before you type |
|---|---|
| **No nomination, no form.** `sign_off_stage` is asked of `epcc_result_nomination()` — the function the guard calls — and where the answer is null the screen says so in place of the form. | Same argument as slice 1: default-deny *discovered* on submit is still default-deny, but it wastes the inspector's time and teaches them that the form lies. |
| **A HOLD waiver is the employer's alone.** On a point of `H`, `WAIVED_NOT_ATTENDED` is not offered to a contractor at all. | A contractor waiving the client's hold is not a waiver; it is the removal of a control the employer owns. Offering the option and refusing it on submit would be that removal, politely packaged. |

The employer is still subject to the same rule everyone is: a waiver must say why
nobody attended, and the guard refuses one without a reason.

**The worklist is whatever RLS lets you see.** A notice is visible to the company that
issued it and, once issued, to the companies it was sent to — so the screen shows
exactly "the stages you were asked to attend" without restating that in PHP. Stating it
twice would be two copies that can drift; here the database is the only copy. A cancelled
notice drops off the list, because a withdrawn request is not a question anybody is
answering any more.

**A correction is offered only where it can succeed.** After a rejection, the form offers
your own company's rejections on that notice as the target of a chained sign-off — and
nothing else, because `previous_signoff_id` must point at a REJECTED sign-off of the same
notice *and* company. Offering the rest would be offering a refusal.

**What the screen reads** (`dashboard()`): the worklist with each stage's point, the
selected notice, the full sign-off history, the current position per company
(`latestPerCompany()`), the rejections a correction may chain to, this actor's
nomination, and whether this actor's company is the project's employer. A non-member of
the project is refused at the door (403), as on the other two surfaces.

## Tests (A.4h slice 2)

- `tests/Feature/ItpSignOffHttpTest.php` — 13 tests / 54 assertions, all signing through
  HTTP: the worklist shows the notice addressed to your company and hides one that was
  never sent to you; nomination gates the form; a nominated signer signs and the point
  comes back **derived** (`W`), not as submitted; signing without a nomination writes
  nothing; a rejection without a cause is refused; a correction chains to the rejection it
  replaces and leaves both rows standing; the same `request_id` is refused rather than
  double-signing the stage; a contractor is offered no waiver on a HOLD point and cannot
  post one either; the employer may waive a hold, but not without saying why.
- Related regression green together — 84 tests / 368 assertions: `ItpSignOff`,
  `InspectionNotification`, `InspectionResultHttp`, `ResultRecorderNominationsHttp`,
  `TableTools`, `CookielessPreview`.
- `tests/Support/QualitySignOffFixture.php` builds an issued notice through the real
  services (register an ITP document → add a stage with a point → configure who may draft
  and issue → create the draft → issue it). Two things it deliberately does not hide:
  the recipient list is a **required argument**, because visibility is the rule the screen
  is built on; and the notification settings row is **versioned**, so the version is read
  from the table rather than counted in the fixture.
- **A bug worth recording for the next screen**: `view($data + ['outcomes' => $filtered])`
  keeps the LEFT operand's value for a key present in both arrays, so the HOLD filter was
  silently discarded. `array_merge($data, [...])`, never `+`.
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
