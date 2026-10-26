> **2026-10-25 bound-opinion increment:** [independent non-approving opinions bound to both exact narrative and structure](SCOPE-STRUCTURE-REVIEWS-EN.md) now exist. Earlier absence-of-structure-bound-review text below is historical for that limited internal opinion writer. No evidence-bound/positive engineering review/admission is enabled; current narrative UI and declaration readiness flags are unchanged.

# Unverified topology/coverage declarations bound to an exact proposal version

**2026-10-24 · internal service increment implemented/focused-tested, not deployed.**
[فارسی](SCOPE-STRUCTURE-DECLARATIONS-FA.md) · [Envelope reading](SCOPE-PROPOSAL-ENVELOPE-READ-EN.md) · [Persistence](SCOPE-PROPOSAL-ENVELOPES-EN.md) · [Technical draft](ADR-024-SCOPE-ADMISSION-CONTRACT-EN.md)

## Delivered / not delivered

`ScopeStructureDeclarationService`, additive migration `2026_10_24_120000_add_scope_structure_declarations`, one FORCE-RLS append-only declaration table, three invoker functions, bilingual validation and16 new tests. New declarations persist explicitly proposed node edges and exact observed-row coverage as **claims**, separately from narrative text. There is no structured-declaration HTTP/UI endpoint yet; the existing narrative detail remains narrative-only and does not display a structure or certify that an opinion covers it.

**Not the complete technical proposal package.** No evidence reference/URI/title/file is accepted, fetched, copied or shared in this increment. No structure-bound review aggregate/writer or positive technical review, historical authority, independently complete engineering coverage, evidence validity/retention, admission writer, effective selection, reservation, MR/PO/stock/GRN change. Declarations remain `UNVERIFIED_STRUCTURE`; API readiness/package/operational authorization are always false and gross=null. Source observations remain UNADMITTED. SA-01/02 and DA-01..04 unchanged; no new role/grant/default nominee.

## Strict internal contracts

```text
declare(project, authenticated_original_actor, {
 proposal_id, expected_proposal_version, expected_proposal_digest,
 nodes: [{id,project_id,kind,parent_id}],
 coverage: [{scope_id,row_ids:[...]}]
})
inspect(project, authenticated_original_actor, declaration_id)
```

No client author/state/hash/source-binding/policy/evidence fields. Unknown top-level or nested node/coverage fields fail. UUIDs and kind enumeration validated. Parent key must explicitly exist, null only for the root; arrays must be lists. Limits:1..500 nodes, exactly1 covered scope in this first increment,1..10000 row UUIDs, maximum262144 bytes of node+coverage JSON (DB canonical text bound as well; app compact bound may reject/accept earlier and DB remains authoritative). Oversize source observed row sets are also blocked. No floating quantities or source row payloads are accepted or returned.

Only the **persisted original proposer**, with current independent propose AND source-reader nomination, can declare against their same-company exact latest proposal. The project is locked and archive rejected. A reviewer holding propose nomination still cannot append structure to another person's envelope. Exact expected proposal version/digest, no prior declaration and no prior narrative opinion are required. Client expected hash is a concurrency/content precondition, never authority.

The existing authenticated reader supplies source binding/visibility gates before the claim is considered. DB insert guard independently repeats actor/project/company/archive/current nominations/full source-row binding, exact author/version/digest/latest state and opinion cutoff, then canonicalizes the structure. Client envelope/digest/policy metadata cannot replace server derivation. Raw SQL remains trusted infrastructure, not a per-human authentication protocol; application caller identity must be genuine. No SECURITY DEFINER or source widening.

## Structural checks — not historical truth

