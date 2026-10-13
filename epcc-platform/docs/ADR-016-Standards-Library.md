# ADR-016 — The library of codes and standards

Status: **ACCEPTED AND PARTLY BUILT**
Date: 2026-09-20
Supersedes: nothing. Extends ADR-015 §2.8 (the standard-vs-project-spec split).

---

## 1. Context

Every module that needed to name a standard named it as prose. An ITP row said
`'EN 10204 · ASME B31.3 §323'` in a `varchar`. A grep across the codebase found
`ASME B31.3`, `ASME B31.3.` and `API RP` as three different strings, and
`API 5L` written fourteen times in four files.

Three things are impossible against prose:

1. **"Which inspections cite B31.3?"** — the question asked on the day a project
   moves to a new edition, and the list of what must be re-checked. Unanswerable
   without parsing text that nobody validated on the way in.
2. **Consistent naming.** `ASME B31.3` and `ASME B 31.3` are one standard to an
   engineer and two rows to a database. Nothing stopped either spelling.
3. **A contractual edition.** A project is bound to a specific issue of a code.
   Prose has no place to record that, so the year lived in people's heads.

The user's instruction: the system must hold a library of the standards and
codes used on a project, usable from the various modules — the ITP standards
column among them.

## 2. Decision

### 2.1 Two tiers: a global catalogue and a per-project applicability list

```
standards          the catalogue - global, no project_id, no RLS
project_standards  "this project works to this code, at this edition" - RLS
standard_citations "this object cites that standard at that clause" - RLS
```

The split is not bureaucracy; the two tiers answer different questions.
*"Does ASME B31.3 exist, and what is it called in Persian?"* has the same
answer for every customer. *"Is this project bound to the 2020 or the 2022
edition?"* is specific, contractual, and must not change because a platform
administrator tidied the catalogue.

Per-project-only was rejected: every project would retype `ASME B31.3` and the
spellings would diverge again, which is the defect being fixed. Global-only was
rejected: it has nowhere to record the contractual edition.

### 2.2 The catalogue is reference data, like the packs

No `project_id`, no `company_id`, no RLS — the same call as `itp_templates` and
the engineering reference packs. The protection reference data needs is *no
tenant may write it*, which is given by not exposing a tenant-facing writer, not
by a permissive row policy that only looks like protection.

### 2.3 `normalised` is the uniqueness key

`body + designation`, uppercased, with everything non-alphanumeric stripped:
`ASME B31.3` → `ASMEB313`. Case, spaces, dots and dashes are exactly what humans
vary, so they cannot participate in identity. `define()` is idempotent on this
key, which is the entire defence against the free-text problem.

The body is stored separately from the designation so that "all API standards"
is an indexed query rather than a `LIKE`, and so the body cannot drift out of
sync with a designation that embeds it.

### 2.4 The edition is pinned on the ADOPTION, not the catalogue entry

The user asked for a simple edition field rather than a full edition entity.
Taken literally — a `year` column on `standards` — that has a defect: the day
somebody updates the catalogue from 2020 to 2022, **every project's acceptance
criteria change at once**, including a project whose contract names the 2020
text and whose inspections were signed against it.

So the catalogue carries the edition it currently describes, and
`project_standards.edition` carries what *this* project signed up to. Null means
"follow the catalogue", which is the honest answer for the many standards nobody
has taken a contractual position on. One extra column, no extra table, and
`isPinnedBehindCatalogue()` puts a visible marker on a project that has
deliberately frozen an older issue.

### 2.5 Citations are polymorphic

`standard_citations` carries `citable_type` + `citable_id`. `ITP_STAGE` is the
only host today; piping classes, weld procedures and datasheets will reuse it.
A table per consumer would be the same three columns, the same model and the
same RLS policy written five times — the call the ITP module already made for
`itp_stage_documents`.

`citable_type` holds our own short constant, **not** a fully-qualified class
name. A namespace rename must not silently orphan every citation in the
database.

### 2.6 Citing is adopting

`cite()` adopts the standard for the project on its way through. Requiring a
separate adoption step first would mean an engineer writing an ITP is refused
and sent to a settings page — so instead the applicability list is true by
construction, assembled from what the project's documents actually reference.

### 2.7 The free-text column survives

`itp_stages.reference` stays, and this is the user's explicit ruling. On a live
project there is always a code the library has not caught up with, and refusing
the row until an administrator adds it would stop inspection work to satisfy a
database.

