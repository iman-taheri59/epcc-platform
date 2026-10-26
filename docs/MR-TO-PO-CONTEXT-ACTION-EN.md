# Create an existing full-copy PO draft from MR details

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED**

## User workflow

1. Open a visible MR detail sheet. If you hold the **current independent PO draft nomination** for that MR's discipline, the MR belongs to your buyer company, is APPROVED and explicitly categorized, contains 1–20 source lines, and the project is writable, an existing **PO draft from this MR** form is shown.
2. Select a participating project vendor and optionally enter the contractual delivery date (Gregorian input). The source UUID is supplied by the server-rendered form; quantities, descriptions, prices and source identities are not editable here.
3. Submit. The existing full-copy POST/service rechecks source, project, actor/grant and vendor under its established locks. It copies every source line, retains actual source-line IDs and redirects to the new PO details.
4. The MR remains APPROVED with unchanged quantities/approval metadata. The PO is DRAFT with unknown prices and no issue/financial/allocation evidence. Continue through the separately accepted original-drafter complete-pricing and independent finance/issue workflows.

Multiple draft copies remain possible and non-reserving. This is not an idempotency or duplicate-purchase guarantee at draft creation; the existing issue-time controls remain authoritative.

## Existing authority, not a new writer

This implements a contextual entry point for **already delivered ADR-033/034/035 behavior**, preserving ADR-038/039 and the later OrderGap boundaries. No new endpoint, role, nomination inheritance, cross-company copy, partial purchasing, reservation, MR ORDERED transition, MTO authority or reconciliation/release workflow is added.

`MaterialRequisitionDetailsService` reads the same original caller scope as before. It evaluates live PO eligibility for display and loads only project-participating vendors, only when eligible. Oversight MR visibility alone is not a copy grant; MR nomination alone is not PO nomination. No new actor-directory or buyer-journal lookup is added.

The shared `materials.po-from-mr` partial is reused from the procurement register and is now self-contained for scalar-safe old-input rendering. Its details element has a stable per-MR ID. The MR sheet displays validation/status feedback and reopens its matching form after a failed submission. A malformed nested vendor value cannot cause an array-to-string error or render script input. The server still uses the existing fixed POST contract; a displayed form is not a certificate that authority/source will remain valid.

**Read/write distinction:** opening the MR sheet remains a nonmutating GET; an eligible user now sees an optional form that deliberately creates a PO through the existing POST. It never edits the source MR. Earlier documentation that the MR sheet had no action controls describes the preceding increment, not this follow-on. Archived MR reads remain available but the copy action is absent and stale submissions are refused.

## Verification

**43 affected tests / 340 assertions PASS in 76.024 seconds**: MR detail, approved-MR PO draft copy and PO entry tests. Five new cases cover:

- EN/FA contextual form plus successful existing POST: exact full-copy source UUIDs/quantities, unchanged source header/lines, promised date, DRAFT/unknown prices and zero pricing/finance/issue/allocation rows.
- Oversight callers do not receive an own-buyer form; MR grant remains active while a revoked independent PO grant hides the form and rejects a stale POST.
- Source eligibility changes after display are rejected and create no PO.
- Archive after display hides the form, keeps the MR readable and rejects the stale POST.
- Ordinary validation redirects to the MR, shows errors and safely restores old input, including a deliberately nested malicious vendor value.

Existing tests retain source-override/tenant/category/full-copy/rollback checks and seven-table no-mutation GET snapshots. `related.log` is authoritative; the initial `focused.log` failure was a whitespace-sensitive HTML assertion, corrected before the passing combined run. These counts are not cumulative with preceding evidence.

Evidence: `docs/evidence/2026-10-19-mr-po-context-action/summary.json`. Two PHP lint/Pint checks, repository diff whitespace check and four implementation hashes recorded. PostgreSQL17/PHP8.4, epcc NOSUPERUSER/NOBYPASSRLS, UTF8, private Unix socket; stopped after verification. No new migration, routine full suite, production deployment, current CI, commit/push, browser acceptance or broad race certification.

General legacy/manual/MTO authority, controlled post-issue amendments, physical receipt, official remaining capacity and write-based legacy reconciliation remain separate and deferred.
