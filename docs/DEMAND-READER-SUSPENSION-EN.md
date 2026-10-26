# Recorded demand-reader suspension without reading a hidden source

2026-10-23 · **Implemented/tested, not deployed.** [فارسی](DEMAND-READER-SUSPENSION-FA.md)

Closes the specific administration limitation in the [web guide](DEMAND-READER-WEB-EN.md). Accepted DA-01..04 unchanged. This increment only reduces existing access; no new MTO read, admission, reservation, purchasing or warehouse authority.

## Use and privacy boundary

Owner-company current project.configure administrators can follow **Recorded reader-policy suspensions** from the demand-reader configuration page:

```text
GET  /projects/{projectCode}/materials/demand-reader-suspensions?lang=en
POST /projects/{projectCode}/materials/demand-reader-suspensions?lang=en
```

Default Persian; English/Farsi supported. GET private/no-store/max-age=0. Existing authenticated web/CSRF form middleware applies. GET lists at most 25 **current heads of policies recorded by the configuring owner company in this project**, not source records. Each shows opaque policy UUID, target company UUID, version, recorded time and suspended state. No MTO document/scope/batch IDs, document title, source quantity, past free-text reason, nominee list, CDE content or hidden-source count is resolved/displayed. This is an administrative metadata surface, not a demand-reader grant or a complete project-material inventory.

The ordinary visible-source history now also shows its recorded policy UUID to let administrators identify the same policy later. The suspension register deliberately uses opaque references rather than looking up hidden names; administrators must identify the intended recorded reference/company/time. Already suspended heads have no action form; archived project has no write forms. No all-owner/all-project metadata expansion or ownership-handover authority is added.

POST accepts exactly `suspension[policy_id]`, `suspension[version]`, `suspension[reason]`. Extra source, selector or activation fields are rejected. Reason required and bounded. It does not accept or resolve a source tuple. A stale ID/version, already suspended head or repeated stale submission is a validation conflict, not an automatic rebase or second action. Reload and intentionally choose the current head. An obsolete form's reason is not copied to a different successor ID. Same-head invalid reason is retained type-safely. This is not an idempotent generic event writer; retries cannot append from an old head.

## Service and SQL mechanics

`DemandReaderService::suspendRecorded()`:

1. Recheck fresh owner-company project.configure eligibility, original tenant/project, active membership/discipline/plan/pack. Lock project root and enforce archive writability.
2. Find **owner-owned policy** by exact project/policy UUID. Missing/foreign reference is 404 after administrator authorization; no new source lookup. No impersonation or SECURITY DEFINER.
3. Re-read the exact key's current head under lock. Require same ID and expected version and active state.
4. Derive the full source/company key from that immutable record, never request fields. Append one next version with `suspended=true`, `selectors=[]`, real actor and supplied reason. Preserve old versions. No INSERT/UPDATE/DELETE against source tables or existing policy rows.

This reduction works even if original source RLS hides every source row or the target company no longer participates. Those conditions block new use/grants but should not prevent removing a previously recorded grant. Current configurator authority and archive gate are **not** waived.

New forward migration `2026_10_23_130000_allow_recorded_demand_reader_suspension` replaces only the invoker INSERT guard, leaving prior migration/table/RLS/FKs unchanged. After current owner/project/active actor/nonarchive/version checks, it permits only an empty suspended successor of the same previously owner-recorded key to return **before any source/target-participation lookup**. All active/new grant paths still execute the original visible accepted PIPING source and target-participation checks. A fabricated key or nonempty/active proposal cannot use the bypass. Table CHECK/FK/unique and immutable-history constraints remain in force.

Down restores the preceding guard exactly; it never deletes recorded versions. Downgrade consequently removes the new hidden-source reduction ability, not historical suspensions. Upgrade/reinstall on populated history is tested. The original service `configure()` remains source-visible for ordinary grant/reactivation; new UI uses the dedicated reduction method. Production callers must retain the facade; SQL guard is defense in depth, not universal human project.configure/RBAC authorization.

## Non-revival and boundaries

After hidden-source suspension, restoring source visibility does **not** revive the old nomination: reader still sees latest empty/suspended head and fails 403. Reactivation requires an explicit new ordinary configuration with visible exact source, current eligible participating nominees and correct version. Reduction endpoint cannot reactivate or grant access.

No source selection/admission or inventory completeness is inferred. Demand/remaining stay unknown; MR, PO, QA and stock behavior unchanged. DA-02..04 remain accepted policies but not activated by this fix. Ownership transfer, bulk suspension and policy search/name lookup across hidden sources remain separate, unimplemented surfaces.

## Verification

Focused/affected **44 application tests / 172 assertions PASS, 66.83s**: `DemandReaderSuspensionTest` (12 new), `DemandReaderTest` and `DemandReaderHttpTest`. Initial 12/61 passed before an extra guard hardening; final affected run is authoritative, counts overlap and are not added.

Cases: hidden-source reduction and no revival after unhide; source-free private GET/POST with no document/batch/scope leakage; guest/nonowner/wrong-project denial; unknown policy; stale/repeated head/version conflicts; activation/selector/source payload injection rejected; target-participation loss; archive read/write boundary; hidden-source reactivation denied; SQL active hidden/fake-key bypass attempts denied; populated guard downgrade/upgrade preserving history; bilingual HTTP and malformed reason type safety. SQL actor identity was explicitly kept ahead of the reduction bypass; no RLS relaxation.

Seven PHP files Pint/lint, diff whitespace, Blade compile/clear, four configuration/suspension routes, ordinary-role/invoker/FORCE-RLS audits passed. `docs/evidence/2026-10-23-demand-reader-suspension/` contains logs and synthetic EN/FA HTTP responses with CSRF redacted, **not browser screenshots**. Query logging proves the application path does not issue source SQL; the guard's early-return branch and hidden-source test cover DB behavior, not a claim of universal SQL noninterference.

No full regression, new concurrency certification, real-browser/load/production CSRF verification, production migration/data audit, CI/deploy/commit/push. Private socket-only test DB stopped afterward. Package is a dependent increment, not a complete runnable project/runtime/vendor/DB backup.

## Next

The identified revocation-administration defect is closed. Continue genuine effective-selection/admission readiness and complete commitment-inventory work before enabling the already-approved atomic final-MR reservation. Do not infer readiness from SQL-visible accepted rows or add further access by inheritance.
