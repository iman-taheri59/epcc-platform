# ADR-EPCC-005 — Engineering data ingestion: Excel, 3D models, and the engineering baseline

**Status:** PARTLY BUILT as of 2026-10-02 — baseline/reconciliation/import foundation exists; the broader MTO and material-management lifecycle remains incomplete (see §8).
**History:** originally proposed on 2026-09-13; the original “nothing implemented” status became stale after the dated build note in §4 and is retained here as history, not current status.
**Date:** 2026-09-13
**Supersedes nothing. Depends on:** ADR-004 (facility/area/subsystem breakdown — still awaiting your decision)
**Trigger:** the user wants the system usable **before construction starts and before material arrives on site**, fed by Excel import and by Navisworks 3D exports, carrying PMS/spec, MTO per unit or pipeline, isometrics, line list, support drawings.

---

## 0. The one-paragraph version

What you are describing is not an import feature. It is a change of what the product *is*:
from a system that **records what happened** to a system that **knows what is supposed to
exist**. That is the single most valuable thing in the whole platform — because once the
system holds the engineering baseline, every number it reports stops being a typed claim and
becomes a calculation. Progress becomes *installed ÷ MTO*, material shortage becomes
*MTO − received*, and "we are 60% complete" becomes auditable for the first time.

It is also the point where this platform stops competing with a spreadsheet and starts
competing with Hexagon SmartPlant Materials and Aveva ERM. That is a fair fight in Iran,
where those are effectively unobtainable — but it must be entered deliberately.

**The critical technical finding:** **NWD cannot be parsed.** Everything else on your list can.
The architecture must therefore be built around a *neutral engineering baseline table* fed by
several importers of differing fidelity, not around Navisworks.

---

## 1. What I verified before designing anything

### 1.1 Navisworks — the bad news, stated plainly

| Format | Contains | Can we read it? |
|---|---|---|
| `.nwd` | all geometry + properties + clashes + viewpoints, self-contained | **No** |
| `.nwf` | pointers to source files + coordination data, no geometry | **No** (and useless without the sources) |
| `.nwc` | cache of one source file | **No** |

