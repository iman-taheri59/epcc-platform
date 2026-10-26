> **2026-10-25 evidence preflight prerequisite:** [Original-caller CDE byte preflight](SCOPE-EVIDENCE-PREFLIGHT-EN.md) now gates both selected references with independent doc.view and actual discipline before any file stream. It does not attach evidence, issue a permanent composition identity or establish retention/current validity; prior readiness/approval flags remain false.

# Exact structure-bound independent opinions — non-approving internal service

**2026-10-25 · implemented and focused/affected tested; not deployed.**
[فارسی](SCOPE-STRUCTURE-REVIEWS-FA.md) · [Declarations](SCOPE-STRUCTURE-DECLARATIONS-EN.md) · [Narrative reading](SCOPE-PROPOSAL-ENVELOPE-READ-EN.md) · [Accepted actors, final section](SCOPE-ADMISSION-AUTHORITY-EN.md)

## Delivered and boundary

New `ScopeStructureReviewService`, additive `2026_10_25_100000_add_scope_structure_reviews` migration, FORCE-RLS append-only opinion table, invoker insert guard, bilingual validation and 20 tests. A non-approving opinion now binds **both exact immutable narrative and structure UUIDs, versions and digests**, with fresh independent reviewer/source/all-node visibility. Earlier “no structure-bound review writer” statements are superseded only for this unverified opinion service.

This is not engineering approval, a complete technical/evidence package or admission. Outcomes only COMMENTED / CHANGES_REQUESTED; state only UNVERIFIED_OPINION. Always ready_to_admit=false, package_verified=false, operationally_authorized=false, engineering_approval=false, evidence_verified=false, admission_state=UNADMITTED and gross=null. content_binding_verified=true means exact saved-content binding/current visibility checks succeeded, **not historical correctness, evidence validity or permission to reserve**.

No evidence URI/title/record/file input, CDE fetch/sharing, positive review, admission/correction/withdrawal writer, effective selection/complete commitments/reservation, MR/PO/stock/GRN change. No new HTTP route, UI, list/register, download/export or new default grant/role. Same-company/single-scope limits remain. Existing narrative UI does not show these new opinions. Existing declaration inspect's constant technical_review_bound=false remains a blocking technical-readiness flag, **not an inventory test saying no non-approving opinions exist**. No old opinion or declaration is relabelled as approved.

## Contracts

```text
review(project, authenticated_original_actor, {
 declaration_id,
 expected_declaration_version, expected_declaration_digest,
 expected_proposal_id, expected_proposal_version, expected_proposal_digest,
 outcome: COMMENTED | CHANGES_REQUESTED,
 reason
})
inspect(project, authenticated_original_actor, exact_opinion_id)
```

Internal methods only; caller must supply the genuine authenticated original User. Client reviewer/proposer/state/binding/policy/evidence or unknown keys are rejected, not trusted identity. Reason is nonblank text up to 4000 characters; DB also rejects whitespace-only raw SQL reasons. Text remains an untrusted narrative, not a document link or controlled evidence declaration; future rendering must escape it. Expected IDs/hashes are optimistic exact-content preconditions, never credentials.

## Append path

1. Bind actor's own company/project, lock project and reject archive.
2. Load/inspect exact saved declaration through the authenticated structure reader. This independently requires current source reader plus eligible original-proposer/read or independent-review/read path, exact full source-row digest and visible ancestry identities; hidden/partial source or hidden ancestor fails closed, without switching to a source owner's tenant.
3. Derive actual proposer and source tuple from persisted records; require independently current review nomination and reviewer different from the actual saved proposer/declarer. Propose/record/QA or configurator status does not substitute for review or source access.
4. Require this narrative still be its author's current stream version, and every expected narrative/declaration ID/version/hash match exactly. Never substitute newest content for the expected content. Reject an existing opinion by the same reviewer on this declaration; outcome/reason changes cannot edit or replay it.
5. DB independently repeats exact tenant/author separation, pair binding/versions/digests, state, hash integrity, source binding, independent live nominations and revalidates canonical nodes/coverage/current node visibility. It derives matched reader/review policy UUIDs, server timestamp and canonical opinion envelope/digest. Client metadata cannot override them.
6. Recheck source/structure/action eligibility after INSERT, then return a bounded identity result with all positive readiness/approval flags false. Failure rolls back the append.

Canonical format is PostgreSQL jsonb::text UTF8 SHA256, not client JSON or an issuer signature. Schema 1 / kind UNVERIFIED_STRUCTURE_OPINION binds event/project/company/reviewer, both exact content identities+versions+digests, DB source binding, outcome/reason and matched current nomination/reader policy UUIDs. Timestamp is immutable server metadata outside the content digest. Audit policy IDs record checks at append time, not perpetual rights.

## Read / history semantics

Exact-ID inspect first loads same-company/project declaration-reference metadata only, then performs full original-actor structure/source visibility checks before reading opinion reason/envelope. Original proposer requires current propose+source reader; another same-company actor current review+source reader. No owner/reader-only/record-only shortcut is introduced. Missing/foreign/hidden/ineligible reading is opaque 404.

Inspect verifies opinion SHA, schema/kind, all projected content identities/versions/actor/tenant/outcome/reason/policy alignment, actual independent reviewer and exact DB source binding against its authenticated parent. It rechecks declaration/source visibility before returning an explicit field allowlist; no envelope/source rows/nodes/evidence bytes are returned by this opinion read method. Corrupt content is not projected as a bound opinion.

