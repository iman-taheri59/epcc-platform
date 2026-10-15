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

Last updated: **2026-10-12** (targeted MTO/PCF/Order Gap status reconciliation; no new runtime test claim).

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
| P-24 | Requirement / piping | Pad thickness on P2P rules must be validated per ASME B31.3 §304.3.3 (owner requirement) — calculator + B31.3-2024 materials pack + class design basis + publish-gate validation built; **flange step built 2026-10-12**: ASME B16.5-2025 P-T ratings pack (45 SI tables, cross-checked against the Customary twin) + `FlangeRatingReference` + publish gate rating every FLANGE_TYPE cell against the class design basis; B16.47 large-diameter + Section II Part D extensions remain follow-ups; runtime verification on owner's Laragon pending | PARTIAL — Step B + flange gate built |
| P-25 | Standards / data | Parallel-session engineering reference library discovered on the owner-published `workspace` branch and ported (commit `bf15816`): full B31.3-2024 Table A-1 stresses (798 rows/138 specs), materials library v1.8, B16.5+B16.47 flange P-T curves, bolting, B16.9 fitting dimensions, valve packs, per-pack schemas and the 2,835-line validator; both flange provenances cross-checked cell-by-cell (4,725/4,725 exact). **Steps 1-4 ported; step 5 catalogue/adoption-only adaptation implemented (awaiting Laragon verification)**: ADR-019 documents ITP-only citation host/migration and mutable-edition conflict; owner chose option A; citation/ITP still deferred. Compatibility assessment (`3bdff9b`) + engineering screening & fitting-review module + MR entry module ported (awaiting Laragon test runs). Next: Laragon verification; group A fluid-service classification implemented pending tests (ADR-020); step 7 baseline/model-import assessed as no-go for wholesale port, owner approved parser-only; pure PCF parser ported (ADR-021), branch-vocabulary conflict handled by a read-only P2P classifier (ADR-022); model importer/baseline deferred | PARTIAL — porting in progress |

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
  (A53B/A106B/API 5L, A312 TP304/316, A333 LTCS), Table 304.1.1-1 `Y`,
  §302.3.5 `W`, and Appendix H worked examples as calculator fixtures.

- **Step B progress (2026-10-12, commit `55272b2`):** build item (1) — the
  eq (3a) wrapper `pressureDesignThickness()` — landed earlier (`c9fbd88`).
  Now the **materials reference pack** is built from the owner-uploaded
  ASME B31.3-2024 PDF:
  - `scripts/build_b313_materials_pack.py` — position-based Table A-1 (SI,
    pp.206–315) extractor: per-page temperature ladders (13/19/32-column
    sections), merged stacked-segment headers, structural metadata parsing.
    Regenerable whenever a newer code edition is uploaded.
  - `standards-packs/reference/b313-materials.ref.json` — 57 rows: A53 A/B
    (S/E/F), A106 A/B/C, API 5L A/B + X42…X70 (published under ISO names
    L290…L485, PSL1/PSL2), A333 1/6, A335 P5/P9/P11/P22/P91, A312
    TP304/304H/304L/316/316H/316L/321/321H/347/347H, A516 55–70, A285 A/B/C;
    plus Table 304.1.1-1 (Y), Table 302.3.5-1 (W) and the Table A-3 (Ej)
    subset; mill-tolerance factor 0.875 per decision (c).
  - `App\Support\Reference\MaterialReference` (ADR-007 pattern: file, not
    table; memoised; every lookup throws): S(T) with linear interpolation
    and hard refusals above the last column / above max use temperature;
    Ej by spec+type or description fragment; Y/W with below-range rules.
  - `tests/Unit/MaterialReferenceTest` — 17 tests anchored on Appendix H
    (H301: API 5L Gr A S = 110 MPa @ 200 °C) and raw table rows.
  Extraction validated row-by-row against the PDF text stream.
- **Step B complete (2026-10-12, commits `551b160` + `ae5351c`):**
  - Migration `2026_10_12_120000`: `piping_classes` gains
    `design_pressure_mpa` / `design_temperature_c` (class attributes per
    decision (a)); the rule-kind CHECK re-stated with `PIPE_MATERIAL`.
  - `PipingClassService::setDesignBasis()`; `addRule()` validates
    PIPE_MATERIAL values against the pack (spec+grade must resolve unless
    an `s_mpa_override` is given — manual entry stays an override; PSL
    ambiguity refused; optional `pipe_type` / `ej_override`).
  - `App\Services\BranchConnectionPadGate`: the publish gate walks the
    worst case of every branch cell — all pack DN pairs in the footprint,
    each pipe sized by its band's schedule/material/CA rules, effective
    wall 0.875×nominal, S(T)/E/W/Y from the pack — and runs the Step A
    calculator. PAD cells must pass with the recorded thickness; NONE
    cells must pass bare; anything uncheckable (§304.3.1(b) limits,
    missing rules, out-of-table temperatures) is REFUSED, never skipped.
    E defaults to the lowest published Ej of the spec (conservative).
  - `tests/Feature/PipingPadGateTest` (5 tests) on pack-computed matrices:
    worst margins +17.4 / +761.9 (adequate), −94.3 / −62.7 (refused).
  Awaiting runtime verification on the owner's Laragon tier (no PHP in
  sandbox). Remaining housekeeping: remove the 12 MB PDF from the
  `b31.3-upload` branch (needs owner sign-off for the history rewrite).
