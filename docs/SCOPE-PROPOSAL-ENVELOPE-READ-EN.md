> **2026-10-25 status:** [Exact narrative+structure-bound independent non-approving opinions](SCOPE-STRUCTURE-REVIEWS-EN.md) are now implemented internally (COMMENTED/CHANGES_REQUESTED only). Any absence of that bounded writer below is historical. This is not evidence-bound engineering review/admission, and the existing narrative UI/readiness flags are unchanged.

> **2026-10-24 structure increment:** [separately persisted unverified topology/coverage claims](SCOPE-STRUCTURE-DECLARATIONS-EN.md) now exist as an internal service. Earlier absence-of-structured-declaration statements below are historical. This narrative page/reader is unchanged and does not display or review structure. No evidence/positive review/admission is enabled.

# Read-only inspection of a source-bound proposal envelope

**2026-10-24 · implemented, focused/affected tested; not deployed.**
[فارسی](SCOPE-PROPOSAL-ENVELOPE-READ-FA.md) · [Persistence contract](SCOPE-PROPOSAL-ENVELOPES-EN.md) · [Accepted actor choices](SCOPE-ADMISSION-AUTHORITY-EN.md)

## Delivered / deliberately still absent

New `ScopeProposalEnvelopeReader`, dedicated controller, one escaped EN/FA/RTL Blade detail view and one authenticated private/no-store GET route. Existing proposal submit/review service, nomination service/controller, their tests and migrations are byte-identical; no schema/RLS/guard modification, grant/backfill, source mutation or new POST route.

This closes **bounded exact-ID human reading** of the earlier narrative envelope. It does not add a full topology/coverage declaration, controlled-evidence read/validity adapter, positive technical review, admission writer, effective selector, MR/PO/stock/reservation or GRN. Every response keeps ready_to_admit=false, package_verified=false, operationally_authorized=false and gross=null (unknown, not zero). Content digest integrity is checked; engineering package authenticity/completeness is not certified.

## Entry point

```text
GET /projects/{projectCode}/materials/scope-proposals/{proposalId}?lang=fa|en
route: materials.scope-proposals.show
```

A **known exact UUID** from the internal submit result is required; there is no new directory, general search, source picker, export/download or create/review UI. This intentionally does not discover hidden proposal IDs. Internal persistence is still not exposed as a write endpoint. HEAD is supported by the framework; POST/DELETE at this route have no handler (405). Existing authenticated workspace middleware is retained. Successful pages use Cache-Control private,no-store,max-age=0. Language link preserves only project/proposal and language, not caller-supplied actor/source/action claims. An ordinary direct route request remains independently authorized on every access/link.

## Bounded read-authority contract

Inside the original actor's own company/project context:

1. Load only this company's exact project/UUID metadata: recorded proposer, exact source tuple and DB-derived binding. **No narrative, full envelope or opinion text is queried before per-human gates.** Different company/project/unknown/malformed UUID is opaque404.
2. Require independently current source-reader eligibility under the original actor, current user/membership/PIPING/participant/plan/pack gates and exact visible source.
3. If actor is the **persisted original proposer**, require currently nominated `propose`; otherwise require currently nominated independent `review`. Configurator status, reader-only and record-only nominations create no reading path in this first read increment. The original proposer cannot use their own review nomination as a substitute after propose revocation.
4. Recompute the existing DB binding helper and require equality with the exact saved binding, including full visible row-set/payload digests, observation and pin. A partly hidden row set fails even if an exact-source EXISTS still succeeds. Same-company/single-scope/header limitations of the persistence increment remain. No today's-graph ancestry or evidence permission is inferred.
5. Only then read full narrative envelope and opinions, verify hashes/content alignment, and project an explicit allowlist of fields. Repeat live source/action/role and binding checks before returning.

Forbidden source/action eligibility is deliberately normalized to the same opaque404 as missing/hidden data; the response does not reveal statement/digest/history. No fallback to old grants or cached read models. Reads do not hold an authorization lease or prove general instantaneous concurrent revocation; repeated checks cannot replace future atomic writer gates.

**These are conservative implementation limits, not an amendment to SA-01/SA-02.** Same-company sharing only; no configurator/employer shortcut. In particular, record-only reading is not implemented yet, rather than permanently forbidden. A future recording workflow must use the accepted separate current record nomination and **persisted exact proposer/reviewer participation**, plus independent source/evidence access. It must not incorrectly require that a recorder also retain proposal/review nomination forever, nor expose a client `action=record` bypass in this reader. No current recording endpoint needs that path.

## Version and opinion semantics

