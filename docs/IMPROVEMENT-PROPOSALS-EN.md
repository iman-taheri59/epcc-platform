# Improvement Proposals — EPCC Platform

**Standing rule 5 (user-mandated, 2026-10-11):** whenever a better alternative
exists for a requirement, a piece of code or a capability, it is voiced here —
never silently skipped. Cheap, safe fixes are implemented on the spot and marked
`BUILT`; anything touching scope, schema or commercial terms stays `OPEN` until
the user decides. Each entry is short on purpose; the load-bearing detail lives
in the linked doc or code.

Statuses: `OPEN` (awaiting decision) · `ACCEPTED` (decided, not yet built) ·
`BUILT` (done) · `REJECTED` (considered and refused — kept so it is not raised
again).

Last updated: **2026-10-11** (second pass — full documentation review, ~11,600 lines).

---

## Summary

| ID | Area | Proposal | Status |
|---|---|---|---|
| P-01 | Commissioning | Wire `blocksReadyForStartUp()` into a real RFSU gate — it is dead code today | OPEN |
| P-02 | Docs / entry points | Replace the stock Laravel `app/README.md` with a project-specific one | OPEN |
| P-03 | Docs / entry points | Replace Laravel-Boost boilerplate in `app/AGENTS.md` / `app/CLAUDE.md` with repo conventions | OPEN |
| P-04 | Docs / language | Create `docs/TODO-EN.md` — the status register is Persian-only, rule 2 needs an English record | OPEN |
| P-05 | Requirements / ADR-015 | The four Slice 6 open questions — all answered by the user and recorded in the ADR | BUILT |
| P-06 | Prototype / UX | Order-Gap-Board prototype screen built and jsdom-pinned **before** coding Slice 6 | BUILT |
| P-07 | Commercial | `demo:seed` artisan command for air-gap sales demos | OPEN |
| P-08 | Performance | Order-gap values must be snapshot-computed, never live-aggregated (RLS scan penalty) | OPEN |
| P-09 | CI | Add a Docker-less "sandbox tier" CI job running `scripts/run-tests.sh` | OPEN |
| P-10 | Process / HANDOFF | Archive the session-log table when HANDOFF grows past ~3,000 lines | OPEN |
| P-11 | Tests | `npm run test:prototype` was missing the 324-combination render suite | BUILT |
| P-12 | Tests / tooling | `prototype/test/run-all.sh` was stale — now a wrapper over `scripts/run-tests.sh` | BUILT |
| P-13 | Docs accuracy | HANDOFF §3 wrongly listed PDF export and PROCUREMENT as absent | BUILT |
| P-14 | Docs index | README contents table was missing ADR-015 | BUILT |
| P-15 | Docs drift | Deep SRS alignment pass: release-phase tags per FR, launch pack list, BR tiers (banner landed already) | OPEN |
| P-16 | Docs drift | Parent-document dated corrections from the 2026-10-11 full review pass (SRS banner, AD-1, ADR-006 tail, ADR-005 dep, ADR-001/002 status, Iran-First §10 item 2) | BUILT |
| P-17 | Defect / scaling | ADR-012 RLS escape hatch — the HIGH-severity defect; all 33 policies rewritten sargable + indexes + `RlsSargabilityTest` | FIX IMPLEMENTED — awaiting runtime verification |
| P-18 | Docs drift | `Prototype-Coverage-Map.md` recount after procurement + piping + MTO (audit §3 item 4) | OPEN |
| P-19 | Runtime | In-sandbox PostgreSQL provisioned (PG tier BUILT); PHP tier network-blocked — Laravel work stays on the Docker/Laragon tier | PARTIAL |
| P-20 | Code / portability | `PdfRenderer` is POSIX-only (`command -v`) — PDF export silently unavailable on Windows/Laragon | OPEN |
| P-21 | Requirement / piping | Branch welds (stub-in/stub-on P2P) must be first-class joint types before weld counts and dia-inch are built | OPEN — queued for the P5 design ADR |
| P-22 | Requirement / piping | P2P branch welds land on the header *wall*, but the joint model only connects two ports — wall-attach representation must be decided | OPEN — decision needed |
| P-23 | Requirement / MTO | MTO has no coverage of branch-connection material: reinforcement pads (plate) and coped stub ends | OPEN — scope decision |
| P-24 | Requirement / piping | Pad thickness on P2P rules must be validated per ASME B31.3 §304.3.3 (owner requirement) — calculator built, PMS wiring queued | PARTIAL — Step A built |