`structureStandards()` runs as each stage is created: what resolves becomes a
citation, what does not stays in `reference`, and `printedStandards()`
concatenates both. The engineer sees exactly the column they always saw —
`'EN 10204 · ASME B31.3 §323'` — while the structured half is now queryable.

Parsing accepts the way engineers actually write: `·`, `;`, `,` and ` / ` as
separators, and a carried issuing body, so `ASME B36.10M · B16.9 · B16.5` means
ASME three times.

### 2.8 Withdrawal is a flag, never a delete

A record signed in 1998 cites what it cites. `is_active = false` keeps a
superseded standard out of the picker while leaving history readable, and both
foreign keys into `standards` are `restrictOnDelete` so nobody can orphan a
signed citation.

## 3. Schema

```
standards           body, designation, normalised (unique), title_en/fa,
                    edition, category, is_active
project_standards   (project, standard) unique -> edition, remarks, is_active
standard_citations  (citable_type, citable_id, standard) unique
                    -> clause, sequence
```

## 4. Consequences

**Good.** "Which rows cite B31.3?" is an indexed query and a screen. The
applicability list builds itself. One spelling per standard, enforced by a
unique index rather than by discipline. A project's contractual edition is
recorded where it is contractually true.

**Costs.** A citation is two rows where it used to be a substring. The parser
is a heuristic and will mis-split something eventually — which is why nothing
it fails to match is ever discarded.

**Not built yet.** Only ITP rows cite the library. Piping classes, weld
procedures and datasheets still carry their own strings; they adopt
`standard_citations` as each is touched. There is no admin UI for editing the
catalogue itself — it is seeded, and `define()` is the API.

## 5. Alternatives rejected

### Keeping the catalogue in a JSON file instead of a table

Asked directly (user, 2026-09-20): could the standards library be a JSON file
so the database is not involved?

**Considered and not taken — but the reasoning is narrower than it looks, and
this should be revisited if the constraint below ever changes.**

The question only ever applied to **one** of the three tables. `standards` is a
global catalogue: no RLS, not per-project, not user-edited, changes between
releases of the software rather than between customers. That profile is exactly
`standards-packs/reference/*.ref.json`, so the instinct was sound.
`project_standards` (which edition THIS project pinned) and
`standard_citations` (which clause governs THIS ITP row) are customer data and
were never candidates.

What blocks it is referential integrity, not performance:

```php
$table->foreignUuid('standard_id')->constrained('standards')->restrictOnDelete();
```

Both project-scoped tables hold a real foreign key to `standards`. Postgres
cannot reference a file. Move the catalogue out and `restrictOnDelete` goes
with it, so a standard disappearing from the file would silently orphan the
editions projects have pinned and the clauses cited on ITP rows. On a project
where the ITP is a contractual document, an acceptance criterion pointing at a
reference that no longer resolves is a real defect, and nothing would report it.

Performance is not an argument either way: 59 rows sit entirely in Postgres's
cache. The genuine benefit would have been maintenance — adding a standard
without touching code or writing a migration.

**If this is picked up later, the shape to build is the one the units module
already uses: file as source, table as mirror** — `standards.ref.json` in git
(reviewable, diffable, validated by `standards-packs/validate_packs.py`),
imported by an idempotent seeder keyed on `normalised`, with the table kept as
a read-only mirror so the foreign keys and joins survive. That keeps both
properties. It was deferred only because the maintenance pain is theoretical
today, and `StandardsLibrary::resolve()` does longest-prefix matching plus
catalogue search from SQL, which would have to be rewritten by hand.


- **A standards *document* in the register.** Standards are not project
  deliverables: they are external, shared and not revision-controlled by us.
  Putting ASME B31.3 in the document register would mean one copy per project
  and would confuse "we issued this" with "we comply with this".
- **Free text plus a validation regex.** Catches shape, not identity: `ASME
  B31.3` and `ASME B 31.3` both pass and remain two things.
- **A full edition entity** (`standard_editions` with dates and supersession
  chains). Correct for a standards publisher, disproportionate here. The
  project's pinned year answers the question the projects actually ask.

## 6. PMS entry integration gap (2026-09-29)

**Accepted architecture unchanged; integration still incomplete.** The new PMS entry interface consumes material/size JSON reference data and may link an approved project definition. These two capabilities do not implement the `standards` → `project_standards` → `standard_citations` relationship in §2.

`PmsEntryService` currently holds dimensional-standard wording and source-reference strings without structured PMS citations/adoption. The user-authorized PMS entry work touched this consumer, so the §4 obligation to adopt the existing structured citation mechanism must not be described as completed. Keep original unresolved wording under §2.7; add resolvable catalogue identity and project edition rather than inventing a second standards catalogue or guessing the contractual edition from the current pack.

