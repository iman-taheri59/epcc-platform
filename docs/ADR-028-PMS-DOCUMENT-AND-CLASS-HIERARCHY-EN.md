# ADR-028 — PMS is an engineering specification document containing classes

Status: **REQUIREMENT ACCEPTED; REVISION MODEL PROPOSED**  
Date: 2026-10-13  
Depends on: ADR-006 (line register), ADR-008 (class rules), ADR-027 (authoring and line preconditions), CDE document/revision model.

## Owner's domain correction

**Piping Material Specification (PMS) is an engineering specification document containing the specifications of one or more piping classes.** It is not itself one class and it must not be modeled as a synonym for `piping_classes`. A class is a member of a PMS, with its own size-dependent material and branch rules. The hierarchy is:

`project → PMS document → issued PMS revision → one or more class specifications → lines selecting an eligible class`.

This is a domain requirement, not yet a description of the implemented schema. The current P1 implementation stores classes and rules but has **no PMS document or document-revision ownership link**. Its `PUBLISHED` state is a class-rule gate, not proof that a PMS document revision has been issued/approved in the CDE. The currently green CI confirms existing behavior only.

## Required invariants

1. One PMS document may specify **one or more** classes. Every line class selected for new line registration must trace to the relevant published/approved PMS document revision and to the exact class specification version used; a class code alone cannot prove engineering approval.
2. Class codes remain project-scoped identities. No automatically created placeholder PMS or class and no implicit membership based on matching filenames, codes or text.
3. A revision is immutable after issue: changes to a class or its rules must be associated with a new revision, not silently rewrite the evidence for existing lines. Define how existing lines remain pinned to the prior revision versus when they adopt a new revision.
4. Reuse the existing CDE `documents` and `document_revisions` where possible for document number, file evidence, lifecycle and audit. Do not conflate CDE state (document issue/review) with the independent calculation/publish gate of a class; both must be satisfied for use on a line, if the owner confirms the CDE approval threshold.
5. Preserve project/company RLS and archive freeze across the document-to-class links; prevent cross-project membership and ambiguous active specifications of the same class.
6. The PMS author nomination currently exists only as policy data. Apply full write authorization and provenance at document/revision/class/rule boundaries before claiming protected authoring; see ADR-027.
7. The previous decision still stands: classes must precede lines, and no unmapped historical line is admitted to the line register. A class marked `PUBLISHED` in the current schema is NOT by itself proof that its parent PMS document was published, because that parent does not yet exist.

## Proposed integration, not yet implemented

Use CDE `documents` with a dedicated PMS type and `document_revisions` for issued revisions; add explicit same-project links from a revision to class-specification snapshots. Keep the existing `piping_classes` row as the stable class identity already referenced by piping lines/MTO, but version its rule set under the PMS revision rather than mutating the only copy in place. The line should pin the selected revision/class snapshot to preserve design basis. This is preferable to putting `pms_document_id` alone on a mutable class row: such a link would lose which revision specified the line's material. Migration of the current P1 rules and legacy class rows must be explicit, with a preflight that refuses ambiguous or unmapped mappings rather than inventing ownership.

## Open decisions for the owner

- What CDE milestone makes a PMS revision usable for a **new line**: approved/accepted, issued-for-construction, or another suitability code? Does a class rule's own `PUBLISHED` gate remain a separate prerequisite? The owner previously selected PUBLISHED class before line, but the document-state threshold was not specified.
- Can the same class identity be defined by multiple PMS documents on one project, or must one governing PMS document own each class at a given time?
- On a new PMS revision, do existing lines stay pinned to their original revision until formally revalidated, or automatically adopt the latest approved class specification? Recommendation: pin and require explicit impact review.

## Falsifiers / acceptance evidence

Demonstrate two distinct classes under one PMS revision, a class under a second revision with changed schedule without changing an old line's resolved result, and a new line refused for (a) class without PMS link, (b) wrong project, (c) draft class, and (d) unapproved PMS revision. Verify cross-tenant denial and that a document with one valid class cannot accidentally authorize another class absent from that revision. No skips in PostgreSQL CI.