- Display the requested stored version, never silently substitute the newest version. Show “historical” when a newer version exists, with independently gated links to latest and recorded predecessor.
- Original narrative, author UUID, version, digest, time, DB source-binding references and submission-time policy UUIDs are shown. Policy UUIDs are audit facts, not reusable live permission. No source row payload/quantity, CDE/evidence bytes or user-profile expansion is returned.
- Show opinions **only for this exact proposal UUID**, verifying their stored proposal version/digest, independent reviewer and allowed non-approving outcome. A newer version's comments cannot enter an old view and an old comment never appears on a newer empty version.
- Both COMMENTED and CHANGES_REQUESTED remain non-approval. No opinions is not approval. The first20 visible immutable opinions are displayed; a21st visible row sets an explicit “more exist” warning. This is not a complete-review inventory/visibility certificate. No total-demand computation or all-dependency completeness is inferred from a bounded display.
- Past opinions remain historical facts even if the old author later loses eligibility; viewing them does not reuse that author's past grant as live authority.

The read model verifies proposal digest against PostgreSQL-jsonb UTF8 SHA256 and alignment of ID/proposer/project/company/version/statement/source binding; opinion digest and ID/tenant/reviewer/outcome/reason/proposal ID/version/digest alignment are checked before rendering. A corrupt/nonmatching fetched record yields opaque404 rather than a partial page or “verified package.” Only the bounded fetched opinion set is inspected, not an unrestricted completeness claim. Normal actor/DB paths already have immutable guard/CHECK protection; this adds fail-closed reading defense, not a promise against a malicious database owner replacing all data/guards/hashes.

## Archive and text safety

Archived projects remain readable with a clear read-only banner; no proposal/review mutation form exists. Existing writers still reject archive (unchanged and affected-tested). HTML in narratives/opinions is escaped, multiline whitespace preserved. Submitted URLs/scripts/images are plain text, never fetched or embedded. No CDE/library access is inherited by reading a human's narrative claim.

Original tenant context, FORCE RLS and invoker helpers are unchanged. Company-level database metadata isolation is not universal per-human raw SQL authorization; the new application facade is the per-person reading gate. Internal User argument must come from trusted authenticated application identity. Browser actor/company/source/action/digest query parameters cannot alter it; they are not consumed and are not retained by language/version links.

## Verification

**58 application tests /371 assertions PASS,125.32s**:18 new ScopeProposalEnvelopeReadTest +22 existing persistence +18 authority HTTP. Focused/affected only, not full regression. Earlier16/114 read-only run overlaps and is not additive.

Tests cover guest/method boundary; both actor paths EN/FA/RTL and private/no-store; owner/company/project/unknown IDs; reader-only/record-only/self-review nomination cannot widen reading; live action/read revocation; fully/partly hidden source; exact old/new narrative/opinion binding and no carry-forward; archive read-only; browser claims ignored; XSS/remote image escaping; inactive/role/participant loss; no mutation or direct raw-source/CDE/stock query in HTTP trace; no narrative/opinion query before human gate; corrupt proposal/opinion fail closed;20-of21 bounded opinion warning.

Corruption cases use explicit **test-only** UPDATE policy/disabled immutability trigger inside a transaction that is always rolled back. Both DDL/data and original enabled triggers/policies are restored; this is never app/migration/deployment behavior or privilege weakening. Private ordinary epcc NOSUPERUSER/NOBYPASSRLS audit confirms FORCE RLS, unchanged SELECT/INSERT-only policy types, enabled immutable/insert triggers and invoker functions afterward. DB helpers internally hash observation/current rows, including their quantities; HTTP query trace checks prove no direct raw-payload return/query path, not that no underlying source bytes were read inside PostgreSQL.

Six PHP Pint/lint, Blade compile/clear, single GET route audit, prior service/migration/test bytes and exact prior route-file restoration after removing only the new import/GET, guide links/whitespace, four locale-correct CSRF-redacted synthetic server-rendered HTML captures passed. Captures are HTTP-test fixtures, not browser screenshots or production-CSRF certification. No full regression/CI/deployment/commit/push, production migration/grant/data, new UI writer or admission activation. Private socket-only test PG stopped after checks. Source archive is a dependent increment, not a full standalone backup; prior archives are not regenerated.

Evidence: `docs/evidence/2026-10-24-scope-envelope-read/`.

## Next bounded work

Add separately versioned structured topology/coverage declarations under the authenticated stored source binding, with canonical exact review content identity and original-actor visibility for every dependency/evidence reference **before displaying or sharing it**. Do not append arbitrary hidden CDE titles/URIs or today's hierarchy and label them trusted history. Existing read-only topology/evidence diagnostics are components, not whole-package authority. Keep declarations/structural review unverified until authoritative historical topology, independently complete coverage, evidence integrity/retention/current validity and lifecycle gates are satisfied. Cross-company/all-scope and recording-participant read paths must be explicit, not inferred from current page success. SA-01/02 and DA-01..04 remain settled; no repeat policy questions.
