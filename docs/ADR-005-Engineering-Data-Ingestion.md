# ADR-EPCC-005 — Engineering data ingestion: Excel, 3D models, and the engineering baseline

**Status:** PROPOSED — analysis only, nothing implemented
**Date:** 2026-09-13
**Supersedes nothing. Depends on:** ADR-004 (facility/area/subsystem breakdown — **ACCEPTED and built 2026-09-13**; this ADR's phasing is no longer blocked on it)
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
