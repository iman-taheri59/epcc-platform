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

Last updated: **2026-10-11** (first pass).

---

## Summary

| ID | Area | Proposal | Status |
|---|---|---|---|
| P-01 | Commissioning | Wire `blocksReadyForStartUp()` into a real RFSU gate — it is dead code today | OPEN |
| P-02 | Docs / entry points | Replace the stock Laravel `app/README.md` with a project-specific one | OPEN |
| P-03 | Docs / entry points | Replace Laravel-Boost boilerplate in `app/AGENTS.md` / `app/CLAUDE.md` with repo conventions | OPEN |
| P-04 | Docs / language | Create `docs/TODO-EN.md` — the status register is Persian-only, rule 2 needs an English record | OPEN |
| P-05 | Requirements / ADR-015 | Recommended answers for the four Slice 6 open questions | OPEN |
| P-06 | Prototype / UX | Build a static Order-Gap-Board prototype screen **before** coding Slice 6 | OPEN |
| P-07 | Commercial | `demo:seed` artisan command for air-gap sales demos | OPEN |
| P-08 | Performance | Order-gap values must be snapshot-computed, never live-aggregated (RLS scan penalty) | OPEN |
| P-09 | CI | Add a Docker-less "sandbox tier" CI job running `scripts/run-tests.sh` | OPEN |
| P-10 | Process / HANDOFF | Archive the session-log table when HANDOFF grows past ~3,000 lines | OPEN |
| P-11 | Tests | `npm run test:prototype` was missing the 324-combination render suite | BUILT |
| P-12 | Tests / tooling | `prototype/test/run-all.sh` was stale — now a wrapper over `scripts/run-tests.sh` | BUILT |
| P-13 | Docs accuracy | HANDOFF §3 wrongly listed PDF export and PROCUREMENT as absent | BUILT |
| P-14 | Docs index | README contents table was missing ADR-015 | BUILT |

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

## P-05 — recommended answers for ADR-015's four open questions

Closing these unblocks Slice 6. Recommended defaults (user to confirm, one by
one):

1. **Allocation granularity:** subsystem level first. Line-level allocation
   needs the piping register joined into procurement; subsystem already exists
   on `purchase_order_items` and matches how material is actually issued.
   Line attribution arrives as data later (`piping_line_id` nullable), not as
   schema surgery.
2. **Effective states:** `DRAFT`, `ISSUED`, `AMENDED`, `CANCELLED` — with
   "in-force" meaning *issued and not cancelled*, a **predicate, not a state**.
   A separate IN_FORCE state would need an explicit transition nobody performs
   on site.
3. **Who allocates:** same deferral as MTO acceptance authority — procurement
   role of the EPC records; no new permission model yet. Record the deferral in
   the ADR so it is visible, not implicit.
4. **Amendment shape:** new row + `supersedes_id` FK (superseded rows stay
   readable), consistent with "archive rather than overwrite" used by document
   revisions and accepted MTO rows.

## P-06 — prototype the Order-Gap board before building it

Slice 6's board is a **new screen language** (shortage / covered / over-plan /
unmatched / unallocated buckets) the customer has never seen. The established
project pattern is to validate screen language in the static prototype first
(`piping-workspace.html`, MTO acceptance preview) — both were jsdom-pinned and
both caught localisation and layout defects before any Laravel code existed.

**Proposal:** one bilingual, air-gap-safe static screen with synthetic PH-14
data (shortage, covered, over-plan and an unmatched-material row), FA-first,
linked from `prototype/index.html`, plus a jsdom suite. Then build the real
service against a screen already approved.

**Cost:** one session. **Why it matters:** the gap states are the commercial
vocabulary of procurement reporting — cheaper to argue about them as static
HTML than as a shipped service.

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
(`scripts/run-tests.sh`: pack validator + four jsdom suites, node+python only)
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