Historical opinions remain attached to the historical pair, and read as is_latest=false after a new parent version. They never carry forward. New versions require a fresh declaration and fresh opinion. A past reviewer losing eligibility does not delete history; the current viewer still needs their own independent current reading/action/source rights. One immutable opinion per reviewer/declaration is implemented; opinion edits/amendment/withdrawal are not implemented and no positive authority depends on these notes.

Existing narrative-only opinion cutoff remains unchanged: adding structure after a narrative opinion requires a new proposal version; once structure exists, the old narrative opinion API remains disabled even after a bound opinion is stored. These new opinions use a separate aggregate and never get imported into the old narrative-opinion list or interpreted as technical approval.

## Isolation and concurrency

Composite project/company FKs bind opinion to exact declaration and narrative. The migration adds only a composite unique declaration tenant key to support the FK, without editing its old migration or rows. Unique declaration/reviewer, digest/positive-version/state/outcome/reason CHECKs, ENABLE/FORCE RLS and immutable trigger. Same-company/exact-project SELECT+INSERT only, no UPDATE/DELETE policy; ordinary epcc role, invoker-only guard, no SECURITY DEFINER/source widening. Per-person checks live in the authenticated application facade; company-level DB metadata RLS is **not a proof of which human is using a raw SQL actor UUID**.

Appends serialize on the same project row as new proposal/declaration/nomination/archive operations. Two ordinary PDO connections tested an uncommitted opinion blocks a competitor 55P03, then duplicate retry fails 23505; a competing new parent version also blocks 55P03, then old-pair review retry fails 23514 after commit. No duplicate or superseded opinion was written. This is bounded serialization, not general personnel/plan/evidence-revocation linearizability or an authorization lease.

Current-version/head and duplicate lookups rely on the normal complete same-company/project metadata policies, as in preceding increments. No general invisible-dependency/review-inventory certificate is claimed under administrator-added selective policies. Full visibility of the **actual source row set and declared node identities** is enforced. A missing/bounded list must not be promoted into a complete admission or commitment inventory.

Up creates empty opinions, no backfill/reclassification or source/observation mutation. Down drops the new table/guard and added tenant key, child first; destructive to new opinion history, not a production history-preserving downgrade. Two old migration fixtures now explicitly drop this newer child before testing the exact declaration/envelope parent migration and restore children in order. Only those fixture methods changed; existing production services, guards, migrations, routes and views are byte-identical.

## Verification

- Affected run: **58 application tests/257 assertions PASS, 167.61 s** = 20 new opinions + 16 declaration + 22 narrative persistence.
- Final narrow hardening (DB whitespace-only reason rejection and read schema/kind/policy/source-binding alignment): **all 20 new tests/95 assertions PASS, 55.53 s**. Unchanged companion code was not needlessly rerun. This is authoritative for the final new service/migration. Earlier 15/73 and 58/257 overlap; never sum them. No full regression.

Cases include exact dual binding/actual reviewer/unverified flags; self-review and browser-field injection; every stale ID/version/hash; duplicate/outcome edit denial; changes-requested non-approval; independent live action+reader; hidden ancestor/partly hidden source; new version/no carry-forward; old narrative cutoff; owner/company/project/archive boundaries; FORCE RLS/no UPDATE/DELETE; rollback and populated migration with observations byte-identical/no default opinions; no CDE/stock/source mutation; raw SQL self-review/wrong hashes/versions/positive state/blank reason/hidden dependency/revoked grant; inactive actor; two-PDO duplicate+supersession serialization; corrupt-opinion read refusal.

Corruption test uses transaction-only fixture UPDATE policy/disabled immutable trigger and rolls all DDL/data back. Final audit confirms original policies and enabled triggers; no app/migration privilege weakening. DB helpers internally hash source row payloads including quantities; query trace proves no direct payload/CDE/stock result path, not that PostgreSQL never reads source bytes internally.

Seven PHP Pint/lint, whitespace, ordinary epcc NOSUPERUSER/NOBYPASSRLS, FORCE-RLS/FK/CHECK/unique/invoker/enabled-trigger audit, prior source invariants and new guide links passed. No new browser/HTTP capture, full regression/CI, customer migration/data/grant, deployment/commit/push. Private socket-only PG stopped afterward. Evidence: `docs/evidence/2026-10-25-scope-structure-reviews/`. Initial private migration used a provisional Oct24 filename; renamed to **local-date Oct25 before all tests**, migrate:fresh removed that draft tracking row and final audit confirms only 2026_10_25_100000. No customer migration was renamed. Dependent source archive excludes runtime/vendor/DB; old archives not regenerated.

## Next recommendation — evidence must get its own immutable composition identity

The same content-binding defect would recur if evidence were attached later and old pair-bound opinions were silently treated as evidence review. **They are not evidence-bound.** Before accepting/displaying evidence, resolve exact controlled revisions/files with the original actor's independent CDE authority, verify bytes/identity and record a separately immutable evidence manifest/composite-package identity. Any added/changed evidence needs a new exact composition version; no automatic carry-forward or relabelling of these opinions. Future independent review must bind that complete composition as well as narrative+structure.

Existing CDE byte/topology diagnostics are useful components, not proof of historical authority, coverage completeness, retention or current validity. Same-company/all-scope/recording participant read paths remain bounded; no QA/purchase inheritance. Positive admission/lifecycle/effective selection/complete commitments and DA-03 reservation stay closed until their gates are implemented. SA-01/02 and DA-01..04 remain settled; do not re-ask or silently replace them.