- Exactly one explicit PROJECT root matching the bound project, null parent. Same-project UUIDs, unique IDs after UUID normalization.
- Fixed kinds and parent types: PROJECT→FACILITY; FACILITY→AREA or SUBSYSTEM; AREA→SUBSYSTEM. Missing/wrong-kind parents, cycles, foreign nodes, duplicates and disconnected/extraneous nodes fail. Nodes must form the exact ancestry closure of the single covered leaf.
- In this conservative first increment, the node **identity/kind universe must exactly match the frozen observed ancestry fragment**. Each actual facility/area/subsystem must independently be visible under the original caller's current DB RLS and exact project. Only identity visibility is checked in today's entity tables; current parent fields/names are not read or used as historical proof. The declared edges are claims, not automatically copied by production code from today's graph.
- Coverage must explicitly name the exact source leaf, be nonempty, contain every authenticated currently visible accepted row UUID for that scope/pin exactly once, with no unknown/omitted/duplicated/misassigned row. Source binding separately proves the current visible row set matches the immutable full captured digest; a partial visible set cannot certify full coverage.
- Same-company/single-scope/header restrictions of the earlier source adapter remain. New/unobserved historical identities, removing a captured identity, split/merge coverage and an empty/zero/retirement disposition remain blocked pending their authentic historical/disposition adapters. This is a first-increment safety limit, not a change to accepted ADR-018 optional-area/header/mapping/withdrawal policy.

The checked coverage is completeness of **this authenticated row set**, not independently complete engineering scope. Material presence cannot prove no missing material/scope. The frozen identity set is not authoritative historical topology. A matching claim remains unverified even when every structural check passes.

## Immutable version/content identity

One declaration per exact proposal UUID, enforced by UNIQUE. Declaration version equals its parent proposal version. Structure changes require a new narrative proposal version and a new declaration; no detached mutable latest structure or edits. Version numbers can skip narrative-only proposal versions. Server derives the predecessor from the same original proposer/project/company/batch/scope/pin stream's last earlier declaration. History is preserved, never carried into the next version as an approved claim.

Canonical envelope schema1 uses PostgreSQL jsonb::text UTF8 SHA256 and binds event UUID, project/company, actual proposer, version/predecessor, exact proposal UUID/digest, authenticated source binding, canonical node/coverage JSON and current matched action/reader policy UUIDs. Nodes sorted by kind/canonical UUID, row UUIDs sorted; case/order do not create duplicate-identity loopholes. Server timestamp is immutable but outside the content digest, as in earlier envelopes. Hashes are not evidence signatures or technical authority.

Internal inspect first uses the original actor's authenticated narrative reader: own proposer needs current propose+reader; another same-company actor current review+reader. Then every node identity/row coverage is revalidated under original visibility before returning any manifest, along with digest/content/proposal alignment; gates are repeated before return. Hidden parent identity, partial source, stale role/read grant or hidden/missing ID fails closed (opaque404 on read). Archive remains readable, no writes. Historical declarations stay bound to their historical exact proposal and are labelled not-latest when applicable. No implicit cross-company/record-only reading path is added.

## Important correction: narrative opinions must not silently cover structure

An old narrative-only opinion signs neither nodes nor coverage. Reusing it as review of a later attachment would be a content-binding defect, even with the same proposal UUID. This increment explicitly blocks that transition:

1. If any narrative opinion already exists on a proposal, no structure can be appended to that same version. Submit a new proposal version.
2. Once a structure exists, the **old narrative-only review method is disabled** for that proposal. A bounded check in `ScopeProposalEnvelopeService::review()` returns bilingual validation after existing actor/source checks. A new BEFORE INSERT cutoff trigger also rejects raw narrative review55000.
3. Both orderings lock the same project as the declaration/nomination/archive paths. Two ordinary PDO connections tested the competing transaction times out55P03; after the first commit, the incompatible retry is rejected23514 (declare after opinion) or55000 (opinion after declaration). Existing narrative-only proposals/opinions without a declaration continue unchanged.
4. No structure review outcome/writer is invented. Inspect deliberately returns evidence_attached=false, technical_review_bound=false. A future independent review must bind **both exact narrative and declaration identities/hashes plus exact independently visible evidence versions**, not reuse a narrative opinion's UUID or a “latest” lookup.

