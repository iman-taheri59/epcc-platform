> **2026-10-24 subsequent read slice:** [exact-ID authenticated read-only envelope inspection](SCOPE-PROPOSAL-ENVELOPE-READ-EN.md) is implemented. Earlier “no dossier read API/UI” text below describes the persistence-only slice. No list/write UI, structured technical manifest, positive review or admission writer was added.

# Source-bound unverified proposal/review envelopes — first persistence increment

**2026-10-24 · implemented and focused/affected tested, not deployed.**
[فارسی](SCOPE-PROPOSAL-ENVELOPES-FA.md) · [Accepted actor policy](SCOPE-ADMISSION-AUTHORITY-EN.md) · [Nomination UI](SCOPE-ADMISSION-AUTHORITY-WEB-EN.md) · [Earlier technical draft](ADR-024-SCOPE-ADMISSION-CONTRACT-EN.md)

## What this is — and is not

An additive internal `ScopeProposalEnvelopeService` and `2026_10_24_110000_add_scope_proposal_envelopes` migration now persist **source-bound narrative proposals** and independent **unverified review opinions**. Identity attribution and source reference are checked by the application/DB path; narrative technical truth is **not authenticated**. This is not the complete structured topology/coverage/evidence package from ADR-024. It does not call the offline prototype and does not promote a structural match, source hash or free-text opinion into authority.

Two FORCE-RLS append-only tables: scope_proposal_envelopes and scope_proposal_reviews. No existing executable source changes, source/observation rewrite, new role/grant, new UI/HTTP endpoint, effective selection, admission event writer, reservation/MR/PO/stock/GRN integration. Existing proposal/review/record nominations remain independent. **No positive approval or admission outcome exists here.** Successful service results always retain ready_to_admit=false, package_verified=false, operationally_authorized=false, admission_state=UNADMITTED and gross=null. Canonical content hashing is integrity/binding, not an issuer signature or evidence-validity certification.

### Deliberate fail-closed first-increment limits, not new business policy

- One exact batch/document/subsystem/pinned-hierarchy source whose **entire captured row set** belongs to that one scope and pin; header null or this scope only. Multi-scope batches and broader headers are blocked even if one row/scope is visible or a caller claims more grants. Supporting them requires all-scope and ancestor/dependency visibility closure first; this is not a redefinition of accepted coverage/header policy.
- Same-company proposal/review persistence only. No cross-company sharing, employer/configurator reading shortcut, CDE or evidence access is invented. Cross-company participant eligibility remains accepted by SA-01; safe cross-company package visibility is a remaining adapter, not a permanent same-company actor restriction.
- Payload is a required nonblank narrative statement, at most4000 characters, not a structured authoritative topology/coverage manifest, retained document or evidence URI. Narrative is untrusted text, must be escaped if later displayed. No public dossier listing/read API is supplied yet.
- Review outcomes only COMMENTED and CHANGES_REQUESTED. One immutable opinion per reviewer per proposal version; no APPROVED/ADMITTED/waiver, edits, review correction writer or admission correction/withdrawal. New proposal versions preserve old opinions but never copy them forward.

## Source authentication and canonical identity

Under original actor company/project context, current reader eligibility AND separately nominated propose/review capability are checked by ScopeAdmissionAuthorityService. Project row is locked; archive is rejected. DB guards independently repeat current company/project, active account, project/company participation, PIPING membership, plan/pack, current user/company-role selection against **latest** independent action and reader policies. Suspended/replaced heads never fall back to older grants. Invoker-only helpers return matched policy IDs for audit, not a new capability. Fresh actor preflight is repeated after insertion; failure rolls the append back.

The source-binding helper joins exact accepted batch and captured observation project/source-company/document/revision tuple, visible PIPING document and accepted batch state. It checks observation kind/state/version/project/batch/pin/header, recomputes PostgreSQL SHA256 of payload and **all currently visible accepted source rows**, and requires equality with the immutable observation digests. Hiding only one of two rows therefore blocks binding even if the ordinary exact-scope EXISTS check still sees the other. This does not expose source row payloads/quantities, reconstruct today's graph as history or certify historical topology. Missing/legacy/unverifiable observations/pins fail closed.

Binding persisted from DB facts: schema_version, project_id, source_company_id, batch_id, document_id, revision_id, scope_id, observation_id, payload_hash, source_rows_hash, hierarchy_revision_id, header_scope_id. Client binding, actor identities, hashes, policy IDs, observation ID, predecessor and state cannot be supplied through the service whitelist. Trigger **derives/overwrites** binding, observation and matched current policy IDs, envelope JSON, digest and server timestamp even on trusted raw SQL insertion. Positive state fails a CHECK rather than being silently accepted.

Proposal stream key: project/company/**original proposer**/batch/scope/pin. Expected_version=0 opens a stream; further submissions by that same actual actor require exact latest expected version. New version appends a new UUID and DB-checked predecessor; no UPDATE of head/history. Different proposers have separate streams, not a shared editable draft. Initial and later service calls derive proposer_id exclusively from the actor argument in the trusted internal application path, never input fields.

Canonical format is **PostgreSQL jsonb::text UTF8 SHA256**, not RFC8785 or a client JSON serialization. Proposal envelope schema1 binds kind UNVERIFIED_NARRATIVE_PROPOSAL, event ID, project/company/proposer, version/predecessor, exact statement, DB source binding and matched action/reader policy IDs. Timestamp is independently server-generated and immutable but is **outside the content digest**. Hashing includes technical claims as submitted; it does not make them true.