---

## P-01 — RFSU gate exists as dead code (commissioning)

`PunchCategory::blocksReadyForStartUp()` (`app/app/Support/PunchCategory.php`)
is implemented and tested but **called nowhere** — verified by grep on
2026-10-11. HANDOFF §9 item 5 says the same. The whole commissioning chain
stops at MCC; RFSU/PAC/FAC are unbuilt, so a project can "complete" in the
system without ever being ready-for-start-up.

**Proposal:** next commissioning slice = RFSU certificate reusing the MCC
machinery (certificate service, punch gate, employer exception) with the A+B
gate calling `blocksReadyForStartUp()`. Readiness should also feed from piping
(line released, test pack ACCEPTED, material delivered) once Slice 6 lands —
record that requirement in an ADR before building (rule 3).

**Cost:** small–medium. **Risk of not doing:** the flagship QA/QC story ends at
MCC, which is commercially weaker than the full MCC → RFSU → PAC chain Iranian
contracts expect.

## P-02 — `app/README.md` is the stock Laravel boilerplate

The application directory still ships Laravel's default README (logo, "About
Laravel", links to laravel.com). An air-gapped customer or a new engineer
opening `app/` learns nothing about RLS, the six-gate pipeline or how to run
the suite. It is also the only README a Filament-era visitor reads first.

**Proposal:** replace with a ~60-line project README: what the app is, the
three commands (`dev-up.sh`, `php artisan test`, `restore.sh`), the standing
rules pointer to `HANDOFF.md`, and the RLS warning (never superuser, never
SQLite in tests). No external image links (air-gap).

**Cost:** trivial. **Why still OPEN:** it is a content decision the team may
want to word themselves; nothing breaks while it waits.

## P-03 — `app/AGENTS.md` / `app/CLAUDE.md` are Laravel-Boost boilerplate

Both files currently instruct any agent to install PHP from `php.new` and run
`composer require laravel/boost` — wrong for this repo: the sanctioned
environment is `scripts/dev-up.sh`, and the project's agent contract is
`HANDOFF.md` (§0 rules, §4 architecture rules, §8 traps). Following the
boilerplate in an air-gapped sandbox would just fail.

**Proposal:** rewrite both as a short pointer file: read `HANDOFF.md` §0–§4
before touching anything; environment via `scripts/dev-up.sh` or
`scripts/run-tests.sh`; tests never on SQLite. Keep the filenames (other tools
look for them).

**Cost:** trivial. **Why OPEN:** if the team later regenerates these with
`php artisan boost:install`, the rewrite must be re-merged — flagging that
collision in advance instead of discovering it.

## P-04 — `docs/TODO.md` has no English record

The exhaustive done/not-done register (254 lines) is Persian-only. Rule 2
requires English documentation kept current; the bilingual-pairing convention
used everywhere else (MTO requirements, roadmaps, UI-UX plan) is missing here.

**Proposal:** add `docs/TODO-EN.md` as the English mirror (translate once, then
update both in the same change — the FA copy keeps serving the internal
conversation). Alternative considered: translate TODO.md in place. Rejected —
it would break existing links and the FA register is genuinely useful locally.

**Cost:** one careful translation pass. **Priority:** medium — this is the file
a new contributor reads to answer "where are we?".

## P-05 — the four ADR-015 open questions — **ALL CLOSED 2026-10-11**

The user confirmed each recommendation; the answers are recorded in ADR-015
§5 as binding requirements:

1. **Allocation granularity: subsystem level** — line attribution later as data.
2. **Effective states: `DRAFT/ISSUED/AMENDED/CANCELLED`** — "in-force" is a
   predicate (issued and not cancelled), not a state.
