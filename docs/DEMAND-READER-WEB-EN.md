> **Subsequent correction 2026-10-23:** metadata-only suspension of an existing owner-recorded current policy is now implemented; [guide](DEMAND-READER-SUSPENSION-EN.md). The hidden-source suspension limitation below is historical and closed by that narrowly reducing path. Ordinary new grants/reactivation still require source visibility.

# Scoped demand readers: configuration and readiness web increment

2026-10-23 · **Implemented in source/test; not deployed.**
[فارسی](DEMAND-READER-WEB-FA.md) · [Accepted policy §7](DEMAND-ACTIVATION-MATRIX-EN.md) · [Preceding internal service](DEMAND-READER-SERVICE-EN.md)

## Delivered

- Owner-company, current project.configure-only bilingual configuration page, independently visible accepted PIPING source picker, eligible explicit person/company-role nomination, versioned POST, suspension, and last-20 exact-key history.
- Separately nominated reader's read-only readiness page. Configuration permission alone is not reading permission.
- Original actor/company/project RLS throughout; no source delegation, default nominee, new role, migration, effective-selection/admission writer, reservation, MR/PO or stock behavior change.
- Link from the incoming traceability page for owner-company users. This link is navigation only; endpoint rechecks actual configuration authority. Access by URL is also supported.

## Routes and use

```text
GET  /projects/{projectCode}/materials/demand-readers?lang=fa
POST /projects/{projectCode}/materials/demand-readers?lang=fa
GET  /projects/{projectCode}/materials/demand-readiness?lang=fa
```

`lang=en` selects English; default Persian. GET responses are private/no-store/max-age=0. Existing authenticated web middleware applies; forms include CSRF tokens. No readiness POST/export endpoint.

1. Configurator selects an eligible participating target company explicitly. No reader is selected by default.
2. Picker displays 25 independently original-caller-visible accepted `(batch,document,scope,hierarchy)` references per page: document number/revision and exact IDs only, no quantities or CDE bodies. Distinct tuples avoid duplicate material rows. They are **not** an effective-MTO selector. Count/page is not a frozen audit/project-demand inventory.
3. Select an exact tuple. GET query fields: `target_company_id`, `batch_id`, `document_id`, `scope_id`, `hierarchy_revision_id`. Source and company are revalidated; partial/malformed tuple produces validation error, hidden/forged selection fails closed. Hierarchy is still a claimed boundary, not admitted engineering topology.
4. The page shows expected current policy version, eligible person/company-role checkboxes, active/suspend choice and required reason. POST accepts `policy[...]` through DemandReaderService. Missing checkbox group becomes **explicit empty**, never current-policy fallback. Active-empty and suspended-nonempty are rejected. After success the page returns to the index with confirmation; reselect source for current history.
5. Stale expected version remains stale in returned old input; no silent rebase. Unchecked nominees stay empty after error, including absent groups. Malformed nested old input is rendered type-safely and escaped. Reload the exact selection intentionally before resubmitting a newer version.
6. Last 20 exact-key versions display reason, immutable actor/time, state and recorded selector strings. Historical selectors are not a claim of present eligibility. No all-company history endpoint.
7. The selected configuration includes a readiness link for the intended nominated reader. Configurator alone gets 403 on readiness. There is no global reader source-discovery register; the reader needs the intended exact link/reference. A current independently eligible nominee must still have original source visibility.

Archived configuration/history is readable to authorized configurators, without a write form. POST is blocked by the existing service archive gate; eligible nominee archived readiness remains read-only.

## Readiness meaning and privacy

Visible exact reference: UNRESOLVED, effective-selection/admission adapter missing and commitment inventory not assessed. Hidden/missing/mismatched source: generic UNAVAILABLE. **Both retain ready=false, gross/remaining=null and operationally_authorized=false.** No quantity, source snapshot, hash, CDE content or newly discovered hidden source identity is rendered. Language-switch URLs retain the already-supplied reference query (including in unavailable state); they do not contain newly resolved private source fields.

Independent receipt/PO/MR rights never make a demand reader. Nomination does not unlock a source hidden by original RLS. Administration source-picker access is the bounded configuration metadata path, not acceptance/admission or capacity. No CDE or material-library browsing is added. Unknown is never zero, complete or a release permission.

## Service/UI boundaries and known limitations

The earlier service has been extended with an authorized dashboard/source picker and shared eligible directory; core configure/assess semantics are retained. Three routes, one controller and two views added; prior source-table policies/schema unchanged.

- Scope policy remains document/scope/hierarchy/target-company, not batch-specific. The picker uses a batch to independently verify the exact visible tuple; this does not pin nomination to one batch or select the latest effective revision.
- Source picker/history selection currently requires the source to remain independently resolvable, as the preceding facade required. There is **no metadata-only revocation path for a completely hidden source** yet. Hidden sources do not become readable, but their old nomination can remain recorded and may become usable again if original visibility returns. Recommended follow-up: narrowly permit suspension of an already recorded exact key without reading hidden source content, with a reviewed trigger/service change and focused tests; do not relax source checks for new grants. This limitation is not silently presented as completed revocation administration.
- No global policy search, cross-source history export or reader discovery. The source picker is bounded per page; eligible directory follows existing per-company/project nomination approach, not a bulk-account management system.
- No live admission, complete demand inventory, reservation, reconciliation execution, MR approval hook or PO reservation handoff. DA-02..04 stay accepted but inactive. Do not enable capacity from source EXISTS.

## Verification and evidence

Focused/affected run: **45 tests / 240 assertions PASS, 83.64s** (`DemandReaderHttpTest`, `DemandReaderTest`, `PipingTraceabilityTest`). After correcting the **test URL helper's** language precedence, the HTTP file was rerun with real EN/FA requests: **12 tests / 61 assertions PASS, 19.58s**. These overlap; do not add counts. Runtime code did not change between these runs. The corrected helper ensures `http-*-fa.html` really has lang=fa/dir=rtl.

HTTP cases: guest redirects; owner/nonowner GET/POST; bilingual private forms; successful configuration and independently nominated unresolved readiness; suspension without checkbox group; stale version and explicit-empty old input; nested malicious old input; hidden source absent from picker and forged selection/POST denied; generic unknown batch without discovered original batch ID; archived history/form/write boundary; escaped history/nominee labels; bilingual readiness and POST405; malformed query/foreign company denial. Existing service tests cover live person/role/participation changes and no-fallback policy. A failed early assertion mistakenly prohibited the caller's own scope parameter in a language link; corrected to prohibit discovered source metadata, not already supplied query values. Initial translation-generation syntax errors were fixed before behavior verification; logs retained.

Six-file Pint/lint, Blade compile/clear, route listing, diff whitespace and ordinary-role/FORCE-RLS audit passed. `docs/evidence/2026-10-23-demand-reader-ui/` includes synthetic EN/FA configuration/readiness HTTP responses (CSRF values redacted), **not browser screenshots**. Shared-shell external assets can be absent in offline previews. No real browser/interactive visual/load test, production CSRF certification, full regression, production migration/data audit, CI, deploy, commit or push. Private socket-only test cluster stopped afterward. Package is a dependent increment, not vendor/runtime/DB or full runnable project.

## Next bounded work

First close the hidden-source suspension administration limitation without widening reads or grants. Then progress admitted effective-selection and inventory readiness; only afterward wire the already-approved atomic final-MR reservation protocol. No need to re-ask DA-01..04. New waiver/stock/partial buying rules remain out of scope.