Tracked as **PMS-ADR-04** in [reconciliation](ADR-RECONCILIATION-2026-09-29.md) and ADR-008 §12. A textual document/revision/clause in a material response or disposition (ADR-017) is likewise not authenticated document linkage or a structured standards-catalogue citation. This amendment records the boundary; no code/schema change is made here.

## 7. Decision-review proposals (2026-09-29)

**Pending user decision; no automatic supersession of §2.4/§2.6.** [DR-01 and DR-02](ADR-DECISION-REVIEW-2026-09-29.md) identify two distinct issues:

1. Pinning one mutable project adoption record does not freeze historical citations when that record changes. NULL-follow-catalogue adds a second drift path. Proposed: versioned adoption or immutable edition/adoption snapshots at the document revision boundary, with explicit unknown/floating policy rather than an implicit contractual default.
2. Citing is not necessarily contractual adoption. Proposed: allow frictionless citation/candidate discovery, but distinguish that state and authority from confirmed project applicability. Do not silently reactivate/accept a standard solely because it appears in a source row.

A full publisher-style edition catalogue is not required to address historical identity; the earlier rejection of that heavy model does not justify live historical references. Existing runtime behavior remains unchanged until the transition, authority and migration policy are decided.

## 8. User decision DR-01 — freeze references at release (2026-09-29)

**ACCEPTED by explicit user selection: `freeze_at_release`. Implementation pending.** The user requested that each review issue be presented and decided separately. For DR-01, the selected approach is to freeze references at publication/final approval, rather than require a separate versioned project-adoption model as the chosen solution.

- Draft references may be corrected before the document version is finalized.
- The standard edition must be identified before finalization; the identity, edition and cited clause are fixed for that document version at publication/final approval.
- Later changes to the project adoption or catalogue must not change that historical version's displayed/evaluated reference basis. A subsequent document version can explicitly use a different basis.
- This amends §2.4's live-edition fallback and §2.5's citation model **for finalized document versions**, and accepts the release-snapshot alternative discussed in §7/DR-01. It does not turn a source citation into contractual adoption; **DR-02 is still awaiting a separate user decision**.
- No new publisher/approver authority is granted. Concrete CDE lifecycle transitions, snapshot storage, atomic capture/locking, legacy-document treatment and migration/test details must be specified against existing document workflows before implementation. Do not invent a historical edition from today's catalogue or claim that historical snapshots already exist.

Acceptance: finalize a document under edition A, then change the project/catalogue to B; the old document version must still display and evaluate its saved edition/clause A. A new document version may use B after explicit selection. Finalization must not silently resolve an unspecified edition to whichever catalogue edition happens to be current.

Only the decision record changed in this step; code, schema and runtime behavior have not changed.

## 9. User decision DR-02 — separate citation from adoption (2026-09-29)

**ACCEPTED principle by explicit selection: `separate_adoption`. Adoption authority remains pending a follow-up answer. Implementation pending.** This replaces §2.6's normative “citing IS adopting” rule; that section remains as the historical rationale, not the newly selected target behavior.

A source citation may be recorded without blocking transcription. For a standard/edition not yet accepted for the project, the reference remains cited/pending adoption rather than becoming a confirmed contractual requirement merely because it was entered. Citation to the same already-accepted edition does not need repeated adoption approval. Withdrawal/inactive status must not be silently reversed by citation.

The user has not yet selected **who may approve project adoption**. Do not infer this authority from the ability to enter documents, from material-definition reviewer roles, or from existing owner-only configuration code. Ask this as a follow-up within DR-02 before moving to DR-03. State names, authority enforcement, schema migration and legacy-adoption classification are still implementation/design work, not completed behavior.

DR-01's release-time frozen citation basis remains independent of this decision. No runtime code or existing project adoption was changed in this step.

### 9.1 Authority follow-up — explicitly deferred by the user

The user selected `defer_authority`: retain the accepted separation principle, leave the approving authority unresolved, and proceed to DR-03. The authority question was asked, not silently omitted. Do not grant a default authority or describe DR-02 as fully implementation-ready. No configuration/permission change was made.

## 10. DR-01 implementation design — existing CDE/ITP path (2026-09-29)

**Implementation authorized:** user selected `implement_dr01`. This increment implements §8 through the existing registered ITP consumer; it does not claim to complete PMS document revisions or structured PMS citations (ADR-008 §12).