3. **Allocation authority: deferred** with the MTO-acceptance policy — EPC
   procurement records; no new permission model yet; the deferral stays visible
   in the ADR.
4. **Amendments: new row + `supersedes_id`** — archive, never overwrite; the
   gap reads lineage heads only.

Slice 6 now has a complete requirements record; the build itself waits for the
full runtime (ROADMAP §3.1: no tenant-scoped feature without the real
PostgreSQL test environment).

## P-06 — prototype the Order-Gap board before building it — **BUILT 2026-10-11**

**Delivered as `prototype/order-gap-board.html`** + `prototype/test/order-gap-board.test.js`
(19 jsdom assertions: RTL boot, all five gap states, draft-exclusion note,
never-guessed unmatched bucket, filter isolation both directions, EN/LTR switch,
air-gap safety), linked from `prototype/index.html` and wired into both test
runners. All states render from the domain rules already accepted in
MTO-REQUIREMENTS §3.2 / ADR-015 §2 — no new decisions were smuggled in. The
Slice 6 service now builds against a screen that can be reviewed with the user
first. Original rationale, kept for the record: the gap states are the
commercial vocabulary of procurement reporting — cheaper to argue about them as
static HTML than as a shipped service.

**Follow-through (2026-10-11):** Slice 6 was built against this prototype —
`OrderGapService` + `OrderGapController` + the
`/projects/{code}/procurement/order-gap` screen implement exactly the states
validated here (SHORTAGE / COVERED / OVER_PLAN / UNMATCHED, draft exclusion,
no proration). Status: IMPLEMENTED — awaiting runtime verification on the
owner's Laragon machine; ADR-015 §9 records the mapping.

## P-07 — `demo:seed` for air-gap sales demos

Product-planning docs list demo seeding as sales infrastructure, unbuilt.
Today the PH-14 fixture is a **test** fixture: enough for assertions, too thin
for a demo where a customer clicks through procurement → warehouse → punch.

**Proposal:** `php artisan demo:seed` — idempotent, clearly-labelled demo
company/project ("DEMO-…"), one project per standards pack, realistic Persian
names and quantities, and it refuses to run when `APP_ENV=production` unless a
flag is passed. The same seed doubles as the docs' screenshots source.

**Cost:** medium. **Dependency:** needs the full runtime (Docker tier).

## P-08 — order gap must be snapshot-computed, never live-aggregated

HANDOFF §5v records a benchmark: an RLS-guarded full-scan aggregate costs
**2,248 ms vs 204 ms** without RLS (11×) at 2M rows, while indexed fetches are
fine. The order-gap board aggregates across `accepted_mto_rows` ×
`purchase_order_items` — exactly the full-scan shape.

**Proposal:** compute gap rows into a `material_gap_snapshots` table on the
events that change inputs (MTO acceptance, PO issue/amend/cancel), refresh
transactionally; the board reads the snapshot. Freeze the same numbers into
any progress/payment certificate, mirroring the `punch_summary` lesson (a
certificate must never re-render itself). Record in ADR-015 before Slice 6
builds.

## P-09 — a Docker-less CI job ("sandbox tier")

CI's four jobs all assume Docker. The degraded tier now exists
(`scripts/run-tests.sh`: pack validator + five jsdom suites, node+python only)
and could run as a fifth fast job on plain ubuntu runners — catching
pack-validator and prototype regressions before the heavy image job even
starts.

**Proposal:** add job `sandbox-tier` running `npm ci && bash
scripts/run-tests.sh`, asserting **zero failures** and tolerating only the two
documented skips (php, laravel). If a skip count changes, fail — same
no-silent-skip philosophy as the `laravel` job.

**Cost:** ~30 lines of workflow. **Why OPEN:** touches CI; merge when the team
reviews the workflow file anyway.

## P-10 — archive the session log before HANDOFF outgrows its purpose

HANDOFF is ~200 KB; the §10 table alone is the largest part. "One file to read
first" only works while reading it is affordable.