- **P-No / Group No (owner requirement 2026-10-12, commit `05737c7`).**
  «P no. و group no. متریال هم در صورتی که در کتابخانه متریال سیستم وجود
  ندارد، باید وارد بشه» — a material outside the library must carry its
  Section IX P-No AND Group No on the PIPE_MATERIAL rule. Library
  materials get the P-No from the standard itself: Table A-1 prints a
  P-No column, now extracted ("1", "4", "5A", "5B", "8", "15E"...); the
  table publishes no Group number — which is why the owner then uploaded
  ASME BPVC Section IX 2021 (branch `sec.ix-upload`). Its Table QW/QB-422
  publishes BOTH numbers; the pack builder now transcribes the Group
  assignments (`SECTION9_QW422`) and ASSERTS Table A-1's P-No column
  against Section IX's on every build. Library materials therefore carry
  complete identity; only out-of-library materials need manual entry
  (commit `4b3bd0d`).
- **Flange step (2026-10-12, commits `e2b3753` + this commit).** The owner
  then uploaded ASME B16.5-2025 (branch `b16.5-upload`), B16.47-2020 and
  Section II Part D (2025 Customary). From B16.5: all 45 SI P-T tables
  (2-1.1 .. 2-3.19, 903 rows) extracted into
  `standards-packs/reference/flange-ratings.ref.json` by
  `scripts/build_flange_ratings_pack.py` — the builder cross-checks the
  Group 1.1 SI table against its U.S. Customary twin cell-by-cell and
  enforces ladder/monotonicity invariants. `FlangeRatingReference`
  (ADR-007 pattern) resolves a flange material (spec+grade) to its B16.5
  material group and returns the step rating at any temperature; every
  lookup throws above the ladder top or where the table retires a class.
  FLANGE_TYPE rules now carry
  `{"rating_class": 300, "spec": "A105", "grade": ...}` — validated at
  entry — and the publish gate rates every FLANGE_TYPE cell against the
  class design basis (`validateFlangeRatings`). Tests:
  `FlangeRatingReferenceTest` (anchors incl. A105 Cl 150 = 19.6 bar at
  ≤38 °C, the 285 psig classic) and `PipingFlangeRatingGateTest`.
  Follow-ups: B16.47 large-diameter ratings and Section II Part D data
  (Tcr/yield/density; duplex+nickel grade coverage stays sourced from
  Table A-1 itself).

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

## P-25 — parallel-session reference library: audit, port, and open integration decision

Recorded 2026-10-12. The owner published a `workspace` branch carrying a
full export of a parallel Arena session (snapshot dated 2026-10-06): a repo
tree based on the same `48b1e5d` root as this branch, plus four zips of
evidence (regression logs, B16 source-review files, engineering-screening
screenshots fa/en, and an ASME B16.9-2018 PDF upload). Nothing from that
session ever merged into `main`; the export is its only surviving record.

**What was ported (commit `bf15816`, verbatim):**
- `allowable-stress` v1.0.0 — the FULL B31.3-2024 Table A-1 (SI): 798
  rows / 138 specs including duplex A790/A789, EFW A671/A672 and nickel
  alloys, each row carrying its own verification fields
  (`verified` / `basisCheck` / `heldNotCitableBecause`). This closes the
  coverage gaps named in the 2026-10-12 materials-library audit.
- `materials` v1.8.0 (A-1 groupings + Chapter VII non-metallics),
  `flange-pt-ratings` v2.0.0 (45 B16.5-2025 groups + 27 B16.47 curves,
  kPa, derived Class 800 per API 602, declared B16.5-vs-B16.47
  discrepancies: 11 cells of 2,100), `flange-bolting` v2.1.0 (B16.5-2025
  drilling templates), `fitting-dimensions` v1.2.0 (ASME B16.9-2018),
  valve-construction/pt-ratings/face-to-face/testing, fluid-service,
  actuators; `component-templates` upgraded to its strict superset
  (+BOLT/+NUT, existing 20 templates unchanged).
