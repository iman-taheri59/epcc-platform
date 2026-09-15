# ADR-EPCC-011 — One database per project?

**Status:** PROPOSED — recommendation is **no** for the shared SaaS, **yes (whole instance)** for on-premise
**Date:** 2026-09-15
**Relates to:** ADR-001 (tenancy + RLS), ADR-007 (reference packs), MKT-EPCC-001 (air-gap)

**Trigger (user):** «آیا امکانش هست برای هر پروژه یک دیتابیس تشکیل شود؟»

Short answer: **technically yes, and it is a normal SaaS pattern — but it would break something
this product specifically needs, and it buys less than it appears to.** The evidence is below.

---

## 1. The three real options

| | Isolation unit | Cross-project query | Ops cost |
|---|---|---|---|
| **A. Shared database + RLS** (today) | row | native | one migration run |
| **B. Schema per project** | PostgreSQL schema | possible | N × 38 tables |
| **C. Database per project** | database | **impossible** | N × everything |

These are not equally spaced. **A→B is a change of degree; B→C is a change of kind**, because a
PostgreSQL database is a hard boundary in a way a schema is not.

---

## 2. What I measured

### 2.1 Cross-database joins do not exist

Built two real project databases and tried to query across them:

```
ERROR:  cross-database references are not implemented:
        "spike_proj_b.public.documents"
```

This is not a configuration problem or a permissions problem. PostgreSQL has no cross-database
join at all. The workarounds are `postgres_fdw`, `dblink`, or joining in PHP — each of which
means giving up transactions, foreign keys and the query planner across that boundary.

### 2.2 The fixture already contains the case that breaks

This is the finding that decides the question. In the seeded fixture:

| Company | Projects |
|---|---|
| **EPC-PARS** | BB-2, **PH-14** |
| **NIOC-AFF** | KISH-1, **PH-14** |
| **PMC-TEHRAN** | BB-2, **PH-14** |

**Three of eight companies already work on more than one project**, which is the normal condition
for an Iranian EPC contractor, not an edge case. Under option C:

- `rezaei@pars.example` becomes **two independent user rows in two databases** — verified:
  `A: user id 10` and `B: user id 10, a SECOND independent copy`. Two password hashes, two
  disable switches. Disabling a departing employee means remembering every project they touched.
- The page you land on after login — *"my documents, my punch items, my transmittals"* — cannot
  be produced by a query. Measured: the cross-project query returned **only project A's row**.
- `plans.max_projects` exists and **the subscription is sold per company, not per project**
  (`plans`: starter `max_projects=1`, full and special unlimited). Under C, "how many projects
  is this company using, and is it within its plan?" is a question no single database can answer
  — the billing boundary and the storage boundary would point in opposite directions.

### 2.3 Isolation is already achieved without it

The usual reason to reach for database-per-tenant is isolation. It is already there. The policy
on `documents` leads with a project clause, and binding a session proves it:

```
bound to PH-14 as EPC-PARS  -> SPK-A-001
bound to BB-2  as EPC-PARS  -> SPK-B-001      (same company, same connection)
no project bound            -> SPK-A-001, SPK-B-001
MC-KARAN bound to BB-2      -> 0              (not a participant)
```

The same company sees strictly one project's rows when bound to that project, both when bound to
neither, and a non-participant sees nothing. **Option C would buy isolation that already exists**,
and would replace a tested database-enforced rule with a "connect to the right database" rule
enforced by application code — which fails open if the routing is wrong, whereas RLS fails closed.

### 2.4 Per-project backup and export do not require it

The strongest honest argument for C is *"the client wants their project's data handed over on a
disk."* Measured: **RLS applies to `COPY` as well as `SELECT`**, so a project export is just a
bound session:

```sql
SET epcc.company_id = …;  SET epcc.project_id = …;
COPY (SELECT number, title_en FROM documents) TO STDOUT WITH CSV;
-- returns PH-14 rows only
```

`pg_dump` on a *schema* (option B) is genuinely self-contained and restorable alone. But the
handover deliverable an EPCC client actually signs for is not a database dump — it is the
dossier: certificates, transmittals, as-builts, the MC pack. That is an application-level export
either way.

### 2.5 Object count

38 tables today. At 50 projects: **1,900 tables and roughly 5,700 indexes** under option B, and
50 separate migration runs under C. Every schema change becomes a fleet operation — and a partial
failure leaves projects on different schema versions, which is a worse problem than the one being
solved.

---

## 3. What option C would cost, concretely

1. **Identity has to be split or duplicated.** `users`, `companies`, `roles`, `plans`,
   `project_company` are inherently cross-project. Either they live in a shared database — at
   which point it is no longer database-per-project, it is a hybrid with the worst of both — or
   they are duplicated, and the duplicates drift.
2. **The reference packs are unaffected** (ADR-007 put them in files precisely because they are
   universal), so that part is already immune.
3. **Foreign keys stop at the boundary.** Today `documents.project_id → projects.id` is enforced
   by PostgreSQL. Across databases it becomes a hope.
4. **Connection management.** Laravel would need a runtime connection per project; the current
   code has **zero** `DB::connection()` call sites and one `search_path` reference, so every
   query today assumes one connection. That is a small number, which is good news for option B
   and bad news for C — the assumption is uniform.
5. **The air-gap story gets better, not worse — but only at instance level.** See §5.

---

## 4. Recommendation

**Keep option A (shared database + RLS) for the hosted SaaS.** It is built, tested, and it is
the only option that lets one login see its work across several projects — which the fixture
shows is already the normal case.

**Offer option C at the level the customer actually means it: a whole separate instance.** When
NIOC says *"our data must not sit in the same database as a competitor's"*, they do not mean
"a separate PostgreSQL database inside your server" — they mean **on our own hardware, inside
our own network**. That is the on-premise/air-gapped deployment (MKT-EPCC-001, AG-01…AG-06),
where the entire application is separate, not just a database. This is also the honest answer
commercially: it is a different product tier, not a schema decision.

**Keep option B in reserve, and it is cheap to reach.** Because every table already carries
`project_id` and RLS is already project-aware, moving to schema-per-project later is mostly a
deployment change. Nothing in this ADR forecloses it.

---

## 5. When to revisit

Any one of these would justify reopening:

- A customer contractually requires **physical** separation but will not take an on-premise
  instance.
- A single project exceeds roughly 50–100 million rows in the hot tables and partitioning by
  `project_id` is no longer enough (the ADR-006 benchmark already showed full-scan aggregates
  are 11× slower under RLS — the fix there was incremental roll-ups, not separation).
- Per-project **restore to a point in time** becomes a contractual requirement. This is the one
  genuine capability option A lacks: today, restoring one project to yesterday means restoring
  everything. Option B solves it; option A does not.

---

## 6. Open question for the user

**What is driving the question?** The right answer differs completely:

- **"A client demands their data be physically separate"** → on-premise instance (option C at
  instance level), and it should be priced as such.
- **"I am worried one project's data will leak into another"** → already prevented, and
  demonstrated in §2.3; I can add the project-isolation assertions to the test suite as a
  standing guard.
- **"I want to hand a finished project to the client and remove it from the system"** →
  application-level archive + export, which is worth building regardless and does not need
  separate databases.
- **"I am worried about performance as projects accumulate"** → partitioning by `project_id`
  and the incremental roll-ups already required by ADR-006.