**Proposal:** when the file passes ~3,000 lines, move the oldest table rows to
`docs/SESSION-LOG-ARCHIVE-EN.md`, keep the paragraph entries, and leave a
one-line pointer. Deliberately **not** doing it now — the current size is still
usable and splits are a navigation cost too.

---

## P-15 — deep SRS alignment (banner landed, body still open)

The 2026-10-11 full review confirmed DOCUMENTATION-AUDIT-EN's finding: the SRS
still frames SaaS as a peer deployment model, keeps §15 Q1 "launch market"
open, lists ISO/AWP/Hybrid/Custom as the pack set, carries the pre-Laravel
indicative stack in AR-08, and marks ~all FRs `M` without release phases.
A dated reconciliation banner was added at the top of the SRS pointing at the
two superseding decisions (Iran-First Addendum, ADR-003 Annex A) — that part is
`BUILT` under P-16.

**Still OPEN here:** the deep pass itself —
1. tag every FR/NFR/BR with a release target (`P0 Pilot` / `P1 MVP` /
   `P2 Enterprise` / `P3 International`) per audit §4.5;
2. rewrite the launch pack list to `ips-iran` / `iso` / `awp`;
3. fold Iran-First commercial terms into BR-01..BR-12.
**Cost:** one focused session; zero code risk. **Why it matters:** the audit's
own conclusion — "the largest current risk is not code; it is parent-document
drift" — and every future decision made against the stale SRS inherits it.

## P-16 — dated corrections from the full documentation review

Second-pass fixes, all dated in place rather than silently rewritten:
SRS reconciliation banner; Architecture §12 AD-1 marked historical/superseded
by ADR-003 Annex A; ADR-006's stale "Nothing is implemented" ending annotated
as historical; ADR-005's dependency on ADR-004 updated to ACCEPTED-and-built;
ADR-001/ADR-002 statuses raised to **Accepted and closed** (HANDOFF §1 and
TODO §5 already record both decisions as closed — the ADRs now say so too);
Iran-First §10 "backend stack" item marked CLOSED via Annex A.

## P-17 — ADR-012: the one known HIGH-severity defect — **FIX IMPLEMENTED 2026-10-11, awaiting runtime verification**

ADR-012 documents a real performance defect present in production code: the
RLS escape hatch degrades with the number of *other customers'* projects and
was measured as an 11× aggregate penalty at scale. **Fixed 2026-10-11** (per
the "proceed without waiting for tests" instruction): migration
`2026_10_11_110000_make_rls_project_scope_sargable` replaces the non-sargable
`(GUC IS NULL OR project_id = GUC)` preamble in all 33 tenant policies with a
sargable participation semi-join, adds eight `(project_id, …)` indexes, and
ships `RlsSargabilityTest` (cross-project visibility, binding, isolation,
write-path pinning, a scale-independent plan-shape guard, and a 60k-row
volume semantics test). The mechanism was EXPLAIN-verified against sandbox
PostgreSQL 16.2 (153 ms seq scan → 26 ms on RLS-only-scoped reads; index
paths confirmed once the query carries the project predicate, as house
controllers already do). ADR-012 §9 carries the full record. Verification
step: `php artisan test` on the owner's Laragon machine.

## P-18 — coverage map recount

`Prototype-Coverage-Map.md` still reads "~15–20% of specified scope" with
"0 backed by real logic" screens, while HANDOFF now carries ~40–45% with
procurement, piping phases 1–3, MTO slices 2–5, test packs and the Piping
Workspace all real. The dated update blocks help, but the module table itself
needs one recount pass mapped to FR ids — which also produces the trustworthy
coverage number TODO.md is waiting for. **Cost:** one session; pairs naturally
with P-15's release-phase tagging.

---

## P-19 — runtime provisioning: database half built, PHP half blocked

Attempted 2026-10-11 at the user's direction. **Built:**
`scripts/sandbox-pg.sh` — real PostgreSQL 16.2 inside the sandbox (binaries
from the `pgserver` PyPI wheel; `btree_gist` compiled from official source via
codeload because the wheel ships no contrib), `epcc`/`epcc_test` databases,
non-superuser app role asserted by the script, FORCE-RLS and the ADR-008 gist
exclusion constraint both proven live as the app role.

