# ADR-015 — Inspection and Test Plans (ITP)

**Status:** PARTLY BUILT — incoming material inspection implemented; fabrication
and installation stages deferred.
**Date:** 2026-09-19
**Supersedes:** nothing. Extends the advisory inspection recorded in
`MaterialInspection` (see §6).

---

## 1. Context

Every discipline on an EPCC project works to an Inspection and Test Plan: a
numbered table of inspection activities, each with acceptance criteria, an
inspection method, a frequency, the record it produces, and — against every
participating party — an intervention level.

The platform already had a `MaterialInspection` record: one free-text verdict
(PASS / FAIL / CONDITIONAL) at one of three stages, attached to a purchase
order, deliberately advisory. That is a useful note. It is not an ITP, and it
cannot answer any of the questions a QA manager actually has:

- Which activities must be inspected for this material, and against what
  criterion?
- Who must be *invited*, and who may *stop the work*?
- Has the invitation been served, and can we prove it?
- May this material be installed yet?

## 2. Decision

### 2.1 The intervention vocabulary is H / W / M / R

Industry-standard and stable across clients. Some add `A` (approve document) and
`RE` (records only); these are folded into `R`, because a party that reviews a
document and one that approves it differ by *authority*, which already lives in
the role model, not by *activity*.

| Code | Meaning | Enforcement |
|---|---|---|
| `H` | Hold | Blocks release. Waits indefinitely. |
| `W` | Witness | Invitation mandatory; absence does not stop work. |
| `M` | Monitor / surveillance | Nothing waits. |
| `R` | Review | Document review only. |

**H versus W is the entire commercial content of an ITP.** It decides who bears
the cost of standby when an inspector does not appear, and it is litigated. A
boolean `requires_inspection` erases precisely the distinction that matters, so
the codes are first-class and `InterventionCode::blocks()` is the single
enforcement surface.

### 2.2 A hold point blocks RELEASE, not the goods receipt

**The user's explicit ruling.** Material arrives at 2am; the third-party
inspector signs on Sunday. A system that refuses the *receipt* is bypassed with
a paper note that never gets typed in — and then the warehouse ledger is a
fiction, which is a worse outcome than a late inspection.

So the gate sits in `WarehouseService::issue()`: the material is received,
counted and visible in the store, and may not leave for installation until every
hold point is released. Checked inside the transaction and after the row lock,
so a concurrently released hold cannot be read stale.

This preserves the earlier decision (ADR: advisory inspection) intact: the
free-text `MaterialInspection` remains advisory. Only an ITP hold point gates,
and only at the issue.

### 2.3 Piping and Pipeline are separate disciplines

Not cosmetic. Plant piping is ASME B31.3: lot-based inspection, PMI critical for
alloy service. A cross-country pipeline is B31.4 / B31.8 with API 5L line pipe:
what matters is heat traceability, end-bevel condition and coating holiday
detection — none of which appears on a plant piping ITP. One merged plan would
impose one set of hold points on both, and the first thing a real QA manager
would do is override it.

Enforced by a partial unique index on `(project_id, discipline) WHERE state =
'ACTIVE'`. Per-discipline, not per-project: otherwise activating the pipeline
ITP would silently retire the piping one.

### 2.4 The party grid is keyed by tenant type, not company

An ITP is written before the TPI firm is appointed, and it survives that firm
being replaced mid-project. Binding the grid to a company row would make a
signed quality document depend on a procurement decision that has not happened
yet. The concrete company is resolved when a stage is signed and stored on the
record (`signed_company_id`, `signed_tenant_type`).

### 2.5 Templates are copied, never referenced

`itp_templates` is library data — no `project_id`, no RLS, like the standards
packs. Its `stages` jsonb is a *document*; nothing reads it at runtime. On use it
is copied into real rows, so improving the standard template can never alter the
signed ITP of a running project. `source_template_id` keeps the provenance.

### 2.6 WAIVED is a state, not a kind of pass