- All three are **proprietary**; the [NWD spec is not open or publicly available](https://forums.autodesk.com/t5/navisworks-forum/how-to-read-parse-nwd-format/td-p/8124963),
  and there are no third-party tools to convert NWD into an editable format without an
  Autodesk licence.
- Navisworks is **Windows-only**. Autodesk has never shipped a Linux or macOS version. Our
  stack is Linux, on-prem, air-gapped.
- Navisworks itself [exports only DWF, DWFx, FBX and KML](https://www.autodesk.com/support/technical/article/caas/sfdcarticles/sfdcarticles/How-to-export-a-Navisworks-file-to-an-AutoCAD-DWG-file.html) —
  all of which are **geometry-only and lose the property data we actually need**.
- Commercial readers exist (ODA BimNv, FME, Spatial 3D InterOp) but are paid, Windows-centric
  SDKs — and licensing an American SDK is exactly the supply-chain dependency ADR-003
  rejected.

**But there is a clean way through.** Navisworks Manage/Simulate can
[export object properties to CSV via Selection Inspector](https://help.autodesk.com/view/NAV/2024/ENU/?caas=caas%2Fsfdcarticles%2Fsfdcarticles%2FHow-to-export-properties-from-Navisworks-to-MS-Excel.html)
— *"Export the function is not available in Navisworks Freedom"*, so this is a task for the
engineering contractor who owns the Manage licence, not for the site.

So the workflow is **not** "upload the NWD". It is:

> **The model owner exports a property schedule from Navisworks; we ingest that.**

This is not a compromise — it is the correct division of responsibility. The 3D model belongs
to engineering; what construction and materials management need from it is the **object list
with properties**, not the geometry.

### 1.2 What we *can* read natively, and how good each source is

| Source | Format | Fidelity | Parser |
|---|---|---|---|
| **Navisworks property export** | CSV / XLSX | tags + properties, **no geometry** | `openspout` (installed) |
| **Plant 3D / any isometric tool** | **PCF** (Piping Component File) | **highest** — every component, coordinates, item code, bore, spec, weight | plain **text**, trivially parseable |
| Line list / MTO / PMS | XLSX | as good as the spreadsheet | `openspout` |
| Isometric / support drawings | PDF / DWG | the **document**, not data | CDE (already built) |
| Open BIM alternative | IFC4 | good, if the source can emit it | `ifcopenshell` (not installed; Python) |

**PCF is the sleeper here and deserves emphasis.** It is a plain-text, line-oriented format,
[originally the input to ISOGEN](https://3dswym.3dexperience.3ds.com/wiki/catia-user-community/piping-3d-export-pcf-file_CQVcw7v-T1azeZTfP7Jveg),
and [an industry-standard interchange written and read by a large number of applications](https://feeacb63-36f4-424a-b7e1-2059c521b397.filesusr.com/ugd/74fdd8_a0d33971b2724fdd9a2f144b8aee0edc.pdf?index=true)
— Plant 3D, CADWorx, CAESAR II, AVEVA, Cadmatic, SmartPlant. Its content looks like this:

```
ISOGEN-FILES        ISOGEN.FLS
UNITS-BORE          MM
UNITS-CO-ORDS       MM
PIPELINE-REFERENCE  6"-CD-6601-A1A
    PIPE
        END-POINT   46.7733 16.3412 0.0000 6.0000
        END-POINT   65.1426 16.3412 0.0000 6.0000
        ITEM-CODE   155
        ITEM-DESCRIPTION  ...
```

A PCF gives us, per line number: every component, its bore, its item code, its end-point
coordinates, and its weight. **That is an MTO that nobody typed.** Parsing it needs no
library, no licence, and no Windows — perfect for an air-gapped Linux box.

### 1.3 What we already have (checked in the running app, not assumed)

- `openspout/openspout 4.32.0` and `league/csv 9.28.0` — **already installed**. Streaming
  XLSX/CSV readers, constant memory. No new dependency needed for Excel.
- `QUEUE_CONNECTION=database`, with `jobs`, `job_batches`, `failed_jobs` tables **already
  migrated**. Batch-import infrastructure exists and is unused — `app/Jobs/` does not exist yet.
- `documents` + `document_revisions` (CDE) with `doc_type`, `discipline`, revision state
  machine and `suitability` — isometrics and support drawings already have a home, including
  revision control.
- `subsystems` currently flat under projects (ADR-004 pending).

---

## 2. The core design problem

You listed six things — PMS, MTO, isometrics, line list, support drawings, 3D model — and it
is tempting to build six importers. That would be a mistake. Look at what they actually are:

| Artefact | Is it a *document* or *data*? |
|---|---|
| Isometric drawing | **document** (revision-controlled, issued, stamped) |
| Support drawing | **document** |
| PMS / piping material spec | **both** — a controlled document *and* a lookup table of permitted components |
| Line list | **data** — one row per line, with design conditions |
| MTO | **data** — quantities per line/unit |
| 3D model | **neither** — it is a *source* from which data is extracted |

Two distinct needs, and conflating them is the classic failure:

1. **The register of controlled documents** → already solved by the CDE. Isometrics and support
   drawings go there today with no new code.
2. **The engineering baseline** → does not exist yet. This is the new thing.

### 2.1 The insight that shapes everything: one baseline, many sources

A line list, an MTO and a PCF parse are three *statements about the same physical reality*
arriving at different times with different authority and different fidelity. The naive design
gives each its own table. Then "how much 6-inch pipe does subsystem SS-6601 need?" has three
answers and no way to choose.

So: **one `engineering_items` table** — the neutral baseline — with every row carrying
**where it came from and how much that source is trusted**:

```
tag / line_number · component_type · size · spec · material · quantity · unit
subsystem_id · facility_id
source_kind: MANUAL | XLSX_MTO | XLSX_LINELIST | PCF | NAVISWORKS_CSV | IFC
source_document_id → CDE       revision → "which issue of the iso is this from"
confidence: derived from source_kind
```

When a PCF parse and a typed MTO disagree, the system does not silently pick one. It **raises a
reconciliation item** — because on a real project that disagreement *is the finding*. It
usually means the iso was revised and the MTO was not re-issued, which is precisely the error
that puts 250 m of the wrong pipe on a truck.

This mirrors a decision already proven twice in this codebase: the movement ledger is
append-only with reversals rather than UPDATEs, and `McCertificate` snapshots its punch summary.
**Never overwrite a source's claim; record both and make the conflict visible.**

### 2.2 Revisions are the hard part, not parsing

Parsing an XLSX is a day's work. The genuine difficulty: **an isometric gets revised, and the
MTO changes after material has already been ordered.**

If import is "truncate and reload", then re-importing Rev C silently deletes the baseline that
PO-0002 was raised against, and the shortage report changes overnight with no explanation.

So every import must be a **versioned, immutable snapshot**:

```
import_batches: id, project_id, source_kind, file_name, file_hash, imported_by,
                imported_at, row_count, state, revision_label
engineering_items: ... + import_batch_id
```

and the current baseline is a **view over the latest accepted batch per scope**, with the
previous batch retained. That gives, free of charge:
- "what changed between Rev B and Rev C" — a diff, which is a **deliverable in its own right**
  and the single report most likely to sell the product;
- an answer to "which revision did we buy against";
- a safe rollback when someone imports the wrong file.

`file_hash` also makes the importer idempotent: re-uploading the same file is a no-op, not a
duplicate baseline.

### 2.3 Import must be staged, never committed straight to the baseline

A 40,000-row MTO that fails on row 39,999 must not leave 39,998 rows behind. And the user must
see what *will* happen before it happens. Therefore every import is three phases:

```
UPLOAD → PARSE+VALIDATE (staging) → PREVIEW (diff + errors) → COMMIT (one transaction)
                                                  ↓
                                          REJECT (discard batch)
```

`import_rows` holds the staged rows with per-row `status` and `error`. The preview screen shows
"1,204 new · 87 changed · 3 removed · 12 errors" and the user commits or rejects. This is the
same shape as the MR approval flow — a two-stage commitment with a human in the middle — and it
reuses a mental model the app already has.

At 40,000 rows this must be a **queued job**, not an HTTP request. The `jobs`/`job_batches`
tables are already there.

### 2.4 Mapping is configuration, not code

Every client's MTO spreadsheet has different column headings — `LINE NO`, `Line Number`,
`شماره خط`. If the column map lives in PHP, every new client needs a developer.

So: **an `import_profiles` table** holding a JSON column map per project per source kind, with a
UI to draw the mapping once. The parser reads the profile. The standards pack can ship default
profiles for `ips-iran`, `iso`, `awp` — consistent with the standing rule that the framework is
**selectable, not hardcoded**.

This also handles Persian headers, Persian digits (the `fold()` helper already exists) and
Jalali dates (`JalaliDate` already exists).

### 2.5 PMS is not a spreadsheet — it is a validator

The piping material spec is the one artefact on your list with *rules*, not just rows. A PMS
says: for service CD, class 150, size 6", the permitted gasket is X and the permitted flange
facing is Y. [Plant 3D embeds the spec so engineers can only select approved components](https://heatingformula.com/en/autocad-plant-3d-piping-routing/).

If we import the PMS as rows in a table and stop, we have a lookup nobody reads. If we import
it as a **validation rule set**, then the moment an MTO line or a PCF component names an item
code outside the spec, the system flags it — *before a purchase order is raised*.

**That is the highest-value feature in this entire ADR**, and it is nearly free once the
baseline and the PMS are both in the database. Catching one out-of-spec valve before it is
ordered from abroad pays for the software.

I would still make it **advisory, never blocking** — the same choice you already made for
material inspection, and for the same reason: real projects run deliberate concessions, and a
hard block teaches people to work around the system.

---

## 3. Proposed architecture

```
                         ┌──────────────── sources ────────────────┐
   Navisworks (Manage) ──► property CSV/XLSX ──┐
   Plant 3D / CADWorx  ──► PCF (text)        ──┤
   Engineering         ──► MTO / line list XLSX┼──► ImportBatch (staged, hashed, versioned)
   Any BIM tool        ──► IFC4 (later)      ──┤         │
   A human             ──► manual entry      ──┘         │ validate against PMS + breakdown
                                                         ▼
                                            ┌──────────────────────────┐
                                            │   engineering_items      │  ← THE BASELINE
                                            │   (neutral, sourced,     │
                                            │    versioned, scoped)    │
                                            └────────────┬─────────────┘
              ┌──────────────────────┬───────────────────┼────────────────────┐
              ▼                      ▼                   ▼                    ▼
      MTO vs procured        progress = installed   material shortage    spec compliance
      (what to buy)              ÷ baseline          (existing §5k)        (PMS check)
                                                          │
   isometrics / support dwgs ──► CDE documents  ◄──────────┘ (lot MTC already points here)
```

**New tables (7):** `import_profiles`, `import_batches`, `import_rows`, `engineering_items`,
`line_list_entries`, `pms_specs`, `pms_spec_items`.
**Reused unchanged:** `documents`, `document_revisions`, `subsystems`, `material_lots`,
`purchase_order_items`, `jobs`/`job_batches`.

### 3.1 Why `line_list_entries` is separate from `engineering_items`

A line list row is not a component — it is the **line's design envelope**: design pressure,
design temperature, fluid service, insulation, NDE class, test pressure, paint system. Those
are attributes of a *line*, and they drive hydrotest packs and NDE planning later. Forcing them
into a generic item row would mean 20 mostly-null columns on 40,000 rows.

The line list is also the natural **join key between the 3D world and the commissioning world**:
Plant 3D's Pipe Line Group tag is [unique project-wide](https://help.autodesk.com/cloudhelp/2018/ENU/Plant3D-UserGuide/files/GUID-EE5C48C9-9F4E-4ED6-BD77-079587E1C8CD.htm),
which makes it a reliable natural key — the thing ADR-004's import mapping needs.

---

## 4. What this unlocks (why it is worth doing)

| Today | With the baseline |
|---|---|
| shortage = ordered − delivered | shortage = **MTO − delivered** (what the design needs, not what someone remembered to order) |
| progress = typed percentage | progress = **installed ÷ baseline**, per subsystem, computed |
| MC gate = punch items | MC gate also sees **"3 line items never procured"** |
| "is this valve to spec?" | PMS check at import and at requisition |
| revision chaos | **Rev B → Rev C diff** as a report |
| material arrives, then we plan | **plan before material moves** — your actual requirement |

The fourth row is where your original sentence lands: *"before construction starts and before
material enters site"*. Today §5k can only answer material questions **after** a PO exists. With
a baseline, the very first thing a project does is import the MTO — and the system immediately
says what must be bought, per subsystem, before anyone has typed a requisition.

---

## 5. Risks — stated honestly

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | **NWD unreadable** | **high** | Ingest the Navisworks *property export*, not the model. Document it as a client responsibility. Do **not** promise NWD upload. |
| R2 | Client spreadsheets are chaotic — merged cells, headers on row 7, units in the header | **high** | `import_profiles` + staged preview + per-row errors. Never auto-guess silently. |
| R3 | 40k-row import times out / exhausts memory | medium | `openspout` streaming + queued batch job. Both already present. |
| R4 | Baseline and reality diverge; users stop trusting the numbers | **high** | Never overwrite: versioned batches + explicit reconciliation items. |
| R5 | Scope explosion — this is bigger than §5k | **high** | Phase it (§6). Phase 1 alone is independently useful. |
| R6 | Units: mm vs inch, kg vs te, Persian digits | medium | Normalise at the boundary; store canonical; PCF states its units in the header (`UNITS-BORE MM`). |
| R7 | Geometry expectations — users may expect a 3D viewer | medium | **Out of scope, say so now.** A web 3D viewer is a separate product decision; the in-app preview iframe has no network access. |
| R8 | RLS on 40k-row tables | medium | Same participation clause; **benchmark** — this is 10× any existing table. |

---

## 6. Recommended phasing

Each phase ships something usable on its own. I would not start phase *n+1* before *n* is green.

**Phase 1 — Excel import spine** (largest value per unit of risk)
`import_profiles`, `import_batches`, `import_rows`, `engineering_items`; the staged
upload→preview→commit flow; MTO + line list importers; the batch diff report.
*Unblocks: MTO-based shortage, planning before material.*

**Phase 2 — PCF importer**
Pure text parsing, no new dependency. Highest-fidelity source. Feeds the same baseline.
*Unblocks: an MTO nobody typed; component-level traceability.*

**Phase 3 — PMS as validator**
`pms_specs`, `pms_spec_items`, advisory compliance check at import and at requisition.
*Unblocks: catching out-of-spec items before purchase.*

**Phase 4 — Navisworks property-export importer**
Same spine, different profile; needs the model owner's CSV.
*Unblocks: object-level completeness check against the 3D model.*

**Phase 5 — IFC4 (optional)**
Only if a client actually emits IFC. Requires Python `ifcopenshell` — a **new runtime
dependency in an air-gapped install**, so weigh it against demand.

**Prerequisite:** ADR-004. `engineering_items.facility_id` needs facilities to exist, and an
MTO "per unit or pipeline" is *by definition* scoped to the breakdown node this platform does
not yet have. **ADR-004 should be decided and built first** — it is the smaller change and this
one depends on it.

---

## 7. Open questions

1. **Which Navisworks export can you actually obtain?** Selection-Inspector CSV, a Quantification
   export, or a custom .NET-API dump? The column set differs; the profile mechanism absorbs it,
   but a real sample file would let me write the parser against truth rather than guesswork.
   **A sample of each file type is the single most useful thing you could give me.**
2. **Is PCF available to you?** If your engineering contractor works in Plant 3D or CADWorx, it
   is one click — and it is a far better source than any spreadsheet. This could reorder the
   phases.
3. **Does the MTO arrive per subsystem, or only per line/unit?** If it does not carry a subsystem
   column, the baseline cannot feed the MC gate until someone maps lines to subsystems — that
   mapping is then a feature in itself.
4. **Is a 3D viewer expected in the browser?** I have scoped it **out**. If it is genuinely
   wanted, that is its own ADR (glTF conversion pipeline, storage, an offline viewer bundle).
5. **PMS: real spec rules, or just a permitted item-code list?** The latter is a week; the
   former (service × class × size → permitted components) is a small rules engine.

---

## 8. Recommendation

Build **Phase 1** after ADR-004 lands. Design the baseline table for multiple sources from day
one — that costs nothing now and is expensive to retrofit. Be explicit with users, in the UI and
the docs, that **the 3D model is ingested as a property export, not as an NWD**, so no one
builds a workflow on a promise the format cannot keep.

**Nothing has been implemented.** Say the word and I will start with the ADR-004 migration, then
Phase 1's staged importer — with an uneven fixture (a clean file, a file with bad rows, and a
re-import of a revised file) because otherwise the reconciliation rules are untestable.

---

## Phase 2 — BUILT (2026-09-18): the PCF importer

**2026-09-19 — §2.1/§2.2 (the neutral baseline and reconciliation) are now
BUILT.** `baseline_batches` / `engineering_items` / `baseline_discrepancies`
exist with RLS; `BaselineService` imports an MTO, takes off from the model,
reconciles within a configurable flat tolerance and reports shortages.
Per the user's ruling §4 is implemented in its strongest form: **no source
wins automatically** — both claims are recorded and the item has no agreed
quantity until a person resolves the finding with a written reason. The one
open piece of §7 phase 3 is IFC/Navisworks CSV ingestion.

**Status of this ADR moves from PROPOSED to PARTLY BUILT.** Section 1.1's
conclusion stands unchanged and unchallenged: NWD/NWF/NWC cannot be parsed, so
the import path is the Piping Component File. Nothing about Navisworks was
attempted.

### What was built

`PcfParser` (pure, no database) → `PcfVocabulary` (PCF names → our 20
templates) → `ModelImportService` (diff, apply, build joints) → the
`model_imports` / `model_import_diffs` tables → a screen at
`/projects/{code}/piping/model-import`.

Components are created through `ComponentService::place()` and joints through
`JointService::join()` — the same paths hand modelling uses. **The importer has
no privileged route into the model.** An imported joint is judged by the
compatibility engine exactly as a typed one is; otherwise an import could book
dia-inch for a butt weld onto a flange, i.e. a weld that cannot exist, on an
invoice that would be paid.

### Three user rulings (2026-09-18)

1. **Re-import diffs, never destroys.** A component whose joint is FITTED, or
   which somebody has edited by hand, is left alone and written to
   `model_import_diffs` as PROTECTED with a before/after. The model does not
   get to rewrite a weld the field has already made. Components absent from a
   new revision are reported as REMOVED_IN_MODEL and **never deleted** —
   deletion has consequences for the take-off and for material already bought.
2. **An unknown line number is quarantined.** Not refused (the model would then
   be unloadable until the line list is perfect, which never happens) and not
   silently accepted. The line is created, flagged `is_quarantined`, kept out
   of progress, and admitted only by an explicit human act.
3. **Imported objects stay editable but get flagged.** `manually_edited_at` is
   what lets the next import distinguish "the model changed" from "a person
   changed this", instead of picking a winner in silence.

### Findings worth keeping

- **Real PCFs indent the pipeline header's own fields** under
  `PIPELINE-REFERENCE`. Read as orphans, the file appears to have no piping
  spec — and the import then *succeeds* with a blank spec, which is worse than
  failing. Caught by the fixture, not by reading the spec.
- **`UNITS-BORE` is refused when absent, never defaulted.** INCH-SIXTEENTH read
  as INCH turns a 6″ line into 96″ and every downstream number stays plausible.
- **`MM-HUNDREDTHS` is real** (AVEVA). Coordinates are converted to millimetres
  once, at the boundary; storing file units would make two files of differing
  `UNITS-CO-ORDS` incomparable, which is exactly when connectivity matters.
- **`TEE-SET-ON` is an OLET, not a TEE.** A stub-in has one weld; a fitting tee
  has three. Mapping it to TEE would inflate the weld count and buy a fitting
  nobody needs.
- **Three ports at one coordinate make no joint.** Impossible in real pipework;
  it means the model has stacked components. Choosing two of the three would
  fabricate a weld.
- **A refused joint does not abort the import.** A butt weld onto a flange is a
  finding about the pipework, not a failure of the importer; the rest of the
  file lands and the refusal is reported.
- **System-generated reasons are stored as machine keys, not `__()` output.**
  Translating at write time freezes the importer's language into the row, so a
  Farsi user reads an English explanation. Found on the Farsi screenshot, not
  by a test. (User-typed reasons remain verbatim — translating a person's own
  words would be a forgery.)

### Still not built

Excel/MTO ingestion, IFC, the engineering-baseline reconciliation of §3 (a line
list, an MTO and a PCF are three statements about one reality and must be able
to disagree visibly), and supports — recognised by the vocabulary as
`NOT_MODELLED` and deliberately left to phase 5.

---

## 8. 2026-10-02 — MTO material-management continuation and shortage measures

**Status: user-confirmed design decisions; implementation is not complete.** This dated amendment clarifies the earlier shorthand in §§0 and 5; it preserves their history rather than silently replacing it.

### Accepted scope and source principles

- The user resumed MTO work as part of material-management completion. Current delivery focus is **piping**; other disciplines are later scope.
- For each process unit and discipline there may be one or more MTO documents/sub-MTOs. The piping examples include pipes, fittings, flanges, valves and other project-needed component-material categories. The current piping phase excludes non-component paint/coating, insulation and welding consumables; their quantity basis is deferred to a later design (ADR-008 §11.20.2). ADR-008 §11.20 governs the known scope/category defect and its project-configuration design; that work was previously deferred and is now reopened for design, not declared implemented.
- The two quantity sources named by the user are an approved project MTO and a net engineering estimate from the 3D model or manual data based on isometric drawings. The user clarified that approved MTO quantity may be a gross procurement quantity, intentionally above net project need. Preserve both bases and provenance. The numerical difference is not automatically a discrepancy, over-order or an allowance percentage; do not collapse the values. ADR-005 §2.1's no-silent-winner/reconciliation rule applies when claims have comparable quantity bases. The latest accepted batch per scope remains the current source baseline under §2.2.
- MTO material specifications must reference the material library. An unmatched item may remain in DRAFT for resolution, but cannot be accepted/issued until mapped to a valid library item. An accepted MTO must retain the exact library specification/version used; later library changes require a new MTO revision.
- An accepted MTO is immutable. A later model refresh/manual change creates a new draft revision; compare it with the prior claim, preserve manual-only rows and edits, and leave conflicts unresolved for human disposition. Acceptance of the new revision does not silently rewrite the prior revision's MR/PO history. For now, the MR number is informational in the MTO header only; exact MTO-row↔MR linkage is deferred.

### Piping quantities and roll-ups

- Each piping line has exactly one piping class. Class is associated with the line and is available as a reporting/grouping dimension between subsystem and lines; it is not a second physical location node.
- Pipes MTO retains gross procurement total length and procurement-branch count separately from net model/isometric need. Model-segment count is not an MTO quantity. The simple automatic branch estimate uses gross MTO length divided by the explicitly configured project supply length, rounded up; a manually entered branch count is preserved rather than silently overwritten. If no applicable project supply length exists for an item, no default is assumed: automatic estimation is unavailable and a manual branch count is required for a complete MTO row. This is not a cutting optimization.
- Need/shortage reporting is required at piping-class, subsystem, system and process-unit levels. Unit, system and subsystem are physical roll-ups; class is a separate grouping dimension.
- For each process unit, the project-defined set of in-scope component-MTO categories must be covered by an ACCEPTED MTO/sub-MTO or an explicit out-of-scope record. Missing categories are coverage gaps, not zero need; DRAFT does not count as accepted coverage. The user accepted an independent, project-configurable scope authority: the current owner-company administrator with `project.configure` configures the project list of authorized people/roles, but gains no automatic technical scope authority unless nominated. The same nominated engineering authority confirms the initial in-scope component-category set per process unit and may declare a category out of scope. Without a nominee/confirmed category set, coverage is unconfirmed, not zero or complete. Every out-of-scope declaration requires a written reason and, when based on a project document, a citation to that document. If a category later returns to scope, preserve the earlier out-of-scope record and reopen the coverage gap until an MTO is accepted; do not overwrite/delete the prior record. Version the per-unit category scope: adding a category opens a gap until accepted MTO exists; removing one follows the out-of-scope process; historical scope/coverage is never rewritten. Detailed workflow remains open. ADR-008 §11.20.3.

### Two distinct shortage indicators — accepted 2026-10-02

1. **Order gap:** compare gross quantity from the accepted MTO with net quantity on valid issued/in-force POs, for the same material, scope and compatible unit. The unprocured gap is `max(MTO gross − net PO, 0)`. If net PO exceeds gross MTO, show the amount above the procurement plan separately, never as negative shortage; do not automatically change or cancel the PO. Draft and cancelled orders do not count. An effective PO amendment replaces/supersedes the prior quantity; it is not added to it.
2. **Receipt/usable-material gap:** required quantity compared with received material that is available for use. This is distinct from the order gap; an issued PO is not physical stock. The operational definition of “available for use” (including inspection, quarantine, rejection and reservation states) was explicitly deferred by the user on 2026-10-02; no proposed state filter is accepted yet.

These clarify, rather than erase, the original `MTO − received` measure in §0. The user requires an order gap and a separate receipt/usable-material gap. PO quantity above gross MTO is shown separately as above-plan procurement, never as negative shortage, and causes no automatic PO change. Exact stock reservation/transfer allocation and the states qualifying as usable remain deferred.

### Unit comparability — accepted 2026-10-02

Compare need and order only for the same material identity and scope, and in compatible units. To support shortage roll-ups without an MTO-row↔MR link, each future PO line must be attributable to the process unit, system, subsystem and piping class as applicable, as well as the material-library item. This is scope attribution, not an MTO source link. Missing or ambiguous scope stays unallocated; the system must not prorate or infer it. For Pipes MTO, branch-count and total-length gaps remain separately available. A branch-to-length comparison/conversion is allowed only when the applicable supply length for that material/order is explicit; if it is unknown or differs without a defined basis, do not infer a conversion—show the unit-specific quantities separately and mark the cross-unit comparison as unavailable. This is a domain conversion, not a generic unit-pack conversion; see ADR-014 §15 and ADR-018 §7.

### Quantity basis — accepted 2026-10-02

The approved project MTO quantity is the gross procurement-planning quantity and may exceed net project need. The 3D-model/isometric/manual engineering estimate is the net need. Preserve them as separate values with their bases; do not automatically infer a waste/allowance percentage or cause. When material identity, scope and units are comparable: if gross MTO is below net need, the positive difference (`net need − gross MTO`) is a purchase-plan shortage; any positive amount counts, with no project tolerance suppressing it. If gross MTO is equal to or above net need, show the positive difference as a quantity only, not as an error, percentage or inferred cause. Where no accepted MTO exists for a matching unit/category/scope, the net engineering estimate is shown as provisional only and is not an official gross-MTO order-gap baseline. Where an accepted MTO exists, gross MTO is the order-gap basis and net need remains separately visible. Apply this per scope/category, not as a project-wide all-or-nothing switch. The physical requirement gap, when later defined, must use the net-need basis; treatment of usable stock remains deferred.

### Exact row correspondence between MTO and net engineering estimate — accepted 2026-10-05

For the gross-MTO versus net-model/isometric comparison, pair rows only by the exact composite identity: project-scoped MTO source document/sub-MTO + its accepted revision or immutable batch identity + MTO item number (`mto_item_no`). The row number is distinct from the material-library item ID and is not assumed globally unique across documents or revisions. The component/engineering record must retain a stable reference to the exact immutable accepted MTO row; that reference must resolve the full composite identity. A bare item number is insufficient. The user selected a direct foreign key/reference to the stable identifier of the exact immutable accepted MTO row, targeting a separate accepted-row snapshot store; DRAFT rows remain in staging and cannot be FK targets. The row code remains available for display, not as the sole join key. Snapshot rows are immutable and resolve the complete composite identity. The acceptance-to-snapshot transition is all-or-nothing per one MTO document/sub-MTO revision: validate every row in that submission, create all its immutable accepted-row snapshots and mark only that batch accepted in one transaction. If any row validation or snapshot write fails, roll back all snapshots and keep that batch DRAFT. Other MTO documents/sub-MTOs for the same unit or discipline are independent; accepting one does not close the others' coverage gaps. Physical table/column names and transaction implementation remain to be designed. Do not substitute a fuzzy match on description, library material code, size or unit. If any part of the identity is absent, keep the estimate unmatched/unmapped; do not infer a row-level shortage or silently merge it with another MTO row or revision. This component/model-to-MTO correspondence does not create a row-level MTO↔MR/PO link; those remain separately deferred. See ADR-006 §10.8, ADR-008 §11.20.5 and ADR-018 §9.1.

### Aggregating distinct MTO documents/sub-MTOs — accepted 2026-10-05

For different accepted MTO documents/sub-MTOs, aggregate comparable gross quantities only when their consumption scopes are disjoint or an explicit additive relationship is recorded between the exact immutable accepted document/sub-MTO revisions. A pair-level additive declaration must include a written reason and, when document-based, cite the exact immutable project-document revision—not only its document number or title. If the source is registered in project CDE, store a stable reference to that exact CDE revision; number/title are display-only. For a source outside CDE, allow a controlled external-evidence record with a stable ID, issuer/source, exact document number/revision/date, and retained original file or controlled immutable URI plus content hash; the hash alone is not sufficient. If the exact version cannot be retained and retrieved, it cannot support the additive declaration and the overlap remains ambiguous. It applies only in the revisions' overlapping scope and only to rows matching on material-library identity and compatible units; rows with unresolved material/scope/unit correspondence remain ambiguous/unallocated. Without a declaration for overlapping comparable scope, do not auto-sum. A successor revision does not inherit the relationship automatically. Additive declarations are append-only: do not edit/delete a prior declaration; corrections or withdrawal are new superseding decision records. Historical roll-ups retain the relation state known at their calculation time; current roll-ups use the latest effective declaration for the exact revision pair, and a withdrawal leaves overlap ambiguous/unallocated. Revisions of the same document follow §2.2 current-baseline/supersession rules; prior immutable snapshots remain historical and are not added. This does not create MTO-row↔MR/PO linkage. See ADR-008 §11.20.6 and ADR-018 §9.2.

### Scope overlap for MTO roll-ups — accepted 2026-10-05

For physical scope, a parent-level MTO at process-unit or system level overlaps its descendant system/subsystem scopes; do not sum parent and child quantities without the explicit additive declaration described above. Separate/disjoint leaf subscopes may be summed at parent roll-up. Piping class is an independent grouping dimension, not a physical hierarchy node. This rule clarifies scope overlap for the sibling-document aggregation decision; it creates no MTO-row↔MR/PO link.

### MTO scope attribution and acceptance — accepted 2026-10-06

The accepted rules for MTO physical-scope identity, assignment, validation and roll-up are:

1. **Physical hierarchy:** Scope is represented by project physical-domain nodes such as process unit, system and subsystem. Piping class is an independent grouping dimension, not a hierarchy level.
2. **Parent/child overlap:** A parent unit/system MTO overlaps its descendant system/subsystem scopes; do not sum parent and child quantities absent an explicit additive declaration. Separate, disjoint leaf subscopes may be summed at a parent roll-up. See ADR-008 §11.20.7 and ADR-018 §9.3.
3. **Header scope:** Store scope at the MTO document/sub-MTO header and inherit it only when all rows share that same physical scope.
4. **Mixed-scope documents:** If rows have different physical scopes, each row must carry its explicit scope.
5. **Narrowing within a header:** A row-level descendant scope may refine a parent scope at the header. A row scope outside the header boundary is a conflict; do not silently widen the header or reassign the row.
6. **Unresolved scope:** Missing or ambiguous scope remains unallocated; do not infer scope or roll the row into another domain.
7. **Acceptance gate:** If a required row scope is missing/ambiguous, or a row scope conflicts with the header boundary, block acceptance of the entire MTO document/sub-MTO revision. Keep that batch DRAFT and create no partial accepted-row snapshots; apply the existing all-or-nothing acceptance rule. Correct the scope and revalidate the same batch. Sibling MTO documents/sub-MTOs remain independent.
8. **Stable identity and historical preservation — accepted 2026-10-06:** Resolve physical scope using a stable identifier in the authoritative project scope hierarchy, not free text as the operative identity. The immutable accepted-row snapshot preserves the exact scope reference used at acceptance; later hierarchy changes must not rewrite the historical row-to-scope assignment. An unresolved reference follows rules 6–7.

These are accepted design rules, not implemented behavior; physical schema and hierarchy-version mechanics remain open. See ADR-008 §11.20.8, ADR-018 §9.4, ADR-RECONCILIATION §33 and DR-10.

### Scope-tree revision and cross-version roll-up — accepted 2026-10-06

The user accepted the following recommendations. They refine, but do not replace, the stable-scope-ID and historical-preservation rules above:

1. **Pin the scope-tree revision:** Maintain identifiable project physical-scope hierarchy revisions. Each accepted MTO snapshot records the hierarchy revision used to validate its scope ID and parent/descendant relationship. Historical roll-ups use that pinned revision; do not reinterpret old MTOs using only today's tree. This makes historical overlap and roll-up results reproducible.
2. **Protect node identity and historical display:** Never recycle a physical-scope ID. A rename may keep the ID, while reparenting, split or merge creates a new hierarchy revision rather than overwriting prior relationships. Preserve the label/path as it was at acceptance for human-readable historical display; labels and paths are not matching keys. This preserves traceability without making mutable text authoritative.
3. **Constrain cross-revision aggregation:** Auto-roll up MTOs tied to different hierarchy revisions only where stable IDs and the relevant pinned hierarchy relationships establish an unambiguous common scope. Otherwise leave the affected amount unallocated; cross-revision equivalence requires an explicit immutable scope-mapping/evidence decision, with no inference from names or paths. This does not replace the separate additive-declaration rule between exact MTO revisions; it prevents accidental aggregation across reorganized scopes.

These are accepted design rules, not implemented behavior. The concrete physical schema and mechanism for creating/retaining hierarchy revision identifiers remain open.

### Cross-version scope-mapping semantics — accepted 2026-10-06

The user accepted the following limits for the explicit immutable scope-mapping/evidence decision described above:

1. Each mapping names the exact source scope ID and source hierarchy revision, exact target scope ID and target hierarchy revision, relationship type (equivalent/reparented/partition/merge), written rationale and an exact immutable evidence reference when document-based. The decision record is append-only; correction/withdrawal uses a superseding record.
2. A one-to-one mapping may establish scope continuity only when evidence supports equivalence. Split/merge mappings do not auto-resolve scope or authorize roll-up; permit them only with an explicit demonstration of complete and non-overlapping correspondence. Otherwise affected quantities remain unallocated.
3. Scope mapping establishes physical-domain correspondence only; it does not authorize adding quantities from different MTO revisions. The existing additive declaration between exact MTO revisions remains separately required.

Rationale: distinguish scope identity correspondence from quantity additivity and prevent names, hierarchy restructuring or a broad mapping from silently double-counting. These are accepted design rules, not implemented behavior. No mapping schema or access authority is selected.

### Mapping lifecycle and chaining — accepted 2026-10-06

The user accepted the following rules for the temporal use of scope mappings:

1. Historical roll-ups preserve the mapping state known when calculated; current roll-ups use the latest effective record for the exact source/target scope IDs and hierarchy-revision pair.
2. A superseding withdrawal leaves current cross-version scope unresolved/unallocated; it does not rewrite a previously calculated historical roll-up.
3. Do not infer a transitive mapping across multiple hierarchy revisions (A→B→C). Require an explicit direct mapping from the exact source scope/revision to the target scope/revision, unless stable identity and pinned hierarchies already establish an unambiguous correspondence without a mapping.

Rationale: keep historical results reproducible and avoid composing one-to-many or stale relationships into an unapproved equivalence. Accepted design; not implemented.

### Process-unit hierarchy changes and MTO coverage — accepted 2026-10-06

The user accepted the following rules for structural changes affecting per-process-unit component-category coverage:

1. A display rename that preserves the stable process-unit ID does not change its versioned category scope or create a new coverage gap. Changes below the process-unit level remain governed by the accepted hierarchy-version and scope-mapping rules.
2. A split, merge or replacement that creates new process-unit IDs does not automatically transfer the prior unit's in-scope/out-of-scope category declarations or accepted-MTO coverage to successor units. Each successor unit requires its own versioned category-scope confirmation.
3. For every successor unit, coverage remains unconfirmed—not zero or complete—until each confirmed in-scope component category has an accepted MTO/sub-MTO or explicit out-of-scope record. A cross-version scope mapping alone establishes domain correspondence but does not close that coverage requirement; an old MTO does not automatically become accepted coverage for each successor unit.

Rationale: preserve per-unit coverage integrity and avoid silently assigning aggregate quantities or technical scope decisions to newly defined units. Accepted design; not implemented. This does not settle access roles or implementation.

### Immutable roll-up calculation provenance — accepted 2026-10-06

The user accepted the following reproducibility rules for MTO roll-ups:

1. Treat each saved historical roll-up as an immutable calculation snapshot. Record the exact accepted MTO revisions/batches and accepted-row snapshot IDs used; the scope IDs and pinned hierarchy revisions; effective additive declarations and scope mappings; material-library identity and compatible-unit/conversion basis; calculation timestamp and rule/version identifier.
2. Preserve both the result and its provenance, including unresolved/unallocated inputs and reasons. Do not edit or silently recalculate an existing historical result; a correction or new calculation creates a new snapshot while retaining the prior one.
3. A current roll-up is a new calculation over the latest effective accepted MTOs, mappings, additive declarations and hierarchy revisions. Unresolved or unallocated amounts remain visibly separate and are never treated as zero or silently included in totals.

Rationale: make historic totals auditable and reproducible when MTOs, hierarchy, mappings or declarations later change. Accepted design; not implemented. Table names, physical storage and calculation authority remain open.


## 9. 2026-10-02 — MTO permissions and access levels deferred

The user directed that current work continue on system logic and general principles, with permission/access-level decisions deferred until later. Do not treat any proposed MTO acceptance role, uploader/reviewer separation, nominated scope-authority mapping or configuration grant as settled for implementation. Earlier accepted principles about project-configurable scope authority remain documented as history, but their operational role/grant details are paused pending a later access review. This does not reverse or reopen the already delivered MR-01/MR-02 policy unless the user explicitly does so. MTO create/edit/accept/resolve and scope-declaration access decisions remain deferred; no new access-control code is authorized by this design note.