- 16 per-pack JSON schemas and the session's validator (superset of the
  previous one). All checks pass this session: 3 standards packs + 16
  reference packs valid; 22/22 standards tests green.

**Cross-validation performed:** the branch's own PDF-extracted
`flange-ratings` pack (positional extraction, SI<->Customary audited) vs
the ported transcribed `flange-pt-ratings`: 4,725 shared B16.5 cells,
4,725 exact matches, zero mismatches. Pinned permanently by
`standards-packs/tests/test_flange_ratings_cross_check.py`. The two packs
stay complementary: the extracted one carries 228 extended high-temperature
rows (550-649 deg C) the transcribed 16-row ladders lack; the transcribed
one carries the B16.47 curves and Class 800 derivation.

**OPEN decisions / follow-ups (need the owner):**
1. The session's APP-level work (different PMS architecture: entry/bulk/
   valve/measurement services; MR entry; NDT requirements; engineering
   screening; fitting review) — assessed step-by-step in
   `docs/PARALLEL-SESSION-INTEGRATION-ASSESSMENT-EN.md` (2026-10-12):
   additive modules ranked for stepwise porting (screening -> fitting
   review -> MR entry -> standards library), PMS core stays a design
   decision, ITP stays deferred. Owner approval of the sequence pending.
2. If the B16.47 curves enter service, the owner's copy is the 2020
   edition while the transcription is from 2025 — request B16.47-2025.
