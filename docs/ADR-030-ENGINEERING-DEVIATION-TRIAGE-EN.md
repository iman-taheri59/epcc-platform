# ADR-030 — Engineering deviation triage and disposition

Status: **Owner requirements accepted; implementation and review authority pending** (2026-10-15). Applies across system-originated discrepancies, not only PIPE schedules. This ADR must not be read as an already deployed TQ/NCR workflow.

## Owner's decision

A discrepancy may be accepted when (1) a project-applicable reference standard, or (2) a project-applicable specification/procedure, explicitly permits it under stated conditions and those conditions are demonstrated. Alternatively (3) issue a Technical Query (TQ) to resolve it. Otherwise issue an NCR (Nonconformance Report). Every exception requires a reason, approver and traceable history (ADR-029).

## Interpretation proposed for review (not yet approved as workflow)

- **Detect versus decide:** detection opens a discrepancy, never an approved exception. Capture source entity, its project/line/class/PMS issue-time pin, expected and actual value, detection time and actor. Preserve the original values even if a later PMS/document revision or component edit occurs.
- **Standards/specification route:** require a versioned project-applicable reference (number, edition/revision, clause), a concrete claim explaining how the clause permits the observed difference, all applicable conditions, and evidence for each condition (calculation, test, inspection or approved document). Referencing a generic code or uploading a PDF is not itself acceptance. Verify the reference's project applicability and contractual hierarchy; lower-level procedures must not silently override a more stringent PMS/contract/code. Independent authorized reviewer records the decision and any conditions/hold points. A citation that does not actually grant an allowance must be rejected.
- **TQ route:** *issuing* a TQ is a request for an engineering ruling, **not approval**. The discrepancy remains open/on hold until the TQ is formally answered by the designated authority and the answer is linked, its scope and conditions verified, and an independent disposition recorded. An unanswered/rejected TQ cannot unlock AFC, fabrication, purchasing or acceptance. A TQ answer may require a controlled PMS/document revision; do not silently rewrite an old line's issue-time pin.
- **NCR route:** where no permissible documented basis or answered TQ supports acceptance, issue an NCR. *Issuing* an NCR is also **not approval**: record containment, disposition (rework/repair/use-as-is/reject, if contract permits), authorized sign-off and verification of closure. Physical nonconformance may warrant immediate NCR and containment even while a TQ is being resolved; do not treat TQ as a way to suppress NCR. Linked TQ/NCR records must be versioned and auditable, not free-text references alone.
- **Fail-closed state machine (proposed):** DETECTED -> UNDER_REVIEW / TQ_PENDING / NCR_OPEN -> ACCEPTED_WITH_CONDITIONS or REMEDIATION_REQUIRED -> VERIFIED_CLOSED. Rejection, changed component, expired/superseded authority, failed conditions, or revoked decision returns the *current applicability* to open/blocked while preserving historical evidence. A recorded approval is scoped to the exact discrepancy + project, line, pinned revision, actual value and conditions; never reusable for another line.

## Gap in the current implementation

The ADR-029 tables hold an append-only reason and an independent decision but **have no reference type/edition/clause, TQ/NCR linkage, condition-evidence check, disposition or authorized reviewer workflow**. Thus their schema alone cannot establish acceptance under this ADR. The existing model-page badge is informational; no downstream gate may regard an `APPROVED` row in those tables as a sufficient engineering disposition. Before exposing an approval API, evolve the evidence model around a common discrepancy identity and add route-specific evidence/decision records rather than a free-form justification string or a generic APPROVED flag. Legacy data is not migrated to assumed approvals.

## Open governance questions

1. Who is the designated approval authority for the three routes (originating contractor, employer, consultant, or an assigned discipline authority), and may it sign off across company boundaries? Current RLS does not grant cross-company writes to components/lines.
2. Is a TQ answer itself sufficient when it explicitly permits use-as-is, or must a separate acceptance decision follow? Recommendation: separate answer from disposition.
3. Which checkpoints must block on an unresolved discrepancy (component issue, AFC, MTO acceptance, procurement, fabrication, final handover)? Recommendation: block irreversible/issued gates and show a warning during draft modelling.
4. What is the binding precedence of project contract, PMS, adopted standard and project specification/procedure if they conflict? Record the project-specific order; never hard-code an invented universal order.

## Verification plan

Test each route with linked project-applicable revision and clause, condition evidence, independent reviewer authorization, stale/withdrawn evidence, unanswered TQ, open NCR, cross-company RLS denial, superseded PMS, and downstream hold. Run on PostgreSQL with RLS; lint is not acceptance testing.