A witness point the invited party did not attend is neither passed nor failed:
the work proceeded lawfully without them.

- Recording it as PASSED asserts an attendance that never happened — a quiet lie
  that becomes expensive in a claim.
- Recording it as FAILED stops material that nothing is wrong with.

So `WAIVED` is its own outcome; it requires the notice evidence
(`notified_at` + `notice_reference`, enforced by CHECK as well as by the
service); and **it is refused for a hold point**. A hold nobody attended stays
PENDING for ever. That asymmetry is the difference between H and W, made
structural rather than advisory.

### 2.7 No approval workflow yet

Deferred by the user. `state` is `DRAFT | ACTIVE | SUPERSEDED` with deliberately
**no** APPROVED state: an unused state invites code that pretends to check it.
Adding sign-off later is a data change, not a schema migration.

### 2.8 A standard and a project spec are different columns

The user's requirement: an ITP row states its row number, activity, the
**standards**, the **related project references and specs**, the **inspection
report form numbers**, and the role of each party. The first, second and last
already existed. The middle three did not, and the naive fix — one `reference`
string holding all of it — conflates two different kinds of thing.

`ASME B31.3` is an external code. We do not own it, it is not in any register
here, it changes on a committee's schedule, and every project cites the same
string. Text is the right storage.

"Technical specification for line pipe supply, Rev. B" is a **project
document**. It already exists in `documents`, revision-controlled, and it is
what an inspector actually argues about on site. As text, the day it is
reissued at Rev. C every ITP silently keeps citing Rev. B with no way to detect
it. So project references are **foreign keys** into the register
(`itp_stage_documents`), and the citation follows the revision chain forward
unless a stage was deliberately agreed against a named revision
(`pinned_revision_id`).

Same for the report form: `QCF-PIP-014` is a controlled form with its own
revision, and handing an inspector a superseded revision is an audit finding.
A registered form wins; `itp_stages.form_number` is the honest fallback,
because on a real project most client-issued forms never reach our register.

One link table with a `usage` discriminator (`SPEC | DRAWING | PROCEDURE |
FORM`) rather than four tables: the relationship is identical in every case —
stage cites document — and only the reason differs. Four tables would
quadruple the RLS surface to express one idea.

Deletion is `restrictOnDelete`, not cascade. If somebody deletes a spec an
active ITP cites, the right answer is to refuse, not to quietly drop the
citation and leave a signed plan referring to nothing.

## 3. Schema

```
itp_templates      library: code, discipline, title, scope, stages jsonb
itps               project copy: discipline, number, state, source_template_id,
                   document_id -> documents   [nullable: see 2.9]
itp_annexes        (itp, document) -> label "A" + kind FORM|PROCEDURE|REFERENCE
itp_stages         clause, sequence, title, acceptance, method, frequency,
                   reference, record, notice_hours
itp_stage_parties  (stage, tenant_type) -> intervention   [sparse: no row = no involvement]
itp_records        (stage, subject) -> result + signature + notice + evidence
itp_stage_documents (stage, document, usage) -> SPEC|DRAWING|PROCEDURE|FORM
                    + optional pinned_revision_id + locator ("§7.3")
```

Constraints worth naming:

- `itp_records_signed_when_settled` — a settled verdict must carry signer,
  time and party. A PASSED row with no signer is not evidence; it is what a
  half-written importer produces.
- `itp_records_waiver_needs_notice` — stops WAIVED becoming a synonym for
  "skip this stage".
- `itp_records_subject_present` — written as an equivalence so adding `WELD`
  later cannot accidentally inherit the material-lot requirement.

### 2.9 The ITP is itself a project document; the forms are its annexes

Until now the plan carried its own `number` and its own DRAFT/ACTIVE/SUPERSEDED
lifecycle, running beside the document register rather than inside it. That was
wrong, and wrong in the way parallel registries are always wrong:

- the plan never appeared on a transmittal, so there was no record of issuing
  it to the client;