3. `b313-materials` and `flange-ratings` (this branch's packs) do not yet
   conform to the ported `standards-pack.schema.json` convention
   (refId/version/units shape) and are not registered in the validator's
   REF_PACKS; they remain guarded by their builder scripts and PHP tests.


## How to use this file

- New proposal: append with the next `P-xx` ID, add the summary row, set
  `OPEN`.
- User decides: flip to `ACCEPTED` (will build) or `REJECTED` (keep the entry
  and the reason).
- Implementation lands in the same or a later session: flip to `BUILT`, cite
  the commit/section.
- Every session must touch this file or state in the session log that nothing
  new was found (rule 5: silence is a defect).

### 2026-10-13: selectively recover `pre-work` sources (OPEN)

The `pre-work` upload branch includes a nested parallel application and four
source archives. A bulk merge would overwrite current schema and security
changes. Better alternative: import only a cohesive tested slice, starting
with the read-only material-screening CLI/fixture, then separately decide on
finding disposition and PMS authoring/history. See
`docs/PRE-WORK-BRANCH-INTEGRATION-REVIEW-EN.md` for inventory and blockers.

### 2026-10-13: keep reference tests pinned to pack applicability (BUILT)

Old tests asserted stress values past the material's max-use temperature and
implicitly selected PSL for multi-PSL API5L grades. The safer alternative is
to retain the runtime guards and exercise the published table only within its
applicable range; tests now specify PSL and use <=816 C for TP304. W examples
were corrected to the committed 302.3.5 pack row rather than changing pack
values to satisfy stale literals. CI revalidation is pending.

### 2026-10-13: exact tabulated factors beside dashed columns (BUILT)

Interpolation must refuse a dashed interval, but an exact published endpoint
must not be refused merely because the preceding entry is a dash. The factor
reader now checks exact temperature columns first, then interpolates only
between two non-dash entries. This preserves the safety guard while making
CSEF W at 510 C readable. CI verification pending.

### 2026-10-13: enforce PMS publication against stale instances (PARTIAL)

The service had trusted the caller's `PipingClass` instance to decide if a
class was published; a previously loaded draft could add rules after another
call published it. Re-reading state under RLS closes that stale-object bypass
in sequential calls. **Better full alternative:** serialize all class mutation
and publish operations on one class row inside a transaction, and backstop
published-state immutability at the database boundary where direct inserts
must also be considered. That concurrency/schema change requires a dedicated
ADR and positive/negative tests; this patch is not the full author-policy gate.

### 2026-10-13: preserve tenant transactions on rule-constraint failures (BUILT)

A duplicate branch footprint raised a PostgreSQL unique violation inside
`TenantContext::runAs`; catching it without first rolling back a savepoint
left the outer transaction aborted, obscuring the intended validation error.
Rule inserts now use nested database transactions (savepoints) around the
constraint-bearing insert, with error translation after rollback. The UNIQUE
and GiST exclusion guards remain intact. CI verification pending.

### 2026-10-13: parenthesize entire RLS membership bodies (BUILT)

A top-level OR in an owner-readable policy escaped project binding because SQL
AND binds more tightly than OR. The policy generator now parenthesizes both
operands. A safer future option is to build a SQL policy-body expression API
with explicit grouping and verify bound-project behavior for every OR-based
policy (not only certificates); existing RLS regression catches the observed
case. See ADR-012 security correction.

### 2026-10-13: count identifiable probes in RLS volume tests (BUILT)

The RLS fixture previously asserted the total count of visible punch items,
ignoring nine legitimate demo rows. Better: count probe rows by their unique
`PI-` numbering while separately asserting foreign `KI-` rows remain hidden.
This retains the positive isolation mirror and avoids resetting demo data or
loosening a policy merely to satisfy a fixture. CI verification pending.

### 2026-10-13: explicit PMS activation on shared class identity (OPEN — owner decision)

Adding `PmsAccessService::allows()` to six methods would be easy but would
leave direct writes and amendment/publish provenance unprotected. Better:
ADR-027 option C explicitly activates PMS on the existing shared class
identity, serializes writes with nomination updates and backstops PMS-only
mutations in the database. A separate PMS aggregate (option B) is also valid
if the owner wants strict domain separation, but requires mapping existing
line/MTO foreign keys. This changes schema and workflow, so no partial
security claim or silent implementation until the owner chooses.

### 2026-10-13: reuse prior class-revision decision rather than re-ask (BUILT documentation)

ADR-028 initially treated automatic versus pinned revision as an open owner
question. ADR-008 §7 #4 and ADR-009 §4.4 already resolve it: lines keep their
issued-against class revision. The better design uses an immutable class/PMS
document revision link and an explicit deviation review instead of looking up
`documents.current_revision_id` for old lines. ADR-028 corrected; no runtime
revisioning is claimed.

### 2026-10-13: reuse CDE for PMS document identity (BUILT, first slice)

Better than a parallel `pms_documents` register: use the existing numbered,
revisioned CDE document with a distinct PMS type, preserving its audit and
approval state machine. The service rejects a non-PIPING PMS regardless of
HTTP validation. This does not replace the needed class-to-revision mapping
or authorize any line; see ADR-028.

### 2026-10-13: stage PMS identity binding before revision snapshots (PARTIAL)

The current class identity is already referenced by line/MTO foreign keys.
Attaching it to one typed CDE PMS document avoids a duplicate class register
while preserving multiple classes per spec. A migration trigger backstops
document type/project/state and immutability; the service uses the existing
versioned nomination lock. **Better final form remains** an immutable
class-specification snapshot linked to each issued PMS revision, then a line
pin to that snapshot. This slice alone is insufficient for line eligibility
or direct-SQL writer enforcement; see ADR-028 and ADR-027.

### 2026-10-13: snapshot specifications at approved CDE issuance (BUILT, incomplete security)

A class ID alone cannot prove what was approved. Capture its header and rule
arrays once under an approved PMS revision in the same transaction as CDE
publication, rather than reconstructing history from mutable live rows. The
remaining stronger alternative is to **prevent arbitrary SQL from forging
snapshot content or editing published source rules** and gate all authoring
entry points before allowing lines to consume these snapshots. See ADR-028.

### 2026-10-14: pin lines to snapshot IDs rather than mutable class IDs (PARTIAL)

Keeping only `piping_class_id` loses which rules were approved when a line was
issued. An explicit immutable FK to the revision's specification snapshot
preserves that lineage, including after supersession. Better final form:
require a non-null valid pin at all **new** line insert paths and in the DB
while migrating historical unassigned records with explicit owner review.
This step only adds the optional pin and its integrity checks; it does not yet
fulfil the class-before-line rejection requirement (ADR-028).

### 2026-10-14: native pinned CLI instead of WebAssembly for sandbox checks (PARTIAL)

A reproducible native PHP 8.4 binary gives real CLI semantics and keeps large
binaries out of Git. It is strictly better than a Node/WebAssembly fallback
for local syntax and dependency-free rule tests. The available upstream build
has **no pdo_pgsql**, so the stronger target remains a full Laragon-like local
PHP 8.4 + Composer + PostgreSQL environment with non-superuser RLS tests.
Never replace PostgreSQL with SQLite merely to get a local green badge.

### 2026-10-14: full native PHP extension stack rather than a green health route (OPEN)

Installing Laravel dependencies and displaying `/up` is not enough: the
sandbox's static PHP omits `pdo_pgsql`, `pgsql`, XMLReader and XMLWriter; login
and PHPUnit/PostgreSQL cannot run. Better: acquire a trusted PHP 8.4 build
with these extensions, official Composer 2, non-superuser PostgreSQL and dev
packages, then execute the entire suite. Do **not** choose SQLite simply
because its PDO driver is available, or disable RLS checks. The local
production-only vendor install is a reproducible intermediate milestone.

### 2026-10-14: replace the local static PHP with a PostgreSQL-capable build (BLOCKED)

A prebuilt `pdo_pgsql.so` from a different PHP build is not a sound shortcut:
this CLI is static musl, does not include libpq and has no matching extension
SDK. A single trusted PHP 8.4 runtime bundling `pdo_pgsql`, `pgsql`,
XMLReader and XMLWriter is preferable to layering incompatible artifacts;
verify the actual `artisan` interpreter reports the PDO `pgsql` driver before
marking the local Laravel environment complete. Current network and Actions
billing restrictions block provisioning that runtime in the sandbox.

### 2026-10-14: single approved-snapshot predicate for picker and write gate (PARTIAL)

Duplicating "approved PMS class" as a UI-only state check would diverge from
the eventual write rule. The picker now calls the same revision-and-snapshot
predicate that resolves a line's issue-time pin. This safely reduces operator
mis-selection, but a hidden/disabled control is never authorization. The
stronger next step is an atomic service plus DB insert rule for every newly
created or imported line, preserving historical unassigned records without
fabricating a class or revision (ADR-028).

### 2026-10-14: reject unclassified imported lines rather than infer class from tag (BUILT, import path)

An imported line number may encode a plausible class token, but that does not
prove current PMS approval or specify a particular published class revision.
The explicit class choice now resolves and stores the approved snapshot in
the supplied-number registration path. Better next step: apply the same
resolver to allocated-number creation and all bulk/SQL writers, then reject
new null pins at the database boundary without assigning fabricated classes
to historical records (ADR-028).

### 2026-10-14: require PMS snapshot for numbered lines independently of tag format (BUILT, service path)

A project can omit class from its line-number pattern; that must not silently
waive engineering approval. Both allocated and externally supplied numbers
now require the same approved revision snapshot in the application service.
The stronger remaining alternative is a database insert backstop for all
writers with a deliberate migration strategy for historical records that
cannot be classified (ADR-028); do not auto-assign a fake class.

### 2026-10-14: INSERT-only database admission rather than blanket NOT NULL (BUILT)

A blanket `NOT NULL` on snapshot would either reject migration of legitimate
historical records or tempt a fake backfill. A database BEFORE INSERT trigger
blocks all *new* unclassified lines without rewriting old ones; the existing
pin trigger verifies the exact approval provenance. Further improvement:
report historical null-pin lines explicitly as awaiting class review, and
ensure their future class amendments are also pinned to an approved snapshot.
See ADR-028.

### 2026-10-14: explicit evidence for historical class amendments (BUILT)

The insert-only backstop alone left a SQL UPDATE path to label a historical
unmapped line with a bare class ID. A narrow UPDATE trigger now requires the
approved snapshot whenever a previously null-pin line acquires a different
non-null class. It does not rewrite older classifications automatically or
block unrelated maintenance. See ADR-028; CI pending.

### 2026-10-14: historical register readiness should match AFC service gate (BUILT)

A null-pin historical line must not appear AFC-ready merely because its
other documents have been linked. The service now refuses AFC, and both the
register and task-first workspace label unresolved PMS provenance and keep
these rows out of their readiness totals. This does not silently reassign
historical revisions. Direct SQL state changes still merit a database guard.

### 2026-10-14: direct SQL AFC null-pin transition (BUILT)

Service-only AFC validation left a direct UPDATE path with valid timestamp
and actor but no historical PMS pin. A narrow database transition trigger
closes that null-pin path while preserving unchanged historical records.
Approved drawing/evidence verification for direct SQL is a separate audit,
not implied by this change; see ADR-028.

### 2026-10-14: versioned class authoring rather than mutable published rows (DECISION NEEDED)

Current service semantics make published class rows read-only; the new SQL
backstop aligns with that. If the owner expects **the same class identity**
to change its specification in a future PMS revision, the better long-term
model is mutable per-revision drafts followed by immutable issue-time
snapshots, not direct edits to a live published class row. This is a schema
and workflow decision, not a silent relaxation of the freeze. Until that
design is approved, publish a new class identity for changed specifications
and retain old line pins. See ADR-028.

### 2026-10-14: versioned class authoring accepted; staged implementation (IN PROGRESS)

The owner accepted retaining stable class identity with independent WIP
revision working copies and immutable issue-time snapshots. The foundation
is stored separately from published class rows; authoring, semantic checks
and publication from drafts remain outstanding. This avoids weakening the
published-class freeze merely to allow a revision. See ADR-028.

### 2026-10-14: narrow, controlled PMS revision editing (IN PROGRESS)

A service for nominated authors edits only existing pipe-schedule values in
WIP revision working copies; it does not expose arbitrary JSON replacement
through HTTP or mutate published class rows. This limits the first editable
path while publication semantics and engineering checks are designed.
Broader rule/branch-table editing should reuse class publish validations,
not bypass them. See ADR-028.

### 2026-10-14: validate complete revision diff, not just edit input (BUILT FOR SERVICE)

Validating only the new schedule string would let other unreviewed changes
already present in a WIP JSON working copy pass through the service. A
strict full-copy comparison against the last approved snapshot whitelists
only schedule leaves before an authorized edit. The publication path and
raw-SQL draft write boundary still need the same invariant; see ADR-028.

### 2026-10-14: review the whole PMS draft set at WIP-to-SHARED (BUILT FOR SERVICE)

Checking an author's individual edit is not enough when another writer can
alter a JSON draft. The CDE transition now revalidates every working copy
and membership against the previous approved snapshot in the same
transaction before review. A later database guard is needed for raw SQL
CDE state changes and a separate snapshot capture step is still required.

### 2026-10-14: re-run pad engineering on schedule revisions (BUILT AT REVIEW GATE)

A schema-only check cannot establish that a new schedule has a published
wall thickness or that the old pad remains sufficient. Reusing the class
publish pad gate with draft schedule overrides is safer than accepting any
well-formed schedule string. The full revision publication path still needs
an issue-time validation and immutable snapshot capture. See ADR-028.

### 2026-10-14: fail closed while revision snapshot capture is incomplete (BUILT)

Allowing a changed WIP draft to reach approved PUBLISHED while storing the
old live class specification would misrepresent the reviewed engineering
basis. The CDE publication service now refuses such drafts until the new
capture and database provenance checks are complete. This temporary
availability cost is preferable to a falsely approved schedule. See ADR-028.

### 2026-10-14: capture reviewed draft, not live class, on PMS issue (BUILT; CI PENDING)

A versioned document approval must pin its reviewed working copy; falling
back to the older live class would falsify revision provenance. The service
now captures the validated draft and the DB checks it against the prior
approved snapshot with only schedule leaves allowed to differ. This is
limited to the current schedule-only scope; DB-side engineering validation
or a protected CDE write channel remains a further improvement. See ADR-028.

### 2026-10-14: issue-time rule reads for each line (BUILT AS API)

A stable class ID can span multiple approved PMS specifications; reading
its frozen live rules would give a B-pinned line the A schedule. A new
line-pin resolver reads exactly the immutable issued snapshot and refuses
unmapped or ambiguous schedules. Future line-dependent component/MTO
consumers must use it, with tests; see ADR-028.

### 2026-10-14: deferred database completeness for PMS approval (BUILT; CI PENDING)

A regular immediate check would reject the legitimate service transaction
between the revision UPDATE and immutable snapshot INSERTs. A deferred
commit-time trigger closes the no-snapshot direct SQL approval path while
preserving that transaction order. It cannot alone attest that pad and
reference-pack checks ran for a privileged writer who also inserts a
structurally valid snapshot. See ADR-028.

### 2026-10-14: approved-revision baseline must skip B-coded issues (BUILT; CI PENDING)

Treating the most recent document revision as the previous approved class
specification would reset working copies after a published B-family issue,
which has no engineering-approval snapshots. A shared baseline resolver and
matching database guard now use the latest prior A-family issue, preserving
class content without granting B-coded documents line eligibility.

### 2026-10-14: pin-based branch resolution (BUILT AS READ API)

Future line-based engineering and MTO work must select branches from each
line's issued PMS snapshot; selecting a current live class rule would erase
revision-specific pads or P2P kinds. The resolver now applies the established
footprint/priority/time precedence to immutable snapshot cells and fails
closed on ambiguity. Integrate it only where a line is available; do not
silently use a current class for historic lines. See ADR-028.

### 2026-10-14: issue-time provenance should be visible, not just stored (BUILT)

Showing only a class code hides whether a line was issued under PMS revision
A or B. The register and workspace now read the pinned snapshot and display
the issued document/revision; they do not accidentally show the *current*
revision after supersession. A later improvement is a permission-aware link
to the exact archived CDE revision and an owner-reviewed historical mapping
workflow; do not infer such a link from the current document pointer.

### 2026-10-14: fail-closed offline Laravel test preflight (BUILT)

When GitHub Actions is blocked, running `php artisan test` against the wrong
DB role or an important database could erase data or falsely pass RLS tests.
The Laragon verifier first proves the dedicated test DB, non-superuser role,
PostgreSQL extension and selected PHP version; only explicit `--run` starts
PHPUnit. This is safer than treating any local `artisan test` output as
CI-equivalent. It does not replace image/Compose or PDF-toolchain checks.

### 2026-10-15: isolate issued schedule resolution (BUILT, integration pending)

Instead of letting downstream material consumers read mutable
`piping_class_rules`, resolve schedule values through the line's issue-time
PMS snapshot. The pure resolver now validates DN and refuses missing or
ambiguous matches; a follow-up should wire each MTO consumer to the pinned
resolver and add database-backed regression tests. Do not treat a lint pass
as an MTO integration result.

### 2026-10-15: pipe-component issued schedule default (BUILT)

A missing schedule on a new pipe component should use the line's pinned PMS
rule rather than remain empty or consult today's live class. This is now
implemented for pinned lines only. Explicit schedule overrides are still
accepted to preserve the existing modelling/joint-compatibility workflow;
a future reviewed decision should define how mismatching overrides are
approved and audited before making them automatically valid for procurement.
Manual MTO rows do not all map to a piping line, so a blanket MTO pin gate
would be unsafe without designing row provenance first.

### 2026-10-15: make modelled schedule/provenance visible (BUILT)

Showing the component's stored schedule beside the line's issue-time PMS
revision is safer than leaving an invisible explicit override hidden behind
a joint warning. It is not a substitute for a reviewed schedule-override
policy or an issue-time PMS conformance check. Those require a separate
workflow decision; do not silently label every manually entered value valid.

### 2026-10-15: surface pipe schedule mismatch without granting approval (BUILT)

The safest interim approach before defining schedule-override authorization is
to compare sized PIPE components to their line's pinned PMS in the model view,
showing expected schedule when different and "cannot be checked" when input
is incomplete. This avoids a misleading green indicator for legacy/corrupt
provenance, but is not a procurement acceptance gate. A reviewed exception
workflow with actor, reason and audit record is preferable to permanently
allowing unexplained manual mismatches.

### 2026-10-15: event-based exception audit instead of mutable approval flag (STAGED)

The owner approved a reviewed exception with reason and traceability. An
append-only request and independent decision pair better preserves who asked,
what the issued PMS required, and who signed than a mutable `approved` flag on
components. The evidence schema is implemented, but no approval capability is
exposed until expected-value revalidation and reviewer authorization exist.
For stronger evidence, separate database privileges must prevent direct SQL
writes from impersonating the reviewer; RLS alone cannot attest identity.

### 2026-10-15: one discrepancy identity, route-specific evidence (PROPOSED)

The owner clarified that the schedule case is one example of a wider
engineering deviation process. A generic approval flag or free-text reason
would silently equate citations, issued TQs and NCRs. Prefer a common
immutable discrepancy record plus typed, versioned evidence for standards,
project specifications/procedures, answered TQs and NCR dispositions; compute
current applicability and downstream holds from the evidence. Do not ship
ADR-029's bare decision row as a procurement/AFC approval. Scope, approval
authority and contractual precedence need owner decisions first (ADR-030).

### 2026-10-15: separate responsible party from licensed tenant (PROPOSED)

The owner requires a responsible-line-company signature even on packages where
that organization lacks a paid login. Treat the subscription as a UI/access
policy, never as grounds to waive engineering authorization. Prefer a
scope-limited external signatory flow, or project-contract-permitted offline
signed evidence verified by an independent authorized user. Model signer,
organization, tenant uploader and verifier separately; `piping_lines.company_id`
is not necessarily legal responsibility. Without verified signature or formal
delegation, leave the discrepancy on hold (ADR-030). Commercial terms for
limited signers and document-control validity require owner decisions.

### 2026-10-15: offline evidence must be file-bound and authority-checked (PENDING)

The owner permits offline signatures. Candidate metadata alone cannot prove
that a SHA-256 digest corresponds to a stored signed file, or that the named
signer was authorized for this line and date. Before exposing verification,
bind the digest to an immutable stored CDE file and validate contractual
responsibility/authority, project and scope using an independently authorized
reviewer; do not treat a raw VERIFIED row as engineering approval.

### 2026-10-15 correction: metadata-only external reports (REQUIREMENT ACCEPTED)

The owner's two-mode TQ/NCR requirement supersedes the earlier proposal that
offline evidence "must be file-bound". Keep a content hash/file link only if
a copy exists; independently verify external report identity, signatory and
authority against its issuing register or other authoritative source even
without an uploaded document. Requiring an uploaded PDF would incorrectly
exclude valid offline-issued documents. See ADR-031. In-system issuance needs
a separately auditable workflow and cannot be simulated by an external
report-number form.

### 2026-10-15: mode-specific report constraints (BUILT schema, workflow pending)

A single generic `issued=true` flag could make an in-system unsigned draft
look like an externally signed NCR. A typed schedule-report register now
permits only internal DRAFT or external RECORDED shapes and does not carry
any approval boolean. For production, prefer separate append-only issue,
answer, sign-off, external verification and disposition events attached to a
common discrepancy identity. Generalize beyond schedule only after contract
roles and held gates are specified (ADR-031).

### 2026-10-15: separate capture from issue/signature authority (BUILT service, API pending)

`PipeScheduleReportService` now checks the actor's project/PIPING scope for
recording either provenance shape, but cannot issue an internal draft or
verify an external report. A future public route should use a dedicated
report-capture capability (rather than the interim `project.view` permission)
and keep issue, external verification, disposition and responsible-company
sign-off as separate privileged events. Never interpret `EXTERNAL_RECORDED`
as verified evidence.

### 2026-10-15: replace project.view write shortcut (BUILT)

Using `project.view` to create report rows was too permissive even without
HTTP routes. `engineering.report.capture` now gates the capture service,
while PIPING scope and RLS continue to apply. Keep future issue/signature/
verify/disposition permissions separate; granting capture must never silently
grant engineering approval.

### 2026-10-15: derive expected schedule at request time (BUILT service)

A client-supplied PMS target could forge an apparently valid exception.
Instead, the request service locks the component and line, checks the pin,
and resolves expected schedule directly from the immutable issued snapshot.
An identical outstanding request is refused; later edits must be checked for
staleness before any reviewer decision. Service checks are not a substitute
for future DB privilege isolation or a workflow-approved disposition.

### 2026-10-15: show pending request without a green approval badge (BUILT)

A discrepancy request is evidence of a problem, not clearance. The modelling
screen offers capture only for a visible mismatched PIPE owned by the actor's
company and then explicitly displays "Review requested; not approved" while
pending. No issue-time PMS values are accepted from the browser. A later
step should display request history and TQ/NCR links as read-only evidence,
then define multi-party dispositions before connecting any downstream gate.

### 2026-10-15: distinguish excess wall from PMS schedule conformity (PROPOSED)

A red schedule-difference badge is not proof that thicker pipe is unsafe,
while a larger nominal wall is not proof that it is buildable or contractually
permitted. Prefer a size-specific reference-wall and B31.3/allowance/mill-
tolerance assessment separate from the issued PMS schedule comparison. Show
PASS/FAIL/UNKNOWN for minimum wall, still requiring referenced conditions,
independent reviewer and line-owner signature for any approved substitution.
Unknown material/design data must not turn into a green badge. Await owner
clarification on whether PMS schedule is exact or minimum; no automatic
thicker-is-approved rule should be added.

### 2026-10-15: evaluate tapered thicker-wall substitution with geometry evidence (PROPOSED)

Owner permits a thicker pipe/component via internal 1:3 taper per applicable
ASME B31.3 requirements. Better than an automatic "thicker is okay" switch:
a size- and joint-specific transition record storing measured offset/length,
minimum remaining wall, drawing, WPS and inspection evidence, with code
edition/figure and approval provenance. A 1:3 geometry check alone is not
PMS conformity or sign-off; first obtain the project's adopted code edition
and verify the figure's application to the intended butt-weld configuration.

### 2026-10-15 correction: defer as-built taper verification to ITP Fit-up (OWNER DECISION)

The prior proposed transition record listed measured wall, geometry,
alignment and WPS as though they were prerequisites for the current PMS
discrepancy review. Owner clarified they are verified **after internal
grinding at Fit-up** by ITP-defined inspectors. Keep engineering-stage
proposed taper/conditional disposition separate from later as-built
inspection and its hold-point release. Do not block recording today's
engineering request on evidence that cannot exist until fabrication, or
claim a proposed taper has passed Fit-up.

### 2026-10-15: typed taper intent, not premature inspection (BUILT)

Recording `INTERNAL_TAPER_PROPOSED` on a schedule request retains the owner's
proposed remedy without inventing an as-built Fit-up result. Gate that intent
by real size-specific reference wall instead of a lexicographic schedule-code
comparison (STD/XS aliases are not universal). This deliberately omits a
numeric 1:3 or minimum-remaining-wall PASS status until the project edition
and ITP Fit-up evidence are defined.

### 2026-10-15: show stale requests and report provenance (BUILT)

An old request hidden after a schedule edit erases the visible trail and may
mislead staff into believing no discrepancy was previously raised. The line
model now shows read-only history and labels prior values, in-system drafts
and unverified external metadata plainly. It does not treat a raw approval
row as engineering acceptance. At scale, paginate the per-line history and
add report-event links once the issue/signature workflow exists; avoid
loading every project report into a single page.