**Blocked, with evidence:** PHP 8.4 cannot be installed here — apt mirrors,
php.net, packagist, getcomposer and the GitHub release-asset CDN are all
outside the sandbox's network allowlist (only github.com/api.github.com/
codeload, registry.npmjs.org, pypi.org answer). `php-wasm` does not satisfy
PHP-8.4 + pdo_pgsql + Laravel's extension set. **Consequence:** migrations,
RLS policies, services and the PHPUnit suite still run only where PHP exists —
either the Docker tier (`scripts/dev-up.sh`) or the owner's Windows/Laragon
machine (`docs/LARAGON-DEVELOPMENT-ENVIRONMENT.md`) — which are now the two
unblocking steps for Slice 6, ADR-012 (P-17) and piping phase P1.

**Recorded caveat:** sandbox PG is 16.2 vs the reference 17 — current
migrations use nothing 17-only, and new ones must not, silently.

## P-20 — PDF export does not work on Windows (Laragon found it)

The owner develops on Windows + Laragon. `App\Services\PdfRenderer::binary()`
locates the browser via the POSIX `command -v`, so on Windows the candidates
(`chromium`, `google-chrome`, …) are never found, `isAvailable()` is false,
and every PDF action degrades silently. Same family as every other
air-gap/typography trap: nothing errors, the capability is just gone.

**Proposal:** add Windows candidates (`chrome.exe` under the usual Program
Files paths, a `where`-based lookup behind a platform check) plus docs for
Vazirmatn + poppler on Windows. **Cost:** small. **Test gate:** needs the
Laragon/Docker runtime — queue behind P-17 once the suite runs. Recorded by
the 2026-10-11 Laragon setup doc (`docs/LARAGON-DEVELOPMENT-ENVIRONMENT.md` §7).

## P-21 — Branch weld joint types: stub-in/stub-on welds must be first-class joint types (recorded requirement)

**Recorded as an owner requirement, 2026-10-12.** A P2P branch connection
(ADR-008, built) has NO component: the branch pipe is contoured and welded
straight onto/into the header. The connection therefore lives in the joint
register as a WELD — but it is not an ordinary butt weld, and welding
metrics must never treat it as one.

**Requirement.** When the welding/joints phase is designed (piping roadmap
phase P5), the joint register must carry formal branch-weld joint types —
at minimum `BRANCH_STUB_IN` and `BRANCH_STUB_ON` — such that:

1. Branch welds are **counted and reported separately** from butt welds in
   the weld log and weld-count KPIs.
2. **Dia-inch / progress metrics** (ADR-010) do not silently mix branch
   welds into the butt-weld population; they are either reported apart or
   weighted by whatever the prevailing norm (IPS/company practice) dictates
   — the choice itself must be a recorded decision in the P5 ADR.
3. **Pad traceability**: when the class rule that governs the connection is
   `reinforcement = PAD`, the pad (with its mandatory thickness from the
   ADR-008 branch rule) is traceable to the weld record.
4. A joint whose actual connection type contradicts its class (e.g. a P2P
   weld where the class says `TEE`) surfaces as a **deviation** — the same
   audit the class-validation phase delivers for components.

**Acceptance:** joint-type vocabulary + migration + tests; weld counts by
type; a weld report showing branch welds separately; pad linkage from the
class rule. **Depends on:** the P5 design ADR (not written yet) and ADR-010
§6 open questions. Cross-references: ADR-008 §9.2 (forward link).

## Standing process — requirements review (owner directive, 2026-10-12)

The owner's 2026-10-12 instruction makes requirement review a **standing
duty**, not a by-product of building: before each build phase, the
requirement set of the target domain is audited for gaps, contradictions
and staleness; cheap hygiene is fixed on the spot, decisions become
numbered proposals. The first pass (piping) lives in
`docs/REQUIREMENTS-REVIEW-PIPING-EN.md` and produced findings R-1…R-8,
including proposals P-22 and P-23.

## P-22 — P2P branch welds connect to the header wall, not a port (decision needed)

