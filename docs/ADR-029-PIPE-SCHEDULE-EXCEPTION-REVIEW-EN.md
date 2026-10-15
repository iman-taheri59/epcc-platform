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