**Metadata completeness boundary:** cutoff/head lookups rely on the existing complete same-company/project metadata-RLS contract, like nomination-policy head resolution. They are not certified under administrator-added selective policies hiding that company's own declarations/opinions. This increment yields no positive approval in any case. Before an admission writer or complete review inventory, prove dependency-inventory closure rather than interpreting an invisible row or an empty/bounded list as absence. The two-connection test is not universal personnel/plan/evidence revocation linearizability.

## Persistence/migration details

New table has exact composite project/company/proposal FK, one-per-proposal UNIQUE, self predecessor FK added only after PK exists, policy/user FKs, state/digest/JSON CHECKs, ENABLE/FORCE RLS and immutable trigger. SELECT/INSERT only under own company/exact project; no UPDATE/DELETE policy. Guards/helpers are invoker-only. This is company-level DB isolation; internal app facade enforces per-human authority. New trigger is added alongside, not by editing the previously delivered review migration/guard.

Up creates empty declarations without source/observation rewrite or invented legacy claims; no historical narrative opinions are relabelled. Down removes the new cutoff trigger, declaration table and3 functions; it destroys declaration history and requires coordinated code rollback because the updated review service expects this migration. It is not a production history-preserving downgrade. A controlled old envelope migration test now explicitly tears down the newer dependent FK/table before down/up of its exact parent and restores the child in finally. No FK/RLS/immutability was weakened for tests.

## Verification

**56 application tests /290 assertions PASS,135.80s**:16 new ScopeStructureDeclarationTest +22 persistence +18 authenticated read tests. Only focused/affected, not full regression. Initial14/62 overlaps; final added raw SQL/cutoff races and parent-fixture ordering are included. No failed logic test; final static run required a second format-only pass of new test argument spacing, then seven-file Pint/lint passed. No semantic change or unnecessary full rerun after formatting.

Coverage: source/parent identity, unverified state, canonical hash; UUID case/order and duplicate IDs; client actor/state/source/evidence rejection; wrong-kind/parent/cycle/project/missing/unobserved nodes; exact once coverage/omission/unknown/wrong scope/empty; caps/list/UUID shape; original author/current exact version/digest/duplicate; both opinion/declaration orderings; historical predecessor/no reuse; independently hidden facility blocks read/write; partial source/read revocation; company/archive/FORCE-RLS/immutability; transaction rollback and populated up/down with observation bytes unchanged; two-connection cutoff and raw SQL positive-state/shape denial. Old persistence and read behavior affected-tested.

Seven PHP Pint/lint, whitespace, ordinary epcc NOSUPERUSER/NOBYPASSRLS, FORCE RLS/FK/CHECK/unique/trigger/invoker audit. Existing declaration source/reader migrations, reader/controller/routes remain unchanged; original envelope service change is only the explicit cutoff, old test change only dependent-FK fixture ordering. No new HTTP route/UI capture, browser, full regression/CI, production migration/grant/data, deployment/commit/push. Private socket-only PG stopped after verification. Evidence: `docs/evidence/2026-10-24-scope-structure/`. Source archive is a dependent increment, excludes runtime/vendor/DB; old archives are not regenerated.

## Next bounded work / recommendation

Implement an independent **structure-bound unverified review** with exact proposal+declaration identities/hashes and complete dependency visibility, then controlled evidence attachments resolved by original caller before persistence/display. Existing CDE byte/topology diagnostic services are components, not authority for historical correctness, retained/valid evidence or independent complete coverage. Do not accept arbitrary URI/issuer/hash strings as controlled evidence and do not display hidden titles/files through a nomination. Add all-scope/cross-company adapters only with explicit closure. Positive admission/record/lifecycle/effective selection/complete commitments and DA-03 reservation remain gated. No settled SA-01/02 or DA-01..04 question is reopened.