## Internal service contracts

```text
submit(project, actor, {
 batch_id, document_id, scope_id, hierarchy_revision_id,
 expected_version, statement
})

review(project, actor, {
 proposal_id, expected_version, expected_digest,
 outcome: COMMENTED | CHANGES_REQUESTED, reason
})
```

These are internal methods, **not public endpoints**. `actor` must be the authenticated original actor supplied by the trusted application entry point; arbitrary User arguments are not an authentication protocol. Service strict whitelists reject client proposer_id/reviewer_id/binding/digest/approved-state injection. Expected review digest is an optimistic exact-content precondition, not an approval credential.

Review first loads the actual persisted same-company/project proposal, derives its proposer/source and requires a separately current review nomination plus independently current reader eligibility. Reviewer must differ from the **persisted** proposer. Expected version/hash must match and proposal must still be its stream's latest version. DB repeats independence, current grants/source binding, digest integrity and supersession checks, then stores actor, exact proposal ID/version/digest, outcome/reason, matched current policies and canonical review envelope/digest. Expected digest can never replace the stored proposal or actor. Duplicate opinion/replay is rejected; no new row or automatic “latest approval” substitution.

A previously permitted proposer/reviewer becoming ineligible does not erase history. Audit policy IDs describe actual checks at append time, not perpetual eligibility, a reusable signature or operational permission. There is no acceptance writer or downstream authority to keep green. Future admission must implement accepted fresh-source/evidence/actor/review dependency requirements atomically, not treat old nomination IDs as live rights.

## Isolation, concurrency and rollback

Composite tenant FK binds review to proposal project/company; source document/scope FKs, unique per-author stream/version, predecessor FK and exact guard, unique proposal/reviewer opinion, digest/shape/state/outcome CHECKs and immutable triggers protect append-only history. New tables have only company+exact-project SELECT/INSERT policies; no UPDATE/DELETE policy. **This is company-level DB isolation, not universal per-human raw-SQL RBAC**. There is no application dossier read/list endpoint bypassing per-person gates. Ordinary raw DB clients are trusted application infrastructure, not authenticated end users; the DB cannot prove which active same-company person is using a supplied actor UUID. No SECURITY DEFINER or tenant impersonation/source widening.

All appends lock the same project row that nomination configuration/archive changes use; exact expected version/review-current checks occur inside that transaction. Two real ordinary-role PDO connections tested a competing insert blocked by the first transaction (55P03 lock timeout), rollback, first commit, then stale retry rejected23514; one proposal version remains. This establishes this bounded project/stream serialization, **not universal concurrent personnel/plan/evidence revocation linearizability**. Transaction rollback of proposal+review leaves neither. Last preflight/source checks are not a time lease. No operational reservation is written.

Up creates empty additive tables/helpers without backfill or source mutation. Down removes only these two tables/four functions, child before parent; it is destructive to new dossier history and must not be used as a production history-preserving downgrade. The populated test is a controlled reversible-fixture migration test, not permission to delete live audit history.

## Verification

**57 application tests /292 assertions PASS,122.57s**:22 new ScopeProposalEnvelopeTest +17 internal authority +18 authority HTTP. Focused/affected, not full regression; prior focused runs overlap. Cases: no defaults/admin inheritance; DB-derived canonical binding/real actor/policies; injected identities/state/hash rejected; exact independent review; no positive approval; stale version/hash/duplicate review; new-version predecessor/no opinion carry-forward; hidden source and partial-row visibility; reader/action revocation; company/owner isolation; archive; FORCE RLS/immutability; all-or-nothing transaction rollback; populated migration/unchanged observation bytes/no backfill; trusted raw SQL overrides/prohibited self-review/false digest/positive state; multi-scope denial; inactive/role loss; two-connection serialization/stale retry.

Five new PHP files Pint/lint, whitespace, ordinary epcc NOSUPERUSER/NOBYPASSRLS, two FORCE-RLS tables, invoker helpers and FK/CHECK audits; prior delivered web executable hashes unchanged. No browser/HTTP dossier route, customer migration/grant/data, full regression/CI/deployment/commit/push. Private socket-only PG stopped afterward.

Evidence: `docs/evidence/2026-10-24-scope-proposal-envelopes/`. Early attempts retained: unqualified artisan migration used historical .env socket and could not connect (no DB action); explicit testing env selected private test DB. Self-FK had to be added after PK creation (Laravel statement ordering). Functions must be CREATE OR REPLACE for migrate:fresh fixtures, which drop tables but retain functions. A later added role-loss fixture required its ProjectMembership import. These are fixed; final57/292 is authoritative, earlier counts overlap. Source package is a dependent increment, excludes runtime/vendor/DB, and does not regenerate old packages.

## Remaining work and next recommendation

This closes only **source-bound identity/versioned narrative persistence and independent non-approving opinion storage**, not the complete technical proposal package gap. Next implement bounded authenticated read/inspection of envelopes and append-only structured topology/coverage declarations, with evidence references resolved under each original actor's visibility **before any sharing or positive review**. Extend all-scope closure explicitly; do not silently loosen the single-scope guard. Controlled evidence integrity/retention/current validity, authoritative historical topology, independently complete coverage and exact review/content dependencies remain blocking adapters. Only then consider admission event lifecycle and record nomination, followed by genuine effective selection/complete commitments/DA-03 reservation. Keep observations UNADMITTED and gross/remaining unknown. SA-01/02 and DA-01..04 are settled; no repeat actor-policy questions.
