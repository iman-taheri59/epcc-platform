# HANDOFF — EPCC Platform

**Living document. Update it at the end of every working session.**
Last updated: **2026-10-06** · Status: **prototype + working vertical slices** · Tests: **864 passed / 3181 assertions** (+83 jsdom; full Laravel/PostgreSQL suite runnable after `restore.sh`)

This is the one file to read before touching anything. It answers: what is this,
what works today, what is deliberately missing, what will bite you, and what to
do next. Everything else is detail hanging off `README.md`.


---

## 0a. 2026-10-06 MTO requirements reconciliation

User uploaded `epcc-platform-mto-docs-changes-2026-10-06.txt`, which is a ZIP package renamed as `.txt`. It contains complete replacement documentation files, not patches. It must **not** be blindly copied over this repository because it would contradict the current branch and could erase the real Piping Workspace / Test Pack status added in `f33c9a7`.

Reconciled MTO foundation requirements are now recorded in:

- `docs/MTO-REQUIREMENTS-FA.md`
- `docs/MTO-REQUIREMENTS-EN.md`

Important current-state rule: these MTO/MR/PMS items are **requirements/design only**, not implemented in the current branch unless code/migration/test is later added. Do not describe them as `BUILT`:

- accepted immutable MTO snapshots;
- `BaselineService`, `baseline_batches`, `baseline_discrepancies`;
- `PcfParser`, `ModelImportService`, `model_imports`;
- PMS services and NDT models mentioned in the uploaded package;
- MTO acceptance, gross/net comparison, PO attribution/order gap, or usable-material gap.

Accepted MTO principles for future work:

- approved MTO = gross procurement-planning quantity;
- model/isometric estimate = net engineering need;
- positive `net > gross` is a shortage and percentage tolerance must not suppress it;
- `mto_item_no` is not globally unique and cannot be the sole downstream key;
- accepted MTO rows must be immutable snapshots;
- acceptance is all-or-nothing per MTO document/sub-MTO revision;
- aggregation across accepted MTOs requires disjoint scope or exact immutable pair-level additivity evidence;
- MTO permissions/access design is deferred.

Material Library foundation was implemented after this reconciliation:

- `app/database/migrations/2026_10_06_100000_create_material_catalogue.php`
- `app/app/Models/MaterialItem.php`
- `app/app/Services/MaterialCatalogueService.php`
- `app/database/seeders/MaterialCatalogueSeeder.php`
- `app/tests/Feature/MaterialLibraryTest.php`

Built behavior: two-level catalogue identity shape (`project_id IS NULL` reserved for global/reference, project rows for project overlays), exact commodity-code lookup, alias-not-identity, controlled categories/status/source/unit, bilingual descriptions, RLS isolation, synthetic PH-14 piping catalogue.

Validation: `restore.sh` passed; `MaterialLibraryTest` passed 6 tests / 15 assertions; `SeedPathTest` passed 2 tests / 6 assertions; standards validator passed. Full Laravel suite was started and showed no failures before the sandbox timeout, but it did not complete.

Recommended next slice: implement MTO document/revision/staging (`mto_documents`, `mto_revisions`, `mto_staging_rows`) against the new material catalogue. Immutable accepted rows remain the slice after that.

---

## 1. What this project is

A bilingual (EN/FA) modular platform for managing large industrial EPCC
projects — oil, gas, petrochemical, power. Sold per company on **Full** and
**Special** plans.

Four decisions are closed and should not be relitigated without a new ADR:

| Decision | Value | Where |
|---|---|---|
| Launch market | **Iran first.** On-prem/air-gap, Persian default, Jalali, IRR. International SaaS is a later phase, not parallel. | `docs/Iran-First-Market-Addendum.md` |
| Stack | **Laravel 13.31 + Filament v4 + PHP 8.4 + PostgreSQL 17** | `docs/ADR-003-Annex-A-Laravel-Evaluation.md` |
| Standards | **Selectable per project** — `ips-iran` (default), `iso`, `awp`. Chosen once at creation. | `standards-packs/` |
| AWP | **Deferred** to the international phase, kept in CI as a genericity guard. | `docs/ADR-002-Defer-AWP.md` |

> The requirements document is written **in English** by explicit instruction,
> even though project communication is in Persian. The *software* is bilingual.

---

## 2. Run it in 60 seconds

```bash
cd /home/user/epcc-platform/app
php artisan serve --host=0.0.0.0 --port=8000
```

After a sandbox reset the runtime is gone (source is not). Rebuild:

```bash
cd /home/user/epcc-platform && ./restore.sh     # idempotent, ~3-5 min
```

Sign in with any seeded account, password `password`:

| Email | Company | Plan | Good for demonstrating |
|---|---|---|---|
| `karimi@nioc.example` | NIOC-AFF (owner) | special | Everything unlocked; can create projects |
| `rezaei@pars.example` | EPC-PARS (contractor) | full | Authority limit blocks a 43.2B certificate |
| `hosseini@arvand.example` | EPC-ARVAND | **starter** | Locked modules / upgrade prompts |
| `tehrani@pmc.example` | PMC-TEHRAN | full | Consultant view |
| `former@pars.example` | EPC-PARS | — | **Disabled account** — gate 1 refusal |

All seeded emails end in `.example` and are generated by `AuthSeeder`; the login
screen lists them from the database, so they cannot drift out of sync.

---

## 3. What actually works today

**~40–45% of the specified system** (`docs/Prototype-Coverage-Map.md`, COV-EPCC-001).
Be honest about this in any demo.

- **Authentication** + the full **ARC §5 six-gate authorization pipeline**
  (AUTH → ENTITLEMENT → MEMBERSHIP → RBAC → ABAC → SoD, first failure wins).
- **Multi-tenancy enforced by PostgreSQL RLS**, not by `where` clauses. Scope is
  company × project.
- **Multi-project portfolio** and per-project certificate workspaces.
- **Project setup**: create a project, pick its standards pack, invite companies,
  staff people.
- **Release gates** driven by the framework-free standards engine.
- **Bilingual FA/EN with real RTL** and Jalali dates.
- **Entitlement-driven sidebar** (see §5).
- **Document register / CDE** (`doc`): rule-driven numbering, ISO 19650 states
  (WIP → Shared → Published → Archived) with suitability codes, revision
  control that archives rather than overwrites, **file attachments**
  (content-addressed, access-controlled), and the **review & comment cycle**
  that gates publication. See §5a and §5c.
- **Transmittals** (`doc`): formal multi-recipient issue, per-company
  acknowledgement with derived overdue tracking, and a bilingual ZIP package
  with a checksummed manifest. See §5d.
- **PDF export** of the MC certificate and the transmittal sheet, in both
  languages, via headless Chromium. See §5f.
- **QA/QC — punch list & Mechanical Completion** (`qaqc`): subsystem walkdown,
  A/B/C categories, a six-state lifecycle with independent verification, and the
  **MC gate** with its employer-approved exception. See §5e.
- **Filter + sort on every register table** (§5b), Persian-digit aware.

**Acceptance journey 3 (engineering document flow) now runs end to end**:
create → attach → share → review with blocking comments → dispose → publish →
transmit → acknowledge — **including PDF output**, which was the last gap.

**Acceptance journey 5 (mechanical completion) now runs end to end**: walkdown →
raise → assign → fix → verify → close → certificate, with the A-item gate
refusing issue and the owner's recorded exception the only way past it.

Deliberately absent: full-text/OCR search, offline sync, PDF export,
integrations, installer, and the PROCUREMENT / HSE / SCHEDULE modules.

---

## 4. Architecture rules you must not break

These were each learned by fixing a real defect. Full list in
`docs/Spike-RLS-Results.md` (12 standing rules).

1. **`SET LOCAL` requires an open transaction**, otherwise tenancy fails **open**.
2. Use **`FORCE ROW LEVEL SECURITY`**; table owners bypass RLS otherwise.
3. **`NULLIF(GUC,'')`** — `SET LOCAL` leaves the GUC as `''` after commit.
4. **Audit by actor**, not by subject.
5. **Never RLS-test on SQLite.** It has no RLS; the test would pass and prove nothing.
6. **Participation ≠ membership.** `project_company` is the commercial fact RLS
   keys on; `project_memberships` is the personal grant. Read queries filter on
   **both** (ARC §5.4).
7. **Tenant state is transaction-scoped.** Never a plain `SET`.
8. **An ungated certificate is not a cleared one** — it is unexamined.
9. **Middleware order is load-bearing** — `auth` before `tenant`.

---

## 5. The sidebar is an authorization surface

`app/Support/Navigation.php` builds the menu from the *same* gates the pipeline
enforces, so the menu can never offer something the pipeline then refuses.
Three states, and conflating any two is a real defect:

| State | Meaning | Rendered as |
|---|---|---|
| `available` | Entitled and built | A real link |
| `planned` | Entitled, **not built yet** | Inert, "Soon" |
| `locked` | **Not in the company's plan** | Inert, "Upgrade" — the in-product upsell surface |

Telling a customer to *upgrade* for a module they already pay for is a billing
complaint; telling them "soon" for something they never bought is a lost sale.
Keep the distinction.

- Locked/planned items render as `<span>`, never `<a>` — no href means they
  cannot be clicked, middle-clicked or crawled into a denial.
- Icons are **inline SVG**: the preview iframe has no network, so icon fonts and
  CDNs silently fail.
- Injected by a **view composer** (`ViewServiceProvider`), not by each
  controller — otherwise every new screen must remember the sidebar, and the one
  that forgets renders a page with no way out.
- `PLANNED_MODULES` in `Navigation.php` is the list to shrink as modules land.

**Collapse / expand.** Desktop toggles `body.rail` (a 60px icon rail); mobile
toggles `body.drawer` (off-canvas, closed by the scrim, Escape, or following a
link). Class names match `prototype/index.html` deliberately, so the two cannot
drift. Three non-obvious constraints:

- The burger lives in `partials/topbar.blade.php`, **outside** the sidebar. A
  control that collapses the sidebar must survive the collapse — inside the
  drawer it would slide off-canvas and strand the user.
- The collapsed state is restored **in `<head>`, before first paint**. This app
  is server-rendered, so every click is a new document; restoring it at the end
  of the body makes the sidebar flash open on every navigation, which reads as
  a broken toggle. The class goes on `<html>` first, then mirrors onto `<body>`.
- The desktop rail state must be **neutralised inside the mobile media query**,
  or the off-canvas panel slides in 60px wide showing icons and nothing else.
- **`max-width` and `overflow` do nothing on an inline box.** Anything collapsed
  by the rail must be `inline-block` (or block) first. The language switch is an
  `<a>` — inline by default — so when it inherited the collapse rules it laid
  out at zero usable width and disappeared from the *expanded* sidebar.
- **`display:none` cannot be animated.** It is a discrete property, so hiding
  labels with it makes the rail snap however smooth the container is. Collapse
  them with `max-width`/`opacity` (inline) and `height`/`padding` (block), and
  keep `overflow-x:hidden` on the panel so text clips instead of scrolling.
- The width transition must live **outside** the mobile media query. The
  original one only applied to `transform` on mobile, so desktop never animated.
- `prefers-reduced-motion` is honoured — motion is a vestibular accessibility
  issue, not a taste preference.
- The script **must wait for `DOMContentLoaded`.** The sidebar renders before
  the content column (flex order), so the script is emitted *before* the button
  it wires up; running immediately made `getElementById` return `null` and the
  script bail at its own guard — the button looked perfect and did nothing.

Behaviour is covered by `app/tests/Browser/sidebar-toggle.test.cjs` (17
assertions, jsdom). PHPUnit never runs JavaScript, so the Laravel tests can only
assert the markup is present — run the jsdom test after touching the script.

> **Never hand-run a script the page already ships.** The first version of that
> test re-evaluated the sidebar script *after* parsing had finished, which found
> the button and passed 17/17 while the live page was completely dead. Let the
> parser execute it in natural document order (`beforeParse` to stub
> `matchMedia`, then wait for `load`). A test that bypasses the real execution
> order proves nothing.

---

## 5a. Document register / CDE

`app/Support/CdeState.php` is the state machine; `app/Services/DocumentRegister.php`
holds the rules; `documents` + `document_revisions` are the tables.

Two rules are load-bearing and are enforced **server-side**, not by hiding
buttons:

- **WIP cannot jump to PUBLISHED.** Skipping SHARED skips review — a drawing
  reaching construction unchecked is the exact failure ISO 19650 exists to
  prevent. SHARED → WIP *is* allowed: review rejecting a drawing is normal.
- **ARCHIVED is terminal.** Superseding is a record, not an erasure, so a new
  revision archives the old one and history is kept.

Suitability codes are validated against the target state (S-codes for SHARED,
A/B-codes for PUBLISHED); only WIP may carry none.

> **When adding a tenant table, copy the policy from migration `110000`, not
> `100100`.** The document register shipped with the *original* policy and had
> two defects at once: no `NULLIF(GUC,'')`, and no project scope — so an owner
> scoped to PH-14 still saw its own KISH-1 rows, because those are also "its
> own". Company identity is not a project scope (standing rule 7).

> **Eager-load RLS relations inside `runAs()`.** A lazy `->currentRevision`
> evaluated after the scope closes runs with no bound tenant and returns NULL.
> The row is invisible, not missing — fail-closed, but it looks exactly like a
> broken write.

### 5a.1 Attachments (FR-DOC-01/02)

`DocumentFileStore` owns the bytes; `FileType` owns what is acceptable.

- **Content-addressed storage.** The path is
  `documents/{project}/{ab}/{cd}/{sha256}.{ext}` and contains **no user input
  at all**. Path traversal is designed out rather than filtered out. The hash
  doubles as the integrity record (FR-AUD-02) and de-duplicates re-issues.
- **Allow-list, never a deny-list.** A deny-list is a list of the attacks
  someone already thought of. SVG and HTML are refused outright: documents to a
  user, executable script to a browser.
- **The stored MIME comes from our table, keyed by extension** — never from the
  client's `Content-Type`.
- **Only PDF/PNG/JPEG are served `inline`**; everything else is `attachment`,
  plus `nosniff` and a restrictive CSP. Inlining an arbitrary upload is how a
  stored file becomes stored XSS.
- **Nothing is on a public disk.** Every byte is streamed through
  `DocumentController@download`, so downloads pass auth → entitlement →
  membership → RLS exactly like the register screen.
- **Files attach only to WIP/SHARED revisions.** A published revision is a
  matter of record; the way to change it is a new revision.
- **Detach never deletes shared bytes** while another revision references the
  same hash.

> **PHP's upload limit fails SILENTLY.** Exceeding `upload_max_filesize` yields
> an `UploadedFile` that is merely *not valid* — no exception. The stock 2 MB
> default rejects nearly every real deliverable. `restore.sh` now writes
> `/etc/php/8.4/cli/conf.d/99-epcc.ini` (100 MB), and the store reports the
> server limit by name instead of "unknown error".

### 5a.2 The CDE must actually be common

> **Found by live verification, not by tests.** The document tables shipped
> with the standard tenant policy — own rows, plus the employer sees all. That
> is right for a payment certificate and **wrong for a document register**: a
> PMC holding `doc.view`, a full participant on the project, opened the
> register and saw *"no documents"*. Consultant, MC and TPI were all equally
> broken. Every unit test passed, because they all asserted the old model.

Migration `130000` makes **the CDE state the sharing boundary, not company
ownership**:

| Row | Author | Other participants | Employer | Non-participant |
|---|---|---|---|---|
| WIP | ✅ | ❌ | ✅ | ❌ |
| Shared / Published / Archived | ✅ | ✅ | ✅ | ❌ |

WIP stays private because a contractor will not adopt a system that exposes
half-drawn work to the client. `WITH CHECK` is deliberately **unchanged** —
read widely, write narrowly.

The employer keeping sight of WIP is a **deliberate, reversible** choice (it
matches how the owner is treated everywhere else here, and Iranian employer
organisations expect it). Strict ISO 19650 would hide it; that change is
confined to one branch of the policy.

Verified live across five roles on PH-14: author 5 docs / 1 WIP, consultant
4 / 0, employer 5 / 1, rival contractor 4 / 0, non-participant 404.

## 5b. Table filter + sort (FR-UX-04)

`resources/views/partials/tabletools.blade.php`. Opt a table in with one
attribute; everything else is discovered from the markup:

```blade
<table data-tt>
  <thead><tr>
    <th data-sort="text">Number</th>
    <th data-sort="num">Amount</th>
    <th data-sort="text" data-facet>State</th>
    <th>Actions</th>            {{-- no data-sort: not sortable --}}
  </tr></thead>
```

