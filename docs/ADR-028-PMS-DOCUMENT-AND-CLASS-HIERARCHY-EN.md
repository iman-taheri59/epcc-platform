# ADR-028 — PMS is an engineering specification document containing classes

Status: **REQUIREMENTS ACCEPTED; DOCUMENT/CLASS REVISION SCHEMA PROPOSED**
Date: 2026-10-13  
Depends on: ADR-006 (line register), ADR-008 (class rules), ADR-027 (authoring and line preconditions), CDE document/revision model.

## Owner's domain correction

**Piping Material Specification (PMS) is an engineering specification document containing the specifications of one or more piping classes.** It is not itself one class and it must not be modeled as a synonym for `piping_classes`. A class is a member of a PMS, with its own size-dependent material and branch rules. The hierarchy is:

`project → PMS document → issued PMS revision → one or more class specifications → lines selecting an eligible class`.

This is a domain requirement, not yet a description of the implemented schema. The current P1 implementation stores classes and rules but has **no PMS document or document-revision ownership link**. Its `PUBLISHED` state is a class-rule gate, not proof that a PMS document revision has been issued/approved in the CDE. The currently green CI confirms existing behavior only.

## Required invariants

1. One PMS document may specify **one or more** classes. Every line class selected for new line registration must trace to the relevant published/approved PMS document revision and to the exact class specification version used; a class code alone cannot prove engineering approval.
2. Class codes remain project-scoped identities. No automatically created placeholder PMS or class and no implicit membership based on matching filenames, codes or text.
3. A revision is immutable after issue: changes to a class or its rules must be associated with a new revision, not silently rewrite the evidence for existing lines. Existing lines remain pinned to their issued-against class revision (ADR-008 §7 #4, ADR-009 §4.4); adoption of a later revision is explicit, never automatic.
4. Reuse the existing CDE `documents` and `document_revisions` where possible for document number, file evidence, lifecycle and audit. Do not conflate CDE state (document issue/review) with the independent calculation/publish gate of a class; both must be satisfied for use on a line, if the owner confirms the CDE approval threshold.
5. Preserve project/company RLS and archive freeze across the document-to-class links; prevent cross-project membership and ambiguous active specifications of the same class.
6. The PMS author nomination currently exists only as policy data. Apply full write authorization and provenance at document/revision/class/rule boundaries before claiming protected authoring; see ADR-027.
7. The previous decision still stands: classes must precede lines, and no unmapped historical line is admitted to the line register. A class marked `PUBLISHED` in the current schema is NOT by itself proof that its parent PMS document was published, because that parent does not yet exist.

## Proposed integration, not yet implemented

Use CDE `documents` with a dedicated PMS type and `document_revisions` for issued revisions; add explicit same-project links from a revision to class-specification snapshots. Keep the existing `piping_classes` row as the stable class identity already referenced by piping lines/MTO, but version its rule set under the PMS revision rather than mutating the only copy in place. The line should pin the selected revision/class snapshot to preserve design basis. This is preferable to putting `pms_document_id` alone on a mutable class row: such a link would lose which revision specified the line's material. Migration of the current P1 rules and legacy class rows must be explicit, with a preflight that refuses ambiguous or unmapped mappings rather than inventing ownership.

## Resolved owner decisions and existing revision requirement (2026-10-13)

- A **new line** requires both a technically `PUBLISHED` class and an **approved** governing PMS document revision. Reuse the CDE predicate `CdeState::isApproved(state, suitability)` — `PUBLISHED` with an A-family suitability — rather than interpreting any published B-family/partial sign-off as approved. This is a design requirement; no document-to-class gate is implemented yet.
- At a given time, each project class identity has **one governing PMS document**, not multiple competing active specifications. One PMS document can still govern multiple classes. Revisions of that document may change each class independently; explicit supersession must not mutate old issued specifications.
- The user pointed out that revision behavior was **already decided**: ADR-008 §7 #4 and ADR-009 §4.4 require published classes to be locked, changes to create a **new class revision**, and lines to **keep pointing at the revision they were issued against**. Do not ask again whether to auto-migrate old lines; do not re-derive their material from the latest revision. The deviation report identifies lines on an older revision. The corresponding PMS document-revision link for each class revision must also remain immutable so this existing rule can be enforced end-to-end.

## Remaining implementation question

Choose a concrete class-specification revision key/link to `document_revisions` and migration path from the current mutable P1 draft row. The governing document is unique per project class identity, while its approved revision and each line's pinned class revision are explicit; do not reuse a changing `documents.current_revision_id` as historical evidence.

## Falsifiers / acceptance evidence

Demonstrate two distinct classes under one PMS revision, a class under a second revision with changed schedule without changing an old line's resolved result, and a new line refused for (a) class without PMS link, (b) wrong project, (c) draft class, and (d) unapproved PMS revision. Verify cross-tenant denial and that a document with one valid class cannot accidentally authorize another class absent from that revision. No skips in PostgreSQL CI.

## First implementation slice (2026-10-13)

The existing CDE register now offers a distinct `PMS` document type for the
`PIPING` discipline. Registration creates a numbered document and WIP revision
through the existing CDE state machine; a PMS in another discipline is refused
by the service, including non-HTTP callers. The first revision is NOT
approved, cannot authorize a line and contains **no class membership yet**.
A focused PostgreSQL feature test covers numbering, WIP/non-approval and
wrong-discipline refusal. No class link, snapshot, line selection gate or PMS
authoring policy enforcement is claimed by this slice. CI verification pending.

CI verification: run 38443239928 for `186707a` passed all six jobs, including
the PostgreSQL DocumentRegisterTest. This confirms the document registration
slice only, not the class/revision mapping or line/adoption gates.

## Second implementation slice: one governing document identity (2026-10-13)

A nullable `piping_classes.pms_document_id` binds a class identity to **one**
existing CDE PMS document. The database refuses non-PMS, non-PIPING, wrong-
project and non-WIP documents at first binding; a non-null binding cannot be
changed or cleared. `PmsClassDocumentService::attach` accepts only an
unassigned DRAFT class owned by the nominated authoring company, an owned WIP
PMS in the same project and the latest unsuspended author policy; it shares
the policy replacement's project advisory transaction lock and locks the
class row. Tests cover default denial, two classes in one document, immutable
reparenting (including direct SQL), wrong document type and suspension.

This is **identity membership only**, not class-specification revision
membership. The column remains nullable for legacy classes and prior fixtures.
The class rule set is still not snapshotted into `document_revisions`, approved
PMS revisions still do not authorize line registration and direct SQL can
still attach a *valid* unassigned class without the nomination service. Do
not call this a complete PMS authoring gate; CI verification pending.

CI run 38448258557 for `0740657` passed all six jobs, including the new
PostgreSQL/RLS binding tests. The next slice must attach an immutable class
rules snapshot to a specific PMS `document_revision_id`, then pin admitted
lines to that approved class snapshot. Do not use `pms_document_id` alone as
revision evidence.

## Third implementation slice: atomic approved-revision snapshot (2026-10-13)

On CDE publication of a `PIPING/PMS` revision with A-family suitability,
`DocumentRegister::transition` now captures an append-only JSONB specification
row per attached, published class in the **same transaction** as publication.
At least one class, with rules, is required; any attached draft refuses A
publication. The snapshot contains the class header, its size-band rules and
branch rules, with an explicit FK to `document_revision_id`. It is never
refreshed by later edits to live tables or by issuing another PMS revision.
Class attachment and approval both lock the same document before class rows;
the database checks the revision's approved state and rejects snapshot
UPDATE/DELETE. PostgreSQL RLS scopes snapshot access by participant/project.
B-family publication does not create approved-class snapshots.

Limitations: direct SQL may still forge snapshot JSON or mutate source rule
rows; `PipingClassService` publication and CDE PMS writing are not yet fully
nomination-gated. Existing lines are not pinned and new lines still have no
snapshot eligibility gate. A snapshot created for a prior revision must not
be mistaken for current approval without checking the exact revision state.
CI pending.

CI correction: run 38453709184 failed because `migrate:fresh` retained the
PostgreSQL function and the migration used `CREATE FUNCTION` instead of
`CREATE OR REPLACE FUNCTION`. The migration now supports repeated fresh
migrations and verifies inserted JSONB against the live source rows in its
trigger. A user able to edit published source rows directly remains outside
this protection; line admission remains unimplemented. Recheck in CI.

Verification update: CI 38456241655 passed five jobs but its Laravel job
failed on an annotated Chromium PDF-render 30-second timeout (zero assertion
failures; nested JUnit reported three errors). The snapshot migration collision
was resolved. The JSONB-forgery guard now has an explicit direct-INSERT test.
Await a clean full rerun before claiming this slice green.

CI run 38460152236 on `1af325a` passed all six jobs after the migration
repeatability correction and forged-content regression. This verifies
revision-specific snapshots at A-family publication, not line admission.

## Fourth implementation slice: issue-time line pin foundation (2026-10-14)

`piping_lines.pms_class_snapshot_id` can now identify the exact immutable
class specification snapshot of an approved PMS document revision. Database
FK/trigger enforce project/class agreement and immutable re-pinning. A first
pin requires a current A-family PUBLISHED PMS revision and a PUBLISHED class;
when the document issues a new WIP revision, existing lines retain their old
snapshot and no new line may select the superseded one. `PmsLineEligibility`
resolves a qualifying snapshot in the current tenant/project transaction and
rejects absent or ineligible classes. The regression test exercises pin
creation, supersession and retained historical lineage.

**Not yet wired into `PipingLineService::create` / `register`**, HTTP pickers,
or the other line writers. The column is nullable for legacy rows and current
tests; direct SQL can still insert a line without a pin. Thus the owner rule
"reject new/imported lines lacking an approved PMS class" is NOT implemented
by this slice. The next slice must enforce the resolver across every write
path, update legacy fixtures and backstop null-pin inserts at the DB layer.
Never silently map an old line to a convenient class or revision.
CI pending.

## Fifth implementation slice: eligible-class picker (2026-10-14)

The manual line register now lists only class IDs that have a snapshot in the
**current** A-family-approved PIPING/PMS document revision and are themselves
PUBLISHED in the same project. The picker uses the exact same predicate as
`PmsLineEligibility::snapshotId`, rather than approximating approval from
class state alone. The class selector is displayed independently of whether
the number format includes a class token, requires a choice, and explains why
no class can be selected; the form submit is disabled when the list is empty.
Tests assert no draft class appears, that a published approved class becomes
eligible, and that superseding its PMS removes it from the picker without
changing the old line's pin.

**This is UI guidance, not a security gate.** `PipingLineService::create` and
`register` still accept unqualified or missing classes, and the DB still
permits inserting a new line with null snapshot. Direct POSTs/imports can
bypass the picker. Do not claim the owner line-admission rule is satisfied;
the next step is to enforce this shared resolver in every write path and add
the DB insert backstop, with historical fixtures updated explicitly. PHP
syntax checks succeeded; PostgreSQL tests are pending while GitHub Actions
jobs are blocked by billing.

CI status: run 38515815314 for `1e38780` was blocked before job execution
by GitHub Actions account billing/spending-limit restrictions. PHP lint
passed locally; feature tests have not yet run for this slice.

CI recovery attempt (2026-10-14): the owner made the repository public; API
confirmed `visibility=public`. Previously blocked Actions runs cannot be
rerun via `gh run rerun`, so a fresh run is requested on this documentation
commit. The last verified green code baseline remains 38465772360 for
`f1d4ae5`; the eligible-class picker still awaits a full CI execution.

Run 38518232416 under public visibility started all six jobs. Five passed;
Laravel/PostgreSQL was red on one annotated 30-second PDF renderer timeout
(3249 tests, zero assertion failures, nested JUnit three errors). There were
no annotated PMS picker errors. This is a CI execution, but not a green gate
for the picker. A fresh documentation-triggered run can distinguish transient
runner contention from reproducible PDF failure without altering acceptance
limits.

Follow-up CI run 38520710949 on `f4b392b` passed all six jobs, including
Laravel/PostgreSQL/RLS. The PDF timeout on the preceding public run did not
recur. This validates the class-picker slice only; direct line creation and
import still need the approved-snapshot gate.

## Sixth implementation slice: external-number register gate (2026-10-14)

`PipingLineService::register` (supplied-number/manual import path) now
requires an explicit project class that resolves to the current approved PMS
revision snapshot **inside the same transaction as the line insert**. It
writes `pms_class_snapshot_id` alongside `piping_class_id`; the existing DB
trigger independently checks current A-family approval, class/project
consistency and immutable pinning. A parsed class code in the tag is not
accepted as an invented mapping. Nonconforming legacy/vendor numbers are
still preserved verbatim **when an eligible explicit class is supplied**;
otherwise the registration is rejected rather than parked in the register.
The duplicate-number and empty-number refusals remain early and unchanged.
Service/HTTP tests cover missing class refusal, valid imported number and
snapshot pin, nonconforming numbered import with an approved class, and
superseded PMS rejection. Synthetic fixture uses a real approved PMS document
and snapshot rather than bypassing admission.

**Partial gate:** `PipingLineService::create` (automatically allocated number)
and other direct writers still admit null pins; the DB currently allows
unassigned inserts to accommodate historical fixtures. No database-wide
new-line exclusion can be claimed yet. Next slice should enforce class and
pin on create, then review and migrate all SQL/import writers and historical
data before a DB-level non-null insert constraint. PostgreSQL CI pending.

CI correction: run 38525864378 exposed two fixture assumptions, not a
service bypass. The Pars synthetic import must use an owner-created numbering
config (RLS forbids Pars creating owner-owned config); the old HTTP test of a
classless legacy number must now expect a form error and zero rows. Both
fixtures were corrected for the next run.

CI run 38528034166 on `2e21d16` passed all six jobs, including the
PostgreSQL/RLS feature suite after fixing the synthetic owner config fixture
and classless legacy HTTP expectation. This is the verified import-path gate,
not yet a complete new-line admission guarantee.

## Seventh implementation slice: allocated-number line gate (2026-10-14)

`PipingLineService::create` now resolves the exact current A-family-approved
PMS class snapshot before writing a newly numbered line and stores its
immutable `pms_class_snapshot_id`. This is the **same resolver and in-write-
transaction check** already used for supplied-number imports. The class is
mandatory even when the project's number pattern has no class token. A
registered draft class cannot authorize a line. The database snapshot trigger
independently validates every non-null new pin. The service tests now build
synthetic *approved* PMS revisions through the real CDE transitions rather
than relying on draft class rows; one shared test helper is intentionally
confined to `tests/Support` and is not an application bypass. PostgreSQL CI
pending.

Existing historical lines remain untouched. DB inserts with a null snapshot
are still possible, and any direct writer that does not call either service
method remains outside this gate. The final step is to inventory those writers,
retain historical unknown lines only as explicitly reviewed legacy data, and
reject **new** null pins at the DB boundary. Do not add a blind default PMS
mapping. No source class revision or PMS suitability is inferred from the
line number alone.

CI correction: run 38533755067 failed in the unit-preference test fixture,
which still created a draft class before line creation. It now builds an
approved PMS class snapshot through the shared test fixture. No production
gate was relaxed. The run is not green; a fresh CI check is required.

CI correction: run 38536908956 showed one remaining HTTP unit-preference
fixture with a draft class; the PMS gate (correctly) refused it before the
invalid-unit check. That fixture now supplies an approved PMS class. No
production authorization rule was weakened; retry the full suite.

CI run 38540006442 on `7d30df6` passed all six jobs after updating the
legacy unit-preference fixture. Both service creation doors are now verified
against real PostgreSQL/RLS; direct SQL/bulk null-pin inserts still require
the final backstop and historical-data strategy.

## Eighth implementation slice: database INSERT backstop (2026-10-14)

A new PostgreSQL `BEFORE INSERT` trigger on `piping_lines` now refuses any
row whose `piping_class_id` or `pms_class_snapshot_id` is NULL. Unlike a
column-wide `NOT NULL` change, it does **not** invent a classification for
pre-existing/historical lines with an unresolved class; such rows can remain
in the register and have their other fields edited while their class is
reviewed. The previously deployed pin-integrity trigger continues to refuse
invalid project/class, superseded revision and non-A-family PMS snapshot IDs.
It applies to service, HTTP, future imports and raw/bulk SQL equally. There
is no import-only bypass or fake approved class. New lines through both
service doors already supply a valid snapshot.

Tests assert direct SQL null-pin insertion fails with SQLSTATE 23514 even
when a real published class ID is supplied, while a duplicate number with a
valid pin still fails its unique index (23505). A migration rehearsal inserts
an unmapped line before the new trigger, installs the trigger, and verifies
that the historical row survives, remains editable and is **not** silently
assigned a snapshot. Postgres CI pending. Remaining: protect the PMS rule
writer paths and distinguish historical unclassified records in downstream
readiness/reporting; do not treat an old null pin as approved.

CI run 38543947981 for `7bf732d` passed all six jobs, including
PostgreSQL/RLS migration tests for direct SQL null-pin refusal, duplicate
number index behavior and survival of unmapped historical lines.

## Ninth implementation slice: historical classification is evidence-bound

The INSERT backstop preserves old unclassified records, but a direct SQL
UPDATE could previously attach a bare class ID to one of those rows without
PMS evidence. A separate PostgreSQL `BEFORE UPDATE OF piping_class_id` trigger
now rejects changing a historical null-pin line to a non-null class without
setting a snapshot in the same statement. The existing pin trigger then
verifies that this is the current approved A-family PMS revision for the
same class and project. Edits to unrelated fields remain possible, as do
explicitly reviewed class-and-snapshot assignments; old, unchanged class
IDs with unresolved historical provenance are not fabricated or overwritten.

The database migration and end-to-end regression test are awaiting CI.
Remaining distinct tasks: reporting unclassified historical lines as not
ready, and enforcing authoring/lifecycle integrity of PMS documents.

Validation caveat: after the repository was made private, GitHub Actions run
38551878338 for `efd7b48` did not start its test jobs due to GitHub billing
or spending-limit restrictions. This is not a test failure or test pass; the
ninth slice currently has PHP syntax checks only and awaits real PostgreSQL
CI or a locally provisioned PostgreSQL test environment.

## Tenth implementation slice: historical PMS readiness is not AFC-ready

Historical records without a PMS snapshot remain visible in the line
register, but the register and task-first workspace now label them as
requiring class/PMS revision review instead of marking them ready when
other AFC documents happen to be present. The workspace readiness count
and blocked status use the same pin requirement. `confirmAfc` now refuses
null class/pin with a localized field error, before evidence checks; after
explicit classification, the existing AFC evidence checks still apply.
Existing issue-time snapshots are not revalidated against *today's* revision
when confirming an already-created line, preserving revision pinning.
The regression exercises the pre-insert-gate historical row, register and
workspace labels, and AFC refusal. Syntax checks passed; database and view
tests await CI when private-repository Actions billing is available or the
owner chooses to make the repository public again. Separate direct-SQL AFC
state transition enforcement remains to be addressed.

## Eleventh implementation slice: database gate on AFC/release transitions

A direct SQL UPDATE could otherwise move a historical null-pin DRAFT line
to AFC_CONFIRMED with actor/timestamp and evade the service gate. A narrow
PostgreSQL trigger now refuses transitions *into* AFC_CONFIRMED or RELEASED
without class and issue-time PMS snapshot. Unchanged historical AFC or
RELEASED rows remain editable without a fake backfill; reversing a state to
DRAFT remains possible. The existing class/snapshot guard validates any new
pin. The historical migration regression now also attempts direct SQL AFC
with complete actor/timestamp and expects SQLSTATE 23514. This is not a
claim that database-only SQL validates every AFC evidence document: that
separate preexisting service requirement still needs its own boundary audit.
PostgreSQL test execution is deferred while private Actions jobs are blocked.

## Twelfth implementation slice: published class specification cannot drift

The immutable snapshot table alone did not stop raw SQL from mutating the
published class header or its rules *after* approval. The live class could
then disagree with the issue-time specification while the same snapshot
still qualified new lines. A PostgreSQL guard now refuses UPDATE/DELETE of
a PUBLISHED class and INSERT/UPDATE/DELETE of either rule-table row associated
with a PUBLISHED class. Rule operations take a share lock on the parent to
serialize with the publication update. Draft classes remain editable and
receive rules **before** publication; tests-only fixtures were reordered to
follow that sequence. The regression asserts failed SQL header/rule edits,
branch-rule insertion and rule deletion while preserving the captured
snapshot and valid line registration. This closes specification drift within
the existing immutable-published-class model; it does not add editable
per-revision class content. A future business need to revise an existing
published class's specification requires a versioned authoring model and an
explicit decision rather than weakening the freeze. PostgreSQL CI deferred
while the repo stays private at the owner's request.

## Decision: same class identity across PMS revisions (2026-10-14)

The owner accepted the recommended versioned-authoring direction. A class
identity and code remain stable, while specification changes belong to a
WIP document-revision working copy, not the published class header/rules
and never the prior issue-time snapshot. Published class contents remain
frozen. This is being built in independently verifiable slices; the new
draft storage **does not yet authorize changed specifications or affect line
eligibility**.

### Thirteenth slice: isolated WIP working-copy foundation

`pms_class_revision_drafts` stores a JSONB working copy keyed by revision and
class. It has FKs, one row per pair, tenant RLS, immutable identity and a
database trigger requiring a PIPING/PMS document's current WIP revision and
a same-project, same-company published class governed by that document. No
deleting drafts; editing is possible only in WIP, not after SHARED/PUBLISHED.
When a new PMS revision is issued, the document service seeds working copies
from the prior approved snapshots in the **same transaction** as the new
revision/current-pointer change. An unchanged prior snapshot stays immutable,
and once the document moves to SHARED the draft cannot be silently edited.
A regression covers these properties. This is a *foundation only*: no UI or
nominated-author edit service yet; the PMS publication path still captures
the unchanged published class contents and deliberately ignores draft JSON.
A follow-up must validate versioned specification shape, coverage, material
and branch/pad checks before publication and capture immutable snapshots
from the validated drafts. Until then, changing draft JSON has NO effect on
line admission. Do not advertise same-identity revisions as complete.
PostgreSQL CI is deferred while private Actions jobs remain blocked.

### Fourteenth slice: nominated-author WIP edit service (schedule only)

`PmsRevisionDraftService::changePipeSchedule` performs a narrow edit to an
existing PIPE_SCHEDULE rule's value in the **current WIP** revision draft.
The project must be writable; it locks the same project admission key used
by PMS author nomination changes, rechecks current nomination, locks the
PMS document/revision/draft and verifies authoring-company ownership. It
cannot change rule identity, rule kind, DN range, class identity, published
class rows or a prior issue-time snapshot. A non-nominated owner is refused,
and the same author is refused once the revision is SHARED. A service test
exercises these cases alongside the database WIP-only trigger. There is no
public HTTP writer yet, and draft content is **not used for publication**:
validation of revision-specific specifications and capture from those
validated drafts must be designed and delivered before line eligibility
can reflect this change. PostgreSQL CI remains deferred by owner request.

### Fifteenth slice: whitelist the first revision delta before persisting it

`PmsRevisionDraftValidator` compares the complete working copy with the
previous approved snapshot. The only allowed difference in this first
iteration is the schedule string on an **existing PIPE_SCHEDULE rule**.
Headers, class identity, rule count/identity/order/DN range, material rules,
branch rules and any extra/missing values must remain identical. Comparison
is type-strict with associative keys canonically sorted to tolerate JSONB
object-key ordering without accepting `106` as `'106'`. The nominated-author
schedule service validates the entire changed copy against the actual prior
approved snapshot before storing it, preventing a direct SQL mutation of
another field from hitchhiking on an authorized schedule edit. Existing rule
value metadata is preserved rather than overwritten. Unit tests cover valid
and forbidden deltas; a database regression covers a forged DN-range edit.
This guard applies to **the service edit only**. Direct database edits to a
WIP draft and publication from drafts still need a separate integrity and
engineering validation gate. There is no approval or line authorization for
a changed schedule yet. Database CI deferred while the repo stays private.

### Sixteenth slice: block invalid PMS drafts before CDE review

The CDE service now validates the entire PMS working-copy set when moving a
revision from WIP to SHARED, inside the revision transition transaction and
after locking the revision. Draft class IDs must exactly match the previous
issued revision's approved snapshot class IDs; each draft is compared against
its own prior snapshot using the strict schedule-only validator. A tampered
branch rule (even after an authorized schedule edit) therefore prevents
sharing, rather than entering the review cycle as though it were approved
material. The first PMS revision has no prior snapshots/drafts and follows
the original class publication checks. A regression checks rejection,
rollback to WIP, restoration and successful sharing. Direct SQL CDE state
updates bypass this service; database review/publish integrity remains a
separate requirement. Draft content is still **not used** for approval
snapshot capture and does not authorize a new line. PostgreSQL CI remains
deferred until the owner restores public visibility or private Actions runs.

### Seventeenth slice: engineering checks for revised schedules at review

Structural schedule-only validation was insufficient: changing SCH 80 to SCH
40 can change pipe wall thickness, and thus invalidate a previously approved
B31.3 §304.3.3 branch-pad result. At WIP-to-SHARED, each revised schedule
must now exist in the published pipe-wall reference pack for **every DN**
covered by its rule range. `PipingClassService` reuses its existing
branch-pad gate with a per-rule schedule override from the WIP draft, leaving
the published rules and prior snapshot untouched. Other inputs (design
pressure/temperature, material, corrosion allowance, branch table and pad)
remain the previously published class's unchanged values, as guaranteed by
the full-delta validator. The regression checks that SCH 40 is rejected at
DN550/NPS22 for a class spanning `[15,600)`, while the reference-backed STD
schedule may pass this check; an unrelated branch-rule forgery still fails
structural validation. Drafts still are not captured into approved snapshots
and cannot authorize new lines. PostgreSQL CI is deferred as requested.

### Eighteenth slice: fail-closed PMS publication until capture matches reviewed draft

A SHARED draft with a revised schedule would currently be accepted by the
old publication service, which snapshots the *unchanged live class* rather
than the reviewed draft. The document would be approved under a false
specification. Before moving a PMS revision to approved PUBLISHED, the CDE
service now repeats the complete working-copy and engineering checks under
the locked revision, and refuses publication if any draft differs from its
prior issued snapshot. Unchanged drafts retain the old publication path.
The regression shares a valid STD schedule revision, attempts approval,
asserts a specification error, SHARED rollback and no new approved snapshot.
This is an explicit temporary fail-closed gate, **not** support for publishing
revised class contents. The next step is to capture the validated draft as
the issue-time snapshot with a corresponding database provenance guard, then
remove only this temporary changed-draft refusal. Existing class/line pins
remain immutable. PostgreSQL CI remains deferred while the repo is private.

### Nineteenth slice: capture the validated revision-specific class copy

The temporary refusal in slice 18 is replaced by an issue-time capture of
the reviewed WIP draft. On approved CDE publication the transaction first
revalidates the full class set, schedule-only delta, published wall schedule
availability and B31.3 branch-pad results, then inserts an immutable snapshot
of **that draft** rather than the frozen live class. The database snapshot
trigger now also verifies current approved PMS provenance. Where a revision
has a working copy, its candidate must equal that exact copy and differ
from the immediately preceding approved snapshot ONLY at existing
PIPE_SCHEDULE `value.schedule` leaves. PostgreSQL compares the full JSONB
object after whitelisted substitutions; other rule/header/branch changes
are rejected with SQLSTATE 23514. First revisions without a draft retain
the original live-class snapshot gate. The test issues revision B with STD
from a prior SCH 80 snapshot, checks new-line pin B and old-line pin A,
and attempts a forged SQL snapshot with an altered DN range.

**Important boundary:** the database delta predicate is structural and does
not itself run the external reference-pack/pad calculator. That engineering
check is re-run inside the service CDE approval transaction; a privileged
raw-SQL CDE state mutation and raw draft write could still evade it. The
DB-only CDE transition authorization/integrity audit remains outstanding.
No claim of a universal engineering gate until PostgreSQL CI runs and this
separate writer boundary is handled. Database tests remain deferred while
the repository stays private at the owner's request.

### Twentieth slice: resolve engineering rules from each line's pin

A stable class ID is not sufficient to identify its specification after a
revision: the frozen live class row still carries the earlier schedule,
while a newer line may have a later approved schedule. New
`PmsPinnedClassSpecification` reads the exact immutable snapshot referenced
by a line, verifies project/class identity, and resolves the issued
PIPE_SCHEDULE for a DN from that snapshot. It fails closed on a historical
null pin, missing/malformed snapshot, missing DN coverage or ambiguous
coverage rather than falling back to mutable/live class rules. The
regression demonstrates the same class identity with old line SCH 80, new
line STD, and unchanged live class SCH 80. Current production workflows do
not yet consume class rules during per-line material or branch resolution;
future line-dependent modelling/MTO writers MUST use the pinned resolver,
not `PipingClassService::ruleFor($class, ...)`, which reads live rows.
This API is a first read path, not evidence of downstream-wide migration.
PostgreSQL CI remains deferred while the repository is private.

Local SQL smoke check after repository visibility was restored: PostgreSQL
16.2 parsed both functions from the revision-capture migration. The
schedule-only JSONB predicate accepted a changed schedule and rejected
range/material/branch/whitespace changes. This does NOT validate the full
snapshot trigger against real schema/RLS or run PHPUnit. GitHub Actions
run 38583348269 was blocked at scheduling by account billing and no new CI
run appeared after `d2b143a`; full PostgreSQL tests remain pending.

### Twenty-first slice: commit-time PMS approval completeness backstop

An SQL UPDATE could previously set a PMS revision to approved PUBLISHED
without creating any class snapshots, bypassing the CDE service's review,
engineering and capture transaction. A PostgreSQL **deferred constraint
trigger** now checks the *current* PIPING/PMS revision at transaction commit:
if it is approved A-family PUBLISHED, at least one governed class must be
published, no governed draft class may remain, and the number of immutable
revision snapshots must equal the published-class count. Deferral is essential:
the service first transitions the revision, then inserts all snapshots in
the same transaction. A direct status UPDATE with no snapshots fails with
SQLSTATE 23514. Existing approved revisions are not retroactively rewritten;
new state changes are checked. A regression forces the deferred trigger
inside an RLS transaction savepoint before attempting normal service
publication. A standalone PostgreSQL 16.2 smoke test installed the actual
migration function/trigger on a minimal schema: update + snapshot COMMIT
succeeded, while an update without a snapshot raised the expected exception.
This is not the full Laravel/RLS suite and does not prove pad engineering for
a privileged SQL writer able to forge the complete draft and snapshots.
GitHub Actions still does not schedule latest-head CI despite public
visibility; account billing or Actions permissions need attention.

CI correction: public-repo run 38585934338 actually started all jobs.
Laravel failed while the other five succeeded. One expected SQL trigger
exception inside an outer RLS transaction left PostgreSQL in aborted state
for the next assertion; negative SQL writes now use savepoints. Pure
validator PHPUnit tests now boot Laravel before creating framework
ValidationExceptions. The artifact download failed with EOF; a fresh full
CI run is required to identify any unannotated failures.

Validation update: CI 38591064891 on `283c4ee` passed the complete Laravel
PostgreSQL/RLS suite and the image, PCF, standards and HTML jobs. The only
failed check was Compose, which never started because of GitHub billing or
spending-limit restrictions. A failed-job rerun request was refused by
GitHub. The PMS revision and admission regressions therefore have a green
real-database suite, but the overall six-job workflow is not green.

### Twenty-second slice: B-suitability issue does not reset the approved class baseline

A PMS revision published with a B-family suitability is legitimate CDE
history, but is **not** approved for new line registration and has no A-family
class snapshots. The initial versioned-draft implementation copied only the
immediately preceding revision's snapshots; a subsequent A revision after
such a B issue would have no working copy and might silently capture the
older frozen live class instead of the most recently approved schedule.
`PmsApprovedRevisionBaseline` now consistently resolves the latest earlier
**A-family issued snapshot set** for new WIP draft seeding, WIP review and
nominated-author schedule edits. A follow-up database migration updates the
snapshot provenance trigger to compare a new draft with the latest prior
approved revision rather than requiring consecutive ordinal numbers. The
regression traverses A(SCH 80) -> A(STD) -> B1(no snapshot) -> A(STD): the
intervening B never authorizes new lines; the following A carries forward
STD; the line issued before B remains pinned to its own earlier A snapshot.
PHP lint passed, real PostgreSQL/RLS CI pending on this new slice.

CI status: run 38639634136 for `3fc043f` did not start any test jobs due to
GitHub account payment or spending-limit restrictions. B-coded baseline
behavior is not yet verified by PostgreSQL; 38591064891 on `283c4ee` is
the last successful Laravel/PostgreSQL/RLS check.

### Twenty-third slice: line-pinned generic and branch rule reads

The issue-time resolver now exposes a typed generic one-dimensional rule
value and a branch-rule selection from each line's immutable class snapshot.
Branch precedence mirrors the class-authoring resolver: smallest combined
header/branch DN footprint, then highest explicit priority, then earliest
creation time. The resolver refuses reversed branch DN, malformed ranges,
missing coverage and indistinguishable first-place cells rather than falling
back to the frozen live class or guessing a fitting. It retains P2P kind,
reinforcement and pad thickness from the issued snapshot. Pure unit tests
exercise specificity/priority/timestamp selection and fail-closed cases;
feature regression checks a B-pinned schedule through the generic rule API.
No existing production per-line component/MTO writer currently calls the
live rule resolver, so this is a safe read API for future line-dependent
engineering, not a claim that all downstream consumers are migrated.
Real PostgreSQL CI is pending; GitHub Actions billing has been intermittent.

Validation note: CI run 38640300534 for `9a000e3` did not start Laravel or
any other test job due to GitHub Actions account billing/spending limits.
Current branch changes after `283c4ee` still await a real PostgreSQL/RLS run.