- it was absent from the MDR, which is precisely the list the client audits;
- two numbering schemes drifted apart, and the number printed on a signed
  report matched neither;
- it sat outside CDE governance, so "which revision was in force when clause
  1.4 was signed?" had no answer the register could give.

So `itps.document_id` points at the register entry, and the register owns what
a register owns: **the number, the revisions, the CDE state, the transmittals**.
The `itps` row keeps what is specific to an inspection plan: the discipline, the
stages, the party grid, the release gate.

Two consequences are deliberate and easy to get wrong:

**`document_id` is nullable.** A plan copied from the library before anyone has
assigned it a number, or imported from a legacy project, must still exist.
`Itp::displayNumber()` prefers `document->number` and falls back to the working
label, and the screen says plainly when a plan is not yet in the register — an
unregistered ITP is a draft the QA team is editing, not a controlled document.
The FK is `nullOnDelete`: deleting a register entry must never destroy signed
inspection records.

**`cdeState()` is not merged into `state`.** They answer different questions.
`state` is the gate: which plan is in force for this discipline, the thing
`releaseAllowed()` consults. `cdeState()` is publication: WIP → SHARED →
PUBLISHED. A plan can legitimately be ACTIVE on site while its document is still
SHARED awaiting the client's stamp. Collapsing them would let a publication
workflow silently switch off a hold point, which is the one failure this whole
module exists to prevent.

**Annexes are not the same as row citations.** `itp_stage_documents` with
usage FORM answers "which form does clause 1.4 use?" — per row. `itp_annexes`
is the annex list printed on the cover sheet: the blank forms that travel with
the plan when it is issued. A forty-row ITP cites a form forty times and has
three annexes. `syncAnnexesFromStages()` derives the second from the first,
labelling A/B/C in first-use order, so the two cannot drift.

`itp_annexes.document_id` is `restrictOnDelete`: an annex of an issued plan is
part of that plan.

### 2.10 Only the employer, the MC and the consultant assign party roles

The party grid is an allocation of authority, not an engineering detail. A TPI
that can edit it can write itself out of a hold point; an EPC that can edit it
can remove the client's witness. Either defeats the purpose of having an ITP.

Until 2026-09-20 there was **no authorisation on ITP authoring at all** — a
third-party inspector could copy a template and assign every party its
intervention codes, demonstrated on the fixture.

`TenantType::MAY_ASSIGN_PARTY_ROLES = [OWN, MC, PMC]`, enforced by
`ItpService::assertMayAssignPartyRoles()` on `createFromTemplate()` and on
`addStage()` when a grid is supplied. Everyone else reads the plan and signs
the rows they are nominated on.

The check is on the acting company's `tenant_type` rather than a role code:
the rule is about which firm this is, not which permission an administrator
happened to grant a user inside it.

### 2.11 Row-level security: reading is membership, writing is ownership

The six ITP tables shipped with a policy naming a column that does not exist
(`project_memberships.company_id`). Postgres resolved it against the outer
table instead of erroring, degenerating the policy to "rows my own company
created" — so the nominated TPI saw **nothing**. See HANDOFF §5ad for the
forensics.

Repaired so that **reading follows project membership** and **writing the plan
text is restricted to the owning company**. `itp_records` is the deliberate
exception: any member may write, because a signature is an UPDATE by a company
that is *not* the record's owner, and the party grid — not RLS — decides who
may sign which row.

## 4. Consequences

**Good.** The gate is real and enforceable without fighting how a site works.
"Where does the TPI hold in this project?" is an indexed query. A revised ITP can
add a stage mid-project and `openForLot()` back-fills only what is missing.

**Costly.** Five tables and a party grid is more machinery than a checkbox. The
justification is that the checkbox cannot express H versus W, and that
distinction is the reason the document exists.