Gives every data table a search box, a dropdown per faceted column, a live
row count, a reset button, and click-to-sort headers (asc → desc → **back to
the server's order**; without that third state there is no undo).

**Two escape hatches, both load-bearing:**

| Attribute | On | Why |
|---|---|---|
| `data-sortkey` | `<td>` | Sort by something other than the displayed text. |
| `data-facet-value` | `<td>` | Group by something other than the displayed text. |

Where they are used and what breaks without them:

- **CDE state** → `data-sortkey="{{ CdeState::ordinalOf(...) }}"`. Sorting the
  *label* is wrong in both languages: alphabetically "Archived" precedes "WIP",
  and the Persian labels collate differently again. The register is read as a
  pipeline and must sort as one. `CdeState::ordinalOf()` is the single source
  of that order; an unknown state sorts last rather than throwing, because a
  sort must never be the thing that takes a page down.
- **Money** → `data-sortkey="{{ $c->net_amount }}"`. The cell renders
  `۴۳٬۲۳۴٬۵۰۰٬۰۰۰`; sorting that as text is nonsense.
- **Gate verdict** → `data-facet-value`. The cell shows "Blocked" *followed by
  every failure reason*, so faceting on raw text would give each blocked row
  its own dropdown entry. A filter with one row behind every option is not a
  filter.

> **Persian digits are folded before matching.** Typing `14` finds `۱۴-P-۱۲۰۱`
> and vice versa. Arabic yeh/kaf are folded to Persian, ZWNJ to a space, and
> diacritics dropped — a user on an Arabic keyboard, or pasting from an older
> Windows document, must still find the row.

**Progressive enhancement.** The toolbar is *built by script*. The server never
ships a search box that would be dead if the script failed — a plain, complete,
working table degrades to exactly that. The script sits in `<head>` and so
carries the same `DOMContentLoaded` guard as the sidebar (§5); without it,
`init()` finds no tables and fails silently.

**Filtered rows use the `hidden` attribute**, not a class, so they leave the
accessibility tree too. The row count is `aria-live="polite"`; sortable headers
are real `<button>`s with `aria-sort`.

> **Client-side, deliberately.** These are one project's rows — tens, not tens
> of thousands. A server round-trip per sort would be slower, lose scroll
> position, and thread every filter through the RLS query. **When a register
> outgrows this** (the SRS assumes 20k documents) the answer is server-side
> paging behind the same controls, not a bigger script.

Guards: `tests/Feature/TableToolsTest.php` (10 tests) + `tests/Browser/
table-tools.test.cjs` (44 assertions fa / 41 en). The PHPUnit sweep walks
**every** Blade view and fails if any `<table>` with a `<thead>` lacks
`data-tt`, so a future screen cannot ship an unfilterable table.

## 5c. Review & comment cycle (FR-ENG-06)

`CommentCategory`, `CommentDisposition`, `ReviewCycle`, `ReviewComment`,
`documents/review.blade.php`. Implements acceptance journey 3.

**The rule the module exists for: an open blocking comment prevents
publication.** Without it a comment sheet is a suggestion box, and a drawing
with an unanswered Code 1 reaches the site.

| Category | Blocks publication | Needs an answer |
|---|---|---|
| HOLD, CODE1, CODE2 | **yes** | yes |
| CODE3 | no | yes |
| CODE4, INFO | no | no |

`CommentCategory::blocksPublication()` is the single source of that truth;
adding a category is a one-line change, not a hunt through controllers.

**The gate lives in `DocumentRegister::transition()`**, not the controller —
the API, the importer and any future bulk action all pass through that method,
and a gate that only exists on one screen is not a gate. The refusal *names the
comments* ("comments 1, 4, 5"), because "blocked by 3 comments" sends the user
hunting.

Other enforced rules, each mutation-tested:

- **The originator cannot review its own document.** Self-approval is the
  control the owner is paying for.
- **Only the originator may dispose.** A reviewer answering its own comment is
  marking its own homework.
- **Rejecting requires a written justification.** "Rejected" with no reason is
  how a review cycle becomes a dispute, and that dispute is eventually read by
  a lawyer.
- **Only SHARED revisions are under review.** WIP has not been offered;
  PUBLISHED is answered by a new revision, not a late comment.

> **This table breaks the tenant pattern deliberately.** A comment has TWO
> companies with a claim: `company_id` (the reviewer who wrote it) and
> `originator_company_id` (who must answer). Keying the policy on `company_id`
> alone would stop the contractor answering comments on its own drawing —
> i.e. the entire workflow. Reads are open to every project participant
> (hiding comments from other reviewers is why reviews go round three times);
> writes are restricted to those two companies, with per-column rules in the
> service.

> **`FOR UPDATE` is not allowed with aggregate functions.** Sequence allocation
> locks the parent `document_revisions` row, not `max(seq)` — PostgreSQL
> rejects the latter outright. Parallel multi-reviewer is a stated requirement,
> so two reviewers colliding on `(revision_id, seq)` is the normal case.

> **A DB column default is NOT reflected back into the model.** `disposition`
> defaulted to `'PENDING'` in PostgreSQL, but the freshly created object held
> `NULL`, so `isPending()` and `isBlocking()` returned the wrong answer on a
> brand-new comment while the row was correct. Set defaults explicitly in the
> service. Caught by a test asserting the in-memory object.

## 5d. Transmittals (FR-ENG-05)

`TransmittalPurpose`, `Transmittal`, `TransmittalRecipient`, `TransmittalItem`,
`TransmittalService`, `TransmittalPackager`, `transmittals/index.blade.php`.
Completes acceptance journey 3: a document is drafted, reviewed, published and
now **formally issued**.

**A transmittal is evidence.** Its value in a delay claim is that it proves
*what* was sent, *to whom*, *when*, and *when they acknowledged it*. Every rule
below protects one of those four facts.

| Purpose | Acknowledgement | Response due |
|---|---|---|
| FOR_REVIEW | required | 14 days |
| FOR_APPROVAL | required | 10 days |
| FOR_CONSTRUCTION | required | 7 days |
| FOR_INFORMATION | not required | no clock |

- **Numbering** `{PROJECT}-TR-{SEQ:4}`, unique per project, DRAFT → ISSUED.
- **Issued letters are immutable.** You cannot add a document to a letter that
  has already gone out; you send a second letter. Editing a sent letter is
  forging the record.
- **Items point at a `revision_id`, never a `document_id`**, and snapshot
  `number / revision / state / suitability` *as at issue*. The revision moves
  on afterwards; the letter must still read as it did on the day.
- **WIP cannot be transmitted** — WIP has not been shared, so it cannot be
  formally issued.
- **Acknowledgement is recipient-only, self-only, and idempotent** — a second
  click never overwrites the first timestamp, because that timestamp is the
  entire evidentiary value of the row.
- **Overdue is derived, never stored.** A stored flag is wrong the moment the
  clock passes it and nobody ran the job.
- **ZIP export**: entries `{number}_Rev{rev}_{sanitised}`, plus `MANIFEST.csv`
  (SHA-256 per file) and a bilingual `TRANSMITTAL.txt` cover sheet.

> **`MANIFEST.csv` is written with a UTF-8 BOM.** Without it, Excel on a Persian
> Windows install renders every Farsi filename as mojibake, and the manifest is
> the one artifact a claims consultant actually opens.

> **RLS is sender ∪ recipients ∪ employer — and only once ISSUED.** The first
> cut gated recipiency alone, so an addressee (and the employer) could read a
> letter the sender had not yet sent, while the sender was still changing the
> distribution list. A draft is not correspondence. Caught by probing the model
> directly rather than trusting the index page, which had looked correct
> because the seeded recipient was also the employer.

> **A policy on a child table MUST NOT select from its parent.** Gating
> `transmittal_recipients` on `transmittals.issued_at` deadlocked the pair —
> the parent's own policy reads the child — and PostgreSQL aborts with
> `infinite recursion detected in policy`. `issued_at` is therefore
> **denormalised onto both child tables by trigger** (`AFTER UPDATE` on the
> parent to propagate, `BEFORE INSERT` on each child to inherit). The trigger,
> not the service, owns the guarantee, so an importer or a DBA cannot bypass it.

> **A third party sees nothing.** A contractor must not read a rival's
> correspondence on the same project — verified live: EPC-SAHAND, a full
> participant, sees an empty register and gets a 404 on the ZIP.

## 5e. QA/QC — punch list & Mechanical Completion (FR-COM-04/05)

**Category letters are A / B / C in BOTH languages** (user decision,
2026-09-11). The punch category is a contractual term that appears in the same
form on drawings, in the MC procedure and in correspondence with vendors who do
not read Persian, so الف/ب/ج invented a second vocabulary for one thing. Only
the explanatory prose after the dash is translated
(`A — مانع تکمیل مکانیکی`). This also covers the gate message and the MC
certificate PDF. Enforced by `tests/Feature/PunchTableTest.php`.

`PunchCategory`, `PunchState`, `Subsystem`, `PunchItem`, `McCertificate`,
`PunchListService`, `McCertificateService`, `PunchController`,
`punch/index.blade.php`. Completes **acceptance journey 5**.

**The one rule the module exists for:**

> An MC certificate cannot be issued while a category-A punch item is open,
> *unless* an Owner-approved exception is recorded.

Both halves matter. A gate with no legitimate override gets bypassed outside
the system, which is worse than no gate — so the override exists, but it is
**employer-only**, needs a **written reason**, and stores a **`jsonb` snapshot
of the items open at that moment**. The snapshot is evidence: re-deriving it
later would show today's open items, not the ones the owner actually accepted.
`test_the_exception_snapshot_does_not_drift_when_items_close_later` pins that.

**Where the rules live**

| Rule | Home | Why there |
|---|---|---|
| Which category blocks which milestone | `PunchCategory::blocksMechanicalCompletion()` / `blocksReadyForStartUp()` | A client renaming a category is a different edit from a client changing a rule, so labels are separate from the gate |
| Legal lifecycle moves | `PunchState::canTransition()` | One table, not scattered `if`s |
| Who may do what | `PunchListService` | The API, an importer and a mobile client must not each invent their own version |
| The gate itself | `McCertificateService::issue()` | Single choke point |

`RAISED → ASSIGNED → FIXED → {VERIFIED | REJECTED}`, `REJECTED → ASSIGNED`,
`VERIFIED → CLOSED`. **`isOpen()` is true for everything except `CLOSED`** —
`VERIFIED` deliberately still blocks, because verified means the inspector
agrees, while closed means the owner accepted it into the record.

Role rules, from the SRS §4.1 matrix row "Punch item (A)": MC/TPI/PMC/OWN raise,
the responsible contractor fixes, **anyone but the responsible company** verifies,
**only the employer closes** and **only the employer grants exceptions**.

**Traps this cost:**

- **The fixture had no management contractor.** Journey 5 needs a verifier that
  is neither the contractor nor the owner, so `MC-KARAN` (`tenant_type = MC`,
  user `jafari@karan.example`) was added. `SeedPathTest` asserts 8 companies now.
  **Note the meaning of MC — see §5h; it is not "Mechanical Completion".**
- **`consultant` had no `punch.*`**, so the PMC got a 403 on a register the SRS
  says it may verify. Capability decides *reach*; the service decides *actions*.
- **State must be set explicitly on create.** A DB default is not reflected back
  into the model, so `isOpen()` would read `NULL` on a freshly created row.
- The seeded subsystem is **deliberately blocked** (2 open A-items). A demo that
  opens on a green screen never shows the feature that was sold.


### A certificate must not rewrite its own evidence

An issued MC certificate is a statement about a **moment**: what was accepted,
on what basis, by whom. Anything on it that is recomputed at render time is not
evidence — it is a live report wearing a certificate's letterhead.

The PDF was computing "total / closed / open category-A" from the punch list on
every download. Those three numbers are the technical justification for the
handover. Found by downloading the same certificate twice:

| | at issue | a few closures later |
|---|---|---|
| Closed | 2 | **3** |
| Open — category A | 2 | **1** |

Same number, same URL, same ISSUED certificate, different facts. And the drift
always runs in the **flattering direction**: a certificate issued over two open
A-items quietly repairs itself into one that looks nearly clean, erasing
exactly what an auditor or an arbitrator would be looking for. The waived-items
list beside it was already correct, because `exception_items` is a stored jsonb
snapshot — so the page was half snapshot, half live query, and the half that
drifted was the summary.

Fixed with `mc_certificates.punch_summary` (jsonb, nullable), written by
`McCertificateService::issue()` and read through
`McCertificate::punchSummary($items, $openBlocking)`. **Nullable and
state-dependent on purpose:** a DRAFT keeps showing live numbers, because a
draft's job is to tell whoever is deciding whether to issue where the subsystem
stands *right now*. Issuing is what freezes it.

The subsystem register on `/projects/{p}/punch` still shows live closure
percentages, and that is correct — it is a working view of the site, not a
record of an event. **The rule is not "snapshot everything", it is "snapshot
what a document asserts about a moment".**

`punchSummary()`'s `isIssued()` check is currently unreachable through the UI
(nothing writes a snapshot before issue), so removing it changes no page and no
end-to-end test — mutation **M108** survived until the contract was asserted on
the model directly. It is kept rather than deleted because it is protecting a
real future mistake: the day anything writes a snapshot earlier, a draft would
silently start reporting a stale position to the person deciding whether to
issue.

## 5f. PDF export (headless Chromium)

`PdfRenderer`, `resources/views/print/{layout,mc-certificate,transmittal}.blade.php`,
`PunchController::certificatePdf()`, `TransmittalController::sheet()`.
**This retires the PDF half of risk IR-R2**, which the ADRs had flagged as the
highest-risk item in the stack and required a P0 spike for.

**Why a browser and not a PHP library.** Persian is a joining script: every
letter has up to four contextual forms, ZWNJ is semantic (`پیش‌نویس` is not
`پیشنویس`), and Latin document numbers sit inside RTL sentences under the bidi
algorithm. HarfBuzz gets this right. PHP PDF libraries mostly do not, and when
they fail they fail **silently** — a page that looks plausible to a
non-Persian reader and is gibberish to the client. mPDF's own maintainers point
users at headless Chrome for this reason.

**Verified, not assumed.** Rendered PDFs were rasterised and read: joining
forms, ZWNJ, bidi-embedded Latin tags, Persian digits and Jalali dates are all
correct in both languages. `pdffonts` confirms Vazirmatn is **embedded and
subsetted**, not substituted. ~1.05 s per document.

**Dependencies** (`restore.sh` installs all three): `chromium`,
**`fonts-vazirmatn`** — without it PDFs still generate, as boxes — and
`poppler-utils` for the tests. Chromium is **~315 MB and must go in the
air-gap bundle**; it runs with networking disabled.

**Design decisions worth keeping:**

| Decision | Reason |
|---|---|
| `PdfRenderer` takes finished HTML and knows nothing about certificates | Documents stay ordinary Blade views that can be previewed in a browser |
| Validates `%PDF-` magic bytes **and** a size floor | Chrome can exit 0 having written a truncated file; serving that as a signed certificate is worse than an error |
| Recursive scratch cleanup | Chrome treats the scratch `HOME` as real and mints `.cache`/`.config`/`.local`; plain `rmdir()` fails silently and leaks |
| Binary list is constructor-injectable | Lets an operator point at a custom Chrome, and lets tests simulate a host without one |
| **ZIP degrades, `/sheet.pdf` does not** | The ZIP's job is delivering drawings, so a missing renderer only drops the cover page. On the standalone endpoint the PDF *is* the deliverable, so it 503s |

**Traps this cost:**

- **`position: fixed` footers do not pin per page in Chrome's print path.** The
  footer landed across the top of page 2, over the table header. Footers now
  flow as normal content at the end of the document.
- **Chrome creates files inside the scratch `HOME`**, so cleanup must recurse.
- **`pdftotext` inserts bidi control characters** (U+202A–U+202E, U+200E/F)
  around RTL runs. They are invisible and they break naive substring matching,
  so the test helper strips them before asserting. A raw `assertStringContains`
  on Persian will fail for the wrong reason.
- The print views are exempt from the `data-tt` sweep test — a PDF has no
  JavaScript and no user to click a filter. The exemption is scoped to
  `views/print/` and was mutation-tested to confirm it still catches screens.


### Shell contract (read before adding a screen)

Every screen that shows the sidebar MUST nest exactly like this:

```blade
<body>
<div class="app">          {{-- flex row --}}
@include('partials.sidebar')   {{-- must be INSIDE .app --}}
<div class="wrap">         {{-- content column, flex:1 --}}
@include('partials.topbar')    {{-- must be INSIDE .wrap --}}
  ...page...
</div>
</div>
</body>
```

Two failure modes, both reported by the user on 2026-09-11:

- **Sidebar outside `.app`** — it stops being a flex sibling, so the content
  column starts at x=0 and slides underneath it.
- **Topbar as a direct child of `.app`** — it becomes a THIRD column next to
  the sidebar instead of a bar above the content, and the collapse button
  disappears from where the user expects it.

The toggle (`id="sb-burger"`) lives in the topbar, deliberately outside the
sidebar: a control that collapses the sidebar must survive the collapse, and on
mobile the sidebar is off-canvas entirely. `ShellStructureTest` enforces all of
this per screen. The view composer supplies `$user`, `$acting`, `$nav`, `$plan`
and `$langSwitchUrl` as **defaults**, so a new screen only needs the markup
above plus its view name in `ViewServiceProvider::SHELL_VIEWS`.


## 5g. Vendor transmittals — the second stream (FR-ENG-05b)

Two letters share a shape but are not the same thing:

| | Counterparty | Meaning |
|---|---|---|
| `CONTRACTOR` — `{PROJECT}-TR-{SEQ:4}` | EPC → Employer / Consultant | the contractual submission; starts a review clock, evidence in a delay claim |
| `VENDOR` — `{PROJECT}-VTR-{SEQ:4}` | Vendor → EPC | supply-chain correspondence; the Employer is not a party |

**User decisions (2026-09-11), all four confirmed before building:**
1. **Vendors do not log in.** The EPC's document controller records what the
   vendor sent. So `company_id` (the tenant column and RLS anchor) stays the
   EPC, and `vendor_company_id` names the originator. Conflating the two would
   hand a vendor tenant the contractor's whole register the day vendors get
   logins.
2. **Separate number series AND a separate screen** — two independent counters
   scoped by `kind`, and `/projects/{code}/vendor-transmittals`.
3. **Roll-up must be traceable.** `transmittal_items.source_item_id` is a
   nullable self-reference, so `vendor letter → EPC letter → client` survives.
4. Document type: existing `{TYPE}` codes are enough for now.

**Where the rules live:** `TransmittalService::draft()` (validation +
per-stream numbering), `TransmittalService::rollUp()`, two CHECK constraints
(`kind` is known; vendor fields agree with `kind`), and the RLS policy.

**The non-obvious part — separation must hold in the DATA, not just the UI.**
The Employer's original blanket clause ("owner sees every issued letter in the
project") silently exposed vendor letters. A filter on screen with a leak
underneath is not separation, so the employer catch-all is now
`kind = 'CONTRACTOR'` only. The Employer still sees a vendor letter when
**named as a recipient** (an explicit act of sharing) and always sees vendor
documents **rolled up** into a contractor submission. Migration
`2026_09_11_130000_...`; all three properties are pinned by tests.

**Traps:**
- `rollUp()` copies the snapshot from the **source item**, never re-reading the
  document. What the EPC forwards is what the vendor actually sent; if the
  vendor has since issued Rev C that is a different letter, not a silent
  upgrade.
- Roll-up is **idempotent** — rolling the same letter up twice adds nothing.
- Only an **issued** vendor letter can be rolled up, and only into a **draft
  contractor** letter.
- A company **cannot be its own vendor**, or a contractor could file its own
  submissions in the vendor stream and drop them from the client-facing count.

## 5h. "MC" means two different things — read this before using it

**This was wrong in the codebase for months and no test could see it.**

| Abbreviation | Meaning | Kind of thing | Where it lives in code |
|---|---|---|---|
| MC | **مدیریت پیمان** — Management Contractor | a **company type** | `App\Support\TenantType::MC`, `companies.tenant_type`, `MC-KARAN` |
| MC / MCC | **تکمیل مکانیکی** — Mechanical Completion | a **milestone** | `McCertificate`, `mc_certificates`, `PunchCategory::blocksMechanicalCompletion()`, `{PROJECT}-MCC-{SEQ}` |

A **management contractor** is a firm the *employer* engages to administer the
contract with the contractor on the employer's behalf: supervising execution,
checking progress and quality, verifying what the contractor claims, signing
off milestones. It is a standard Iranian contracting arrangement with its own
fee structure. **Mechanical Completion** is the point at which a subsystem is
built and ready for pre-commissioning. Same two letters, unrelated concepts.

The seeder, two Blade views, the SRS role table and the coverage map all
described the `MC` *tenant type* as "Mechanical Completion body". The company
was even named "Karan Completion Services / خدمات تکمیل کاران".

**Why nothing failed.** A tenant type only has to be a *matching string*. The
role rules (`PunchListService::VERIFIER_TYPES` / `RAISER_TYPES`) were correct —
an employer's contract administrator *should* raise and verify punch items —
so every behavioural test passed. The label is decoration to the code and
load-bearing to the user. Only a human reading the screen could catch it, and
that is exactly what happened.

**What now prevents a recurrence:**

- `App\Support\TenantType` — the nine SRS types in one place, with bilingual
  labels and a long comment on the MC collision. The codes were previously
  loose strings in five files.
- Migration `2026_09_11_140000_constrain_tenant_type.php` — a CHECK constraint
  so an importer or a `psql` session cannot invent a tenth type that the role
  rules would then silently refuse to recognise.
- `tests/Feature/TenantTypeTest.php` (7 tests) — asserts the words, not just
  the behaviour: that MC's label is «مدیریت پیمان», that the string
  "mechanical" does **not** appear in it, that the two contract-role views
  agree, that every type has labels in both languages, and that the CHECK is
  neither too loose nor too tight. Mutations M75–M77 confirm all three bite.

**The lesson worth keeping:** behavioural tests cannot see a wrong name. If a
term is contractual vocabulary the customer will read on screen, pin the term
itself in a test.

## 5i. The Filament admin panel (§9 item 1)

`/admin`, provider `app/Providers/Filament/AdminPanelProvider.php`.
Currently five resources: **Document**, **PunchItem**, **Transmittal**,
**User**, **Subsystem** (9 admin routes). Filament v4 + Livewire, discovered
from `app/Filament/`.

**TENANCY IS THE LOAD-BEARING PART.** Filament builds its OWN middleware stack
in the panel provider; the generated one knows nothing about `BindTenant`. By
standing rule 1, `SET LOCAL` outside a transaction is silently discarded, so a
panel without it does not merely lose isolation — it never establishes it.

What that actually looked like, measured rather than assumed:

| | with no `BindTenant` | with `BindTenant` |
|---|---|---|
| sees another tenant's rows | no | no |
| sees its OWN rows | **no** | yes |

RLS was failing **closed**, so "does not leak across tenants" passed while the
panel was simply broken and empty. **Only the mirror test told them apart.**
Never assert isolation without also asserting visibility — a screen that shows
nothing passes every leak test ever written.

**Four things the stock scaffold got wrong for this product:**

1. **403 for everyone.** Filament refuses panel access outside `local` unless
   `User` implements `FilamentUser`. Added `canAccessPanel()` returning
   `is_active` — mutation **M79** proved the first version was untested: a
   *deactivated* account could open the admin panel and the whole suite stayed
   green. That is the finding an on-prem security review leads with.
2. **Google Fonts.** `->font('Vazirmatn')` fetches from bunny.net/Google by
   default — a hard **AG-01…AG-06 violation**. In a customer datacentre it does
   not degrade, it hangs. Fixed with
   `provider: \Filament\FontProviders\LocalFontProvider::class`, which emits
   nothing and lets the OS-installed `fonts-vazirmatn` win — the same font the
   PDF renderer uses. Pinned by a test that greps the HTML for five CDN hosts.
3. **English, LTR, UUID-first.** `--generate` emits a column per DB column: it
   led with three UUIDs and showed both title columns at once. A document
   controller identifies a document by its **number**. The UUID column is
   *deleted*, not toggled off — a hidden column is still one somebody can turn
   on and paste into an email.
4. **A bulk DELETE button.** On a controlled register. Revisions supersede and
   states archive; that is the whole point of ISO 19650. Removed.

**A bug tests could not see.** The state column was bound to
`currentRevision.state`; the attribute is `cde_state`. Filament resolved null
and rendered an **empty cell on every row** — page still 200, tenancy tests
still green, and the register silently lost the field that says whether a
drawing may be built from. Found by *looking at a screenshot*. Any column whose
value arrives through a relation now gets a content assertion, because a
mistyped attribute fails silently rather than loudly (mutation **M81**).

**Locale.** `users.locale` existed and was populated but nothing read it; the
hand-written screens use `?lang=`. New `SetUserLocale` middleware applies the
user's own language (Persian default, `?lang=` still wins) because Filament
resolves labels through the translator at render time, far from any view.
Panel strings live in `lang/{fa,en}/epcc.php` — the first translation files in
the project.

**What an admin resource may DO is the second load-bearing decision.**
`--generate` gives full CRUD on every model. For this product that is wrong in
a specific way, and differently wrong per resource:

| Resource | create | edit | delete | why |
|---|---|---|---|---|
| Document | no | titles only | **no** | number/sequence/company are derived by `DocumentRegister`; the register archives rather than deletes (ISO 19650) |
| PunchItem | no | **no** | no | `state` is a position in a workflow with rules about *who* may move it, not a column |
| Transmittal | no | no | no | an ISSUED transmittal is evidence of what was sent on a date; items snapshot the revision as sent, and the per-stream counter is allocated under a row lock |
| Subsystem | no | no | no | the breakdown arrives as a bulk import from system engineering, and `code` is the join key the whole handover chain hangs off — a hand-typed code that misses the P&ID is invisible to every document, punch item and certificate that references it; editing it after punch items exist orphans them |
| User | no | no | no | `is_active` IS gate 1 and is what `canAccessPanel()` reads — an editable toggle re-opens every door in the product without passing any of the six gates; `company_id` is the tenant; roles live on the per-project membership, not the user |

The generated document form offered `number`, `sequence`, `company_id` and
`current_revision_id` as free inputs. Retyping a number is not an edit, it is a
collision with the `(project_id, number)` unique index; changing `company_id`
does not move a document, it makes it vanish from its author's register. The
form now edits the two genuinely free fields — the bilingual titles — and shows
the rest as read-only placeholders, because an admin still needs to *see* which
record they have.

The punch resource is stricter still: an admin form writing `state` would let
someone mark an item VERIFIED with nobody having verified it, on a register
whose entire purpose is proving somebody did — and `McCertificateService`
reads that column, so a forged state also unblocks a completion certificate.

**A grep is not an assertion.** The first version of the no-delete test
searched the source for `"DeleteAction"` and failed against the *code comment*
explaining why there is no DeleteAction. It would equally have missed a delete
introduced under another name. Rewritten to call `canDelete()` / `canCreate()`
/ `getPages()` — the resource's own authorization, which is what Filament
actually consults.

**One resource shows BOTH transmittal streams**, with `kind` as a filter.
The hand-written UI deliberately splits contractor (`-TR-`) from vendor
(`-VTR-`): a document controller working the contractual log does not want
supply-chain letters in their numbering. The admin view answers the opposite
question — "what left this project, by any route?" — so splitting it would be
the wrong shape. The employer still sees no `VTR` rows here, and that is
enforced in PostgreSQL, not in the resource: mutation **M86** hard-filtered the
table to `CONTRACTOR` and the isolation test still passed, because RLS was
doing the work all along.

### The Jalali defect — why a green suite still shipped a wrong page

The transmittal list went out with Filament's `->dateTime()` and rendered
`سپتامبر 12, 2026` on a page that was otherwise entirely Persian. HTTP 200,
every test green. **It was found by looking at a screenshot.**

On a submission log that is not cosmetic: the date decides whether a reply
landed inside the contractual 14-day window.

The root cause was not the panel. Jalali formatting only ever existed as a
private closure inside each Blade view — `$jdate = function ($d) use ($fa)`,
copy-pasted per screen. They agreed by luck, and a surface written months later
could reuse none of them, so it reached for the framework default.

Fixed by extracting **`app/Support/JalaliDate.php`** (`format`,
`formatWithTime`, `digits`) and routing every date and number through it —
all three panel tables and all ten Blade views; there is no `Morilog` call left
in `resources/views`. It takes `$lang` as an argument rather than reading the
app locale, because the PDF renderer formats a document in a language chosen
per render, not per request.

`DateFormattingTest` pins this **structurally**, not just by output: one test
fails if any view calls `Morilog` directly, another if any Filament file calls
`->date()`/`->dateTime()` outside a comment. Mutations **M87–M89** confirm all
three bite. A behavioural test alone would not have caught the re-introduction
of a fourth private closure.

**Scope.** This is an ADMIN view, not a replacement for
`/projects/{code}/documents`. That screen enforces the workflow (numbering via
`DocumentRegister`, transitions via `CdeState`, gating via `ReviewCycle`). This
one is for what the workflow does not cover: fixing a typo, looking a record up
across projects, inspecting what is stored. Both sit under the same RLS.

### 5i-2. Identity tables: the leak the Users resource exposed

**Building the Users resource is what found it.** `users`, `companies` and
`project_memberships` were never in the original RLS migration's
`TENANT_TABLES`. Isolation for users was a `->where('company_id', ...)` in one
controller — precisely the "forgotten where clause" that migration's own
comment says RLS exists to backstop. Measured, bound as EPC-PARS:

```
documents visible: 5      <- correctly narrowed
users visible:     9      <- every user of all eight companies
```

Nothing had exposed it because no screen listed users. A naive Filament table
would have published the client's representatives, the PMC's inspectors and two
rival EPCs' engineers — names and addresses — to every tenant.

Fixed in `2026_09_12_100000_secure_identity_tables`: RLS on `users` and
`project_memberships`, with three USING clauses — own company, the identity
hatch, and **counterparties on a shared project** (a document says "reviewed
by", a transmittal "acknowledged by"; hide those rows and working pages render
blank names).

**`companies` is deliberately NOT covered.** The participant list is on the
contract and every party already knows it; covering it would need a second
bypass for a row whose leak is "the contractor learns the consultant's name".
Recorded so the next person does not assume it was missed.

Three traps paid for here:

1. **Policy recursion.** The tight rule — "users holding a membership on a
   project my company is on" — makes `users` read `project_memberships` while
   that table's policy reads `users`. PostgreSQL rejects it outright:
   *infinite recursion detected in policy for relation "users"*. The clause was
   re-expressed through `project_company` (no RLS of its own), which is
   slightly wider — employees of a participating company who are not on the
   team — and that is the accepted trade.
2. **`NULLIF` on every GUC read** (rule 3). `current_setting(...,true)` returns
   `''`, not NULL, once a GUC is set and cleared in the same transaction, and
   `''::uuid` is a hard error. The first draft reproduced the bug this project
   already fixed once in `2026_09_09_100200`.
3. **Login happens before a tenant exists.** Authentication must read a user
   row to learn which company to bind, so `epcc.company_id` is necessarily
   unset — and unset means "see nothing". Without an escape hatch RLS does not
   leak; it locks everyone out. Hence `TenantContext::forIdentityLookup()`.

**The hatch is a bypass and is documented as one.** Mitigations: one greppable
name, transaction-scoped like every other GUC, cleared in a `finally`, and
**snapshot-restored rather than force-cleared** — a nested call that cleared
the GUC on exit silently shut the hatch its caller still needed, and the
symptom was not an error but a write that matched no rows, leaving the
"disabled" fixture account enabled.

### The bug that a green suite could not see

After the policy landed, **every `/admin` route redirected to the login screen
with a perfectly valid session.** Filament's `Authenticate` resolves the user
before `BindTenant` binds a tenant, so the guard got NULL and treated the admin
as a guest.

All 21 panel tests stayed green, because **`actingAs()` injects a User object
straight into the guard and never asks the user provider for it**. Found by
taking a screenshot — the third defect on this project found that way rather
than by a test.

Fixed at the *provider*, not in a middleware: `TenantAwareUserProvider` wraps
`retrieveById` / `retrieveByToken` / `retrieveByCredentials` /
`updateRememberToken` in the hatch, so the session guard, the panel,
`Auth::attempt` and remember-me are all covered by one greppable class.

**And the first test written for it was also inert.** `phpunit.xml` sets
`SESSION_DRIVER=array`, so a session does not survive between test requests and
Laravel keeps the already-resolved user in memory — `retrieveById()` is never
called. Mutation M95 deleted the provider override outright and the HTTP test
stayed green. The assertion that bites calls the provider directly, the way the
session guard does. **If a test exercises authentication, check it is not
riding on `actingAs()` or an in-memory session.**

### 5i-3. The subsystem register — deriving a gate, and a panel-wide digit defect

The MC gate is **derived, never stored**. `subsystems` has no state column: a
subsystem is certifiable when it has no open category-A punch item, which is a
question about *other* rows and changes the moment somebody closes the last
one. A boolean written at walkdown time is wrong by the afternoon, and "can
this be handed over" is precisely the question nobody should answer from a
stale column.

**Three rules the table must not restate in its own words.** `PunchCategory`
owns "A blocks MC", `PunchState` owns "open means anything but CLOSED", and
`McCertificate` owns DRAFT/ISSUED. The table asks all three rather than
hardcoding `['A']` or a literal state list — otherwise a future change to the
rule leaves this screen quietly asserting the old one, on the screen where
somebody decides whether to hand over a unit.

**Counts are computed by the database.** `Subsystem::openBlockingItems()` and
`closurePercent()` walk the loaded `punchItems` collection: correct for one
record on a detail page, quadratic on a register. The table uses `withCount`
with the same predicates, and a tinker probe asserts the SQL and the model
agree row by row — if the two ever disagree, one of them is wrong and it is
worth knowing which before shipping.

**`closurePercent()` returns 100 for an empty punch list.** Arithmetically
fine, dangerous on a handover register: "100%" beside a subsystem nobody has
walked down reads as finished when it means the opposite. The column renders
**"not walked down"** in words for that case.

**A one-row fixture proves nothing** (recorded before, re-proved here). With a
single subsystem a working badge and a hardcoded one render identically. The
fixture now carries five, each existing to kill a specific mutation:

| code | state it pins |
|---|---|
| PH-14-SS-2201 | 2 open A-items, DRAFT certificate — the blocked case |
| PH-14-SS-3301 | items raised and all CLOSED — genuinely clear |
| PH-14-SS-4401 | zero items — never walked down |
| PH-14-SS-5501 | one A-item **VERIFIED, not CLOSED** — still blocked |
| PH-14-SS-6601 | one open **category-B** item — open, but not blocking |

SS-5501 and SS-6601 were added *because* mutations survived without them.
M96 (the table's own open-state list drops VERIFIED) and M99 (blocking widened
to A+B) were both undetectable by any test until a row existed that could tell
the rules apart. **When a mutation survives, ask whether the fixture can
express the difference at all before rewriting the assertion** — and note M96
survived a test that checked `PunchState::isOpen()` and the *model* helper.
Both were right; the *table* was wrong. Assert on the rendered row.

**Adding fixture rows broke three unrelated tests**, which hardcoded
`PH-14-PL-0006` and `assertSame(5, $count)`. Those literals tested the
fixture's size, not the behaviour claimed in the test name. They now derive the
expectation (`count() + 1`, and the MC body's view as the reference for "every
participant sees the same list"), so the next fixture change does not break
them again.

**The panel-wide defect the screenshot found — mixed digit systems.** Persian
pages rendered our columns in Persian digits (۵, ۴۰٪) and Filament's own chrome
in Latin: *"نمایش 1 به 5 از 5 نتیجه"*. Latin numerals inside a Persian sentence
are instantly visible to a reader and invisible to an assertion that only
checks the page loaded. It affected **all five resources**, not just the one
being built — it had been shipping since the first one.

Fixed once, at the translator: `App\Support\LocalisedNumberTranslator` extends
Laravel's `Translator` and converts numeric `:parameter` values when the locale
is `fa`, bound via `$this->app->extend('translator', …)` in
`AppServiceProvider::register()` (**register, not boot** — the container
resolves `translator` during boot). Per-table fixes were rejected: they leave
the next table free to reintroduce it and do nothing for strings inside the
Filament package.

Only **wholly-numeric** values convert. Converting the rendered line would turn
PH-14-SS-2201 into PH-۱۴-SS-۲۲۰۱, and tags are read off drawings and typed into
search boxes in Latin. That guard is currently **unobservable through the UI** —
no rendered string passes a digit-bearing non-numeric parameter, and a mutation
removing it leaves every page byte-identical. So it is pinned by a test against
the translator directly, not by an HTTP test that would pass either way. An
inert test is worse than none: it reports safety it never checked.

### The mobile blank page — and why only Persian had it

`/projects/PH-14/documents?lang=fa` at a 430px viewport rendered **one unique
colour**: the background. Not a slow load, not missing data — the HTML was 66KB
with every document in it. The same page in **English at the same width was
fine**, and that asymmetry was the whole clue.

A flex item defaults to `min-width:auto`, which means *never shrink below my
content's intrinsic width*. The documents table is ~1032px intrinsic; the phone
viewport was 485px. So `.wrap` grew **past** the viewport instead of
overflowing inside it. In LTR that clips harmlessly off the right edge and the
page still reads. In RTL the block is laid out from the right edge, so the same
overflow pushes the **entire document off-screen to the left** — a blank
rectangle.

Measured, before → after:

| page (fa, 430px) | before | after |
|---|---|---|
| documents | **1** | 2760 |
| transmittals | 206 | 2632 |
| project / team / punch | 2562 / 3217 / 1087 | 2953 / 3219 / 3321 |

Fixed in the shell, not per view: `min-width:0` on `.app > .wrap`, plus
`overflow-x:auto` inside the existing mobile media block. **Both halves are
needed.** `min-width:0` alone contains the overflow but then clips it, and the
columns past the fold on a document register are the state and the revision —
the two fields somebody opens that register to read.

Three candidate fixes all restored the page (`table-layout:fixed`,
`min-width:0`, `display:block` on the table). The containment fix was chosen
because it repairs the layout bug itself rather than restyling every table, and
it leaves the `<table>` semantics the jsdom harnesses depend on intact.

**Why no test caught it.** The suite asserts status codes and the presence of
strings in HTML. Every one of those assertions passed on a page that painted
nothing — the markup was perfect and unreachable. `ShellStructureTest` now
pins both CSS rules *and* checks that the pages carrying wide tables really do
render through the shell, so a page that stopped using it could not keep
passing on the CSS assertion alone (M110–M111).

**This is the fifth defect found by looking rather than testing**, and the
first found by looking at a phone width. Rendering at desktop only is a blind
spot for an Iran-first product where site staff are on phones.

### 5j. AG-06 — the air-gap pre-flight, and what static scanning cannot see

`php artisan verify:airgap` is the command the customer runs at acceptance. It
scans `public/` and `resources/` for anything a browser would fetch from the
internet and grades each finding:

| severity | meaning | exit |
|---|---|---|
| **BLOCKER** | fetched while rendering: `src=`, a stylesheet `href`, `@import`, `url()`, or a JS loader that assigns `.src`/`.href` | **1** |
| **LATENT** | present in a shipped file on a path we do not enable | 0 (1 under `--strict`) |
| **BENIGN** | comment, licence, XML namespace | not reported |

The severity split is the whole product. A bare grep for `https://` over a
vendor tree returns dozens of hits, all noise until classified, and a command
that fails on things the customer cannot act on is a command the customer
learns to ignore. Default is BLOCKER: an unknown external reference is guilty
until somebody writes down why it is not. `--json` feeds the acceptance report;
`--strict` is what CI runs.

**Current findings: 0 blockers, 3 latent** — Pusher's CDN inside Filament's
`echo.js` (served `->core()` on every panel page but inert, because Echo is
never initialised with `BROADCAST_CONNECTION=log`) and Font Awesome inside
EasyMDE (`autoDownloadFontAwesome` defaults to true; we register no
MarkdownEditor field). If anyone adds one, pass
`autoDownloadFontAwesome => false` or it becomes a blocker on site.

**Two things the static scan missed, and the general lesson.**

First, Pusher. `echo.js` stores `cdn_https:"https://js.pusher.com"` and
concatenates the script URL at call time, so no literal `src="https://..."`
exists anywhere in the file. The first version of this command gave it a clean
bill of health. Dynamic script loaders are the highest-risk shape precisely
because they fetch lazily, so a pattern for CDN bases held in config
properties was added.

Second — and this one is the real lesson — **`ui-avatars.com`**. Filament's
default avatar provider builds the URL in PHP from the user's name, so it
appears in no shipped file at all; it was rendered into the user menu of every
admin page. Found by capturing a real browser's net-log against `/admin`, not
by reading code. Two defects in one: an image that cannot be fetched in a
customer datacentre, and a named employee posted to a third party — for a NOC
affiliate's staff list that is a confidentiality problem quite apart from the
air gap. Fixed with `App\Support\LocalAvatarProvider`, which draws initials as
an inline SVG data URI (deterministic colour per name, Vazirmatn for Persian
initials, zero requests).

> **A file scanner can only find URLs that exist as text on disk.** Anything
> assembled at runtime is invisible to it. That is why
> `AirgapVerificationTest` asserts against the *rendered HTML* of every panel
> screen as well as against the command — rendered output is the only place
> runtime-assembled URLs become visible. Note it filters to fetching
> attributes only: an `<a href>` to an external site is navigation and costs
> nothing until clicked, and flagging those would bury the real findings.

`FilamentInfoWidget` was also dropped: it put the framework's branding and
exact version number on the customer's dashboard, which is both wrong-branded
and a free CVE list for an attacker.

**This is the ninth defect found by looking rather than testing, and the third
in the air-gap family** (after `->font()` and the preview iframe). The pattern
is consistent: everything that reaches outward is invisible in a sandbox with
working internet. The only reliable detector is to watch the network, or to
cut it.

### 5k. Procurement and materials — تهیه، بازرسی، انبار، سایت

The second C in EPCC. Five documents covering the chain the user named:

| Persian | document | prefix |
|---|---|---|
| تهیه | requisition → PO | `-MR-`, `-PO-` |
| بازرسی | inspection record | `-IRN-` |
| ورود به انبار | goods receipt | `-GRN-` |
| خروج از انبار | material issue | `-MIV-` |
| ورود به سایت | site delivery | `-SDN-` |

**One movement table, not four.** GRN/MIV/SDN/MRN are four pieces of paper but
the same fact: a quantity of one lot moved somewhere at a time and somebody
signed. Four tables would mean four sequence allocators, four RLS policies and
a balance that UNIONs four shapes. The ledger is **append-only** — a wrong
receipt is corrected with a reversing movement, never an UPDATE, because a
warehouse's only real asset is being able to say what it held on the day of an
audit.

**The sign convention lives in `MovementType::sign()`, not in the data.** A
stored signed quantity means every reader must know the convention, and
somebody eventually inserts a negative receipt. Note `SITE => 0`: the material
left the warehouse at ISSUE, and counting it again would double-deduct. That
mutation (M114) kills three tests.

**ISSUE and SITE are two steps on purpose.** ISSUE is a stock question; SITE is
a completion question. Material can sit on a laydown area for weeks between
them, and collapsing them hides it exactly when somebody is looking for it.
`test_issued_but_not_yet_on_site_is_still_a_shortage` pins this.

**Lots exist because the user asked for heat-number traceability.** Stock is
not "300 m of pipe" but "120 m of heat A-4471". The MTC is a **document in the
CDE**, not a file path, so it inherits revisions, transmittals and access
control — a material certificate outside the register is one nobody can find
at handover. `isFullyTraceable()` requires both an identifier *and* the
certificate: a heat number with no MTC behind it is not traceability.

**Inspection is ADVISORY — the user's explicit choice.** A failed or missing
inspection records a warning and lets the receipt through. That matches how
sites run (material lands at 2am, the TPI signs on Sunday) and a system that
refuses the receipt gets bypassed with a paper note nobody types in. The price
is that "received but not passed" must be loud, so `inspection_result` is
denormalised onto the lot and `uninspectedLots()` drives a panel on the screen.
**Advisory applies to the verdict, not to who may give it** — a contractor
still cannot inspect its own material, or the record looks like assurance while
carrying none. NULL is treated as needing attention: not inspected is not the
same as passed.

**Two separate SoD rules, two different messages.** The raiser may not approve
their own MR; and the technical and commercial approvals may not be signed by
the same person. They are different failures and a buyer needs to know which
one they hit. Mirrors `cert.approve` XOR `cert.submit`.

**Expediting keeps the promise and the forecast in separate columns.** A system
that lets the forecast overwrite the promise makes every order look on time.

**RLS is commercial here, unlike the punch list.** A punch list is shared — the
whole project walks down together. Procurement is not:

- a **vendor** sees the PO addressed to it and nothing else (vendors on one
  project are frequently competitors and a PO carries prices)
- **requisitions** are the buyer's planning: employer/PMC/MC read them, vendors
  never do
- **stock and movements** are operational, so every participant reads them —
  the employer is entitled to know whether the valve is on site, which is the
  weekly spreadsheet this module exists to kill

**The MC link (user asked for it).** `subsystemMaterialStatus()` compares
ordered against *delivered to site*, computed live, never stored — same rule as
`closurePercent()`. A subsystem that buys nothing reports complete rather than
short, the same call as closure returning 100 for an empty punch list.

Module key is `proc`, in the `full` and `special` plans. Screens:
`/projects/{code}/procurement` and `/projects/{code}/materials`.

**UML (`docs/uml/`).** Five PlantUML sources plus a single self-contained viewer,
`docs/uml/uml.html` — inline SVG, **zero external references**, so it obeys the
same air-gap rule as `tour/tour.html` and opens from a file:// path on a machine
with no network:

| file | what it answers |
|---|---|
| `01-domain-model.puml` | the 7 tables, their multiplicities, and *why* lots and the ledger are shaped that way |
| `02-state-machines.puml` | MR, PO, and the physical custody of a lot side by side |
| `03-sequence-receive-to-site.puml` | one heat from inspection to the MC gate, including the lock and the derived PO state |
| `04-reversal-and-rls.puml` | correcting a booking without erasing it; what a vendor sees (0 MRs / 3 POs / 3 lots) |
| `05-component-view.puml` | layering, and the rule that value objects depend on nothing |
| `06-breakdown.puml` | **AS BUILT** — ADR-004 facility / area / subsystem + the composite FK |
| `07-proposed-ingestion.puml` | **PROPOSED, not built** — ADR-005 engineering data ingestion |

Regenerate with `plantuml -tsvg docs/uml/*.puml` then re-run the inliner
(the HTML embeds the SVGs; editing a `.puml` alone does **not** update it).
Diagram text is **English** per the standing documentation rule, while the
column and label names are quoted verbatim from the schema — the diagrams were
written against a live `information_schema` dump rather than from memory, so
they are safe to trust when the code and this file disagree.

**Note for whoever installs the toolchain:** `graphviz` and `plantuml` are *not*
in the base image and are wiped by a sandbox reset. `sudo apt-get install -y
graphviz plantuml` restores them (`apt-get` without `sudo` fails on the dpkg
lock — the user is uid 1000 but has passwordless sudo).

### 5l. Physical breakdown — facility / area / subsystem (ADR-004)

`project → facility → (optional) area → subsystem`. Decision record and the full
as-built table: `docs/ADR-004-Physical-Breakdown-Plant-Pipeline-Subsystem.md`;
diagram `docs/uml/06-breakdown.puml`.

**What a facility is.** One table, `facilities`, with `kind ∈ FacilityKind` =
PLANT | PIPELINE | OFFSITE | MARINE | UTILITY. Not sibling `plants` and
`pipelines` tables: what they share is being a *commissioning parent*; what
differs is *spatial addressing*, and that lives in a per-kind validated
`addressing jsonb`. Sibling tables would push every upward query into a
two-branch `COALESCE` plus an XOR CHECK — the classic shape of a missing
abstraction. Same pattern already proved twice here: `McCertificate.state` and
`MovementType`.

**`FacilityKind::all()` vs `selectable()` — the distinction matters.** `all()`
drives the CHECK constraint, so a PIPELINE row is storable and importable.
`selectable()` omits PIPELINE, so nobody can create one through the UI while KP
stationing is undesigned. Storable-but-not-offerable is the honest state; a
CHECK that rejected PIPELINE would have to be migrated later, and one that
offered it would promise reporting that does not exist.

**Areas are optional, on purpose.** Plant 3D's own refinery example has 13
areas, so omitting the level would break lossless import; but an Iranian booster
station with 12 subsystems should not have to invent 12 areas. Hence
`subsystems.area_id` is nullable and the fixture is **deliberately uneven** —
`PH-14-PL-01` has areas `AR-22`/`AR-33` *and* `SS-5501` hanging directly off the
facility, while `PH-14-PL-02` has no areas at all. A fixture where every branch
looks the same cannot fail informatively. Same rule as `closurePercent()`
returning 100 for an empty punch list: **absence of structure is not an error.**

**Numbering is per facility.** `UNIQUE(project_id, code)` → `UNIQUE(facility_id,
code)`. Real projects renumber per plant, and forcing project-wide uniqueness
across a 4,000-subsystem job invents collisions that do not exist on site.
Consequence to remember: **the bare code no longer identifies a subsystem.** The
admin register shows Facility as a *non-toggleable* column for that reason, and
`Subsystem::fullCode()` composes `"{facility} / {code}"` at read time. It is not
a stored column — a composed code in a column drifts the moment a facility is
renamed, and issued certificates already snapshot their identifiers.

**The one constraint to not remove.** `subsystems_area_within_facility` is a
*composite* FK `(area_id, facility_id) → areas (id, facility_id)` (backed by
`areas_id_facility_id_unique`), `ON DELETE SET NULL`. Two independent FKs would
happily allow a subsystem in facility *A* pointing at an area in facility *B* —
silent cross-facility corruption that every roll-up would then report
confidently and wrongly. Deleting an **area** nulls `area_id` and keeps the
subsystem; deleting a **facility** cascades, because a subsystem with no
commissioning parent is meaningless.

**Migration trap (cost an hour, will recur).** The backfill wrote nothing and
the migration died with `23502 … facility_id contains null values`. `subsystems`
is under `FORCE ROW LEVEL SECURITY` and a migration has no `epcc.company_id`
GUC, so the backfill `SELECT` matched zero rows — **silently**, because RLS
filters rather than errors. Fix: `DISABLE ROW LEVEL SECURITY` around the
backfill, then restore **`ENABLE` *and* `FORCE`** — `ENABLE` alone leaves the
owner exempt, and role `epcc` owns these tables. Safe only because PostgreSQL
DDL is transactional.

**Tests:** `tests/Feature/BreakdownTest.php`, 25 tests / 69 assertions, covering
structure, per-facility code reuse, `fullCode()`, cross-facility integrity,
area-delete vs facility-delete semantics, `FacilityKind` (including
known-but-not-selectable), the addressing jsonb, `Area::displayCode()`, RLS
reads and writes, and that the migration left `relrowsecurity` *and*
`relforcerowsecurity` true on all three tables. Mutations M118–M123 all caught.

## 5m. Engineering reference data — ASME sizes, schedules, end types, templates (phase 0)

**ADR-007 is ACCEPTED AND BUILT.** This is the first piece of ADRs 006–010 to exist as code.
It is deliberately the first: the line-number parser, the port compatibility engine and the
PCF importer all need the size vocabulary before they can validate anything.

### What it is

Four versioned JSON packs under `standards-packs/reference/`, read through one memoised
singleton. **Not database tables.** `NPS 6" = DN 150 = OD 168.3 mm` is true on every project
in every company in every year — putting it in a table means either ~500 duplicated rows per
project or the system's first cross-tenant table plus a permanent RLS exception. The
protection actually needed is *nobody can write, including the owner company*, which a file
on disk gives absolutely and a permissive RLS policy only pretends to.

| Pack | Size |
|---|---|
| `pipe-sizes.ref.json` | 36 sizes, 1/8″–48″ |
| `pipe-schedules.ref.json` | 355 (size, schedule) pairs, 18-rung ladder |
| `end-types.ref.json` | 13 PCF end types |
| `component-templates.ref.json` | 20 templates, 41 port definitions |

Access: `App\Support\Reference\PipeReference`, singleton in `EpccServiceProvider`, directory
from `config('epcc.reference_dir')`. `App\Support\Reference\PipeSize` is the readonly value
object. **Every lookup throws** (`UnknownSizeException`, `UnknownScheduleException`) — except
`resolveLabel()`, which returns null because line-number parsing legitimately asks "is this
token a size at all?" where "no" is a normal answer.

### The four NPS columns, and why none can go

At the user's explicit instruction, and each has a job the others cannot do:
`nps` `"2 1/2"` is what the engineer reads · **`npsDecimal` `2.5` is the only summable one —
this is what makes dia-inch computable (ADR-010)** · `dn` `65` is the integer key for ADR-008's
`int4range` bands · `odMm` `73.0` is the physical dimension. A future "simplification" to
fewer columns silently breaks dia-inch reporting.

### STD is not SCH 40 — the trap that justified this whole ADR

Published tables disagree, and **several print STD values under a column headed SCH 40**.
Verified in the shipped data and asserted in three independent layers:

| NPS | SCH 40 | STD | SCH 80 | XS |
|---|---|---|---|---|
| 6″ | 7.11 | 7.11 | 10.97 | 10.97 |
| 10″ | 9.27 | 9.27 | **15.09** | **12.70** |
| 12″ | **10.31** | **9.53** | 17.48 | **12.70** |
| 24″ | **17.48** | **9.53** | 30.96 | **12.70** |

STD diverges from SCH 40 from 12″; XS diverges from SCH 80 from 10″. Above those, STD is
pinned at 9.53 and XS at 12.70 regardless of diameter. **A test that spot-checks 2″ and 6″
passes on a completely wrong large-bore table.**

### TRAPS

- **PHP coerces numeric string array keys to integers.** `array_keys()` on the decoded wall
  table returns `40` as an **int**, so `schedulesFor()` handed back a mixed `int|string` list:
  strict `in_array()` silently fails, and feeding a key back into `wall()` is a `TypeError`.
  Fixed with `array_map(strval(...), …)`. Caught by the suite on first run.
- **The reachable alias-fallback bug runs the other way.** Asserting STD ≠ SCH 40 at 12″+ does
  **not** stop a "helpful" fallback, because STD is never the missing one. **ASME defines STD
  at 22″ and 26″ but no SCH 40 there at all** (likewise XS but no SCH 80 from 26″ up) — a
  fallback returns 9.53 mm for a schedule that does not exist. Survived the first suite; found
  only by mutation M1.
- **Instance identity does not prove memoisation.** A singleton that re-decodes 500 rows per
  lookup is still a singleton. The test now warms the cache, deletes the files, asserts the
  lookup still works, **and** asserts a fresh instance against the same empty directory throws
  — otherwise it would pass on an eager-loading implementation. Found by mutation M14.
- **A PCF bore is meaningless without its unit.** `UNITS-BORE` may be `INCH`, `MM` or
  `INCH-SIXTEENTH`. A bore of `96` is 6″ in sixteenths and **not a pipe size at all** in
  inches. `resolveBore()` therefore takes the unit as a required argument and throws on an
  unrecognised one rather than defaulting.
- **Persian digits must be folded.** `۲ ۱/۲` → `2 1/2`. Without it, a Farsi-locale user typing
  a perfectly valid size gets `UnknownSizeException`.

### Validation, three layers

Generation-time checks in the emitting script; `validate_packs.py` grew
`check_reference_schema()` + `check_reference_integrity()` (NPS labels re-parsed with
`Fraction` and compared to `npsDecimal`, `odIn × 25.4` reconciled to `odMm`, monotonic ladder,
the STD/XS pins, `numbered ⇒ weldable`, `TEE` must have 3 ports and be `qualifierDerived`,
`EQUAL_TEE`/`REDUCING_TEE` **forbidden**, `NOZZLE` must carry `ownerKind`); then 49 PHPUnit
tests including `test_it_touches_no_database()`, which fails if reference data is ever moved
into a table. **15 data mutations and 15 PHP mutations were run; all 30 caught.**


## 5n. Why one database, not one per project (ADR-011)

Asked by the user on 2026-09-15. Answer: **no for the hosted SaaS, yes at instance level for
on-premise.** Measured rather than asserted, and one finding settled it.

**The fixture already contains the breaking case.** `EPC-PARS`, `NIOC-AFF` and `PMC-TEHRAN` each
participate in **two projects** — normal for an Iranian EPC contractor, not an edge case. Under
database-per-project a single human becomes two independent user rows with two password hashes
(verified in a spike), and the post-login landing page — *my documents across my projects* —
cannot be produced by a query at all:

```
ERROR:  cross-database references are not implemented
```

That is a hard PostgreSQL limit, not a config problem. `plans.max_projects` also proves the
**commercial unit is the company, not the project**, so the billing boundary and the storage
boundary would point in opposite directions.

**Isolation is already there.** Binding a session shows the same company gets strictly one
project's rows when bound to that project, both when bound to neither, and a non-participant
gets zero. Separation would buy isolation that exists, and would swap a database-enforced rule
(fails closed) for a connect-to-the-right-database rule in application code (fails open).

**RLS applies to `COPY`, not just `SELECT`** — so a per-project export is just a bound session.
The usual "but we need per-project handover" argument does not require separation.

The one genuine capability the shared database lacks is **per-project point-in-time restore**.
Schema-per-project solves it and stays cheap to reach later, because every table already carries
`project_id`, RLS is already project-aware, and there are **zero** `DB::connection()` call sites.


## 5o. The RLS escape hatch is a performance defect (ADR-012) — UNFIXED

**Found 2026-09-15 at 200 projects / 2.64 M rows. Present in production code today, invisible at
fixture scale, and it couples tenants: one customer's page slows down when an unrelated
customer's project grows.**

Every tenant policy has this shape:

```sql
(NULLIF(current_setting('epcc.project_id', true),'') IS NULL      -- escape hatch
 OR project_id = NULLIF(current_setting('epcc.project_id', true))::uuid)
AND EXISTS (SELECT 1 FROM project_company pc WHERE …)
```

`A IS NULL OR col = A` is **not sargable**. No index on `project_id` can ever be used:

```
Seq Scan on punch_items   Rows Removed by Filter: 1315440   -- to return 25 rows
Execution Time: 870 ms  (1,690 ms once the table doubled)
```

Adding `(project_id, sequence)` changed nothing — **the index was never the problem.**

**The trap: the fastest fix is the broken one.** Deleting the `OR` branch gives 870 ms → 0.16 ms
and **silently returns 0 rows** for the cross-project landing page (company bound, no project
bound). No error; every project-bound test still passes. A benchmark alone would have chosen it.
`coalesce(setting, project_id)` is correct but still opaque to the planner (183 ms, parallel seq
scan).

**The fix that is both:** the policy keeps only membership (`project_id IN (SELECT … FROM
project_company WHERE company_id = …)`) and the *query* adds `WHERE project_id = :current`.
Measured: register page **0.144 ms**, cross-project view **211,200 rows**, non-participant **0**,
participant asking for a project it is not in **0**.

**The curve goes flat** — a 540 k-row project pages in the same 0.13 ms as a 5 k-row one, at both
1.3 M and 2.6 M total rows. Page latency follows page size, not table size. **No partitioning, no
sharding, no database-per-project.**

Not yet applied: it changes 24 security policies. Isolation *between companies* stays
database-enforced; isolation *between projects of the same company* moves to the query layer —
a deliberate decision, not a side effect. Plan in ADR-012 §8, and **write the failing
cross-project test first**, because the current suite would pass the broken version.


## 5p. Per-project backup and deletion (ADR-013) — proven on a scratch copy, not yet built

User asked whether one project can be backed up and then completely deleted. **Yes — the full
cycle (export → delete → restore → verify) was run end to end on a byte-for-byte copy of the live
database.** 93 rows / 26 tables exported, delete took **53 ms** via 23 cascades, zero orphans,
other projects untouched, restore matched the manifest exactly and every FK re-validated.

**Four traps, and the first two make the naive answer wrong:**

1. **`pg_dump` as the app user silently truncates.** `FORCE ROW LEVEL SECURITY` applies to the
   owner, so the dump **aborts mid-way, exits 0, and leaves a 29 KB file** where the real dump is
   166 KB. A backup script checking `$?` reports success. Per-project backup therefore requires a
   superuser/`BYPASSRLS` role — the backup job is a **privileged component**.
2. **`pg_dump --where` is PostgreSQL 18.** On 17.11 it is `unrecognized option`. Row-filtered
   export must be built table by table with `\copy (SELECT … WHERE project_id=…)`.
3. **A topological sort of the 25 tables silently drops the document register.**
   `documents.current_revision_id ↔ document_revisions.document_id` is a genuine cycle, so those
   four tables have no topological position and fall out of the recursion — the export completes,
   reports success, and contains **no documents**. Fix: explicit reviewed order + restore under
   `SET session_replication_role = replica`, then re-validate. **The per-table row-count manifest
   is what catches a silently-empty table.**
4. **Deleting the rows does not delete the files.** No FK reaches the filesystem: after a clean
   cascade with zero orphans, the project's 4 blobs are still on disk. Blob paths are
   project-scoped (`documents/<project-uuid>/…`) and no sha is shared across projects, so it is a
   directory removal — but it must happen **before** the rows, or the paths are gone.

**Also:** `authorization_denials` and `gate_evaluations` carry `project_id` with **no FK**, so they
are never cascaded and must be deleted explicitly. And `projects_code_unique` means a deleted
code becomes reusable, so two unrelated `PH-14`s can end up in the archive.

Deleting PH-14 destroyed 1 MC certificate, **2 payment certificates**, 5 transmittals and 3
inspections — contractual evidence. ADR-013 therefore proposes three separate operations (export /
archive / ceremonial two-person hard delete) and recommends **archive as the default**.


## 5q. Project archive — BUILT (the answer to "what happens when a project finishes?")

ADR-013 asked whether a finished project should be archived or hard-deleted. **The decision was
archive**, and it is now built. A hard delete remains a separate, ceremonial operation that is
deliberately *not* implemented yet.

**What archiving is.** A project moves `ACTIVE ↔ ARCHIVED`, reversibly. Archived means:
read-only for everyone including the owner, hidden from the portfolio by default, and **not
counted against `plans.max_projects`**. Nothing is deleted, nothing is moved, every certificate and
transmittal stays exactly where it was and stays exportable. There is deliberately **no `DELETED`
state** in `ProjectState` — deletion is not one dropdown position away from archiving.

**Why the quota exclusion is the commercial crux.** A customer on a one-project plan who finishes a
job and cannot start the next one without deleting the first will delete the first. The licence
terms would be quietly instructing them to destroy contractual evidence. Archiving only works as an
alternative to deletion if it is free of the quota — see `ProjectSetupController` (the count is now
`->active()`), and the refusal message now tells them archiving is the way out.

**Two enforcement layers, and both are necessary.**

| Layer | File | Fails how |
|---|---|---|
| Service (refuses first) | `app/Services/ProjectArchiveService.php` | `ValidationException`, translated, actionable |
| RLS (backstop) | migration `2026_09_15_120000_add_project_archive_state.php` | `SQLSTATE 42501` on INSERT/UPDATE; **silence** on DELETE |

Neither alone is enough. The service is forgettable — a new write path that skips `assertWritable()`
gets no protection from it. RLS is mute — see the trap below. So the service exists to produce a
sentence a user can act on, and RLS exists so that forgetting it is merely rude rather than
destructive.

**`epcc_project_is_writable(uuid)` is `STABLE SECURITY DEFINER` and must stay that way.** Without
`SECURITY DEFINER` the function is itself subject to RLS on `projects`, so a session scoped to
project A cannot see project B's state, the lookup returns NULL, and the freeze **fails open**.

**The DELETE hole, found by trying it.** The freeze policy is `FOR ALL USING (true) WITH CHECK
(...)`. `USING (true)` is deliberate — a RESTRICTIVE `USING` clause is ANDed into `SELECT` too, and
an archive you cannot read is just a slow delete. But **`WITH CHECK` is never evaluated for
DELETE**, because there is no new row to check. With only that policy, deleting the punch list of
an archived project reported `DELETE 9` and removed all nine rows. Fixed with a second policy per
table, `FOR DELETE USING (epcc_project_is_writable(project_id))`, which constrains only DELETE and
leaves reads alone. **22 tables × 2 policies.** Verified: archived → `DELETE 0`, rows intact;
reopened → `DELETE 9`.

**UI.** Archive/reopen live on the project page, owner company only (`TenantType::OWN`), with the
reason recorded and shown back as `Archived on <date> by <user> — <reason>`. The portfolio hides
archived projects but always shows a clickable count — hiding without saying so is how a finished
project starts to feel lost.

Tests: `php artisan test --filter=ProjectArchiveTest` — **30 tests**, including the DELETE
regression, the silent-failure documentation, the quota exclusion and the bilingual refusal.


## 5r. Piping lines + numbering + the AFC gate (ADR-006 phase 1) — BUILT

The first piece of the engineering-object spine. A **line** is the unit the whole piping discipline
hangs off: components, joints, welds, NDT, hydrotest and progress all resolve to a line. Phase 1
builds the register, the project-configurable number, and the gate that says *"this line may be
built"*.

### The numbering engine is data, not code

The number format is **per project**, stored as jsonb on `project_piping_config`:

```
{size}-{service}-{class}-{subsystem}-{sequence}-{coating}   →  8-P-A1-PH-14-SS-2201-001-CT2
```

Each token declares `source` (`attribute` | `parent` | `counter`), an optional `width` for padding
and an `optional` flag. **Padding is render-time only** — `sequence` is an integer in the database,
so `MAX(sequence)+1` stays a number and never sorts as `"10" < "9"`.

**Do not parse a number by splitting on the separator.** The separator occurs *inside* token values:
`explode('-', '8-P-A1-PH-14-SS-2201-001-CT2')` yields **9 fragments for 6 tokens**, because the
subsystem code `PH-14-SS-2201` contains three of them. `parse()` builds an anchored regex from the
injected vocabularies instead.

Two PCRE facts the tests now pin:

- **Alternation is first-match, not longest-match.** Usually backtracking hides this (`A1|A1B`
  against `A1B-…` retries and succeeds). It stops hiding it when the *next* token can absorb the
  difference — with `PH-14|PH-14-SS` against `PH-14-SS-2201`, both splits match and the wrong one
  wins silently. Hence vocabularies are sorted **longest-first**. Mutation M1 proves it.
- A relaxed diagnostic token must be lazy `.+?`, and **only one token may be relaxed at a time**.
  `[^-]+` cannot cross a separator, so relaxing everything at once just fails again.

**`compose()` throws; `parse()` never throws.** Composition with a value outside the project
register is a refusal — otherwise an unrecognisable number reaches a drawing. Parsing is diagnosis:
it returns one of four outcomes (no-match / valid / matched-with-`unknownValues` /
matched-but-`unconfirmed`), because `isValid()` and `isFullyChecked()` are different questions when
a number arrives from someone else's P&ID.

### Two doors, and why imports must not feed the counter

`create()` allocates: it locks the **subsystem** row and takes `MAX(sequence)+1`.
`register()` imports: it stores the supplied number **verbatim** and leaves **`sequence` NULL**.

That NULL is load-bearing. An imported `…-777` must not push our next allocation to 778 — we would
be inheriting a foreign numbering scheme's gaps by accident. A non-conforming number is stored with
`number_tokens` NULL and flagged, never rejected and never "normalised": ADR-006 §10.2 forbids
inventing or rewriting someone else's tag. Mutation M10 pins it.

### What AFC actually means, and the B-code trap

AFC — *Approved For Construction* — is the moment the office tells the site **this is safe to
build**. A gate that can be passed without real approved evidence is worse than no gate, because
every downstream report then claims a check happened.

`CdeState::isApproved($state, $suitability)` requires **both**:

| Condition | Why |
|---|---|
| `cde_state === PUBLISHED` | SHARED is issued *for review*, however it is coded |
| suitability starts with `A` | **B1–B5 mean "approved with comments"** — published, valid, *not* fit to build |

Both halves are needed and neither is redundant. **Mutation M8 caught the gap here**: the obvious
"not approved" fixture is SHARED+S2, but S2 already fails the A-check, so the state condition was
never exercised and could have been deleted with the suite still green. The test that separates
them is **SHARED + A1** — right code, wrong container. A null or empty suitability is not approval
either: absence of a code is absence of a decision.

Waving a B-code through is precisely the rework mechanism the gate exists to stop.

**Two defects were found by writing these tests, before any of this ran:** `confirmAfc()` called
`CdeState::isApproved()` — *a method that did not exist* — on `$revision->state`, *a column that
does not exist* (it is `cde_state`, with `suitability` a sibling column).

### States

`DRAFT → AFC_CONFIRMED → RELEASED`, with backward edges to `DRAFT` only. `revertToDraft()` demands
a reason and **nulls** `afc_confirmed_at` / `released_at` — a line pulled back must not keep a
stale approval timestamp that a later report would read as still-approved (mutation M12). Four DB
CHECK constraints enforce known states and AFC/release provenance independently of the service, so
a future write path that bypasses `PipingLineService` still cannot forge an approval.

### UI — `/projects/{code}/piping`

Register, both doors, the gate buttons, and per-line **blockers**: the screen says *why* a line
cannot pass (`Missing: ISOMETRIC, PID` / `Not approved: <doc no.>`), not merely that it has not.

Two UI defects found by driving the real screen with curl after the service tests were green:

1. `UnknownTokenValueException` escaped as a **500**. The engine was right to refuse; the
   controller was wrong to crash. An operator choosing a service the project has not set up is
   making a correctable mistake and is now told which value and which token.
2. The form offered **free text** for vocabulary-controlled tokens. Now they are `<select>`s built
   from the live register, and a token the pattern needs but the project has not populated renders
   as a disabled "not set up for this project yet" notice rather than a box that can only fail.

Note `back()` is wrong for the error path — a form POST may carry no referer, and the redirect to
`/` burns the flashed error. Redirect to the register explicitly.

### Tests

| Suite | Tests | Notes |
|---|---|---|
| `LineNumberingTest` | 24 | pure unit, no DB, ~1 s |
| `PipingLineTest` | 31 | service + RLS, real DB |
| `PipingLineHttpTest` | 6 | the screen, authorization, the two defects above |

**13 mutants killed, 0 survivors** (after fixing the two the first pass exposed): sort order,
`preg_quote`, lazy relaxation, padding, compose validation, regex anchors, both halves of the
approval predicate, the missing-evidence check, import sequencing, counter scope, revert clearing,
participation check.


## 5s. The numbering editor and evidence attachment (ADR-006 phase 1b) — BUILT

§5r left phase 1 *correct but not operable*: the number format could only be changed from tinker,
and evidence could only be attached programmatically. Both are now real screens.

### `/projects/{code}/piping/config` — the numbering editor

Owner-company only. Edits the token list, the separator, and the three project registers
(services, coatings, insulation types). Two blank token rows are always rendered, so adding a
token needs no JavaScript.

**Why this screen needed guard rails more than most.** A number format is the one setting where a
plausible-looking mistake does not fail on save — it fails weeks later, on the hundredth line.
Four rules, each verified rather than assumed:

| Rule | What happens without it |
|---|---|
| Pattern must contain a counter | **Probed the engine first:** composing a counter-less pattern twice with identical inputs returns the identical string, so the second `create()` dies on the uniqueness check with *"number already taken"* — a message naming the symptom and hiding the cause. The project silently caps at one line per subsystem. |
| Counter width may grow, never shrink below what was issued | Padding is render-time, so narrowing does **not** corrupt stored data — which is exactly why it is dangerous. Nothing breaks until the project crosses the threshold, then line 100 renders beside `001` and a text sort interleaves them. |
| Register values may not contain the separator | Every number built from the value becomes unparseable: the regex cannot tell the operator's dash from a structural one. |
| A value used in an existing line number cannot be removed | Removing it renumbers nothing; it just makes those numbers unrecognisable to `parse()`. Paired with a test proving an **unused** value can still be removed, so the rule does not freeze the register solid. |

Well-formedness (one counter maximum, no duplicate token names, non-empty separator) is
**delegated to `NumberPattern`'s constructor** rather than reimplemented, so the two cannot drift.
The service only translates those into a `ValidationException` a form can render.

**Trap:** `AmbiguousPatternException` and `InvalidPatternException` both extend `RuntimeException`
directly and are `final` — neither is a subclass of the other. Catching only `InvalidPatternException`
let the two-counter case escape as a 500. Catch both.

### Evidence attachment, and the gate that could not be escaped

The register now has a document picker per draft line, showing each linked document with its CDE
state and suitability, `✓` or `•` by whether it actually passes.

**Driving the screen found a real defect.** Attaching is *not* exclusive — several documents can
be linked under the same kind, and `unapprovedEvidence()` checks **all** of them. So a B-coded
drawing attached by mistake locked the line out of AFC **permanently**, with no way back except a
database edit. Attaching the correct A-coded isometric afterwards did not help: the bad link was
still evidence.

`detachEvidence()` fixes it, with two constraints:

- **Draft lines only.** Detaching evidence from a confirmed line would erase the justification for
  a decision already taken. Revert to draft first — that path demands a reason and is logged.
- **The link must belong to the line.** Caught by **mutation M21**, which survived the first pass:
  nothing stopped a hand-edited URL pairing line A with line B's evidence link. Both rows sit in
  the same project, so **RLS is perfectly happy** — the ownership check is the only thing between a
  curious URL and quietly stripping evidence off a line the user never opened.

A strict gate must still be escapable by *correcting the input*. That is the difference between a
gate and a trap.

### Confirmed end-to-end through the UI

Attach B-coded ISO + approved P&ID → gate refuses, naming the document and explaining that a B
code is *approved with comments* → detach the B-coded one → attach the A-coded one → **AFC
confirmed**.

### Tests

`PipingConfigTest` 15 · `PipingLineHttpTest` 10 (was 6) · `PipingLineTest` 32 (was 31, +2 archive
guards, −1 duplicate I had added twice).

**Mutants 14–22, all caught** after M21 was fixed: counter-less pattern, narrowing guard,
owner-only, separator-in-value, in-use removal, archive guard, detach-on-confirmed,
detach-cross-line, and `data-tt` on the register table.

### `data-tt-exempt`

`TableToolsTest` enforces that every view with a `<thead>` opts into filter/sort. The config
screen's token grid legitimately must not: **the row order *is* the data** (it is the order tokens
appear in the number), so sorting would corrupt what is being edited and filtering would hide rows
about to be submitted as a set. Rather than weaken the guard, the view declares `data-tt-exempt`
and says why. Verified the guard still bites: removing `data-tt` from the real register table
still fails the test, and there is exactly **one** exemption in the codebase.



## 5t. Units of measure (ADR-014) — BUILT

**User request:** «قابلیت تغییر واحدها مثل فشار، دما و غیره رو ایجاد کن».

### What it does

An engineer types `285 psi`; the owner reads `1965.01 kPa (285 psi)`; a contractor who prefers barg
reads `19.65 barg (285 psi)`. **Nothing in the database changed between those two readings.**

### The storage shape — copy this for every future physical quantity

Three columns per value, not one:

| Column | Example | Role |
|---|---|---|
| `design_pressure` | `285` | **source of truth** — what the datasheet says |
| `design_pressure_unit` | `psi` | **source of truth** — a number without this is not a pressure |
| `design_pressure_kpa` | `1965.005828` | derived mirror, for `WHERE`/`ORDER BY`/indexes |

Storing only the canonical loses the engineer's number: 285 psi round-trips to **285.0006** at
`decimal(12,4)`, so a 150#/300# rating lookup keyed on "285" or "740" misses. Storing only the
entered pair makes "every line above 10 barg" a full scan with a unit-dependent predicate — which
ADR-012 already measured as 0.12 ms vs 1690 ms under RLS.

**A value with no unit gets no canonical.** Guessing is how a psi number lands in a barg column.

### Four rules the engine enforces

1. **Temperature is affine.** `canonical = value × factor + offset`. Multiply-only is wrong by 32
   degrees everywhere (it maps 0 °C to 0 °F). One code path: offset defaults to 0 and collapses.
2. **A difference is not a temperature.** A 50 °C rise is 90 °F, not 122. `convertDifference()`
   uses the factor alone. Nothing calls it yet — it exists so the first delta field finds it.
3. **Gauge never converts to absolute.** They differ by *site* atmospheric pressure, not a
   constant. The converter refuses and the message names atmospheric pressure, so the reader knows
   it is a missing input rather than a missing feature.
4. **Absolute zero is checked on the CONVERTED value**, so −500 °F (−295.6 °C) is caught while
   −400 °F (−240 °C) passes. Enforced in the service *and* as a `CHECK`, so an import path that
   skips the service still cannot write impossible data.

### The menu is narrower than the engine (user decision, 2026-09-17)

Choosable: **pressure → `bar` or `psi`; temperature → `C` or `F`.** Nothing else.

Everything else is retired with `"selectable": false` in the pack — **not deleted**. The engine
still converts kPa, kg/cm², barg, K and the rest, forever, because values already stored and
values arriving from imports must stay readable. Deleting a unit turns historic data into an
unreadable number; hiding it does not.

**Consequence worth remembering: canonical ≠ default display.** Pressure is stored in kPa and read
in bar. Falling back to the canonical unit would show an unconfigured project a unit that is not in
its own dropdown, so the pack carries `default_display` per quantity and `UnitRegistry` has
`defaultDisplayUnit()` separate from `canonicalUnit()`. If you add a quantity, set both.

A refused choice says "kPa is not one of the units this project uses — choose one of: bar, psi",
never "unknown unit", which would be false.

`validate_packs.py` asserts the agreed menu, that every quantity offers at least one unit, and that
`default_display` is itself selectable. Widening the menu is an edit to that file, not a drift.

### Preference resolution

```
user override  →  project default  →  pack canonical
```

`users.unit_preferences` is **nullable**; `project_piping_config.unit_preferences` defaults to `{}`.
**Null ≠ `{}`.** Null on a user means "inherit"; `{}` means "I have chosen". Collapsing them would
freeze a user's display at whatever the project default was the day they first opened the screen.

Project defaults are employer-only and blocked on an archived project. A *personal* preference is
neither gated — it changes no stored value.

### Gotchas found while building this

- **`unitsFor()` returns whole unit definitions, not codes.** Passing them to a `string $code`
  callback is a 500 on page render, not a type error at compile time.
- **`$user->fresh()` returns null after updating a preference.** `users` carries RLS and a bare
  re-read outside a tenant scope sees nothing. `setUserPreferences()` returns the updated model;
  use it. Same trap as the one already recorded for `fresh()` outside `runAs()`.
- **`decimal(16,6)` rounds the mirror.** 150 psi stores as `1034.213594`, not `1034.2135935`.
  Comparing an in-memory value against a stored one fails on the last digit — compare a stored
  read against a stored read. **Reports must print the entered value**, never the mirror.
- **RELEASED has a `CHECK` demanding AFC provenance.** A test that forces `release_state` directly
  must supply `afc_confirmed_at`/`_by` too.
- **The auth proxy on 8099 does not forward POST bodies.** Test write paths directly against 8000
  with a real login, or the CSRF failure looks like a token bug.

### Pack validation

`standards-packs/reference/schema/units.schema.json` + `check_units()` in `validate_packs.py`.
Beyond shape, it verifies: the canonical unit exists and has factor exactly 1.0; no alias collides
with another unit's code; offsets appear only on temperature and datums only on pressure; the six
quantity keys the PHP names still exist; and anchor conversions (285 psi, 1 bar, 1 kg/cm², 1 in,
1 ft, 1 lb, 100 °F, 0 K). **Verified by breaking the pack six ways — all six were caught.**

### Files

`app/Support/Units/{Quantity,UnitRegistry,Measurement}.php` · `app/Services/UnitPreferenceService.php`
· `PipingLineService::{canonicalValues,updateDesign}` · migration `2026_09_15_160000_…` ·
`resources/views/piping/index.blade.php` · `lang/{en,fa}/epcc.php` under `units.*` ·
`tests/Feature/{UnitRegistryTest,UnitPreferenceTest}.php` (63 + 37).

**Mutation testing: 43 mutants on the units layer, 0 survivors.** Two survived first pass: M28 (the
cross-quantity check was masked by the datum check — only PRESSURE has non-NONE datums, so
isolating it needed `mm→kg` and `barg→bara`) and M38 (`updateDesign` had no archive-gate test).



## 5u. Components and ports (ADR-006 phase 2) — BUILT

**The user's question this answers:** «چطور سیستم تشخیص بده که لوله دو port و Tee سه port داره؟»

**It never asks.** The port count, the port roles and the default end types all come from the
component template in the reference pack. `ComponentService::place()` takes a template code and
creates the ports itself; there is no parameter anywhere that accepts a port count, and
`test_the_service_exposes_no_way_to_set_a_port_count` exists to keep it that way.

### The gesture

Pick a component type, pick an open port to attach it to. Everything else is derived:

| Derived | From |
|---|---|
| number and roles of ports | the template |
| the connecting port's size | **inherited from the parent port** — never typed |
| the other ports' sizes | the template's `sizeRule` |
| which end is "branch" / "small" | the port ROLE, not its number |

Size inheritance is what makes *"6″ pipe onto a 2″ branch"* structurally impossible rather than
merely discouraged. An explicit `nominal_size` passed to `attach()` is **discarded**, and a test
asserts the discard.

### Schema

`components` (per line, `UNIQUE(piping_line_id, sequence)`) and **one** `ports` table with an
`owner_kind` discriminator. The nozzle side lands at phase 3b; the column and its CHECK exist now
so that arrival is an INSERT, not a migration. ADR-010 §3.2 rejected sibling tables because every
joint query would then need a two-branch COALESCE.

CHECKs worth knowing: `ports_exactly_one_owner` (a row cannot claim both a component and a nozzle),
`components_size_pair_complete` (NPS string and decimal travel together or not at all — a decimal
without the string cannot be displayed, a string without the decimal cannot be compared, and
dia-inch needs the comparison), `components_length_not_negative`.

`is_connected` is denormalised **on purpose**: "every open end on this line" is the single
most-used query in the system (ADR-009 §2.3) and drives the worklist, the progress metric and the
import validator.

### Rules enforced

- branch ≤ run on a TEE/OLET (`BRANCH_LE_RUN`)
- a REDUCER's two ends must differ — a 6×6 "reducer" is a spool nobody ordered
- a per-port override on a `SAME_AS_FIRST` component (a "6×4 valve") is **ignored**, not honoured
- a length on a component whose template has none is dropped
- NOZZLE is in the pack but **not placeable on a line** — it belongs to equipment
- an unrecognised size is refused, never silently nulled
- RELEASED lines and ARCHIVED projects refuse modelling

### Gotchas found while building this

- **There is no `epcc_can_read_project()` helper.** I invented it from memory and the migration
  failed on `CREATE POLICY`. The real read rule is spelled out inline from `current_setting` —
  copy it from `create_piping_lines`, do not reconstruct it.
- **`->sortBy([fn, fn])` silently does nothing useful.** The array form of `sortBy` expects
  *property/comparison pairs*, not a list of key closures. It presented as P2 listed before P1 in
  the open-ends panel. Use one closure returning a composite sort key.
- **Form vocabulary must map to port ROLES, not port numbers.** The branch is port 3 on a TEE but
  port 2 on an OLET, and a REDUCER has no branch at all. The controller resolves the role to a seq
  from the template; a positional guess would put a branch size on a reducer's large port.

### Two display bugs the screenshot caught that no test would have

Both were found by *looking* at the rendered page, not by running the suite — worth remembering
when a feature is "done and green".

1. **Open and connected ports were near-identical pills.** Both used `var(--line)` borders on a
   dark background. Colour alone also fails a colour-blind reader. Fixed with a dashed amber
   border and a `○` / `●` glyph, plus a legend next to the heading, so the state is readable
   without relying on hue.
2. **RTL reordered the inch mark.** `P2 RUN 6"` rendered in Farsi as `"P2 RUN 6` — the quote
   floated to the visual start of the line. Latin-plus-digit tokens inside an RTL paragraph need
   `<bdi>`; inside an `<option>`, where markup is not allowed, they need a trailing **U+200E LRM**
   instead. Six places on this page were affected. **Check every mixed-script token in FA, not
   just the prose.**

Screenshots of both languages: `docs/screens/model-page-{en,fa}.png`.

### Files

`app/Models/{Component,Port}.php` · `app/Services/ComponentService.php` ·
`app/Http/Controllers/ComponentController.php` · `resources/views/piping/model.blade.php` ·
migration `2026_09_17_120000_create_components_and_ports.php` · routes `piping.model*` ·
`lang/{en,fa}/epcc.php` under `component.*` · `tests/Feature/ComponentModellingTest.php` (39).

Reachable from the line register: each row has a **Model** button.

**Mutation testing: 13 mutants, 0 survivors** (M44–M56).


## 5v. Joints, compatibility and dia-inch (ADR-006 phase 3) — BUILT

The model stops being a parts list here. Every connection is now a row with a number, a state and
a measurable size — the entity Plant 3D does not have, because there a connection is geometry and
here it is a construction object.

### The table

`joints`: uuid pk, `project_id`/`company_id`/**`piping_line_id`** (denormalised — a joint never
moves between lines, and every read is line-scoped), `port_a_id`/`port_b_id`, nullable
`joint_seq`, `numbering_policy`, `joint_type`, `state`, `fitted_by_code`+`fitted_at`, frozen
`nominal_size`/`nominal_size_decimal`/`schedule`/`thickness_mm`, `shop_field`, `spool_id`,
`isometric_sheet_no`, `compatibility_state`+`compatibility_notes jsonb`, `source`, `external_ref`.

Constraints that carry weight:

- **`CHECK (port_a_id < port_b_id)`** plus `UNIQUE(port_a_id, port_b_id)`. Without the CHECK,
  (A,B) and (B,A) both satisfy the UNIQUE and the same weld is counted twice in dia-inch.
  `JointService::orderedPair()` sorts before every write so no caller can get it wrong.
- **Two separate unique indexes on `port_a_id` and on `port_b_id`.** The pair-unique does not give
  this: (A,B) and (A,C) are distinct pairs, so without them port A could be welded to two things.
- `joints_unnumbered_policy_has_no_seq` — a NOT_APPLICABLE joint must not be carrying a number.
  Switching a weld to a flanged connection otherwise leaves the old number on reports forever.
- `joints_fitted_needs_evidence` — FITTED demands **both** `fitted_by_code` and `fitted_at`, the
  same shape as the AFC gate. Who-but-not-when is not auditable.
- Partial unique `joints_line_seq_unique WHERE joint_seq IS NOT NULL`, partial index
  `joints_awaiting_number_idx` for the chase-list.

### Three rules worth not re-deriving

**Dia-inch: the smaller diameter governs, and it is never stored.** `min(portA, portB)`, computed
every time. A stored copy drifts from the ports and the error does not crash — it inflates
progress, which on a job paid by dia-inch is an invoice. Only an approved progress snapshot
freezes it. Flanged joints contribute **nothing**: bolt-up is real work but it is not welding.

**Frozen vs computed is a deliberate split.** `nominal_size`/`schedule`/`thickness_mm` are frozen
onto the joint at creation — a joint accepted at 6"/SCH40 must keep saying so even if a port is
later edited. Dia-inch is the opposite. The distinction: those columns record what was **built**,
dia-inch **measures** it.

**A number nobody uses is a lie that costs.** Welded joints get a per-line sequence from a shared
counter (welded + threaded), allocated under `lockForUpdate()` on the line row. Flanged joints get
`numbering_policy = NOT_APPLICABLE` and **no** number — they are not welds awaiting one. Next
number is **`max()+1`, never `count()+1`**: joints get deleted during modelling and a reissued
number means two different welds on two drawings.

### PortCompatibility — a pure function with three outcomes

`app/Support/Piping/{PortCompatibility,Verdict}.php`. No DB, no locale — the verdict carries
reason **codes**, and translation happens in `Verdict::messages()`, so tests compare verdicts
without string-matching a language file.

| Verdict | Example |
|---|---|
| INVALID | BW onto FL · size mismatch · unknown end code · port joined to itself |
| WARNING | schedule break at equal bore · SW/SW gender unverified |
| VALID | BW/BW equal bore · **PL onto BW** (a field-cut pipe) · anything against `**` |

The schedule check compares the **resolved wall from the reference pack**, not the schedule name.
STD vs SCH 40 at 6" is the same wall and must not nag; at 12" it is 10.31 vs 9.53 and must. Two
tests pin both halves, which is what stops it being "optimised" into a string comparison later.

### What phase 3 fixed in phase 2

`ComponentService::remove()` can now reopen the counterpart port. It separates every joint
touching the component **first**, then deletes its ports — the other order cascades the joints
away and leaves the neighbour flagged connected with nothing attached, invisible to the open-ends
worklist. `markConnected()` is gone; `attach()` returns a `joint` key alongside the component.

### Files

`app/Models/Joint.php` · `app/Services/JointService.php` ·
`app/Support/Piping/{PortCompatibility,Verdict}.php` · migration
`2026_09_18_090000_create_joints.php` · `lang/{en,fa}/epcc.php` under `joint.*` ·
`resources/views/piping/model.blade.php` (joints table + metrics header) ·
`app/Http/Controllers/ComponentController.php` · `tests/Feature/JointTest.php` (**46**).

**Mutation testing: 23 mutants, 0 survivors** (M57–M78), harness restored at **`mutate.sh`**.

Screenshots: `docs/screens/joints-{en,fa}.png`.

### Two things the mutants and the demo caught

- **M64 found a missing test, not a missing guard.** The self-joint check existed; nothing
  asserted it. A PCF import with colliding END-POINT coordinates would have booked dia-inch for a
  weld that does not exist.
- **The demo script's first version was engineering-wrong, and the engine said so.** Welding a
  flanged valve straight onto a pipe was refused; the fixture needed a flange, exactly as site
  would. When a refusal looks like a bug, check the pipework before the code.


## 6. The preview iframe (read before debugging "login is broken")

The preview serves the app in an **iframe whose top-level document is a
different site**, so its cookies are third-party and the browser blocks them.
Symptom: login succeeds server-side, the redirect arrives anonymous, the form
re-renders blank, and **nothing is logged**.

**Five rounds of cookie-attribute tuning failed.** The browser's policy is not
observable from the server, so it cannot be satisfied — the dependency had to be
removed instead. Current mechanism (both positions load-bearing):

| Middleware | Position | Job |
|---|---|---|
| `PreviewSessionFallback` | **first** in `web`, before `EncryptCookies` | Converts a signed `sid` token from the URL/body into a genuine session cookie |
| `PreviewUrlRewriter` | **last** in `web` | Appends the token to links, forms, redirects, Livewire URIs and JSON `redirect` |

Traps, all paid for in debugging time:

- The forged cookie **must be encrypted and carry the `CookieValuePrefix` HMAC**,
  or `EncryptCookies` silently discards it.
- The injected script must observe **`childList` only** — watching attributes
  retriggers on its own writes: infinite loop, white page.
- **Exceptions bypass the whole middleware pipeline**, so `PreviewUrlRewriter::apply()`
  is also called from `$exceptions->respond()`.
- **`URL::forceScheme()` does not touch `$request->fullUrl()`** — `redirect()->back()`
  emitted `http://` and the browser blocked it as mixed content. Fixed by marking
  the connection secure in `ConfigureForPreviewProxy`.
- **`$middleware->replace()` does not touch group middleware** — it fails
  *silently*. Use the `*InGroup` variants.
- **Diagnose from the `sessions` table, not the log:**
  `select to_timestamp(last_activity)::time, count(*), count(user_id) from sessions group by 1 order by 1 desc;`
  Many rows with 0 authenticated per page load ⇒ the cookie is not coming back.
- **Test over `http://` with a preview `Host`.** Driving tests at `https://`
  makes the request secure by construction and silently disables the condition
  under test — one guard here was inert until a mutation test caught it.

The whole mechanism is gated on a preview host **and** `SESSION_URL_FALLBACK`
(default **off**). On a real deployment it is inert.

---

## 7. Testing discipline

```bash
cd app && php artisan test                          # 667 passed / 2751 assertions (~1050 s)
python3 standards-packs/validate_packs.py           # 3 standards + 4 reference packs
node app/tests/Browser/sidebar-toggle.test.cjs      # 17 assertions, needs a fixture
node app/tests/Browser/sidebar-animation.test.cjs   # 19 assertions, computed styles
node app/tests/Browser/table-tools.test.cjs         # 42-47 per page, needs /tmp/tt.html
#   fixtures: SB_FIXTURE= / TT_FIXTURE= override the default paths. Capture a
#   logged-in page first - these drive the REAL shipped script in jsdom.
```

The jsdom tests need `jsdom` at `/home/user` (wiped by every reset — **`restore.sh`
now reinstalls it**) and a captured logged-in page; override with `SB_FIXTURE=`
for the sidebar harnesses and `TT_FIXTURE=` for the table harness. **A missing
`jsdom` prints nothing at all**, which reads like "no tests to run" rather than
a failure — if a harness produces no output, check `node_modules` first.
The app's `package.json` sets `"type": "module"`, hence the `.cjs` extension.

- **A guard test that passes may be inert.** Mutate the fix away and watch it go
  red. **153 mutations have been confirmed caught** this way (123 + 15 pack-data + 15 PHP in phase 0); the practice has
  already exposed three false-confidence tests (the mixed-content guard, a
  sidebar entitlement check, and "the contractor cannot verify its own fix"),
  a guard made inert by its own documentation, and a test that encoded the
  wrong model entirely.
- **`display:inline-block` on a `<td>` silently breaks the whole table.** It
  replaces `display:table-cell`, so the cell leaves the column layout and every
  following column shifts by one - the header ends up over the wrong data. Cell
  COUNTS still match, the DOM still looks right, and jsdom cannot see it
  because jsdom has no layout engine. Diagnosed by measuring
  `getBoundingClientRect()` per cell in real headless Chromium. `.mono` (an
  LTR-run helper meant for `<span>`) had been applied to `<td>` on two screens.
- **Per-page markup drifts, and a one-fixture harness cannot see it.** The
  sidebar harnesses ran against a fixture from a SINGLE page, so three newer
  screens that nested the shell differently were invisible to 345 passing
  browser assertions. `ShellStructureTest` now asserts the nesting order
  (`.app` > `aside.sb` > `.wrap` > `.topbar`) on EVERY screen via a data
  provider. When a partial has a required position, test the position.
- **`@{{ $var }}` silently prints braces.** `@{{ }}` is Blade's escape syntax
  for JS templating. Written as a literal `@` separator it disabled the
  variable and shipped `ips.iran{{ $packVersion }}` to the page. A regex sweep
  for `{{ $` in rendered HTML now covers every screen.
- **A working endpoint is not a reachable feature.** The certificate PDF route
  passed every test while the download link sat inside an `isIssued()` branch,
  so a draft had no way to reach it. Found by loading the live page, not by the
  suite. When you add an endpoint, assert the UI actually links to it.
- **A guard test can be caught by the WRONG GUARD.** `expectException()` only
  proves *something* refused. The self-verification test passed with its guard
  deleted, because the acting company was an `EPC` — a tenant type that is not
  a verifier at all — so a different rule threw. Assert on the **message**, and
  choose fixture actors that can only fail the rule under test.
- **A test can encode the WRONG MODEL and still pass.** `the employer sees every
  letter on its project` asserted a count of 3 and was green — it was asserting
  that the employer could read another company's unsent draft. When a guard
  fails after you fix a defect, first ask whether the *test* was wrong.
- **A jsdom harness can encode one page's shape.** `table-tools.test.cjs` has
  now been fixed three times for this: it assumed the register's column layout,
  then a minimum row count, then a CDE-state column. Assert against what the
  markup *declares* (`data-sort`, `data-facet` counts) rather than magic
  numbers, and use `skip()` so a rule that does not apply to a page can never
  be mistaken for a rule that passed.
- **The oracle must use the same collation as the page.** The harness sorted
  with `<` and flagged correct Persian ordering as a bug: "گ" precedes "م" for
  a reader but follows it by code unit. Both sides now use `Intl.Collator`.
- `DatabaseTransactions` collides with the seeders — use `migrate:fresh --force`
  in `setUp()`.
- CSRF cannot be exercised through HTTP feature tests (`PreventRequestForgery`
  short-circuits on `runningUnitTests()`); call the handler directly.
- `assertRedirect()` is too strict once a query parameter is appended — assert on
  the `Location` header with `assertStringStartsWith`.

---

## 8. Known traps (non-obvious, will cost you an hour each)

- **A bidi-isolated NUMBER is not enough — isolate the whole phrase.** `<bdi>6</bdi> welds ·
  <bdi>2</bdi> fitted` renders in Farsi as **"۶ جوش ۲۰ مونتاژشده ۲۸۰"**: the `·` separator is a
  neutral character sitting between a digit run and a word, so the algorithm attaches it to the
  digits and it reads as part of the next number. The digits were right; the punctuation moved.
  Wrap `number + label` together in one `<bdi>`, and use a separator inside its own element.

- **A mutation run killed by a timeout leaves the mutant IN THE SOURCE.** The harness restores the
  file after each test run; kill it mid-run and the last edit survives. It then looks like working
  code — `if (false) {` sat in `JointService::join()` and the next partial run reported SKIP
  ("pattern did not apply") rather than a failure. After any interrupted run:
  `grep -rn "if (false)" app/app/` and check `git diff` before believing anything.

- **A SKIP from the mutation harness is not a pass.** It means the pattern never applied, so
  nothing was broken and the test suite proved nothing. Treat SKIP as loudly as SURVIVED — the
  harness exits non-zero on either.

- **An equivalent mutant is not a surviving test gap.** `min(null, 6.0)` is already `null` in PHP,
  so deleting the null guard in `diaInch()` changes no observable behaviour. Before writing a test
  to kill a survivor, check the mutation actually alters the result — otherwise you are adding a
  test that asserts the language spec. Retarget the mutant instead (`return 0.0`).

- **`LineReleaseState` is a class of string constants, not an enum.** `LineReleaseState::RELEASED->value`
  throws "Attempt to read property value on string". Use the constant directly.

- **Forcing `release_state = RELEASED` needs TWO provenance pairs, not one.** `afc_confirmed_at`
  + `afc_confirmed_by` **and** `released_at` + `released_by`. Supplying only the AFC pair fails
  with a 23514 that looks exactly like the guard you are trying to test firing.

- **A standalone bootstrap script needs its own `<?php` tag and absolute paths.** `php artisan
  tinker --execute="require '/tmp/x.php'"` printed the file instead of running it (no opening
  tag), and `__DIR__` inside a `/tmp` script resolves to `/tmp`, not the app.

- **Outside a tenant binding, `firstOrFail()` on an RLS table looks exactly like "the seeder never
  ran".** The rows are there; the role cannot see them. Use
  `TenantContext::forIdentityLookup(...)` to resolve the project and user, then `runAs(...)`.
  Confirm with `sudo -u postgres psql` before re-seeding — re-running the seeder hits
  `companies_code_unique` and buries the real cause.

- **When the engine refuses your demo data, check the pipework before the code.** Welding a
  flanged valve directly onto a pipe was refused by `PortCompatibility`; the model was wrong, not
  the rule. The fixture needed a flange, exactly as site would.

- **PCRE alternation is first-match, and backtracking usually hides it.**
  `A1|A1B` against `A1B-…` *works* — the engine tries `A1`, fails the next
  separator, retries `A1B`. So a naive test passes and you conclude ordering
  does not matter. It matters when the following token can absorb the
  difference: `PH-14|PH-14-SS` against `PH-14-SS-2201` matches both ways and
  keeps the **wrong** split, silently attributing a line to the wrong
  subsystem. Sort vocabularies longest-first. See §5r.
- **A test can pin a rule for the wrong reason.** The AFC gate needs
  `PUBLISHED` **and** an A-family suitability. The obvious negative fixture,
  SHARED+S2, fails the A-check on its own — so the `PUBLISHED` half was never
  actually exercised and deleting it left the suite green. Mutation testing
  found it; the fixture that separates the two conditions is **SHARED + A1**.
  When a rule is a conjunction, write one test per conjunct that satisfies
  every *other* conjunct. See §5r.
- **RLS `WITH CHECK` does not apply to DELETE.** A RESTRICTIVE policy with a
  `WITH CHECK` clause blocks INSERT and UPDATE and lets every DELETE straight
  through — there is no new row to check, so the clause is never evaluated.
  A read-only guard therefore needs a *second* policy `FOR DELETE USING (...)`.
  Found by trying it: an "archived" project's punch list deleted cleanly,
  `DELETE 9`. See §5q.
- **A blocked UPDATE/DELETE under RLS is SILENT.** INSERT raises 42501 naming
  the policy, but UPDATE and DELETE simply match zero rows: no error,
  `rowCount = 0`, and Eloquent's `save()` returns **true**. The data is safe and
  the user is told the opposite. Never let RLS be the only thing refusing a
  write that a human is waiting on — refuse in a service first.
- **A `SECURITY DEFINER` helper is mandatory when a policy reads another
  RLS-protected table.** `epcc_project_is_writable()` reads `projects`; without
  `SECURITY DEFINER` it sees only the currently scoped project, returns NULL for
  every other one, and the policy **fails open** — the most dangerous direction.
- **`__()` was only localised inside the Filament panel.** `SetUserLocale` was
  registered on the admin panel alone, so on every other route the translator
  used the default locale. The hand-written views hid this for months because
  they take language from their own `?lang=` closure — the page was Persian and
  only the `__()` strings were English. It surfaced the moment a *service* threw
  a translated exception. Now registered on the `web` group too, before
  `PreviewUrlRewriter` (which must stay last).

- **`pg_dump` exits 0 after an RLS abort.** Under `FORCE ROW LEVEL SECURITY` the
  dump fails part-way and still returns success, leaving a truncated file. Never
  trust the exit code — compare the output size or the row counts against a
  manifest. See §5p.
- **A topological sort over the schema drops any FK cycle.** `documents` and
  `document_revisions` reference each other, so a recursive dependency ordering
  returns 21 of 25 tables and loses the whole document register without error.
  Order such lists explicitly, and load with
  `SET session_replication_role = replica`.
- **`x IS NULL OR col = x` in an RLS policy disables every index on `col`.** It reads
  like a harmless convenience ("unbound means all") and it silently forces a
  sequential scan forever. At fixture scale a seq scan is the *correct* plan, so
  this is invisible until a million rows exist. Check `Rows Removed by Filter`
  in `EXPLAIN (ANALYZE)` — if it is orders of magnitude larger than the rows
  returned, the predicate is not sargable. See §5o.
- **Benchmark a security policy and you will select the insecure version.** The
  fastest form of the tenant policy is the one that returns zero rows for the
  cross-project view. Every correctness probe must be run alongside the timing,
  in the same script, or the fast-and-wrong answer wins.
- **The backup was archiving itself.** `tar -czf source-backup.tar.gz .` from the
  repo root includes the *previous* `source-backup.tar.gz`, so each refresh
  nests the last one and the file roughly doubles. It also swept in republished
  Filament assets (`app/public/{js,css}/filament`, ~3 MB) and compiled Blade
  views, all of which `restore.sh` regenerates. Exclude the tarball itself, the
  published assets, `storage/framework/views` and `storage/framework/testing`;
  then **diff the entry list against the old one** — a pure size comparison
  cannot tell "captured more source" from "captured more garbage". Current:
  **3,469,495 B / 418 entries**, extraction verified in `/tmp` before replacing
  the old file.
- **Numeric string keys are integers in PHP.** `json_decode(..., true)` turns
  `"40"` into array key `40`, so `array_keys()` on a schedule table returns a
  mixed `int|string` list. Strict `in_array()` then fails silently and passing a
  key to a `string`-typed parameter is a `TypeError`. Cast at the boundary with
  `array_map(strval(...), …)`.
- **An assertion about data only tests the direction the data exercises.**
  `PipeReferenceTest` asserted STD ≠ SCH 40 at 12″+, which felt like complete
  coverage of the alias question. It was not: a fallback from a missing schedule
  to its "equivalent" survived, because STD is never the missing one. The
  reachable case is the reverse — ASME defines STD at 22″/26″ but no SCH 40
  there at all. Only mutation testing found it.
- **Anything that reaches the internet is invisible in a connected sandbox.**
  A URL assembled at runtime (Filament's avatar provider) exists in no file, so
  grep and any static scan report a clean build. Capture a browser net-log, or
  assert on rendered HTML. `php artisan verify:airgap` is the standing check.
- **Render at a phone width before believing a page works.** A page can be
  200, structurally perfect and still paint nothing; in RTL an overflow does
  not clip, it evicts the document from the viewport. Measuring unique colours
  in a screenshot is a cheap, reliable blank-page detector (1 colour = blank).
- **A document that asserts something about a moment must snapshot it.** Any
  number a certificate, transmittal or approval renders from a live query will
  drift after the fact, and the drift is not random — it flatters, because the
  work continues in the direction the document was hoping for. Ask of every
  figure on an issued artefact: *is this a fact about then, or a report about
  now?* (§5e). Test it by producing the artefact twice with a real change in
  between; a single render can never show this.
- **`pkill -f "artisan serve"` kills the calling shell.** Use the process tools.
- **`artisan serve` caches `bootstrap/app.php`** — restart it; `config:clear` is
  not enough.
- **Sandbox resets wipe the runtime, not the source** (7× so far). Snapshots cap
  at ~10,000 files / 128 MB and truncate *silently*, which is why
  `app/vendor` is a symlink into `~/.cache/epcc-vendor`.
- **Seeded attachments will blow the file cap if nothing purges them.** Every
  `migrate:fresh --seed` mints new project UUIDs and writes real blobs under
  `storage/app/private/documents/{project_id}/{ab}/{cd}/`. Nothing removed the
  previous run's tree, so it reached **5,066 project folders / 12,963 files**
  and pushed the workspace to 13,263 — past the cap, which truncates in
  silence. The content-addressed fan-out makes this a file-**count** problem
  (~31,000 directories) long before it is a disk-space one (58 MB).
  `tests/bootstrap.php` now purges that tree **before and after** every suite,
  and `BlobIntegrityTest` re-verifies each surviving blob against its SHA-256
  so the purge can never quietly delete live data.
  **Check `find /home/user -type f -not -path "*/vendor/*" -not -path "*/.cache/*" | wc -l`
  when anything writes files in a loop.** It should be in the low hundreds.
- **Never pin `APP_URL` to a sandbox host** — it is stale after the next reset.
  It stays `http://localhost:8000`.
- **The pack ID is `ips.iran`, but it is addressed by basename `ips-iran`** in
  the database and forms.
- **`WithoutModelEvents` in a seeder breaks `HasUuid`.**
- **The `?as=` tenant-switch parameter is deliberately dead.** Do not reflect
  arbitrary query parameters into links — `ViewServiceProvider::CARRIED_QUERY`
  is an allowlist for exactly this reason.

---

## 9. Suggested next steps

**ADR-006 phase 1 — BUILT (2026-09-15).** `piping_lines`, the per-project numbering engine and
the AFC gate, with a working register screen at `/projects/{code}/piping`. See **§5r** for the
engineering content, the B-code trap and the 13 killed mutants. **Next is phase 2: ports and
components** (`components` → `component_ports`), then 2b the manual modelling UI, then 3 joints
with `diaInch()`.

**Phase 1b closed all three items phase 1 left open** (see §5s): the numbering editor exists, the
register has a document picker with detach, and `assertWritable()` turned out to be wired into all
six piping write paths already — it just had no test, which it now does (two, in `PipingLineTest`).

Left open by phase 1b, in the order I would take them:
1. **The pattern editor cannot reorder tokens.** Rows are edited in place and blank rows append;
   changing `{size}-{service}` to `{service}-{size}` means retyping both. Drag-and-drop needs
   JavaScript, but up/down buttons would not. Minor, and invisible until someone tries it.
2. **No preview of the next number while editing.** The screen shows the *current* sketch; it
   cannot show what the number would look like under the pattern being typed without a round trip.
3. **`insulation_types` is editable but unused** — no token reads it yet. Harmless, but it is the
   kind of half-wired field that later gets mistaken for dead code and deleted.

**ADR-007 (`docs/ADR-007-…md`) — ACCEPTED AND BUILT (2026-09-15, phase 0).** The engineering
reference packs: 36 sizes, 355 schedule pairs, 13 end types, 20 component templates, behind
`PipeReference`. See §5m for the traps. **This is phase 0 of the consolidated build order in
ADR-010 §5.**

ADR-006 is now **PARTLY BUILT** (phases 1, 1b, 2 and 3 of 7). ADRs 008, 009 and 010 remain PROPOSED (analysis only). Three questions at the end of
ADR-010 are still unanswered by the user: equipment tag uniqueness (per project vs per
facility — I lean per facility), whether an instrument register is needed now, and whether
equipment tags are imported-and-validated rather than allocated.

**ADR-004 (`docs/ADR-004-…md`) — ACCEPTED AND BUILT (2026-09-13).** `project → facility →
(optional) area → subsystem`. Investigated rather than assumed: Plant 3D has **no
System/Subsystem, no MC, no punch, no handover** — it is a design authoring tool whose
"hierarchy" is a fixed 3-node folder tree plus `Area`/`Unit` as **string tag subparts** in a
SQLite `.dcf` PnPDatabase, so compatibility means a documented **import mapping**, not a copied
schema. Built Option B: one `facilities` table with a `FacilityKind` discriminator, an
**optional** `areas` level (= Plant 3D Area = AWP **CWA**), per-kind addressing in validated
`jsonb`. `subsystem_id` did **not** move — all 6 dependent tables untouched. The four open
questions are now answered in ADR §5; the load-bearing decisions:

- Numbering is **per facility** — `UNIQUE(project_id, code)` became `UNIQUE(facility_id, code)`,
  so `PL-01/SS-01` and `PL-02/SS-01` are both legal. The admin register therefore shows Facility
  as a non-toggleable column: the bare code alone is now ambiguous.
- `subsystems_area_within_facility`, a **composite** FK `(area_id, facility_id) → areas
  (id, facility_id)`, makes "area belongs to another facility" unrepresentable rather than
  merely discouraged.
- `PIPELINE` is in `FacilityKind::all()` (importable) but excluded from `selectable()` — KP
  stationing is deferred, and the `addressing` jsonb means adding it is data, not a migration.
- `fullCode()` composes `"{facility} / {code}"` at read time; storing it would drift on rename.

Six mutations (M118–M123) confirm the new rules. **M118 first reported SURVIVED and that was
the mutation's fault, not the test's** — dropping a constraint with `psql` proves nothing when
`setUp()` runs `migrate:fresh` and rebuilds it. Mutate the **migration source**.

**ADR-006 (`docs/ADR-006-Piping-Line-Component-Joint-Model.md`) — PROPOSED, all ten questions answered (§10, §11). Ready to build; phase 0 is ADR-007.**
The user corrected a category error in ADR-005: isometrics and support drawings are not things
to *file*, the system needs **their content**. Model: `subsystem → piping_lines → components →
component_ports → joints`, with the port model adopted from Plant 3D's `PartPort` /
`P3dPartConnection`. Key findings: Plant 3D stores a connection as a **relationship row**
(part1/port1/part2/port2) and has **no joint number, no weld, no NDT** — the joint is *geometry*
there and a *construction object* here. **PCF is the import path**: ports are explicit
(`END-POINT x y z bore ENDTYPE`), a TEE really does emit 3 keypoints, and **welds are already
first-class** with `CATEGORY FABRICATION|ERECTION` = shop/field. Line numbering is
**per-project configurable** (the PMS is the authority, not the standards pack) and must
**parse as well as compose**, so the pattern is stored as typed tokens, not a `{}` string.
Compatibility returns **three** verdicts (VALID/WARNING/INVALID) — a boolean would make every
spec break either a lie or a blocker, the same reasoning as advisory inspection.

**The answers changed the shape.** Joint numbering is **per line and conditional**: welded and
threaded joints need a number, flanged ones do not. That makes `joint_number` nullable for *two
different reasons*, which must not be conflated — so `numbering_policy` (REQUIRED |
NOT_APPLICABLE) is stored, guarded by a **partial unique index** plus a CHECK. Spiked on this
PostgreSQL first (no partial-unique precedent existed in the schema): unnumbered flanges and
unnumbered imported welds coexist, duplicates rejected, a *numbered* flange rejected. Import
**must not invent numbers** — an unnumbered welded joint is a valid row and needs its own
worklist. Joints gained a state machine (**MODELLED → FITTED**, `fitted_by_code` + `fitted_at`
mandatory on entry); **`fitted_by_code` is a string, not a `users` FK** — verified there is no
personnel table and `users` holds office accounts, so a pipe fitter has no login and must not be
forced into the identity RLS policies. Supports are **part of the line but their own register**
(no ports, so no fake component rows). Spools are an **optional grouping** (nullable `spool_id`),
not a level — same reasoning as optional `areas` in ADR-004. `isometric_sheets` was **deleted**:
components derive from the **MTO** (`mto_item_no`, the missing link to
`material_requisition_items`), and the sheet is just a string on components and joints; pipes
carry `length_mm` under a type-conditional CHECK.

**Second round resolved the rest.** Joint number is a **simple integer sequence per line**,
welded and threaded sharing one counter, allocated under `lockForUpdate()` on the parent line —
stored as an int, padded only in the view (storing `"007"` turns sorting and next-number into
string surgery). **Flanged joints stay unnumbered** (my recommendation, accepted): a number
nobody uses gets quoted in reports and then has to be broken when the bolt-up register arrives
with its own scheme; they lose nothing meanwhile, since they are still rows, still
compatibility-checked. Supports get the **same typed-token numbering engine** as lines, fed by
the Standard Support / Support Details / Support List documents.

**ADR-007 (`docs/ADR-007-Reference-Data-Engineering-Standards.md`) — PROPOSED, new.** The user
asked where universal constants live «که به دیتابیس فشار وارد نکند». Answer: **versioned JSON
reference packs** beside `standards-packs/`, read-only, **no RLS** — and the reasoning matters:
RLS on reference data could only ever be "everyone can read", a policy that fails open, whereas
the real guarantee needed is *nobody can write, including the owner*. A file gives that
absolutely. It also dodges the ADR-004 trap where a seeder cannot read an RLS-forced table.
Split: **universal** (NPS/DN/OD, schedules, end types, flange ratings) → reference packs;
**per-project** (piping classes from the PMS, services, coatings) → normal tenant tables.

**ADR-010 (`docs/ADR-010-Equipment-Nozzles-and-Dia-Inch.md`) — PROPOSED, new.** Closes ADR-009's
three questions.

- **Dia-inch: the smaller diameter governs.** `min(portA, portB)`. Measured on a 5-weld sample:
  **19.0 vs 27.0** under the other reading — a **42% overstatement**, and on a job progressed or
  paid by dia-inch that is a commercial number. Computed, **never stored on the joint** (it would
  drift from the port sizes), *except* frozen into an approved progress snapshot. The test must
  contain **reducing** welds — an equal-bore fixture passes under either convention and proves
  nothing, the same trap as testing schedules only at 2".
- **Component templates get two tiers.** Universal set (PIPE/ELBOW/TEE/…/NOZZLE) ships read-only
  in the reference pack — a tee has three ports everywhere and that is not a project opinion.
  Projects may add their own (Victaulic coupling, vendor connector) but **may not shadow a
  universal code**; same closed-vocabulary / configurable-subset split as `FacilityKind::all()`
  vs `selectable()`.
- **Equipment: a nozzle is a port owner, so `joints` needs NO change.** Verified with a spike —
  one `ports` table, `owner_kind ∈ {COMPONENT, NOZZLE}`, a CHECK enforcing exactly one owner FK.
  A COMPONENT↔NOZZLE joint inserted with the joints table untouched; a mixed-owner row rejected
  by `check_violation`. Mirrors Plant 3D, where `AssetOwnership` links equipment to nozzles and a
  nozzle is a `NozzleSubPart`, not a line component. **Rejected** the alternative (separate
  `nozzle_ports` + `port_a_kind`/`port_b_kind` on joints) because every joint query would become
  a two-branch UNION — the same smell that argued for one `facilities` table in ADR-004.
- `equipment.subsystem_id` means equipment **joins the existing completion machinery for free**:
  punch, MC and material status are already keyed to subsystems. Biggest immediate win: a 6" 300#
  RF nozzle against a 6" 150# RF flange becomes a **hard INVALID at entry**, instead of being
  discovered at bolt-up with the crane hired.
- Equipment lands at **phase 3b**, not phase 1 — lines keep free-text endpoints until then and
  upgrade to nozzle FKs without migrating a single joint.

**ADR-009 (`docs/ADR-009-Manual-Modelling-and-Component-Templates.md`) — PROPOSED, new.**
Answers "how does manual entry work, and is the model PCF or Plant 3D?".

- **The user never types a port count.** Ports are a property of the component *class*, declared
  once in a **component template** shipped as reference data — verified that this is exactly how
  Plant 3D works: the catalog template holds properties "shared by all sizes", and
  `PLANTPARTCONVERT` is the command that *adds ports* to a custom block before it can be a
  piping component. Chain maps 1:1 — Plant 3D catalog → our `component_templates`; spec →
  `piping_classes`; placed part → `components`.
- **Manual flow is "pick a type, pick an open port".** Simulated end-to-end: adding a 6"×6"×2"
  TEE materialises 3 ports with roles RUN/RUN/BRANCH; attaching a PIPE to P3 inherits the 2"
  size and creates the joint implicitly. Port count, sizes, schedule, end type, wall thickness
  and the expected branch component are all **derived**. **The "open ends" list is the whole UI**
  — same query drives the worklist, progress and the import validator.
- **Structural refusals vs engineering warnings**: attaching to a taken port or typing a
  mismatched size is *impossible*; a spec-break schedule change or a branch component that
  differs from the class table is a *warning/deviation*. Same split as advisory inspection.
- **PCF vs Plant 3D is a false choice — they are different layers.** Conceptual model from
  **Plant 3D** (ports belong to a class; a connection is a relationship row). Import format
  **PCF, primary** — text, vendor-neutral, emitted by CADWorx/AVEVA/SmartPlant too, and it
  already carries welds with `CATEGORY FABRICATION|ERECTION`. Reading `.dcf` would tie us to
  Autodesk **and Windows** and still would not give welds. Commercially decisive for Iran, where
  CADWorx and AVEVA are common.
- **NPS is stored in four columns** at the user's instruction: `nps_label` "2 1/2",
  `nps_decimal` 2.5, `dn` 65, `od_mm` 73.0. The decimal column is **load-bearing, not
  redundant** — it is the only one that can be summed, and **dia-inch of accepted welds** is the
  stated purpose. Tested: summing the string column raises `ValueError` and sorting it puts
  `12` before `2 1/2`.
- **ADR-008's four questions all closed** (ADR-009 §4): branch rules stay 2-D; **equal and
  reducing tees are ONE `TEE` type** with the reduction *derived from port sizes* (a stored
  `REDUCING_TEE` could contradict its own ports); **no class inheritance** — `parent_class_id`
  dropped; **published classes lock**, edits create a revision and lines keep pointing at the
  revision they were issued against.
- The user corrected the size-band example (no size is in two bands). **Half-open ranges and the
  exclusion constraint stay** — they now encode that intent exactly, and guard it against
  transcription error rather than against the user.

**ADR-008 (`docs/ADR-008-PMS-Piping-Class-Rules.md`) — PROPOSED, new.** How a PMS piping class
expresses size-dependent rules («۱/۲ تا ۴ اینچ SCH80، ۴ تا ۱۰ اینچ SCH40») and the branch table.

- **Half-open DN ranges `[from, to)`, never inclusive text.** The user's own sentence has 4" in
  *both* bands — obvious to an engineer, fatal to a database. Keyed on **DN integers** because
  NPS is a label (`1 1/4`, `1.25`, `1-1/4`, `۱¼`), not a number.
- **One table `piping_class_rules` with a `rule_kind` discriminator** — schedule, end prep,
  valve type, flange type, gasket, bolt spec all share the shape. Same pattern as `MovementType`
  and `FacilityKind`.
- **`EXCLUDE USING gist (class WITH =, rule_kind WITH =, dn_range WITH &&)`** makes overlapping
  bands unrepresentable. **Verified on PG 17.11:** half-open bands accepted, DN100 resolves to
  exactly one rule (SCH 40), DN80 to SCH 80, and the *inclusive* spelling of the same sentence
  is **rejected** with `exclusion_violation` naming DN100. Needs `btree_gist` — **already
  installed in the dev DB** by the spike; the migration must `CREATE EXTENSION IF NOT EXISTS`.
- Overlap is impossible but **gaps are not**, so coverage is checked with PG 17 `range_agg`:
  one contiguous fragment = complete, more than one = holes. Becomes a **publication gate** on
  the class.

**The branch table spike changed the design — record this.** I first tried the same exclusion
constraint in two dimensions. It **rejected two perfectly legitimate PMS rules** (`[15,600)×
[15,600)→TEE` plus `[50,600)×[15,50)→WELDOLET`), because a specific rule's footprint sits inside
the general one. Every real branch table is written as *general rule + specific exceptions*, so
**overlap there is a feature, not corruption**: `UNIQUE` on the exact footprint only, and
resolution by **specificity — narrowest `header span + branch span` wins**. Verified: three
rules match the 6"×1" cell and SOCKOLET (tightest) is correctly chosen, while 6"×6" gives TEE.
`branch_dn ≤ header_dn` cannot be a row CHECK (it is a property of the query, not the row) so it
lives in the resolver with its own exception.

**Two-layer validation, deliberately separate:** ASME says what *exists* (INVALID if not),
the PMS says what is *specified* (DEVIATION if not). A 6" SCH 60 pipe is real and wrong — one
layer alone would either lie or miss it. Same philosophy as advisory inspection.

**A real trap caught while verifying that data.** Published B36.10M tables *disagree* on
large-bore walls because some print **STD** values under a column labelled **SCH 40**.
Verified: **STD diverges from SCH 40 at 12" and above** (12" is 10.31 vs 9.53) and **XS diverges
from SCH 80 at 10" and above** (fixed at 12.70). So STD/XS/XXS are **first-class schedules, not
aliases**, and the tests must assert the *divergence* — a fixture checking only 2" and 6" would
pass on a completely wrong large-bore table.

**Benchmarked before proposing** (2M rows, real RLS policy, role `epcc`): indexed fetch of one
line's ports **0.45 ms** — the policy's `EXISTS` is hashed once, not per row — but a full-scan
aggregate is **2,248 ms vs 204 ms without RLS, an 11× penalty**. So: normal screens are safe,
and **piping progress roll-ups must be incrementally maintained, never aggregated live.** That
is a *new* rule — `subsystemMaterialStatus()` (WarehouseService:441) computes live today, which
is fine at its current scale but must not be copied into the piping module.

**ADR-005 (`docs/ADR-005-Engineering-Data-Ingestion.md`) — PROPOSED, depends on ADR-004.** The
user wants the system usable *before* construction starts and *before* material reaches site,
fed by Excel and by Navisworks exports, carrying PMS, MTO, isometrics, line list and support
drawings. **The decisive finding: NWD/NWF/NWC cannot be parsed** — proprietary, Windows-only,
spec not public, and Navisworks itself exports only DWF/FBX/KML (geometry without properties;
Freedom cannot export at all). So the ingestion path is the **property export** the model owner
produces from Navisworks Manage, never the model file. Do not promise NWD upload.
**PCF is the best available source** — plain text, ISOGEN's own input format, emitted by Plant
3D / CADWorx / AVEVA / CAESAR II, giving every component with bore, item code, coordinates and
weight: an MTO nobody typed, parseable with no library and no licence.
Design: **one neutral `engineering_items` baseline** carrying `source_kind` + `confidence`, fed
by several importers; **versioned immutable `import_batches`** (never truncate-and-reload, or a
re-import silently deletes the baseline a PO was raised against — and keeping batches yields the
Rev B→Rev C diff for free); staged `upload → preview → commit` so a 40k-row file that fails at
row 39,999 leaves nothing behind; **`import_profiles`** so a new client's column headings are
configuration, not code; **PMS as an advisory validator**, matching the inspection choice already
made. Already present and reusable: `openspout` 4.32 + `league/csv` (installed), `jobs` /
`job_batches` / `failed_jobs` (migrated, `app/Jobs/` does not exist yet), and the CDE for
isometrics/support drawings. Five phases in §6 of the ADR; **Phase 1 is independently useful**.
Five open questions — the most useful thing the user can supply is **a real sample of each file
type**. A browser 3D viewer is explicitly **out of scope**.


> **Full itemised status — what is done and what is not — lives in
> `docs/TODO.md`.** It is the answer to "where are we?"; this section is only
> the short priority order.

Roughly in value order. None is started.

1. ~~**Filament v4 admin panel**~~ — **done**, see §5i. Five resources
   (Document, PunchItem, Transmittal, User, Subsystem). `companies` is
   deliberately uncovered (it is not an RLS-scoped tenant table).
2. ~~PDF export~~ — **done**, see §5f. Remaining: the payment certificate
   (صورت‌وضعیت) and the punch register still have no PDF.
3. **Procurement, HSE, Schedule** module groups.
4. **Installer + air-gap verification** (`verify-airgap`, AG-01…AG-06).
5. **Extend QA/QC**: RFSU certificates (A+B gate — `blocksReadyForStartUp()` is
   already written and tested but nothing calls it), **subsystem bulk import**
   (the reason the admin resource has no create form — see §5i-3),
   punch photos on `DocumentFileStore`.

~~Transmittals (FR-ENG-05)~~ — **done**, see §5d.
~~QA/QC + punch list (FR-COM-04/05)~~ — **done**, see §5e. `awp` is now the only
module still in `PLANNED_MODULES`.

---

## 9a. Git remote — and why the config keeps disappearing

> **State as of 2026-09-18 (end of phase 3): PUSHED AND IN SYNC.** Remote `main` and local
> `main` are both at **`c442de8`** (phase 3 — joints, compatibility, dia-inch), **376 files**.
> The eight-commit backlog that had built up since `edc2925` is gone: the range
> `edc2925..c442de8` covers phase 1, phase 1b, ADR-014 units, the units menu narrowing,
> phase 2 components/ports, the RTL/legibility fixes and phase 3.
>
> Verified after pushing, not assumed: `git ls-remote` returns the same SHA as `git rev-parse
> HEAD`; the pushed tree contains **no** `.env`, `.netrc`, credentials file or
> `source-backup.tar.gz` — only `app/.env.example`, whose secret-shaped keys are all empty.
>
> **The token was not persisted, by design.** It was passed inline to a single `git push` via a
> one-shot `https://x-access-token:TOKEN@…` URL and never written to `.git/config`,
> `.git-credentials` or the `origin` remote (confirmed by grep). `origin` is registered with the
> plain HTTPS URL, so it is usable for `fetch`/`ls-remote` but a future push will ask for a
> credential again — which is the intended posture. Expect to mint a fresh fine-grained PAT
> (**Contents: Read and write**, scoped to `epcc-platform`) next time; assume this one is spent.


**`github.com/iman-taheri59/epcc-platform`** (private). Current `main` =
**`c442de8`**, 376 files. `app/.env` and `source-backup.tar.gz` are gitignored
and have been confirmed absent from the remote tree.

**The recurring trap:** `.git/config` is on the snapshot exclusion list (along
with `.git-credentials` and `.netrc`), so **every sandbox reset wipes the remote
URL and the committed identity**. Symptoms, in the order you will hit them:

1. `git commit` fails with *"empty ident name"* — fix with
   `git config user.email … && git config user.name …` (repo-local is fine).
2. `git push origin main` fails with *"repository does not exist"* — the
   `origin` remote is simply gone, not misconfigured.

**Do not paste the token into the remote URL.** Push with a one-shot URL so it
never lands in a file that could be committed or archived:

```bash
git push "https://x-access-token:${TOKEN}@github.com/iman-taheri59/epcc-platform.git" HEAD:main
git remote add origin https://github.com/iman-taheri59/epcc-platform.git   # tokenless
```

Pipe the output through `sed "s|${TOKEN}|***|g"` — git echoes the full URL on
error, and that output otherwise ends up in the transcript.

**Verify the push against the API, not the push output.** This repo has twice
reported `size: 0` and a stale `pushed_at` for a non-empty tree. Compare
`git/ref/heads/main` to `git rev-parse HEAD`, then list
`git/trees/main?recursive=1` and check the files you expect.

**A fine-grained PAT is scoped to a repo LIST, and a missing repo looks like a
404, not a permission error.** A token that authenticates perfectly
(`/user` → 200, correct login) can still return **404 Not Found** for
`/repos/<owner>/<repo>` and `403 Write access to repository not granted` on
push — because the repository was simply never added to the token's
*Repository access* list. 404 rather than 403 is deliberate on GitHub's part:
it avoids confirming that a private repo exists.

Diagnose it in one call before assuming the token is broken or expired:

```bash
curl -s -H "Authorization: Bearer $PAT" \
  "https://api.github.com/user/repos?per_page=100&affiliation=owner" \
  | python3 -c "import json,sys; [print(r['full_name'], r['permissions']['push']) for r in json.load(sys.stdin)]"
```

This listed exactly one repo — `iman-taheri59/awp-epcc`, an **unrelated
project** (Laravel at the repo root, Docker, `phases-*` branches, last pushed
2026-09-07) whose history shares no commit with ours. Pushing there would have
needed `--force` or a nonsense merge and would have damaged someone else's
work. **If the reachable repo is not the one you expect, stop and ask** — the
fix is on GitHub (add the repo, Contents: Read and write), not in the shell.

**Push status:** `main` = **`04fe0ad`**, pushed 2026-09-15 and verified through
the API (branch sha, five spot-checked new paths, HANDOFF §5q present). Local
and remote are level; nothing is outstanding.

**Token hygiene:** tokens have been pasted into the chat twice. Treat every one
of them as compromised and rotate it once the push is confirmed — a PAT with
repo scope on a private commercial codebase is the whole product.

## 10. Session log

### 2026-10-05 — external repo handoff, backlog reconciliation and MVP roadmap

Cloned the private GitHub repo in a fresh Arena workspace, confirmed local and remote `main` are in
sync at `61093dd3f9a8e146161e288e2857c9dc372e6b4d`, and kept the PAT out of git config/remotes.
Workspace footprint stayed small (~15 MB; no `vendor`/`node_modules` committed). The current sandbox
has Node/Python but no PHP, Composer or PostgreSQL, so the full Laravel/RLS test suite was not run
here. What could be verified was verified: `python3 standards-packs/validate_packs.py` passed for all
3 standards packs and 5 reference packs, and `node prototype/test/sidebar.test.js` passed 19/19.

Reconciled `docs/TODO.md` with the newer state already recorded in this handoff: procurement is no
longer a zero-page module, ADR-006 phases 1/1b/2/3 are built, physical breakdown/reference data/units
/components/ports/joints are now listed as done, and the open backlog is split into real gaps instead
of stale module labels. Added `docs/ROADMAP-TO-MVP.md` with the recommended sequence: first restore a
real Laravel/PostgreSQL test environment, then finish small PDFs, RFSU/PAC/FAC, PMS rules, engineering
imports, equipment/nozzles and HSE. Also saved the initial Arena analysis as
`docs/ARENA-INITIAL-ANALYSIS-FA.md`. User then set a standing documentation rule: every new
document created from now on must have an English counterpart. Added the corresponding English
versions `docs/ARENA-INITIAL-ANALYSIS-EN.md` and `docs/ROADMAP-TO-MVP-EN.md`; keep this bilingual
pairing rule for future docs.

Follow-up documentation audit: reviewed all 15 ADRs and the parent docs (`SRS`, `Architecture`,
`Prototype-Coverage-Map`, `Spike-RLS-Results`, `Infra-Cost-Model-Iran`, `Iran-First`). Added
`docs/DOCUMENTATION-AUDIT-FA.md` and `docs/DOCUMENTATION-AUDIT-EN.md`. Key findings: parent docs
have drifted behind the real Laravel/Iran-first implementation; ADR-003 main is superseded by Annex
A; ADR-006 is stale because phases 1/1b/2/3 are now built; SRS needs release-phase tags and the pack
list aligned to `ips-iran`/`iso`/`awp`. Requirement recommendations recorded there: defer visual BPM,
phase OCR/search/digital signature/offline sync, keep the visibility matrix constrained for MVP, and
do not promise direct NWD parsing. User then clarified the product priority: all EPCC disciplines
must remain in scope, but Piping should be completed first. Added
`docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-FA.md` and
`docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-EN.md`; updated TODO/roadmap/SRS/Architecture and ADR
status notes accordingly. User then requested synthetic data for testing/preview and noted the UI feels
cluttered. Added `docs/UI-UX-IMPROVEMENT-PLAN-FA.md` and `docs/UI-UX-IMPROVEMENT-PLAN-EN.md`: use
realistic demo data, shift from register-first to task-first UX, build a Piping Workspace first, add
summary cards, blocker-first views, detail drawers, deviation inbox and test-pack board. User also added that product planning and sales infrastructure must be completed: Iran-first requirements now, international readiness later. Added `docs/PRODUCT-PLANNING-AND-SALES-INFRA-FA.md` and `docs/PRODUCT-PLANNING-AND-SALES-INFRA-EN.md`, covering on-prem/air-gap sales, offline licensing, demo seed, sales collateral, implementation methodology, support diagnostics, and international commercial readiness. Built the first static, air-gap-safe Piping Workspace preview at `prototype/piping-workspace.html` using synthetic PH-14 data: bilingual FA/EN, RTL/LTR, summary cards, blocker-first filters, readiness bars, deviation inbox, test-pack board and a line detail drawer. Verified it contains no external refs and the existing pack validator/sidebar tests remain green. Follow-up: user reported the demo was not responsive; fixed the prototype with a compact mobile nav, stacked filters, card-style line rows under 760px, responsive metrics and full-width drawer. Second follow-up: filter checkbox labels overflowed and Persian mode still showed English literals such as “All subsystems”; localized those UI strings and made checkbox text wrap safely; also replaced remaining Persian-mode English labels in the filter/tabs/panels where they were not deliberate domain acronyms. Second pass made the filter bar a constrained grid, added a Persian static fallback for the subsystem option before JS runs, and made checkbox labels impossible to overflow on narrow widths. Added `prototype/test/piping-workspace.test.js` so Persian filter labels, language switching, mobile-card structure, drawer behaviour and air-gap safety are pinned by jsdom (16 assertions). Made the new Piping Workspace discoverable from `prototype/index.html`, added `npm run test:prototype`, and updated `README.md` so reviewers know which preview to open and which tests to run.


Runtime restoration is now fixed and repeatable in this sandbox. `restore.sh` now creates `app/.env` when missing, forces Laravel onto PostgreSQL (`epcc` / `epcc_test`, user `epcc`, password `epcc_dev`), generates the app key, migrates/seeds, and publishes Filament assets; this closes the prior SQLite/PDO-driver restore failure. After the fix, `bash restore.sh` completed, and the full Laravel/PostgreSQL suite ran green: **856 passed / 3153 assertions**. Also added the first real Laravel version of the Piping Workspace at `/projects/{code}/piping/workspace`, linked it from the sidebar before the line register, and backed it with real project data: summary cards, blocker-first worklist, AFC evidence gaps, joint fit-up progress and subsystem-grouped test-pack readiness. New HTTP assertions pin EN/FA rendering, tenant isolation, sidebar discoverability and blocked-line worklist behaviour.


Next main slice: turned test-pack readiness from a derived placeholder into a real Laravel object. Added `piping_test_packs` with RLS/archive-freeze policies, `piping_lines.test_pack_id`, `PipingTestPack` and `PipingTestPackService`. The workspace can now create hydrotest/test packs, assign/unassign lines, show unassigned-line counts, and move packs through `DRAFT → READY → TESTED → ACCEPTED`; `READY` is blocked until the pack has at least one line and all assigned lines are released. Added HTTP coverage for pack creation, assignment, and refusing readiness while a line is not released. Full suite after this slice: **858 passed / 3166 assertions**. Note: Laravel DB tests must not be run in parallel against the same `epcc_test` database; doing so produced expected migration/drop-schema races, then sequential reruns were green.

### 2026-09-17 (later still) — ADR-006 phase 2: components and ports

Built the modelling layer: `components` + a single discriminated `ports` table, `ComponentService`,
a per-line model screen, and the attach gesture. The user's "how does it know a tee has three
ports" question is answered structurally — the template declares them and no API accepts a count.

**Sandbox reset #33 mid-turn.** Wrote `rebuild-env.sh` so the next one is a single command instead
of ten minutes of remembering; it also verifies chromium/poppler and warns that their absence makes
13 tests skip silently rather than fail.

Full suite **852 passed / 3136 assertions**, zero skipped. 13 new mutants on the modelling service,
all caught.

### 2026-09-17 (later) — units menu narrowed to bar/psi and C/F

User: «فقط واحدهای سلسیوس و فارنهایت را برای دما و bar و psi برای فشار را در نظر بگیر».

Retired the other units with a `selectable: false` flag rather than deleting them, so stored and
imported values in kPa/kg/cm²/barg/K keep converting. That exposed a distinction the original
design had glossed over: the canonical storage unit (kPa) is no longer a legal *display* unit, so
the fallback for an unconfigured project had to become a new `default_display` (bar).

**Sandbox reset #32 at the start of this turn** — rebuilt PHP 8.4, Composer, vendor, PostgreSQL,
the database, and (newly) **chromium + poppler-utils + fonts-vazirmatn**, which brought 13
previously self-skipping PDF tests back to life. Full suite now **767 passed / 2998 assertions,
zero skipped** (was 758 passed with 13 silently skipped).

5 new mutants on the narrowing logic, all caught; 4 new pack-corruption probes, all caught.

### 2026-09-17 — units of measure (ADR-014) wired end to end

Built on the previous session's engine: `UnitPreferenceService` (user → project → canonical),
canonical mirror columns populated on every write via `PipingLineService::canonicalValues()`, a new
`updateDesign()` path (there was none — design conditions could only be set at creation), the pack
schema + `check_units()` CI gate, FA/EN keys, and the register UI (two new columns, value+unit
inputs, a units panel with separate "just for me" and "project default" buttons).

Verified end to end over HTTP against a real login: created a line at 285 psi / 650 °F, switched
the reader's units to barg, confirmed the display changed to `19.65 barg (285 psi)` and the stored
row did not change at all.

Full suite **758 passed / 2980 assertions** (was 667 / 2751). Units mutation total **38 run, 0
survivors**; M28 and M38 survived first pass and both exposed real gaps. Pack validator proved by
breaking `units.ref.json` six ways — all six caught.

ADR-014 written (it had been referenced in docblocks but did not exist).

Append one row per session. Keep it terse.

| Date | Change | Result |
|---|---|---|
| 2026-09-15 | **Phase 1b (§5s): the numbering editor and evidence attachment — the two things that made phase 1 *correct but not operable*.** The number format could only be changed from tinker, which is a hardcoded rule with extra steps; evidence could only be attached programmatically, so the gate was demonstrable but not usable. Both are now screens. Probed the engine before designing the editor's guard rails rather than reasoning about them: a **counter-less pattern composes to the identical string twice**, so the second create dies on the uniqueness check with "number already taken" — symptom named, cause hidden, project silently capped at one line per subsystem. Four rules now refuse at the point of editing, including that counter width may grow but never shrink below what was issued (padding is render-time, so narrowing corrupts nothing until the project crosses the threshold, which is exactly what makes it dangerous). Well-formedness is delegated to `NumberPattern`'s constructor so the two cannot drift — which exposed that **`AmbiguousPatternException` is not a subclass of `InvalidPatternException`**; both extend `RuntimeException` and are `final`, so catching one let the two-counter case escape as a 500. Driving the register with curl then found the defect that mattered: **attaching evidence is not exclusive and the gate checks every link**, so one B-coded drawing attached by mistake locked a line out of AFC permanently with no way back but a database edit. Added `detachEvidence()` — draft lines only, because removing the justification for a decision already taken is not a correction. **Mutation M21 survived the first pass**: nothing stopped a hand-edited URL from pairing line A with line B's evidence link, and since both rows live in the same project **RLS is perfectly happy** — the ownership check was the only guard, and it was untested. Also: `TableToolsTest` correctly caught the config screen's token grid, which legitimately must not be sortable because **the row order *is* the data**; declared `data-tt-exempt` in the view with its reasoning rather than weakening the guard, then confirmed the guard still bites | **667 / 2751**, 21 new tests, **mutants 14–22 caught, 0 survivors** |
| 2026-09-15 | **ADR-006 phase 1 built (§5r): piping lines, per-project numbering, and the AFC gate.** The first piece of the engineering spine — every component, joint, weld and NDT record eventually resolves to a line. The number format is **data, not code**: a jsonb token list per project, composed and parsed by an engine with the vocabularies injected. Confirmed against real fixture data that the obvious implementation is wrong — **`explode('-', …)` yields 9 fragments for 6 tokens**, because the subsystem code contains three separators. Two defects existed before a line of test ran: `confirmAfc()` called **`CdeState::isApproved()`, which did not exist**, on **`$revision->state`, a column that does not exist**. Fixing it forced the real question — what *is* approval? Answered from the ISO 19650-2 table already in the code rather than invented: `PUBLISHED` **and** an A-family suitability, because **B1–B5 mean "approved with comments"** and waving them through is exactly the rework the gate exists to prevent. Mutation testing then earned its keep twice: **M1** showed my longest-first-sorting test proved nothing (PCRE backtracking rescues `A1|A1B`, so the real hazard is a *following* token absorbing the difference — `PH-14` vs `PH-14-SS`, wrong subsystem, silently), and **M8** showed the `PUBLISHED` half of the gate could be deleted with the suite still green, because the SHARED+S2 fixture fails the A-check anyway; the test that separates them is **SHARED + A1**. Built the register UI and drove it with curl, which surfaced two more: a legitimate refusal escaped as a **500**, and the form offered free text where a project register governs. Imported numbers keep **`sequence` NULL** so a foreign `…-777` cannot push our counter to 778 | **647 / 2716**, 61 new tests, **13 mutants killed, 0 survivors** |
| 2026-09-15 | **Project archive built (§5q) — the answer to ADR-013's open question, which the user settled as «آرشیو».** `ACTIVE ↔ ARCHIVED`, reversible, with **no `DELETED` state**: deletion stays a separate ceremony rather than one dropdown position away. Archived projects are read-only, hidden from the portfolio behind a clickable count, and **excluded from `plans.max_projects`** — the commercial crux, because a customer on a one-project plan who cannot start job two without deleting job one *will* delete job one, and the licence terms would be instructing them to destroy contractual evidence. Two layers, both necessary and failing differently: the service refuses loudly and translated, RLS is the backstop. Spiked the mechanism before writing it and found the asymmetry that shaped the design — **a blocked UPDATE/DELETE raises nothing**, `rowCount=0`, `save()` returns true, so RLS alone would tell a user "saved" while the edit vanished. Then found a real hole by trying it rather than trusting the design: **`WITH CHECK` is never evaluated for DELETE**, so the freeze policy reported `DELETE 9` and emptied an archived project's punch list — fixed with a second `FOR DELETE USING (...)` policy per table (22 × 2). Also uncovered incidentally: **`SetUserLocale` was registered on the Filament panel only**, so every `__()` outside the admin panel fell back to the default locale; invisible while only views used it (they have their own `?lang=` closure) and exposed the instant a service threw a translated exception | **586 / 2564**, 30 new tests |
| 2026-09-15 | **Phase 0 built: the engineering reference packs (§5m, ADR-007 → ACCEPTED AND BUILT).** First code from ADRs 006-010, and deliberately first: the line-number parser, the compatibility engine and the PCF importer all need the size vocabulary before they can validate anything. Four versioned JSON packs (36 sizes, 355 schedule pairs, 13 PCF end types, 20 component templates) behind one memoised singleton - **not** database tables, because the protection needed is *nobody can write, including the owner company*, which a file gives absolutely and a permissive RLS policy only pretends to. The ADR predicted from published sources that **STD is not an alias of SCH 40 above 10″ and XS is not SCH 80 above 8″**; the shipped data now asserts it in three layers. Two defects: PHP **coerces numeric string array keys to integers**, so `schedulesFor()` returned a mixed `int|string` list (caught by the suite on first run); and the reachable **alias-fallback bug runs the opposite way to the assertion** - ASME defines STD at 22″ and 26″ but no SCH 40 there at all, so a "helpful" fallback returns 9.53 mm for a schedule that does not exist. That one **survived the first suite** and was found only by mutation M1. M14 also showed the singleton test proved nothing: instance identity is not memoisation, so it now warms the cache, deletes the files, and asserts a *fresh* instance still throws | **556 / 2491**, **153 mutations caught** (+15 pack-data, +15 PHP) |
| 2026-09-13 | **Physical breakdown built (§5l, ADR-004 → ACCEPTED).** `project → facility → (optional) area → subsystem`, after establishing that Plant 3D has **no** System/Subsystem, MC, punch or handover — so compatibility is an **import mapping**, not a copied schema. One `facilities` table with a `FacilityKind` discriminator instead of sibling plant/pipeline tables, because what they share is being a commissioning parent and what differs is addressing (per-kind `jsonb`). Numbering moved to **per facility**, which made the bare code ambiguous — so `fullCode()` composes at read time (never stored: it would drift on rename) and the register shows Facility as a non-toggleable column. Integrity rests on a **composite** FK `(area_id, facility_id) → areas(id, facility_id)`, making cross-facility corruption unrepresentable. The fixture is deliberately uneven — one facility with areas *and* a direct subsystem, one with no areas at all — because a uniform fixture cannot fail informatively. Two traps recorded: the backfill silently matched **zero rows** under `FORCE ROW LEVEL SECURITY` (RLS filters, it does not error), and **M118 first reported SURVIVED because the mutation was wrong, not the test** — dropping a constraint in `psql` proves nothing when `setUp()` runs `migrate:fresh`. Mutate the migration source. Pushed as `cf99f0f` (§9a — a reset had wiped `.git/config`, so the remote and the git identity had to be recreated first) | **507 / 1716**, **123 mutations caught** |
| 2026-09-13 | **UML for the procurement module (`docs/uml/`).** Installed `graphviz` + `plantuml` (absent from the base image; plain `apt-get` fails the dpkg lock, `sudo` works) and drew five diagrams — domain model, the three state machines, an inspection-to-MC-gate sequence, reversal + RLS, and the component view. Written against a live `information_schema` dump rather than from memory, so the attribute lists are the real columns. Packaged as `docs/uml/uml.html` with the SVGs **inlined** — checked for external refs and found none, matching the `tour.html` air-gap pattern — and verified by screenshot rather than by byte count | no code change, **482 / 1647** |
| 2026-09-09 | Docs, standards packs, prototype, ADRs, Laravel scaffold, standards engine, conformance corpus | Foundations in place |
| 2026-09-09 | PostgreSQL + RLS spike, multi-project, Octane and PgBouncer spikes | All CLOSED, 12 standing rules recorded |
| 2026-09-09 | Auth + six-gate authorization, `BindTenant`, project setup & team screens | Vertical slice works |
| 2026-09-10 | Preview login debugging, rounds 1–5 (cookie attributes) | **All failed** — wrong layer |
| 2026-09-10 | Cookie-less preview: `PreviewSessionFallback` + `PreviewUrlRewriter`; fixed mixed-content `redirect()->back()` | **Login works in the preview** |
| 2026-09-10 | Entitlement-driven sidebar, view composer, `HANDOFF.md`; fixed `?as=` reflection regression | 147 tests green |
| 2026-09-10 | **Sandbox reset #6** — PHP, PostgreSQL and `vendor` wiped, source intact. `restore.sh` rebuilt it in ~43 s | 147 tests green, preview back up |
| 2026-09-10 | Collapsible sidebar: icon rail (desktop) + off-canvas drawer (mobile), ported from the prototype; pre-paint state restore | 150 PHP tests + 17 jsdom assertions green |
| 2026-09-10 | **Sandbox reset #7.** Toggle was dead — script ran before its button existed; the jsdom test had masked it by re-running the script post-parse. Fixed both. | 151 PHP + 17 jsdom green |
| 2026-09-10 | Desktop rail snapped with no animation: no width transition outside the mobile query, and `display:none` labels are unanimatable. Added `sidebar-animation.test.cjs`. | 152 PHP + 29 jsdom green |
| 2026-09-10 | Language switch invisible — the animation change gave an inline `<a>` `max-width`, which inline boxes ignore. Fixed with `inline-block`; guard added. | 152 PHP + 36 jsdom green |
| 2026-09-10 | **Document register / CDE shipped** (ISO 19650 states, numbering, revisions, RLS). Caught a cross-project RLS leak and a lazy-load-outside-scope trap in the process. | 173 PHP + 36 jsdom green |
| 2026-09-10 | **File attachments shipped** (content-addressed, allow-listed, streamed through authz). Then live verification exposed that participants saw an EMPTY register — the CDE was not common. Migration 130000 makes the CDE state the sharing boundary. | 196 PHP + 36 jsdom green |
| 2026-09-10 | **Filter + sort on every data table** (FR-UX-04): reusable partial, Persian-digit folding, lifecycle-ordered CDE column, sweep test over all views. | 206 PHP + 80 jsdom green |
| 2026-09-10 | **Review & comment cycle shipped** (FR-ENG-06, journey 3): categories, dispositions, closure stats, and a publication gate wired into the state machine. Two real bugs caught by tests (unreflected column default; invalid `FOR UPDATE` + aggregate). | 225 PHP + 125 jsdom green |
| 2026-09-10 | **Transmittals (FR-ENG-05)** + ZIP packager. Found and fixed a **draft-visibility leak** (addressees and the employer could read unsent letters) and the **child-policy RLS recursion** it exposed; corrected a test that encoded the wrong model. Harness fixed for page-shape and collation assumptions | **248 / 836**, 128 jsdom, 36 mutations caught |
| 2026-09-10 | **Sandbox reset #8** — `.cache` (and with it `vendor`) wiped; the workspace also rolled back past the transmittal work. Recovered in full from `source-backup.tar.gz`, which is why it is refreshed at the end of every session | 249 / 838 green after restore |
| 2026-09-10 | Blob-store purge in `tests/bootstrap.php` + `BlobIntegrityTest`; workspace 13,263 → 318 files | File cap no longer at risk |
| 2026-09-10 | **Sandbox reset #9** — PHP, PostgreSQL and `vendor` wiped, source intact (incl. transmittals). `bash restore.sh` rebuilt in ~38 s | 249 / 838 green after restore |
| 2026-09-10 | **QA/QC + punch list + MC certificates (FR-COM-04/05, journey 5).** Added the `MC-KARAN` tenant the fixture lacked; gave `consultant` the `punch.*` it was missing. Caught an **inert test** in mutation testing — "contractor cannot verify its own fix" passed with the guard deleted, because the tenant-type rule threw instead; fixed by asserting on the message and using a verifier-type company. Fixed 3 harness over-assumptions (`data-sort="none"` counted as declared, single-value facets, digits inside identifiers) | **284 / 939**, 345 jsdom (7 fixtures), **46 mutations caught** |
| 2026-09-10 | **Sandbox reset #10** — PHP/PostgreSQL/vendor wiped again; `bash restore.sh` rebuilt in ~45 s and **auto-reinstalled jsdom** (the fix added last session paid off immediately) | 284 / 939 green after restore |
| 2026-09-10 | **PDF export via headless Chromium (§5f) — retires the PDF half of risk IR-R2.** Rasterised and visually verified Persian output rather than trusting a byte count; found and fixed a per-page footer collision and a category column that wrapped to four lines. Mutation testing found the `%PDF-` magic-byte guard was **completely untested** (a fake browser writing garbage would have been served as a certificate) and that `isAvailable()` is only a fast path, not the guard that keeps the ZIP alive | **301 / 1002**, 257 jsdom (5 pages × table tools + 36 sidebar), **54 mutations caught** |
| 2026-09-11 | **Sandbox reset #11** — same wipe; `restore.sh` rebuilt in 58 s and **auto-installed Chromium + Vazirmatn + poppler** for the first time, so PDF export survived the reset untouched | verified live: cert PDF 200, Vazirmatn embedded |
| 2026-09-11 | **Shell layout bug reported by the user** — punch, transmittals and document-review put the sidebar outside `.app` and the topbar as a direct child of it, so content slid under the sidebar and the collapse button went missing. Root cause was markup order, not CSS. Also gave `/gate` the shell (it is linked from the sidebar but had none, stranding the user) while keeping it usable logged-out, moved `$user`/`$acting` into the view composer as defaults, and fixed a literal `ips.iran{{ $packVersion }}` caused by Blade's `@{{ }}` escape | **319 / 1110**, **57 mutations caught** |
| 2026-09-11 | **Register column shift + category letters (both user-reported).** The QA/QC table showed "Subsystem" above the category data: `.mono` sets `display:inline-block` for LTR runs and was on `<td>`, which replaces `display:table-cell` and drops the cell out of the column layout. Cell counts matched, so only per-cell geometry in real Chromium found it; the same latent bug was on the document-review table. Fixed with `td.mono{display:table-cell}`. Also switched punch categories from الف/ب/ج to **A/B/C in both languages**, including the gate message and the MC certificate PDF | **325 / 1149**, **60 mutations caught** |
| 2026-09-11 | **Sandbox reset #12** — mid-task; `restore.sh` rebuilt in 58 s with Chromium/Vazirmatn intact | — |
| 2026-09-13 | **Procurement & materials (§5k).** Built the chain the user named: تهیه ← بازرسی ← ورود به انبار ← خروج از انبار ← ورود به سایت. 7 tables, 7 models, 2 services, 2 screens, 51 tests. Key decisions: one append-only movement ledger rather than four document tables; the sign convention in code not data (`SITE => 0`, or site delivery double-deducts); ISSUE and SITE kept separate so material on a laydown area stays visible; lots keyed to heat number with the MTC as a CDE document; inspection advisory per the user's instruction, paid for with a permanent "needs attention" panel; two distinct SoD rules on the requisition. RLS is commercial here — a vendor sees its own PO and not a competitor's, and never sees a requisition | **482 / 1647**, **117 mutations caught** |
| 2026-09-13 | **AG-06 shipped, and a ninth defect (§5j).** Wrote `verify:airgap`, the pre-flight the customer runs at acceptance, with a BLOCKER/LATENT/BENIGN split so the output stays actionable. Writing it found two leaks the suite could not see: Pusher's CDN, hidden from the first scanner because `echo.js` concatenates the URL at call time, and **`ui-avatars.com`**, which Filament builds in PHP from the user's name and rendered into every admin page - an un-fetchable image on site AND an employee's real name handed to a third party. The avatar one exists in no file on disk and was found with a browser net-log; fixed with `LocalAvatarProvider` (inline SVG initials). Dropped `FilamentInfoWidget` too. The test now asserts on rendered HTML, not just files, because that is the only place a runtime-assembled URL is visible | **427 / 1523**, **113 mutations caught** |
| 2026-09-13 | **The mobile blank page (§5i-3).** Building a screenshot tour for a phone, the first shot came back as a single flat colour: `/documents?lang=fa` at 430px painted **nothing**, while the same page in English was fine. Cause was `min-width:auto` on a flex item — a 1032px table widened `.wrap` past a 485px viewport, and in RTL that pushes the whole document off-screen rather than clipping it. Fixed once in the shell with `min-width:0` + `overflow-x:auto`; both halves needed, since containment alone hides the state and revision columns. Bisected by measuring unique colours per render, which is what separated "page is broken" from "screenshot tool is broken" and then LTR from RTL. Also shipped `tour/tour.html`: a self-contained, data-URI gallery so the work is reviewable on a phone where the live preview cannot open | **416 / 1433**, **111 mutations caught** |
| 2026-09-12 | **Certificate evidence integrity, and clearing two pieces of flagged debt.** Started on the `User::find()` in `PunchController` flagged as unconverted since the identity-table work — and it turned out to be **correct**: the `users` counterparty policy already covers reading an issuer from another company, verified from all four participant sides. But the fixture had **never issued a certificate**, so the whole signatory path was unrendered by any test; the blade guards it with `@if($issuer)`, meaning a failure drops the "issued by" line from signature evidence rather than erroring (**M105**). Then, having finally rendered an issued certificate, downloading it twice showed the real defect: the punch summary was **recomputed live on every render**, so an issued certificate's own numbers drifted as the site worked on — closed 2→3, open A 2→1, always in the flattering direction. Fixed with a `punch_summary` jsonb snapshot written at issue, mirroring `exception_items`; drafts deliberately stay live (M107–M109). Also replaced all **ten** `Subsystem::first()` call sites with an explicit code lookup — proven load-bearing: pointing the helper at a different subsystem fails 8 tests | **414 / 1423**, **109 mutations caught** |
| 2026-09-12 | **Subsystem admin resource, a fixture that could finally tell rules apart, and a panel-wide digit defect (§5i-3).** Built the read-only Subsystem register whose point is the **derived** MC gate — no state column exists, so the table asks `PunchCategory`/`PunchState`/`McCertificate` for the rules and computes counts with `withCount` rather than the model's collection-walking helpers (quadratic on a register); a probe confirmed SQL and model agree row by row. `closurePercent()` returns 100 for an empty list, so an un-walked subsystem says **"not walked down"** instead of claiming completion. **Two mutations survived** and neither was an assertion problem: the fixture had no A-item in VERIFIED and no B-only subsystem, so "VERIFIED still blocks" and "only A blocks" were unprovable. Added SS-5501 and SS-6601, then M96 and M99 died — M96 having survived a test that checked `PunchState::isOpen()` and the model helper while the **table** disagreed with both. Growing the fixture broke three PunchList tests hardcoding `PL-0006`/`count=5`; de-hardcoded rather than renumbered. Screenshot then caught a defect shipping since the first resource: Persian pages mixed **Persian digits in our columns with Latin in Filament's pagination**, on all five resources. Fixed once at the translator (`LocalisedNumberTranslator`, bound in `register()` not `boot()`), converting only wholly-numeric parameters so tags stay Latin — a guard **unobservable through the UI**, so pinned against the translator directly rather than by an inert HTTP test | **410 / 1401**, **104 mutations caught** |
| 2026-09-12 | **Users admin resource, and the identity-table leak it exposed (§5i-2).** Before writing the resource, checked whether `users` was even under RLS: it was not. Bound as EPC-PARS the database returned **all nine users of all eight companies** — the client's reps, the PMC's inspectors and two rival EPCs' engineers — while documents correctly narrowed to 5. Added RLS on `users` + `project_memberships` with a counterparty clause, hitting **policy recursion** (the two policies read each other; re-expressed through `project_company`) and re-learning **rule 3** (`NULLIF` on every GUC read). Login needs a read before a tenant exists, so added the greppable `forIdentityLookup()` hatch — which then had to **snapshot-restore rather than force-clear**, because a nested call was shutting the hatch its caller still needed and silently leaving the disabled fixture account enabled. Then the screenshot: **every `/admin` route redirected to login on a valid session**, because Filament resolves the user before `BindTenant` and `actingAs()` had hidden that from all 21 panel tests. Fixed at the user provider. **M95 proved the first test for it was inert too** — `SESSION_DRIVER=array` means `retrieveById()` never runs in tests | **395 / 1348**, **95 mutations caught** |
| 2026-09-12 | **Transmittal admin resource + the Jalali defect (§5i).** Read the domain classes before writing a line (`TransmittalPurpose::label()` returns a string where `PunchState::label()` returns an fa/en array) — the habit that stopped a repeat of the invented-constant bug. Built a read-only Transmittal resource showing **both streams in one list** with `kind` as a filter, the inverse of the hand-written UI's deliberate split. Then the screenshot showed `سپتامبر 12, 2026` on a fully Persian page: Filament's `->dateTime()` renders **Gregorian**, and on a submission log the date is contractual. Root cause was structural — Jalali formatting existed only as a private `$jdate` closure copy-pasted into each Blade view, so a surface written later could reuse none of it. Extracted **`App\Support\JalaliDate`** and routed all three panel tables and all ten views through it; no `Morilog` call remains in `resources/views`. `DateFormattingTest` guards the **shape of the codebase**, not just output (M87–M89) | **378 / 1305**, **89 mutations caught** |
| 2026-09-12 | **Admin resource scope + punch resource (§5i).** The generated Document form exposed `number`, `sequence`, `company_id` and `current_revision_id` as free inputs — retyping a number collides with the unique index, changing the company makes a document vanish from its author's register. Form reduced to the bilingual titles; create/delete withdrawn. Added a **read-only** PunchItem resource: `state` is a workflow position with rules about who may move it, and `McCertificateService` reads that column, so an editable form would let an admin forge a VERIFIED and unblock a completion certificate. **A grep is not an assertion** — the first no-delete test failed against its own explanatory comment; rewritten against `canDelete()` / `getPages()` | **369 / 1275**, **84 mutations caught** |
| 2026-09-12 | **Filament v4 admin panel (§5i).** Wrote the tenancy test FIRST, and it earned its keep: with the stock middleware stack the panel showed the signed-in contractor **nothing at all** — RLS failing closed — so the "no leak" test passed on a broken screen. Only the mirror test ("still shows its own rows") could tell the two apart. Three more real defects: `->font()` pulls from Google Fonts (**air-gap violation**, now pinned by a CDN-host test), `canAccessPanel` was untested so a **deactivated account could open the admin panel** (M79), and the state column was bound to `currentRevision.state` when the attribute is `cde_state` — an **empty cell on every row** that returned 200 and was found by looking at a screenshot, not by a test (M81). Added `SetUserLocale` + the project's first `lang/` files | **363 / 1256**, **81 mutations caught** |
| 2026-09-11 | **Corrected the meaning of `MC` (§5h).** The user asked "do you know what MC means?" — it is **مدیریت پیمان / Management Contractor**, the employer's contract administrator, not the "Mechanical Completion body" the seeder, two views, the SRS role table and the coverage map all claimed. **No test could have caught it**: a tenant type only has to be a matching string, and the role rules were already right, so the behaviour passed while the screen lied. Added `TenantType` (nine types, one place — they were loose strings in five files), a CHECK constraint on `companies.tenant_type`, and `TenantTypeTest` which pins the words themselves. Renamed the fixture company to مدیریت پیمان کاران and the role to `mc_supervisor` | **356 / 1240**, **77 mutations caught** |
| 2026-09-11 | **Vendor transmittals as a separate stream (§5g).** Asked the four structural questions first (who records, what "separate" means, roll-up traceability, document typing) rather than guessing. Separate `VTR` series, own screen, `source_item_id` chain, two CHECK constraints, `VEN-AZAR` added to the fixture with two seeded letters. **Mutation testing found the HTTP create path was untested** — a controller ignoring the submitted `kind` filed vendor letters into the contractual series and the whole suite still passed. Narrowing the employer RLS clause surfaced a genuine leak: the owner could read supply-chain letters | **349 / 1202**, **74 mutations caught** |
