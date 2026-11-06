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

## Dedicated capture authorization (2026-10-15)

The interim `project.view` write guard has been replaced by the atomic
`engineering.report.capture` capability in the core module. Demo roles are
explicitly granted capture so employer, contractor, QC, consultant and
management-contractor access arrangements can record their own scoped reports;
project membership, PIPING discipline scope, plan and same-company request
checks still apply. A view-only role is refused. This capability is **only**
for capture; it grants no issue, signature, external verification, NCR closure
or disposition right. Before exposing routes, agree project-specific authority
and audit/denial UX. This correction supersedes the earlier paragraph that
called `project.view` an interim guard.

## Freshness at internal draft creation (2026-10-15)

The schedule-report service now locks the component/line and re-resolves the
current issue-time PMS rule before creating an **IN_SYSTEM** TQ/NCR draft. A
request whose actual schedule or snapshot no longer matches the current pipe,
or whose expected schedule was forged through direct SQL, is refused. A report
already issued and signed *outside* the application may be registered later
against the historical request even if the component subsequently changed;
that registration is only metadata, labelled stale by the model view, and
still needs independent verification and applicable disposition. It must not
be used to approve the component's new value. This is not a bypass around
report issue/signature authority.

## Report capture from the model page (2026-10-15)

For an existing own-company schedule discrepancy, the line model now exposes
CSRF-protected capture forms: create an **unissued internal draft** on a
current request, or register **metadata only** for an externally issued/signed
TQ/NCR, including report number, issuer, signatory/capacity, dates and external
register reference (optional revision, no file requirement). The controller
checks the report request belongs to the line in the URL; the service checks
capture capability, project scope and archive status. One in-system draft per
request/report kind is permitted, serialized by a request-row lock. Stale
internal drafts are refused while retrospective external registrations remain
historical, never verified. No route issues, signs, verifies or disposes of
a report; neither mode grants a release. Report fields supplied by a user are
unverified claims until project-authorized verification is implemented.

## Read back external report claims (2026-10-15)

The model history displays the captured external issuer, signatory and
capacity, issue/signature dates, register reference, optional revision, plus
system recording time and recorder ID under a collapsed **unverified external
claim** heading. This enables review of what was actually recorded without
confusing the external signatory with the user who typed the metadata.
Displaying a claim does not verify a signature, issue a report or provide a
contractual disposition. Blade escapes user-supplied field values.

## Whitespace-only metadata is invalid (2026-10-15)

External claim text is trimmed *before* required/min-length validation.
Whitespace-only required fields now return field-level validation errors
rather than failing the database CHECK and surfacing as a server error. A
whitespace-only optional revision normalizes to null. Validation remains
separate from external-report verification and cannot authenticate a claimed
signature or document reference.

## Capture acknowledgement is not approval (2026-10-15)

After a successful model-page POST, the user sees a one-request flash
acknowledgement specific to the chosen mode: internal draft **not issued or
approved**, or external metadata **report and signature not verified**. Field
errors remain separate and no success acknowledgement is set on a failed
capture. The feedback is informational and is not an audit decision.

## A project-level register that closes the inspection loop (2026-11-03)

The register that exists is **schedule-specific** (`pipe_schedule_discrepancy_reports`,
2026-10-15): it hangs off a pipe-schedule exception request and nothing else. That
leaves the loop open at exactly the point the platform now has a gate for: a release
note can refuse a purchase order line, and the refusal blocks receipt — but it starts
no process. The refusal is a dead end, so the same material comes back on the next
truck with no record of why it was sent away.

So this slice generalises the register to the project, keeping the two provenance
modes and the discipline that made the first slice correct.

### Decisions

- `register_scope = project`: ONE register, `project_discrepancy_reports`, instead of
  one per source. A discrepancy is a project fact; only its *source* varies.
- `source = exactly one, enforced`: a row points at exactly one of a purchase order
  line, a component, a pipe-schedule request, a test pack, or a release note. A CHECK
  requires at least one and the guard requires that whichever is set belongs to THIS
  project. Polymorphism without a project check is how one tenant's NCR starts
  blocking another tenant's line.
- `state = derived_from_events, never declared`: `project_discrepancy_events` is
  append-only and carries RAISED, ANSWERED (TQ), DISPOSED (NCR), CLOSED and
  EXTERNAL_VERIFIED. Status is read off the events. A column saying "CLOSED" that no
  event supports is a claim, not a record.
- `provenance = two shapes, never mixed`: the same non-overlapping IN_SYSTEM /
  EXTERNAL_REGISTER shapes the schedule slice established, carried over unchanged.
  An externally registered report is still not described as issued or signed by the
  application.
- `gate = unresolved holds the line`: `epcc_po_release_status` returns BLOCKED when a
  TQ on the line has no ANSWERED event, or an NCR has no DISPOSED event — on top of
  the release-note rule. This is the sentence already in this ADR: unresolved matters
  stay on hold at agreed issue/procurement/fabrication gates. It does NOT override a
  release: a release says the material may move; a disposition says the discrepancy is
  answered. Both are needed, and either one missing holds the line.

### Acceptance list

- A report cannot reference another project's line, component or pack (guard raises
  `DR_SOURCE_NOT_FOUND`).
- Both provenance shapes are enforced by CHECK, not only by the service.
- Events are append-only: UPDATE and DELETE touch zero rows.
- A TQ with no answer, and an NCR with no disposition, keep the purchase order line
  BLOCKED; a disposition that arrives later clears it.
- RLS: the recording company writes its own; project participants read.
- A company outside the project is refused, and tenant/company-scope denial is tested.

### Deliberately NOT built in this slice

- No in-system issue, routing or signature workflow, and no signatory matrix. An
  authenticated click is still not a binding electronic signature.
- No independent external-verification *authority*: who may verify an external record
  is still an open question in this ADR and is not answered here.
- No file storage and no mandatory hash.
- No migration of the existing schedule-specific register. The two coexist; this one
  is the general one, and folding the old one in is a later, separate decision.
- No claim that a captured external record is authentic. Unverified is unverified.