**Open.** Subjects other than material lots (welds, spools, subsystems) —
`subject_kind` is present and constrained to `MATERIAL_LOT` so the widening is a
one-line CHECK change. Notification *delivery* (the platform records that an
invitation was served and its reference; it does not send it). The approval
workflow of §2.7.

## 5. Alternatives rejected

- **A boolean `requires_inspection` on the material.** Erases H versus W.
  Rejected outright.
- **Blocking the goods receipt.** Rejected by the user for the 2am-truck reason;
  it is the classic control that gets bypassed on paper.
- **One ITP per project covering all disciplines.** Rejected: a piping hold point
  has no business gating a civil pour.
- **Referencing the library template live.** Rejected: a signed inspection plan
  that changes when somebody edits a standard is not a contractual document.
- **`jsonb` for the party grid.** Rejected: it cannot answer "which stages does
  the TPI hold", which is a daily question.

## 6. Relationship to `MaterialInspection`

Unchanged and still advisory. It records a one-line verdict against a purchase
order and is denormalised onto the lot for visibility. An ITP record is a
different thing: one *stage* of a *plan*, signed by a party that holds a role at
that stage. The two coexist; only the ITP gates.

## 7. DR-01 reference-basis integration (2026-09-29)

ADR-016 §8/§10 governs standards-reference capture when a registered ITP's CDE revision enters PUBLISHED. ACTIVE still controls the inspection gate, not publication. This increment freezes only the standard-reference basis, not the complete ITP row/party/inspection record history. Missing reference editions/unresolved standards prevent publication, not draft transcription or automatic gate-state changes. Legacy finalized revisions are explicitly uncaptured; no catalogue-derived historical backfill. Existing ownership/view/issue policies remain, and DR-02 adoption authority is still deferred.


## 8. PMS NDT requirements excluded from material inspection (2026-09-29)

Explicit user correction: **«الزامات NDT را از حوزه بازرسی متریال خارج کن»**, following selection of NDT/PMS–ITP work. Neither offered incoming-only nor full fabrication/installation option was selected. ADR-008 §24 records the accepted boundary.

PMS NDT requirements must not be routed through this ADR's currently implemented incoming-material/PO/lot inspection and warehouse-issue gate just because itp_stages has similar fields. PMS remains the source of requirements (§11.8 of ADR-008); an appropriate non-material ITP consumer may cite that source, but its implementation boundary/lifecycle is not supplied by the old incoming workflow. Existing template-copy rules do not authorize an independent editable copy of PMS requirements.

No existing material-inspection, PMI stage, signed record or release condition is deleted/reclassified by this documentation change. No automatic expansion of roles, activation/sign-off authority, construction/installation execution or PMS publication/revision scope. This is an accepted scope correction, not completed runtime separation or NDT implementation.


## 9. Accepted separation of requirements, planning and execution (2026-09-29)

User accepted the staged recommendation with «خوبه ادامه بده»: PMS owns the NDT requirement; the appropriate non-material ITP references it and owns execution planning/intervention information, not an independently editable requirement copy. Execution/results, defects, repairs and retests belong to independent NDT execution work; changing a requirement must not silently rewrite earlier test bases. Immediate authorized increment is structured PMS requirement entry (ADR-008 §25), not implementation of that later execution lifecycle or release/revision system. Existing incoming-material ITPs, inspection records, PMI and warehouse gates remain untouched.


## 10. ITP work paused; material-management priority (2026-09-30)

After selecting continuation of NDT/ITP, the user was shown the existing single-ACTIVE-per-project/discipline conflict and asked about explicit domains versus a separate NDT plan. User selected neither and instructed: **«ITP رو بذار برای بعد. فعلاً می‌خوام چرخه مدیریت متریال رو کامل کنیم»**. Pause ITP integration and domain redesign. No domain option is approved; no activation/index/warehouse-gate changes authorized by that unanswered question. Existing PMS NDT entry remains, outside material inspection. Current work priority is completing the material-management lifecycle; identify real service/UI gaps before choosing implementation. No runtime change or new tests in this scope-change entry.
