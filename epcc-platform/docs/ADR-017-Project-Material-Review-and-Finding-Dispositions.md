# ADR-017 — Project material declarations, review and finding dispositions

**Recorded:** 2026-09-29, retrospectively following the user's mandatory ADR instruction.  
**Decision status:** the explicitly listed user decisions below were already confirmed; this record does not create new approval authority.  
**Implementation status:** delivered in earlier increments, migrations 50–55; full architectural conformity is not implied.  
**Related:** [ADR-007](ADR-007-Reference-Data-Engineering-Standards.md), [ADR-008 §12](ADR-008-PMS-Piping-Class-Rules.md#12-pms-entry-and-source-links--reconciliation-2026-09-29), [ADR-013](ADR-013-Project-Backup-Export-and-Deletion.md), [ADR-014](ADR-014-Units-of-Measure.md), [ADR-016](ADR-016-Standards-Library.md).

## 1. Context and confirmed scope

Universal engineering references and project-specific declarations answer different questions. A user-entered Spec value must not alter an ASME reference table, and a project approval must not be represented as standards certification.

The user explicitly required:

1. Keep B16.9 within NPS ½–48; out-of-scope values are user-defined project-Spec data.
2. Review authorities and stage order configurable per project, not a universal employer-only or consultant-then-employer sequence. Apply the material workflow beyond fittings where needed.
3. Completeness/compatibility screening against available standards and project Spec, without inventing unavailable facts.
4. Cited explanations and non-applicability requests with history, without automatic changes to approval rules.
5. Formal dispositions by the independent current-stage reviewer, record-only effect, and documented N/A acceptance with mandatory rationale/citation.
6. Subsequently, explicit PMS integration with both reference-library data and approved project definitions; the consumer boundary is ADR-008 §12, not a new automatic-consumption entitlement.

## 2. Decisions

### 2.1 Separate project and universal provenance

Definitions retain project/company ownership, source document/revision/clause, wording and technical properties. Corrections append a new version with predecessor/root and reason; original versions remain readable. Internal correction does not invent a new revision number for the cited external Spec.

B16.9 standard scope, loaded reference coverage and cap thickness/agreement applicability are separate matters. An in-scope missing reference row is not proof of out-of-standard scope. No declaration is added to a universal reference pack simply because a project approves it.

### 2.2 Captured, configurable review

Implementation has per-project **and material-family** policy versions, ordered selectors and a captured policy per submission. The service permits one to five stages with named persons or company-scoped roles. A family with no configured policy cannot submit/approve; it does not inherit FITTING policy.

Current eligibility is rechecked on signing. Authors anywhere in the correction chain and prior signers cannot act as later independent signers. A RETURN ends that review cycle; a correction needs its own submission and decisions. New policy versions do not rewrite in-flight reviews.

Approval is derived from captured workflow events and successor state. The immutable legacy `review_state` column is not authoritative current approval.

The delivered configuration endpoint uses the existing owner-company `project.configure` boundary. This is a record of the implementation, not evidence that the user selected a new universal policy-administration role. Configuration authority and actual reviewer selection are distinct.

### 2.3 Screening evidence is not approval

For new non-fitting submissions, retain exact checker input/output, rule/reference identifiers and hashes atomically with the review run. Historical runs without a capture are not backfilled with today's result. A corrected definition receives its own capture when submitted.

FITTING remains under its separate B16.9/cap path. Hashes establish stored-byte consistency, not source authenticity, reviewer reading, a digital signature or complete historical replay. Code/reference replay archives were not delivered.

### 2.4 Responses bind to captured findings

An eligible source-company participant may append EXPLANATION or NA_REQUEST; the currently assigned independent reviewer may append REVIEW_NOTE under the delivered lifecycle rules. Each response binds to a captured finding/run/version, with source citation and attribution. An N/A request alone is not an acceptance.

Responses are immutable; later clarification is an additional response, not a rewrite of the earlier note or captured finding. Corrections and new responses do not inherit a formal disposition.

### 2.5 Formal decisions are scoped and record-only

| Response | Permitted formal outcomes |
|---|---|
| EXPLANATION | ACCEPT_EXPLANATION, REQUEST_EVIDENCE |
| NA_REQUEST | ACCEPT_NA, REJECT_NA, REQUEST_EVIDENCE |
| REVIEW_NOTE | Not a formal-disposition target |

Only the independent eligible reviewer of the currently captured stage may record the decision; nobody may decide their own response. Rationale and document/revision/clause are mandatory; ACCEPT_NA additionally requires a nonblank excerpt.

Replacement appends an event naming the actual latest predecessor and a reason. Stale predecessors are rejected. Prior events, original response and captured finding remain unchanged. Returned, closed, superseded and archived versions cannot receive new formal dispositions.

**RECORD_ONLY is an invariant.** A request for evidence or rejected N/A does not add a new final approval gate. ACCEPT_NA applies only to the response/finding/version; it is not a general standards deviation or material-use authorization.

## 3. Alternatives not selected

- Universal fixed reviewer order: rejected in favor of project-configurable authority/order.
- Rewriting a source declaration or captured finding to mark it resolved: rejected in favor of immutable source plus separate events.
- Treating N/A proposal as acceptance, or disposition as an approval blocker: not the user-selected workflow.
- Automatically applying an approved definition to PMS/model/MTO: not authorized by review approval; PMS now has a separate explicit-selection path.
- Treating hashes/textual citations as authenticated attached documents: not delivered and not claimed.

## 4. Consequences and technical record

Shared project review locking serializes submissions/decisions/corrections and disposition append operations. Forced RLS, composite scope FKs, stage/actor guards and append-only policies protect stored review history. Nested response/disposition form inputs avoid old-input collisions and evidence is HTML-escaped.

Forward-only migrations preserve history; this is not a production-backup/restore certification. Project archive remains distinct from deletion. Current authority revocation can block a run; rerouting/recovery is not silently inferred from newer policy settings.

Authoritative implementation pointers:

- `app/app/Services/ProjectFittingDefinitionService.php`, `FittingReviewService.php`, `ProjectMaterialSchema.php`.
- `app/app/Services/Engineering/MaterialScreening.php`, `MaterialScreeningEvidence.php`, `MaterialFindingResponses.php`, `MaterialFindingDispositions.php`.
- Review/material/snapshot/response/disposition migrations dated 2026-09-29.
- `app/tests/Feature/MaterialReviewTest.php`, `MaterialFindingDispositionTest.php`, corresponding response/snapshot and populated-migration tests.

Earlier execution evidence remains in [material review report](PROJECT-MATERIAL-REVIEW-2026-09-29.md), [snapshot report](MATERIAL-SCREENING-SNAPSHOTS-2026-09-29.md), [response report](MATERIAL-FINDING-RESPONSES-2026-09-29.md), and [disposition report](MATERIAL-FINDING-DISPOSITIONS-2026-09-29.md). Counts in those reports belong to their respective runs; no runtime tests were rerun to write this ADR.

## 5. Acceptance obligations and open boundaries

Required regression coverage: actor/tenant/project isolation, captured-policy consistency, author-chain and own-response separation, stale/replayed writes, source immutability, corrected-version isolation, record-only final approval behavior and populated upgrade preservation. Link to actual test evidence rather than marking a criterion passed from prose alone.

Remaining: source-document authenticity/attachments, reference/code replay archives, deliberate workflow recovery, downstream consumption design and full deployment certification. ADR-014's entered-value/unit/canonical contract remains applicable to physical numeric fields; existing `_mm` fields and source prose do not establish full conformity by themselves. ADR-016 structured standards identity is likewise distinct from a textual source citation. No exemption from either ADR is granted here.
