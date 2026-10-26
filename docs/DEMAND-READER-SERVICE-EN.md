> **2026-10-23 subsequent delivery:** bilingual owner configuration and scoped readiness HTTP pages are now implemented; [web guide](DEMAND-READER-WEB-EN.md). Internal-only/no-HTTP statements below describe the preceding slice. Effective demand/reservation still unimplemented.

# Scoped demand reader: first application-service increment

2026-10-23 · **Migration + internal configuration/readiness facade implemented and tested. No HTTP/UI or reservation activation.**
[فارسی](DEMAND-READER-SERVICE-FA.md) · [Accepted matrix, §7](DEMAND-ACTIVATION-MATRIX-EN.md) · [Preceding seam design](DEMAND-ATOMIC-APPROVAL-EN.md)

## 1. As built versus pending

- **Implemented:** `DemandReaderService`, additive `2026_10_23_120000_add_scoped_demand_reader_policies` migration, bilingual validation messages, focused PostgreSQL application tests.
- **Internal callable only:** no route/controller/form/configuration picker, source directory or report link added. Ordinary users cannot configure this through a new endpoint. No default policy/reader is seeded. Do not claim the human configuration workflow is finished.
- **Not implemented:** effective-selection/admission resolver, full commitment inventory, source-content report, MR content/enrollment journal, demand reconciliation writer, reservation or PO handoff, GRN/stock. Existing procurement/custody routes/services and the original MTO RLS remain untouched.
- DA-01..04 remain accepted policy. This is only the first DA-01 implementation slice; DA-02..04 are not runtime-enabled here. Approval of scoped readers does not make raw observations admitted scope.

## 2. Internal contracts

### `configure(Project, User, input): policy UUID`

Input: `target_company_id`, `document_id`, `scope_id`, exact `hierarchy_revision_id`, expected current `version` (0 initially), `suspended`, `selectors`, `reason`. Selectors are exact `user:UUID` or `role:COMPANY_UUID:CODE` strings; target company must participate and nominees must be current active eligible PIPING project members with proc/pack entitlement. Roles must exist and have a currently eligible member. No generic role wildcard or default nominations. Suspension requires an empty list; a live policy requires an explicit nonempty list (bounded to 100 selectors).

Original tenant/project is bound; project locked; fresh active owner-company project.configure authorization and archive writability are checked. Source must independently resolve under the configuring caller's existing RLS as an accepted PIPING document/batch/row tuple with the exact scope and claimed hierarchy on batch and row. No CDE/catalogue content is read. The claimed hierarchy is **a nomination boundary, not engineering admission**.

Each policy is scoped to `(project,target company,document,scope,hierarchy)`, with its own increasing version and immutable actor/reason/time. Target companies are configured independently, so one company's replacement never grants another company's access. Different documents/scopes/hierarchy versions need distinct configuration. No automatic cross-hierarchy carry-forward. Current-user or role selectors are evaluated freshly at read time, not expanded into permanent grants.

Stale expected version fails validation without appending. Source hidden/unresolved or unauthorized configuration fails generically. The identity-only directory lookup for eligible nominees never delegates source access or changes the actor to buyer/custodian. No source title/row payload is returned by this method. The future UI must still obey independent source metadata visibility; no picker has been shipped.

### `assess(Project, User, batch UUID, document UUID, scope UUID, hierarchy): array`

Fresh identity/company, active plan proc/pack, membership/PIPING, participation and explicit current nomination are required. Project configuration alone is **not** a reader. The latest policy is read **including suspension/empty replacement**; older nomination is never a fallback. Role loss, suspension, person ineligibility or participation loss fails 403. Invalid UUID/hierarchy input fails 404.

Read the exact supplied batch/document/scope/hierarchy under original caller RLS, then recheck current nomination/eligibility. Nomination does not unlock hidden source. Source exists/visible yields:

```text
ready=false; status=UNRESOLVED; gross=null; remaining=null;
operationally_authorized=false;
findings=[EFFECTIVE_SELECTION_ADAPTER_NOT_IMPLEMENTED,
          COMMITMENT_INVENTORY_NOT_ASSESSED]
```

