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

## Owner clarifications (2026-10-15)

1. The **company responsible for the line must also sign**; other reviewing parties may need to sign per project workflow. The purchased package can grant access only to employer, consultant or management contractor, not to the contractor/line-owning company. Missing a paid interactive account must NOT silently waive the responsible company's signature.
2. A **TQ answer alone is not final acceptance**: record a separate explicit disposition after the answer, with conditions and required sign-offs.

## Access-independent signature model (offline path accepted by owner; workflow pending)

Separate (a) the *legally/contractually responsible organization* for the line, (b) the tenant company that recorded the row, (c) the human who signed, and (d) the person who imported/verified the evidence. `piping_lines.company_id` currently identifies a writing tenant for RLS and must not automatically be treated as proof of the legally responsible line company. Record project-party identity and authority, effective period and scope (e.g. line/discipline), not a made-up signature from the only licensed user. Two acceptable routes, subject to contract:

- A narrowly scoped **external signer** invitation/portal with only assigned discrepancy and attached evidence, short lifetime, identity verification and a tamper-evident decision record, ideally independent of paid full seats. Commercial feasibility and licensing require owner approval; do not assume this feature exists.
- **Offline signed evidence** (formally signed letter, TQ response or approved document) from the responsible organization, uploaded by a licensed user and checked by a *different* authorized verifier against signatory identity, authority, validity, document/version and the exact discrepancy. Record signer organization/person/capacity/date, source document ID/hash, uploader, verifier, verification outcome/time and any withdrawal. Never display the uploader as the signer; an uploaded PDF alone does not establish authenticity or delegated authority.

The remaining employer/consultant/management-contractor decisions are separate signed events under a project-configured authority matrix. Stages/signatories depend on project agreement, not a hard-coded universal order. If the responsible company cannot sign or the signature cannot be independently verified, keep the discrepancy **ON HOLD**; allow substitution only with explicit contractual delegation/novation evidence approved by authorized parties, never because of the subscription tier. For an unresolved TQ/NCR, an external signature is not itself a technical disposition.

RLS design implication: store an evidence/attestation record written by the verifier's **own tenant** with a separate `represented_company_id`/`responsible_company_id` and evidence provenance. Do not change another company's component/line row or broaden its WITH CHECK. A future approval projection may read several cross-company attestation events under carefully scoped project RLS; active signature/role authority must be checked at the time of signing. ADR-029's existing decision row is same-company only and cannot implement this multi-party process; it must not be exposed as final approval.

## Remaining governance questions

1. Which checkpoints block on an unresolved discrepancy (component issue, AFC, MTO acceptance, procurement, fabrication, final handover)? Recommendation: block irreversible/issued gates and show a warning during draft modelling.
2. What is the binding precedence of project contract, PMS, adopted standard and project specification/procedure if they conflict? Record the project-specific order; never hard-code an invented universal order.
3. The owner accepts offline signed evidence as a valid signature route, subject to verification. Limited-access external signing and its commercial terms are still open; neither route is deployed yet.

## Verification plan

Test each route with linked project-applicable revision and clause, condition evidence, independent reviewer authorization, stale/withdrawn evidence, unanswered TQ, open NCR, cross-company RLS denial, superseded PMS, and downstream hold. Run on PostgreSQL with RLS; lint is not acceptance testing.

## First offline-evidence persistence slice (implemented schema only)

On 2026-10-15 the owner accepted an offline signed document as a valid
signature route. The schedule-specific initial evidence tables
`pipe_schedule_external_signatures` and
`pipe_schedule_signature_verifications` store the *claimed* signer company,
person and capacity, signed document number/revision/hash/date, uploader,
independent verifier, decision and verification basis. They are append-only,
project/RLS-scoped and prohibit the uploader from verifying their own evidence.
The signer company differs from the uploader's `company_id`; **neither** is
assumed to be the project's responsible party without separately verified
project authority. The SHA-256 value is metadata only: no file-upload or
hash-to-content binding exists yet, and no service checks signature validity
or authorizes the verifier. A raw `VERIFIED` row therefore cannot approve an
exception or unblock any downstream gate. Future design should generalize the
evidence identity beyond schedule without inventing a universal free-text
approval.
