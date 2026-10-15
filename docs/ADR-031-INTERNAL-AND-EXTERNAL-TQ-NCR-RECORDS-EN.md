# ADR-031 — Two provenance modes for engineering TQ/NCR and signatures

Status: **Owner requirement accepted; workflow not implemented** (2026-10-15).

## Owner decision

The same project discrepancy must support BOTH modes:

1. **IN_SYSTEM**: the TQ or NCR is created, routed, signed and issued within the platform.
2. **EXTERNAL_REGISTER**: the report was issued and signed outside the platform; **only its information is registered** in the platform. The owner accepts offline signed evidence as a valid signature route after verification (ADR-030). Storing/uploading the signed file is optional, not a condition of registration.

## Shared identity and distinct evidence

Both modes refer to the same project-scoped discrepancy and its issue-time source (line, component, PMS snapshot, expected/actual value). Preserve report kind (TQ/NCR), provenance mode, external or internal document number, revision if present, issue/date, issuing organization, current recorded response/disposition, and traceability to the relevant scope. Never mix an internally generated document ID with a user-typed external reference or invent a local issuance date for an already-issued report. An externally registered report is **not** described as issued or signed by the application.

For IN_SYSTEM, require controlled draft/review/issue transitions, project-authorized signatories (including responsible-line-company sign-off), recorded identity/time and immutable issued revision; an authenticated button click is not automatically a legally binding electronic signature without project-approved signing policy. A TQ answer and final acceptance/disposition remain separate events. NCR containment, disposition and close-out remain distinct. Multi-party approval must not be omitted because a company has no paid seat; a restricted external signer or verified offline sign-off can accompany the in-system process.

For EXTERNAL_REGISTER, record the reported issuer, signer organization/person/capacity/date, report ID, available revision, where the original can be checked, who entered the metadata and when, and an independent verifier plus verification basis/time/outcome. A file identifier and SHA-256 digest can strengthen provenance **when a copy is available**, but cannot be mandatory: the source may be an external document-control system or a paper register. Verification without a file must reference the authoritative external register/document and how it was checked; neither free-text metadata nor a supplied hash proves authenticity by itself. Corrections must append a new registration/verification event, not overwrite history.

No report is accepted simply because it has a number, signature metadata or a VERIFIED evidence event. The TQ must have a recorded answer and a separate applicable disposition; the NCR must have an authorized disposition and closure evidence as needed. Standards/specifications still require applicable edition/clause and demonstrated conditions (ADR-030). Unresolved matters stay on hold at agreed issue/procurement/fabrication gates.

## Current implementation boundary

The existing `pipe_schedule_external_signatures` tables are a **schedule-specific candidate evidence slice**, not TQ/NCR registers. Migration `2026_10_15_120000` permits metadata-only external entries (optional file hash and document revision), correcting the prior mistaken mandatory-file assumption. It does not create an external report registry, check signer authority or provide internal TQ/NCR creation, routing, signing or issuing. Existing ADR-029 APPROVED decisions must not unlock the project. Generalize the provenance and evidence model only after the project authority matrix and report lifecycle are agreed. Current `uploaded_by` database column represents the licensed metadata recorder when no file is uploaded; a future schema should name that role unambiguously.

## Open design decisions

- At which stages does each report kind need sign-off from the responsible line company and employer/consultant/MC? Who may verify an external record when the original remains outside the platform?
- What establishes contractual acceptance of in-system electronic signatures, and what external registry/reference is authoritative for metadata-only verification?
- Which irreversible gates must block while a TQ is unanswered or NCR lacks an accepted disposition?

## Verification plan

PostgreSQL/RLS tests must cover both modes independently; externally registered metadata without a file/revision; failed, withdrawn or superseded verification; missing line-owner sign-off; unanswered TQ; NCR without disposition; and tenant/company-scope denial. No successful integration test has run for this requirement yet.

## First typed register slice (2026-10-15)

`pipe_schedule_discrepancy_reports` records a TQ or NCR linked by FK to a
schedule-exception request, with two non-overlapping shapes. `IN_SYSTEM` can
only be inserted as DRAFT without an issue number, issuer or signature;
`EXTERNAL_REGISTER` requires reported number, external issuer/signatory/
capacity, issue/signature dates and external register reference, but accepts
no file and no revision. Database CHECKs, append-only trigger, source-company
integrity, project-scope RLS and archive write guard prevent mode confusion.
This is a **schema-only, schedule-specific first slice**; there is no public
controller/service yet, no in-system issue/signature events, independent
external-source verification, TQ answer, NCR disposition or release gate.
The `EXTERNAL_RECORDED` state means metadata captured, not accepted. Direct
SQL with application credentials can still forge metadata; DB role separation
and authorized API are required before treating it as trustworthy evidence.

## Scoped capture service (2026-10-15)

`PipeScheduleReportService` can record an IN_SYSTEM TQ/NCR **draft** or
EXTERNAL_REGISTER metadata for an already issued/signed report, scoped to an
existing same-company project schedule request. It checks active project
membership, `project.view` capability, PIPING discipline, archive status and
external source metadata before writing under tenant/RLS context. It never
allows a caller to set state, issue an internal report, or claim an external
record has been independently verified. The service is not an HTTP workflow;
its use is currently limited to application code/tests. `project.view` is a
minimal interim capture capability, **not** signing or approval authority;
a distinct issue/verification permission is required before exposing public
report operations. DB-backed feature assertions have not run locally.