Found in the 2026-10-12 requirements review (finding R-2). ADR-006 §5.3
models a joint as `port_a_id < port_b_id` — two component ports. A
stub-in/stub-on branch weld has only **one** port (the branch pipe's end);
the other side is a point on the header pipe's wall, which has no port. So
the built joint schema cannot yet represent the very connection the class
table now defines.

**Decision options** (recorded, not chosen):

- **(a) Synthesised wall port** — create a virtual port on the header at
  the branch point, mirroring how Plant 3D models `TEE-SET-ON` with a
  `BRANCH1-POINT`. Keeps the port-pair invariant; adds port provenance
  (real vs derived) to the vocabulary.
- **(b) Wall-point joint** — let a joint reference `(line, station)` on the
  header instead of a second port. Smaller concept, but the `port_a_id <
  port_b_id` invariant and every port-based query need a nullable second
  side — the COALESCE-smell ADR-010 §3.2 rejected elsewhere.

**Recommendation (updated 2026-10-12 by research):** **(c) tap semantics** —
the joint carries the branch-side port plus a *frozen* reference to the
header component and the connection point (ADR-006 §5.3 "derived
attributes are frozen"). This is the PCF-native idiom: ISOGEN resolves a tap
connection by projecting the branch end-point onto the header centreline,
and the header has no port (see `docs/RESEARCH-PLANT3D-PCF-P2P-BRANCHES-EN.md`;
Plant 3D calls the result a "tap weld"). Rejected: (a) synthesised wall
port — nothing in the exchange formats requires it, and it would invent a
state no importer/exporter carries; (b) raw line+station — weaker than a
frozen component reference. **Decides:** ADR-006 follow-up, ADR-008 build
phase 3 wiring, and the P5 joint design. Cross-references: ADR-006 §13,
ADR-009 P2P addendum, proposal P-21.

## P-23 — MTO has no coverage of branch-connection material

Found in the 2026-10-12 requirements review (finding R-5). `MTO-REQUIREMENTS-EN`
says nothing about branch connections. Two material realities exist at a P2P
node (and at weldolet/sockolet cells): a **reinforcement pad** is a plate
item — diameter and thickness, and since ADR-008 §9.1 the thickness is
mandatory — and a stub-in **stub end** is pipe that gets coped/notched,
which affects cut length and offcut. Neither is on any MTO list today.

**Proposal:** when the MTO↔piping integration phase is designed, add (1)
reinforcement pads as plate material rows sourced from the class rule, and
(2) stub/cop allowance in branch-pipe length calculation. **Status:** OPEN —
scope/commercial decision, waits for the owner per rule 5.

## P-24 — ASME B31.3 §304.3.3 validation of pad thickness in the PMS (owner requirement)

Recorded 2026-10-12: *«استاندارد ASME B31.3 برای محاسبه ضخامت پد فرمول و
محاسبات را ارائه کرده؛ نیاز است از این محاسبات برای اعتبارسنجی ضخامت پد در
PMS استفاده شود»*. The requirement text and the formulas live in ADR-008
§9.3. Two steps:

- **Step A — BUILT (2026-10-12).** `App\Services\BranchReinforcementCalculator`,
  a pure stateless service (ADR-006 §5.4 pattern) implementing the
  para 304.3.3 area-replacement method: zone limits `d1/d2/L4`, areas
  `A1..A4` (pad capped by the zone, weld credit per para 328.5.4,
  allowable-stress ratio reductions per §f(3)), PASS/FAIL verdict, margin,
  `requiredPadThickness()` (bisection — no closed form, Tr feeds L4), and
  `pressureDesignThickness()` — B31.3 eq (3a) `t = PD/(2(SEW + PY))`,
  refusing the t ≥ D/6 thick-wall regime.
  Covered by `Tests\Unit\BranchReinforcementCalculatorTest`: published
  worked example in both run-wall variants, pad recovery, zone capping,
  stress-ratio reduction, 45° lateral, and corrupt-input refusals. Unit
  system is caller-consistent (platform standard mm).
- **Step B — QUEUED.** Wiring into the PMS. Parameter inventory (audited
  against the live code 2026-10-12 — the branch table belongs to each
  piping class, so every input below is per class):

  | B31.3 input | Status | Where |
  |---|---|---|
  | OD per DN (`Dh`/`Db`) | **EXISTS** | ADR-007 pack `pipe-sizes.ref.json`, `PipeReference::byDn()` |
  | Wall per DN×schedule (`Th`/`Tb`, mm) | **EXISTS** | ADR-007 pack `pipe-schedules.ref.json`, `PipeReference::wall()` |
  | Schedule per DN band | **EXISTS** | class rule kind `PIPE_SCHEDULE` (value shape not yet validated against the pack) |
  | Corrosion allowance `c` per DN band | **EXISTS** | class rule kind `CORROSION_ALLOWANCE` |
  | Design pressure / temperature | **MISSING on the class** — exists only per line (`piping_lines.design_pressure/temperature`) | decision needed |
  | Pipe material grade per class | **MISSING** — no material rule kind; `MaterialItem.grade` is MTO-only | decision needed |
  | Allowable stress `S(T)` (B31.3 Table A-1) | **MISSING** — no material master, no stress reference pack | decision needed |
  | Joint factor `E`, weld reduction `W`, coefficient `Y` | **MISSING** | decision needed |
  | Mill-tolerance deduction convention (`T` = nominal minus tolerance) | **MISSING** — convention to record | decision needed |
  | Pad material stress ratio | **MISSING** — pad spec not captured; calculator defaults to 1.0 | deferred |
  | Branch angle β | convention 90° (laterals not modelled in PMS tables) | OK |
  | The calculation itself | **BUILT** (Step A) | `BranchReinforcementCalculator` |

  Step B therefore needs: (1) a `t = PD/(2(SEW + PY))` wrapper computing
  `th`/`tb` (B31.3 eq 3a), (2) the missing class-level inputs above,
  (3) publish-gate validation of every PAD rule against the worst case of
  its footprint and refusal of `NONE` where reinforcement is required.

  **Decisions (owner, 2026-10-12):**
  (a) design pressure/temperature are **class attributes** — every class
  carries its own design basis; the per-line columns remain for line-level
  checks;
  (b) material data comes from a **Table A-1 reference pack** built from
  the ASME B31.3 standard (owner will upload it), with manual entry
  remaining available as an override;
  (c) **mill tolerance 12.5%** — effective wall `T = 0.875 × nominal`
  everywhere `Th`/`Tb` appear;
  (d) the standard itself: ASME B31.3, current edition.
  From it we extract: Table A-1 allowable stresses for the common grades
  (A53B/A106B/API 5L, A312 TP304/316, A333 LTCS), Table 304.1.2 `Y`,
  §302.3.5 `W`, and Appendix H worked examples as calculator fixtures.
  Lands after Step A's runtime verification.

## BUILT this pass (2026-10-11)

- **P-11:** `package.json` `test:prototype` now runs `render.test.js` too —
  the 324-combination suite was silently outside the npm entry point while
  `scripts/run-tests.sh` included it. Verified green.
- **P-12:** `prototype/test/run-all.sh` ran only 2 of the 4 jsdom suites and
  used bare `python3` (breaks on PEP-668 hosts). Now a thin `exec` wrapper over
  `scripts/run-tests.sh`; old invocations keep working.
- **P-13:** HANDOFF §3 said PDF export and the PROCUREMENT module were
  "deliberately absent" — both have been built for weeks (§5f, procurement base
  chain). Corrected with a dated note so the drift is itself visible.
- **P-14:** README contents table now lists ADR-015 and this register.

---

## How to use this file

- New proposal: append with the next `P-xx` ID, add the summary row, set
  `OPEN`.
- User decides: flip to `ACCEPTED` (will build) or `REJECTED` (keep the entry
  and the reason).
- Implementation lands in the same or a later session: flip to `BUILT`, cite
  the commit/section.
- Every session must touch this file or state in the session log that nothing
  new was found (rule 5: silence is a defect).
