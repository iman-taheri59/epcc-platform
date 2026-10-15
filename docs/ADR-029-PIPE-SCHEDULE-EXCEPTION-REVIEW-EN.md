# ADR-029 — Reviewed exceptions to issued PMS pipe schedule

Status: **Accepted requirement, staged implementation** (owner decision 2026-10-15). This ADR records both what exists and what is still blocked.

## Decision

A PIPE component whose schedule differs from the line's issue-time PMS rule may be recorded as an **exception only with a reason, an independent approver, and a traceable history**. A difference by itself is never approved. A line's PMS pin is immutable; a later PMS revision must not retroactively change the expected schedule for existing lines. The comparison in the modelling UI remains a warning, not an approval.

## Step 1 — append-only audit structure (implemented)

`pipe_schedule_exception_requests` captures project/company, component, issue-time snapshot ID, expected and actual schedule, reason, requester and timestamp. `pipe_schedule_exception_decisions` captures one APPROVED/REJECTED decision per request, independent actor, note and timestamp. SQL constraints require a substantive nonblank reason, actual != expected, a PIPE belonging to the same project/company and line pin, an active same-company requester and a distinct active same-company decision actor. Row-level security restricts reads to project participants and writes to the actor's own participating company on a writable project; UPDATE/DELETE of both tables are rejected by PostgreSQL triggers. Existing records survive superseding PMS issues. No UI or approval service is exposed yet, so neither a mismatch nor a raw request is currently treated as approved.

## Next steps / safety boundary

The application service must independently recompute expected schedule from the pinned snapshot (never accept a client-supplied expectation), verify current component size/schedule/pin under a transaction and serialize competing submissions. It must authorize a named reviewer with active project/PIPING membership and an explicit approval capability, prevent self-approval, and recheck that the request is still applicable at decision time. Approval should be a separate immutable event, not a mutable boolean on the component. Do not infer reviewer authority from `project.owner_company_id` or silently allow cross-company writes: current `piping_lines` and components RLS WITH CHECK binds row `company_id` to tenant. The owner should decide whether the reviewer must belong to the component's company or whether a formally delegated cross-company approval is needed before the latter is implemented. Database role separation is needed to stop a direct SQL client with full application-role credentials from forging reviewer evidence; the current trigger is not a substitute for that boundary. Downstream MTO/procurement must not treat this schema alone as approval.

## Verification

Feature assertions cover immutable reason and rejection of self-approval; execution awaits a real Laravel/PostgreSQL/RLS runtime. PHP lint and whitespace checks are not DB verification. Existing CI is billing-blocked. Do not claim the workflow is operational until reviewer authorization, application API, and DB tests pass.

## 2026-10-15 scope correction from owner

See ADR-030: exception review is not merely an independent actor plus a free-text
reason. Valid disposition requires proof under a project-applicable standard
or specification/procedure and its stated conditions, or a formally answered
TQ and subsequent disposition; otherwise NCR handling applies. A TQ/NCR
number or issuance alone is **not acceptance**. The current request/decision
tables do not yet encode this proof, so **do not expose their APPROVED row as
an effective override**, and do not connect it to AFC/MTO/procurement gates.

Owner clarification: the responsible line company must sign even if its users
cannot access the licensed application. `company_id` on existing request/
decision rows is an RLS tenant attribute, **not verified legal responsibility
or a valid offline signature**. See ADR-030 for a proposed external signer or
independently verified offline attestation, with separate signatory, uploader
and verifier identities. Do not mistake the same-company SQL constraint in
this initial skeleton for a complete multi-party approval workflow.

Offline signed evidence is now an owner-accepted route (ADR-030) and has
append-only **candidate metadata** tables. It remains separate from the
same-company ADR-029 decision: uploader, external signer and independent
verifier are distinct identities. These tables do not certify the file,
responsible-party authority or engineering disposition by themselves.

ADR-031 clarifies two provenance modes: an in-system TQ/NCR lifecycle with
internal issue/signing, or metadata-only registration of an already issued/
signed external report. The candidate external signature table does not itself
implement either TQ/NCR route. Optional file hash in migration
`2026_10_15_120000` corrects an overly strict assumption; external report
metadata may be registered without a file.

## Scoped discrepancy-request capture (2026-10-15)

`PipeScheduleExceptionRequestService::submit()` now captures a PIPE mismatch
from the locked component/line and **recomputes** the expected schedule from
the line's immutable issued PMS snapshot at its DN. Clients cannot provide or
forge the expected value, snapshot or actual schedule. The service requires
an active, entitled project/PIPING member with
`engineering.report.capture`, the same-company component/line, a substantive
reason, a writable project and a distinct actual schedule; it refuses an
identical unresolved request. The request is evidence of a discrepancy,
**not** a sign-off, approved exception, TQ answer or NCR disposition. It has
no public HTTP route yet. PostgreSQL feature assertions are added but have
not been executed locally.
