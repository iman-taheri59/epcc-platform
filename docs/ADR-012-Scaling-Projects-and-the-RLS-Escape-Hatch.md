# ADR-EPCC-012 — Scaling with many projects: the RLS escape hatch is the bottleneck

**Status:** PROPOSED — a real defect is identified and a fix is measured, but **nothing is changed yet**
**Date:** 2026-09-15
**Depends on:** ADR-001 (tenancy + RLS), ADR-011 (one database per project — answered "no")
**Severity:** **HIGH.** Present in production code today, invisible at fixture scale, and it gets
worse as *other customers'* projects are added.

**Trigger (user):** answered "4" to ADR-011 §6 — *"نگران کارایی با انباشت پروژه‌ها هستید"*.

---

## 1. Summary

The concern is justified, but **not for the reason I expected**, and the fix is not partitioning.

At 200 projects and 2.6 million punch items I measured a **register page taking 1.7 seconds** — and
the time scales with the size of the whole table, meaning **one customer's page slows down when an
unrelated customer's project grows**. That is the multi-tenant failure mode the user was asking
about, and it is real.

The cause is **one `OR` branch inside the RLS policy**, not the volume of data. Removing it —
correctly — takes the same page from **1,690 ms to 0.15 ms**, and makes the curve flat: a project
with 540,000 rows pages in the same time as one with 5,280.

**No table partitioning, no sharding, and no database-per-project is required.**

---

## 2. How this was measured

A faithful replica, not a toy: `pg_dump --schema-only` of the real database restored into a
scratch database, **38 tables and all 24 RLS policies intact**, then loaded with

| | |
|---|---|
| Companies | 40 |
| Projects | **200** |
| Participations | 1,200 (6 companies per project) |
| Subsystems | 3,484 |
| **Punch items** | **1.32 M, then doubled to 2.64 M (948 MB)** |
| Skew | `PRJ-001` deliberately 10× the rest (540 k rows) so *"many projects"* could be told apart from *"one big project"* |

RLS was re-enabled and `FORCE`d before every measurement. The scratch database was dropped
afterwards and the real one verified untouched (38 tables, policy unchanged).

---

## 3. The defect

The policy on every tenant table has this shape:

```sql
(  NULLIF(current_setting('epcc.project_id', true), '') IS NULL     -- (1) escape hatch
   OR project_id = NULLIF(current_setting('epcc.project_id', true))::uuid )
AND EXISTS (SELECT 1 FROM project_company pc WHERE …)               -- (2) membership
```

Branch (1) exists for a good reason: it is what lets a user see **their work across all their
projects** when no single project is bound — the post-login landing page that ADR-011 §2.2 showed
is essential, because three of eight fixture companies already work on two projects.

But `A IS NULL OR col = A` is **not sargable**. The planner cannot prove the disjunction is false
for any row, so it cannot use an index on `project_id` — ever. The plan:

```
Seq Scan on punch_items  (actual rows=5280)
  Rows Removed by Filter: 1315440          <-- reads the whole table
Execution Time: 870 ms                      (1,690 ms at 2.6M rows)
```

**`Rows Removed by Filter: 1315440` to return 25 rows.** Adding an index on
`(project_id, sequence)` changed nothing — I tried, and the seq scan remained. The index was never
the problem.

**This is the multi-tenant coupling the user was worried about**, located precisely: latency on
project A is a function of the row count of projects B…Z.

---

## 4. Why the obvious fix is wrong

The tempting change is to delete branch (1):

```sql
project_id = NULLIF(current_setting('epcc.project_id', true))::uuid AND EXISTS (…)
```

Measured: **870 ms → 0.16 ms.** A 5,400× improvement, and **it silently breaks the product.**
With a company bound but no project bound, the cross-project view returns **0 rows** instead of the
user's work. The landing page goes blank, no error is raised, and every test that binds a project
still passes.

This is worth recording as its own lesson: **the fastest version of this policy is the broken one**,
and a benchmark alone would have selected it.

