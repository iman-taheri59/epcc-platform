# ADR-028 — PMS is an engineering specification document containing classes

Status: **REQUIREMENTS ACCEPTED; DOCUMENT/CLASS REVISION SCHEMA PROPOSED**
Date: 2026-10-13  
Depends on: ADR-006 (line register), ADR-008 (class rules), ADR-027 (authoring and line preconditions), CDE document/revision model.

## Owner's domain correction

**Piping Material Specification (PMS) is an engineering specification document containing the specifications of one or more piping classes.** It is not itself one class and it must not be modeled as a synonym for `piping_classes`. A class is a member of a PMS, with its own size-dependent material and branch rules. The hierarchy is:

`project → PMS document → issued PMS revision → one or more class specifications → lines selecting an eligible class`.

This is a domain requirement, not yet a description of the implemented schema. The current P1 implementation stores classes and rules but has **no PMS document or document-revision ownership link**. Its `PUBLISHED` state is a class-rule gate, not proof that a PMS document revision has been issued/approved in the CDE. The currently green CI confirms existing behavior only.

## Required invariants

1. One PMS document may specify **one or more** classes. Every line class selected for new line registration must trace to the relevant published/approved PMS document revision and to the exact class specification version used; a class code alone cannot prove engineering approval.
2. Class codes remain project-scoped identities. No automatically created placeholder PMS or class and no implicit membership based on matching filenames, codes or text.
3. A revision is immutable after issue: changes to a class or its rules must be associated with a new revision, not silently rewrite the evidence for existing lines. Existing lines remain pinned to their issued-against class revision (ADR-008 §7 #4, ADR-009 §4.4); adoption of a later revision is explicit, never automatic.
4. Reuse the existing CDE `documents` and `document_revisions` where possible for document number, file evidence, lifecycle and audit. Do not conflate CDE state (document issue/review) with the independent calculation/publish gate of a class; both must be satisfied for use on a line, if the owner confirms the CDE approval threshold.
5. Preserve project/company RLS and archive freeze across the document-to-class links; prevent cross-project membership and ambiguous active specifications of the same class.
6. The PMS author nomination currently exists only as policy data. Apply full write authorization and provenance at document/revision/class/rule boundaries before claiming protected authoring; see ADR-027.
7. The previous decision still stands: classes must precede lines, and no unmapped historical line is admitted to the line register. A class marked `PUBLISHED` in the current schema is NOT by itself proof that its parent PMS document was published, because that parent does not yet exist.

## Proposed integration, not yet implemented

Use CDE `documents` with a dedicated PMS type and `document_revisions` for issued revisions; add explicit same-project links from a revision to class-specification snapshots. Keep the existing `piping_classes` row as the stable class identity already referenced by piping lines/MTO, but version its rule set under the PMS revision rather than mutating the only copy in place. The line should pin the selected revision/class snapshot to preserve design basis. This is preferable to putting `pms_document_id` alone on a mutable class row: such a link would lose which revision specified the line's material. Migration of the current P1 rules and legacy class rows must be explicit, with a preflight that refuses ambiguous or unmapped mappings rather than inventing ownership.

## Resolved owner decisions and existing revision requirement (2026-10-13)

- A **new line** requires both a technically `PUBLISHED` class and an **approved** governing PMS document revision. Reuse the CDE predicate `CdeState::isApproved(state, suitability)` — `PUBLISHED` with an A-family suitability — rather than interpreting any published B-family/partial sign-off as approved. This is a design requirement; no document-to-class gate is implemented yet.
- At a given time, each project class identity has **one governing PMS document**, not multiple competing active specifications. One PMS document can still govern multiple classes. Revisions of that document may change each class independently; explicit supersession must not mutate old issued specifications.
- The user pointed out that revision behavior was **already decided**: ADR-008 §7 #4 and ADR-009 §4.4 require published classes to be locked, changes to create a **new class revision**, and lines to **keep pointing at the revision they were issued against**. Do not ask again whether to auto-migrate old lines; do not re-derive their material from the latest revision. The deviation report identifies lines on an older revision. The corresponding PMS document-revision link for each class revision must also remain immutable so this existing rule can be enforced end-to-end.

## Remaining implementation question

Choose a concrete class-specification revision key/link to `document_revisions` and migration path from the current mutable P1 draft row. The governing document is unique per project class identity, while its approved revision and each line's pinned class revision are explicit; do not reuse a changing `documents.current_revision_id` as historical evidence.

## Falsifiers / acceptance evidence

Demonstrate two distinct classes under one PMS revision, a class under a second revision with changed schedule without changing an old line's resolved result, and a new line refused for (a) class without PMS link, (b) wrong project, (c) draft class, and (d) unapproved PMS revision. Verify cross-tenant denial and that a document with one valid class cannot accidentally authorize another class absent from that revision. No skips in PostgreSQL CI.

## First implementation slice (2026-10-13)

The existing CDE register now offers a distinct `PMS` document type for the
`PIPING` discipline. Registration creates a numbered document and WIP revision
through the existing CDE state machine; a PMS in another discipline is refused
by the service, including non-HTTP callers. The first revision is NOT
approved, cannot authorize a line and contains **no class membership yet**.
A focused PostgreSQL feature test covers numbering, WIP/non-approval and
wrong-discipline refusal. No class link, snapshot, line selection gate or PMS
authoring policy enforcement is claimed by this slice. CI verification pending.

CI verification: run 38443239928 for `186707a` passed all six jobs, including
the PostgreSQL DocumentRegisterTest. This confirms the document registration
slice only, not the class/revision mapping or line/adoption gates.

## Second implementation slice: one governing document identity (2026-10-13)

A nullable `piping_classes.pms_document_id` binds a class identity to **one**
existing CDE PMS document. The database refuses non-PMS, non-PIPING, wrong-
project and non-WIP documents at first binding; a non-null binding cannot be
changed or cleared. `PmsClassDocumentService::attach` accepts only an
unassigned DRAFT class owned by the nominated authoring company, an owned WIP
PMS in the same project and the latest unsuspended author policy; it shares
the policy replacement's project advisory transaction lock and locks the
class row. Tests cover default denial, two classes in one document, immutable
reparenting (including direct SQL), wrong document type and suspension.

This is **identity membership only**, not class-specification revision
membership. The column remains nullable for legacy classes and prior fixtures.
The class rule set is still not snapshotted into `document_revisions`, approved
PMS revisions still do not authorize line registration and direct SQL can
still attach a *valid* unassigned class without the nomination service. Do
not call this a complete PMS authoring gate; CI verification pending.

CI run 38448258557 for `0740657` passed all six jobs, including the new
PostgreSQL/RLS binding tests. The next slice must attach an immutable class
rules snapshot to a specific PMS `document_revision_id`, then pin admitted
lines to that approved class snapshot. Do not use `pms_document_id` alone as
revision evidence.

## Third implementation slice: atomic approved-revision snapshot (2026-10-13)

On CDE publication of a `PIPING/PMS` revision with A-family suitability,
`DocumentRegister::transition` now captures an append-only JSONB specification
row per attached, published class in the **same transaction** as publication.
At least one class, with rules, is required; any attached draft refuses A
publication. The snapshot contains the class header, its size-band rules and
branch rules, with an explicit FK to `document_revision_id`. It is never
refreshed by later edits to live tables or by issuing another PMS revision.
Class attachment and approval both lock the same document before class rows;
the database checks the revision's approved state and rejects snapshot
UPDATE/DELETE. PostgreSQL RLS scopes snapshot access by participant/project.
B-family publication does not create approved-class snapshots.

Limitations: direct SQL may still forge snapshot JSON or mutate source rule
rows; `PipingClassService` publication and CDE PMS writing are not yet fully
nomination-gated. Existing lines are not pinned and new lines still have no
snapshot eligibility gate. A snapshot created for a prior revision must not
be mistaken for current approval without checking the exact revision state.
CI pending.

CI correction: run 38453709184 failed because `migrate:fresh` retained the
PostgreSQL function and the migration used `CREATE FUNCTION` instead of
`CREATE OR REPLACE FUNCTION`. The migration now supports repeated fresh
migrations and verifies inserted JSONB against the live source rows in its
trigger. A user able to edit published source rows directly remains outside
this protection; line admission remains unimplemented. Recheck in CI.

Verification update: CI 38456241655 passed five jobs but its Laravel job
failed on an annotated Chromium PDF-render 30-second timeout (zero assertion
failures; nested JUnit reported three errors). The snapshot migration collision
was resolved. The JSONB-forgery guard now has an explicit direct-INSERT test.
Await a clean full rerun before claiming this slice green.

CI run 38460152236 on `1af325a` passed all six jobs after the migration
repeatability correction and forged-content regression. This verifies
revision-specific snapshots at A-family publication, not line admission.