- Boundary: entering CDE `PUBLISHED` (A and B suitability families both count as publication). ITP `ACTIVE` is not approval/publication (ADR-015 §2.9); no new approval workflow or adoption authority is introduced.
- A versioned JSON reference snapshot belongs to `document_revisions`, under its existing RLS. It captures every attached ITP stage's identity/order and standard identity, explicit edition, clause and sequence. It is a **standards basis only**, not a snapshot of all ITP content or attached standard texts/engineering data packs.
- Each draft citation can carry a source edition independently of contractual adoption. Capture prefers this explicit source edition, otherwise an explicitly populated project-edition field. Never use catalogue fallback. Unresolved standard text and missing editions prevent publication, but not draft entry. No new adoption state/authority is inferred; the older automatic `cite()` adoption behavior remains the separately tracked DR-02 implementation gap.
- PostgreSQL captures the snapshot in the same state-update statement and refuses snapshot replacement/removal, deletion of finalized revisions and reopening their state. Parent/citation guards serialize reference edits with publication using revision row locks, validate host/project ownership and reject reference changes to a finalized current revision. Subsequent revisions start without a frozen basis; old snapshots remain readable independently of mutable source rows.
- Application transition checks run after re-reading/locking the current document and revision inside one transaction. The existing issue/view permissions remain authoritative. Snapshot creation is not permission to publish.
- Migration: additive; no inferred backfill. Existing published/archived revisions with NULL snapshot are **LEGACY_UNCAPTURED**, not empty/verified snapshots. Their readers must not silently fall back to the live catalogue. Issuing a subsequent draft is the normal path to a newly captured basis. Unknown historic editions cannot be reconstructed without evidence.
- Excluded: whole-ITP content/version/signature binding, PMS revisions/AFC pins, adoption authority, standard-pack replay, automatic upgrade of references, and bulk/source-import tools. New revision drafts may initially retain explicitly entered source wording/edition; changing to a new edition must be explicit.

Alternatives: a detached snapshot service without a publication hook was rejected; model-only immutability was rejected because SQL bypasses it; guessing legacy editions and treating `ACTIVE` as approval were rejected. No new parallel publication system is created.

Acceptance: edition A survives catalogue/adoption edits and a B-based subsequent document version; missing/floating edition and unresolved standard text fail atomically; SQL edits/deletes/reopening fail; new draft editing remains possible; legacy NULL is visible as unknown; tenants cannot read/write another project's basis; read-only FA/EN history uses the captured values. Verify migration and affected document/ITP/library tests independently of older regression totals.

### 10.1 UI/permission mapping within this increment

The document register exposes a FA/EN standard-basis page with revision selection. Read access reuses `doc.view` and document/project RLS. Source-edition transcription on the **current open revision only** reuses `doc.issue` plus document-company ownership; the submitted current-revision ID is checked under lock. This records what the source says; it neither calls `adopt()` nor changes project adoption/authority. Frozen/legacy pages are read-only. The page does not add an editor for unresolved free-text references or a standard-catalogue admin UI; those remain draft-authoring/import work. Mixed batches are atomic and cross-host IDs are rejected.

## 11. DR-01 first increment — implemented and verified scope (2026-09-29)

Implemented §10 for CDE/ITP: additive migration `2026_09_29_235500_freeze_document_standard_references.php`, publication/issue locking in `DocumentRegister`, snapshot-aware model readers, FA/EN document reference history and source-edition entry, and ITP reference rendering. Source-edition input uses existing doc.issue plus company ownership, not adoption authority. Frozen references survive draft-ITP deletion through independent stored history; draft deletion cleans polymorphic citations before FK cascades.

Verified: `DocumentStandardSnapshotTest` **15 tests / 81 assertions**; final serial `DocumentRegisterTest`, `StandardsLibraryTest`, `ItpTest` **128 / 317**. The first regression exposed a draft-delete cascade defect; fixed and the complete selected set rerun. Includes real forward-migration legacy handling, raw-DML guards, two-connection publication contention, edition A/B history, HTTP scope/escaping/FA/EN checks. Live read-only HTTP smoke passed in both languages; no new visual browser QA or full-product suite. Ordinary DML protection is not protection against a database administrator/DDL privileges.

Status: **DR-01 partially implemented across the product; verified for this reference-basis path only.** Neither full ITP document/signature versioning, historical engineering-pack replay nor PMS-ADR-01/04 is completed. DR-02 auto-adoption and its deferred authority remain open. See [delivery report](DR01-REFERENCE-SNAPSHOTS-2026-09-29.md).