A second attempt using `coalesce(setting, project_id)` was also measured — correct, but still
opaque to the planner (`Parallel Seq Scan`, 183 ms). Cleverness in the predicate does not help.

---

## 5. The fix that is both correct and fast

**Move the project scoping out of the policy and into the query. The policy keeps only what it is
uniquely able to enforce: membership.**

```sql
-- POLICY: sargable, and the only thing that must be un-bypassable
project_id IN (
  SELECT pc.project_id FROM project_company pc
  WHERE pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
)
```

The application then adds `WHERE project_id = :current` when a project is bound, and omits it for
the cross-project view — which is what the ORM would naturally write anyway.

### Measured, all four correctness probes plus latency

| Probe | Result |
|---|---|
| Register page, project bound | **0.144 ms** (`Index Scan`) |
| Cross-project view, no project bound | **211,200 rows** — works |
| Non-participant bound to the project | **0** — isolated |
| Participant querying a project it is **not** in | **0** — isolated |

### The curve is flat

| Table size | Small project (5 k rows) | Giant project (540 k rows) |
|---|---|---|
| 1.32 M | 0.133 ms | 0.132 ms |
| 2.64 M | **0.12–0.17 ms** | **0.12–0.21 ms** |

Old policy over the same growth: **870 ms → 1,690 ms**. It doubles with the table; the new one does
not move. **Page latency is driven by page size, not by table size** — which is the property that
makes a shared database viable at fleet scale, and it confirms ADR-011's recommendation rather than
undermining it.

---

## 6. Consequences, and the risk of this change

This is a **security-sensitive change to 24 policies** and must not be done casually.

1. **The security property changes shape.** Today the policy enforces *"the bound project only"*.
   Afterwards it enforces *"any project this company participates in"*, and narrowing to one
   project becomes the application's job. Isolation **between companies** is unchanged and still
   database-enforced; isolation **between projects of the same company** moves to the query layer.
   For a company that legitimately sees both projects this is not a privilege boundary — but it
   must be a deliberate, recorded decision, not a side effect of a performance fix.
2. **`BindTenant` must always set `epcc.company_id`.** It already does; this makes it load-bearing
   for correctness rather than merely for convenience.
3. **Tests must assert the cross-project view returns rows**, not just that isolation holds. The
   existing suite would have passed the broken §4 version — that gap is the real finding here.
4. **Roll-ups are unaffected by this and still need incremental maintenance** (ADR-006's benchmark,
   `WarehouseService::subsystemMaterialStatus`). Fixing the policy does not make a full-table
   aggregate cheap; it only stops *paginated reads* from scanning.
5. **The `(project_id, …)` indexes become useful** — today several of them cannot be used at all.
   `punch_items` is indexed on `(subsystem_id, category, state)` with no `project_id`, which is
   correct for the detail view and useless for the register.

---

## 7. What is NOT needed

Measured and ruled out for the foreseeable horizon:

- **Table partitioning by `project_id`** — the index does the job once the predicate is sargable.
- **Sharding / database-per-project** — see ADR-011; and this ADR removes the performance argument
  that would have been its strongest remaining justification.
- **Read replicas** — nothing here is replica-shaped; it was a planner problem.

Revisit partitioning only if a **single project** exceeds roughly 50–100 M rows in one table, where
index maintenance and vacuum start to dominate. At 540 k rows in one project there is no sign of it.

---

## 8. Proposed plan

1. Write the failing test first: a fixture with **two projects for one company**, asserting the
   cross-project view returns rows **and** that a non-participant sees none. This test fails
   against the §4 "fast but broken" policy — it is the guard that makes the rest safe.
2. Migrate the 24 policies to the §5 shape, one table at a time, re-running the full suite.
3. Add `project_id` as the leading column of the register indexes.
4. Add a scale test to the suite that fails if a register query plan contains `Seq Scan`.

Step 4 matters more than it looks: this defect was **invisible at fixture scale** — 5 subsystems and
a handful of rows make a seq scan the correct plan. Only 200 projects and a million rows exposed it.
Without a standing guard it will come back.
