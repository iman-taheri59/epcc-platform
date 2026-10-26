> **2026-10-24 subsequent web increment:** owner nomination/suspension pages and own-source diagnostics now exist; [current web guide](SCOPE-ADMISSION-AUTHORITY-WEB-EN.md). Statements below about absence of human UI describe the earlier service-only increment. Trusted package/evidence/admission writer remains absent.

# Scope admission: internal action nomination and capability preflight

2026-10-24 · **First SA-01/SA-02 application-service increment implemented/tested, not deployed.**
[فارسی](SCOPE-ADMISSION-AUTHORITY-SERVICE-FA.md) · [Accepted choices, final section](SCOPE-ADMISSION-AUTHORITY-EN.md)

## Delivered / not delivered

New `ScopeAdmissionAuthorityService`, additive `2026_10_24_100000_add_scope_admission_actor_policies` migration, bilingual validation messages and focused real PostgreSQL application tests. No changes to existing source/observation tables or their UNADMITTED constraint, no default actors, new seeded role or inheritance from DA-02/MR/PO/QA. Existing demand-reader services/routes are unchanged.

This is **internal service only**: no admission configuration HTTP route/form/directory, engineering proposal storage, trusted evidence/content verification, reviewed package, admission event writer, effective selector or reservation. A successful capability preflight is not engineering approval. DA-01..04 and SA-01/02 remain accepted policies, not a claim that their entire operational chain is enabled.

## Independent nominations

`configure(Project, administrator, input)` appends one immutable action-policy version for the exact `(project,target company,document,scope,hierarchy revision,action)` key. Actions are **propose / review / record**, separately nominated and versioned. Input also includes expected current `version`, `selectors`, `suspended`, `reason`, and a visible `batch_id` for active nomination. User/company-role selector format follows the existing reader directory, at most100 explicit distinct selectors. No wildcard/default/shared grant among actions.

Fresh current owner-company project.configure authorization, membership/discipline/plan/pack and original tenant/project context are required. Project is locked and archive writability checked. Active configuration reuses the existing bounded reader dashboard's **original configuring caller source-visibility check and eligible nominee directory**, not a delegated source read or a new reader grant. Only eligible participating people/company-scoped roles are nominated. An action nominee need not already hold source-read nomination at configuration time, but **cannot perform even preflight until independently nominated to read that source**. Nomination does not prove engineering competence; the owner assigns responsibility explicitly.

Suspension appends an empty successor only for an already recorded owner-owned key at the expected version. It requires no source/batch lookup or eligible target directory and works after source hiding or target participation loss; administrator authority/archive gates remain. This internal configuration contract does not yet have a human suspension register of its own. Nonempty suspension, empty active list, wrong version, unknown action or foreign/ineligible selector fails closed. Ordinary reactivation again requires visible source and eligible selectors.

New table ENABLE/FORCE RLS permits policy metadata only to recorded owner company or exact target company in the bound project, not arbitrary participants. This remains company-level DB metadata isolation, not universal per-person SQL RBAC. Same-project document/scope FKs reuse preceding composite keys; unique key includes action/version. SQL invoker guard preserves current owner/project/active recorded actor/archive/version checks, visible PIPING source for active grant, and only same-key empty recorded reduction bypass. No UPDATE/DELETE policy plus immutable-history trigger. Down drops only this new table/function; prior reader source keys/records are untouched. No historic actors or grants are backfilled.

## Read-only capability preflight

`preflight(Project, actor, source, action, proposerId?, reviewerId?)`:

1. Validate exact source identifiers/action and normalize participant UUID case for person separation.
2. Require active/writable project and independently current source-reader eligibility under the actor's **own** company/project RLS. Unknown/hidden exact reference is not admitted by nomination.
3. Require latest current admission action nomination for this actor/company/source/action; live role membership checked, suspended head never falls back to an old grant.
4. `propose`: any provided proposer ID must be this actor. `review`: proposer UUID required and different from actor; any provided reviewer must be this actor. `record`: proposer and reviewer UUIDs required and distinct, actor must be one of them, plus a separately current record nomination. Review nomination does not grant recording.
5. Recheck current independent reader and action policy/role before returning.

Successful result deliberately retains:

```text
capability_preflight=true
ready_to_admit=false
operationally_authorized=false
package_verified=false
admission_state=UNADMITTED
gross=null
writes_performed=0
findings=[TRUSTED_PACKAGE_AND_EVIDENCE_NOT_VERIFIED,
          EFFECTIVE_SELECTION_NOT_IMPLEMENTED]
```

No CDE/library/stock content or quantities are read by the preflight. It uses exact source-reference EXISTS checks, not whole-observation topology/evidence payloads. It does not grant acceptance, change a source, approve a package or reserve demand.

**Trust boundary:** participant IDs are supplied to this internal preflight for separation checks only. They must eventually be loaded from an authenticated immutable persisted package/review, not client claims. The service does **not** prove their actual proposer/reviewer role, past approval, technical competence, policy at review time, source-binding completeness, evidence authenticity/validity or package version. A malicious caller can supply invented participant UUIDs and pass mere inequality; this still yields package_verified=false/ready_to_admit=false and no write. Do not expose this as an admission endpoint or treat capability_preflight=true as approval. The future writer must authenticate all package actors/versions/reviews and recheck dependencies atomically. There is no authorization lease, frozen audit snapshot or general multi-writer revocation guarantee.

## Verification

**49 application tests /157 assertions PASS, 106.36 seconds**: ScopeAdmissionAuthorityTest (17 new), DemandReaderTest and DemandReaderSuspensionTest. Related tests only, not full regression. Cases include independent source reading/action grants; no admin/reader inheritance; action separation; fresh role loss; hidden-source suspension/no old fallback; UUID-case self-review rejection; record requires separate grant and one of two distinct participants; hidden source; stale version/ineligible selector; reader revocation; archive; FORCE RLS/zero-row UPDATE/foreign metadata; populated upgrade with observation bytes unchanged/no default actors; no preflight mutation/CDE/library/stock reads.

Two test-fixture issues retained in evidence: a helper named `head()` collided with Laravel's HTTP method and was renamed `policyHead()`; the old reader-suspension migration test assumed “rollback latest” meant its particular guard migration. After adding this newer migration, it correctly needed to call its exact guard's down/up methods (with finally restoration). No app privilege or behavior was weakened to fix the fixture. Final49/157 is authoritative; earlier17/48 and failed affected48-pass run overlap and must not be added.

Six-file Pint/lint, diff whitespace and ordinary epcc NOSUPERUSER/NOBYPASSRLS, FORCE-RLS/FK/invoker audits passed. Evidence: `docs/evidence/2026-10-24-admission-authority/`. No new routes, browser/HTTP proof, app concurrency certification, full regression, production migration/data, CI/deployment/commit/push. Private socket-only PG stopped afterward. Dependent source package excludes runtime/vendor/DB.

## Next bounded work

Expose owner-only action nomination configuration and read-only capability diagnostics with original source/policy metadata boundaries and focused HTTP tests; do not duplicate an unrestricted source picker or inherit reader grants. Then build immutable authenticated proposal/review storage and evidence/validity adapters before an admission writer. Correct/withdraw actions must follow accepted independently reviewed append-only lifecycle; no direct generic correction writer is authorized by this service. Do not re-ask SA-01/02 or DA-01..04.