A missing, mismatched or hidden batch/source yields UNAVAILABLE with generic SOURCE_UNAVAILABLE_OR_UNRESOLVED and null quantities. Output contains no batch/row IDs, quantities, titles, selector history, source snapshots, hashes or hidden counts. It does not call the old whole-batch readiness reader, which would read outside this new exact scope. It does not select a latest effective revision or aggregate any demand.

Archived reads remain possible for a currently eligible nominee; configuration writes are blocked. No archive policy change elsewhere. A final recheck is fail-closed for observed policy changes, not an immutable audit snapshot or instantaneous post-response revocation guarantee. No transaction isolation setting is changed.

## 3. SQL boundaries

`demand_reader_policies` has FORCE RLS and original company/project context. Configuration metadata is readable in SQL only by its owner configuring company or its exact target company in that project; unrelated participant companies cannot see it. This **company-level metadata boundary is not per-person SQL RBAC**: application readers must use the facade's nomination check, and no metadata endpoint was added. Configurator access to policy metadata does not imply demand-source reading.

INSERT policy/trigger checks owner company, project scope, real active recorded actor, participating target, ACTIVE/nonarchived project, visible PIPING accepted tuple and next version under the shared project lock. Composite document/project and scope/project FKs prevent cross-project source references; unique version key prevents duplicate versions. Existing parent tables gain additive composite unique constraints. No UPDATE/DELETE policy; normal attempted mutation affects zero rows. An immutable-history trigger additionally rejects mutation if a future write path reaches it. No SECURITY DEFINER or new role grant. Down migration drops only this slice's table/function/constraints; accepted snapshots remain.

Human `project.configure`, selector eligibility and plan/role checks live in the facade; the SQL trigger is not universal human-capability authorization against arbitrary trusted DB callers. No endpoint, user SQL access or bypass is introduced. Future integrations must not insert directly and bypass the facade. No claim of effective demand correctness follows from successful INSERT.

## 4. Verification

**49 application tests / 153 assertions PASS, 85.03 seconds**:

```sh
cd app
php artisan test --compact tests/Feature/DemandReaderTest.php \
 tests/Feature/MtoScopeObservationTest.php \
 tests/Feature/MtoAcceptanceJournalTest.php
```

Includes 20 new reader cases plus affected existing scope-observation/chronology cases. Coverage: no defaults/admin inheritance; exact document/scope/hierarchy; nonowner denial; suspension/replacement no fallback; stale version; fresh active person/discipline/company-role/participation; original hidden source despite nomination; archived read/write boundary; zero-row UPDATE denial/FORCE RLS; invalid cross-company selector; unknown batch; hidden source during configuration; populated rollback/reinstall without seeded grants or snapshot change; unrelated company metadata isolation; no writes/CDE/library/stock queries in readiness. Earlier fixture failures were corrected to use actual tenant-scoped mutations (unscoped updates affected zero rows), and test zero-row UPDATE denial instead of expecting an exception; no RLS was weakened.

Five-file Pint/lint and whitespace checks passed. Ordinary PostgreSQL test role `epcc` is NOSUPERUSER/NOBYPASSRLS; FORCE RLS/FKs/invoker audit retained. Evidence: `docs/evidence/2026-10-23-demand-readers/`. No full regression, app concurrency certification, browser/HTTP/UI test, production data/migration, CI/deploy/commit/push. Prior isolated protocol results are not added to this PHPUnit count. Private socket-only test cluster stopped after verification; runtime/vendor/DB not packaged.

## 5. Next bounded increment and remaining gate

Add owner-only configuration screen and exact-scope readiness GET/controller with independent source picker visibility, proper version conflicts, bilingual validation/escaping/private caching, archive behavior and focused HTTP tests. This can surface “authorized but unresolved” honestly. Do not wire new grants into existing MTO acceptance/admission or change buying. Before authoritative capacity/reservation, implement admitted effective selection and complete inventory/controlled MR lifecycle using the approved atomic protocol and real source data tests. Do not re-ask DA-01..04.
