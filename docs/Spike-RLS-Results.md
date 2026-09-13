# Spike results — Eloquent + PostgreSQL Row-Level Security

| Field | Value |
|---|---|
| Document ID | SPK-EPCC-001 |
| Date | 2026-09-09 |
| Spike | ADR-003 §5 "ORM choice", restated by Annex A §3.4 as *Eloquent + session-scoped RLS under Octane* |
| Verdict | **PASS — with three defects found and fixed.** Recommend proceeding on Eloquent + RLS. |
| Evidence | `app/tests/Feature/RowLevelSecurityTest.php` (10 tests), `CertificateScreenTest.php` (8 tests) |

---

## 1. What was built

A working vertical slice, not a toy:

```
PostgreSQL 17 + RLS  ->  Eloquent  ->  epcc/standards-engine  ->  bilingual Blade screen
```

- 6 tables. `work_packages` uses the ADR-002 Seam 1 shape: one table, `type` discriminator, self-referencing `parent_id`.
- RLS enabled **and FORCEd** on the three tenant-scoped tables.
- Tenant bound per transaction via `set_config('epcc.company_id', ?, true)`.
- Seed: one employer project, two competing EPC contractors, one consultant, two صورت‌وضعیت.

**Note on version:** the spike ran on **PostgreSQL 17**, not the 18 named in ADR-003 — 17 is what Debian 13 ships. RLS semantics are unchanged between the two, so the findings hold. Re-run against 18 before production.

---

## 2. Does it work?

Yes. Verified behaviour, all covered by tests:

| Actor | Sees | Why |
|---|---|---|
| Pars (contractor) | own certificate only | tenant predicate |
| Sahand (rival contractor) | own certificate only | **commercial firewall holds** |
| NIOC affiliate (employer) | both | project-owner predicate |
| Tehran Consulting | nothing | participates, owns nothing, doesn't own the project |
| **nobody bound** | **nothing** | fail-closed |

Also verified: raw `DB::select()` is filtered too (the backstop works when Eloquent is bypassed), and forging another company's `company_id` on insert is refused by `WITH CHECK`.

---

## 3. Three defects found

The spike earned its keep here. All three were real, and two were silent.

### 3.1 A committed `SET LOCAL` leaves the GUC as `''`, not NULL

```
never set in session        -> current_setting(..., true) IS NULL
SET LOCAL, then COMMIT      -> current_setting(..., true) = ''      <-- !
```

The original policy cast directly (`current_setting(...)::uuid`), so after any bound transaction committed, subsequent unbound queries on that **reused connection** threw `invalid input syntax for type uuid: ""` instead of returning zero rows.

It failed *closed* — no data leaked — but under Octane, where connections are long-lived and reused constantly, this is a 500 on every unscoped query.

**Fix:** `NULLIF(current_setting('epcc.company_id', true), '')::uuid`. Migration `..._100200_fix_rls_empty_guc`.

This is precisely the failure mode Annex A §3.4 predicted. It would have been found in production otherwise.

### 3.2 The audit table conflated "who owns the object" with "who acted"

`gate_evaluations` had one `company_id`. When the employer opened a contractor's certificate, the audit row was written with the *contractor's* id while the acting tenant was the employer — `WITH CHECK` correctly refused it, and the employer's page 500'd.

RLS was right; the schema was wrong. An audit row records *an actor performing an action*, so its tenant column must be the actor.

**Fix:** split into `actor_company_id` (the RLS tenant column) and `subject_company_id`. Migration `..._100300_fix_gate_evaluations_actor`.

Side benefit: "the employer checked Pars's certificate at 14:32" is now expressible — it wasn't before, and it is exactly what an auditor asks.

### 3.3 `TenantContext` was not a singleton

Laravel handed `GateService` a *different* `TenantContext` instance whose in-memory `companyId` was null, so it fell back to the certificate's owner and stamped audit rows with the wrong company.

**Fix, two parts:**
1. Register `TenantContext` as a singleton.
2. Make `GateService` read the actor from **PostgreSQL** (`currentGuc()`), not PHP memory. The GUC is what RLS enforces against, so it is the only trustworthy answer to "who am I acting as?".

The second part matters more than the first. Object state can drift from database session state; the database is the source of truth.

---

## 4. Rules that fall out of this

Adopt these as standing engineering rules:

1. **`SET LOCAL` requires an open transaction.** Outside one, PostgreSQL discards it silently — which fails *open*. `TenantContext::bind()` throws rather than allow that. Tested.
2. **Always `FORCE ROW LEVEL SECURITY`.** Without it the table owner bypasses the policy and the backstop is decorative. Easy to miss, silent when wrong.
3. **Always `NULLIF(..., '')` around a GUC** before casting.
4. **Audit tables are scoped by actor,** never by the subject's owner.
5. **Never run the RLS suite on SQLite.** SQLite has no RLS, so every isolation test would pass vacuously. The suite is pinned to PostgreSQL (`phpunit.xml`), and a separate `epcc_test` database exists for it.
6. **Read tenant identity from the database session,** not from an injected object.

---

## 5. Cost

Roughly 90 minutes including the three defects. Annex A §3.4 called Eloquent+RLS "a less well-trodden path… a spike, not a blocker." That was right.

No Eloquent feature had to be given up. No global scopes, no repository indirection, no query-builder wrapper — models are ordinary Eloquent models and the policy sits underneath them.

---

## 6. Still open

- **Octane not yet tested.** The slice runs on `artisan serve` (one process per request). The connection-reuse hazards above are *reasoned about* and defended, but not yet proven under a long-lived worker. **Re-run this suite under Octane/FrankenPHP before committing to it.**
- **PostgreSQL 18** — re-verify (expected to be a formality).
- **Connection pooling (PgBouncer)** in transaction mode should be compatible with `SET LOCAL`, but is untested here.
- **Performance** — the employer predicate runs an `EXISTS` subquery against `projects` per row. Fine at demo scale; needs an index review and an `EXPLAIN` at realistic volume.

---

*End of document — SPK-EPCC-001*

## 8. Multi-project outcome (2026-09-09)

The spike was extended from one project to many. Findings:

**Defect 4 — a company-only RLS predicate is not enough.** The original policy
compared `company_id` to the GUC and never consulted `project_company`. A
company holding rows tagged with its own id on a project it had left would
still have seen them. The predicate now requires participation:

```sql
company_id = current_company()
AND EXISTS (SELECT 1 FROM project_company pc
            WHERE pc.project_id = payment_certificates.project_id
              AND pc.company_id = current_company())
```

**Defect 5 — "no gate" and "passed the gate" rendered identically.** KISH-1
runs the `iso` pack, which defines no `interimPaymentCertificate` gate. The
engine correctly passes such objects (corpus MISC-01), but the screen showed a
green *Cleared* badge — asserting an assurance no rule had provided. `Verdict`
gained `gateEvaluated` / `isUngated()`, the UI renders a distinct dashed
*Not gated* state with an explanation, and the portfolio counts ungated
certificates in their own column. Corpus v1.2.0 adds **MISC-04** and pins
`ungated` on MISC-01; mutant #7 (engine forgets the flag) fails 2 cases.

**Project scope is optional and additive.** `epcc.project_id` unset means
"every project I participate in" (the portfolio); set means one project. Scope
is applied with `SET LOCAL` inside the same transaction as the company bind,
so it cannot outlive the request.

### Verified multi-project matrix (HTTP + tests)

| Acting company | Portfolio | PH-14 | BB-2 | KISH-1 |
|---|---|---|---|---|
| EPC-PARS   | PH-14, BB-2   | own row only | own row only | **404 / RLS empty** |
| EPC-SAHAND | PH-14         | own row only | **404** | **404** |
| EPC-ARVAND | KISH-1        | **404** | **404** | own row (ungated) |
| NIOC-AFF   | PH-14, KISH-1 | both rows | **404** | own row |
| NIGC-REF   | BB-2          | **404** | row | **404** |
| PMC-TEHRAN | PH-14, BB-2   | none (no rows) | none | **404** |

404 is the controller; RLS refuses the same reads independently when the
controller is bypassed, and `WITH CHECK` refuses writes into a non-participant
project. Per-project serials confirmed: `BB2-SV-0001` and `KISH-SV-0001` both
carry serial 1.

### Standing rules (added)

7. **Scope by participation, not by ownership of the row.** Tenancy is
   `company × project`, never company alone.
8. **Never render an ungated object as cleared.** Absence of a rule is not
   evidence of compliance, and a green badge claims otherwise.

## 9. Octane outcome (2026-09-09) — the last open item, now CLOSED

ADR-003 Annex A §3.4 flagged Octane as the place this whole scheme could fail:
a long-lived worker reuses its database connection, so any tenant state left
behind becomes the next request's starting state. Tested live against
**FrankenPHP 2 workers** (deliberately few, so requests are guaranteed to share
a process).

### It holds

| Probe | Result |
|---|---|
| 400 interleaved requests, 4 tenants | **0 leaks**, 0 missing rows |
| 240 concurrent requests, 4 tenants in flight | **0 leaks**, 0 missing rows |
| Fresh request after a bound one (same PID 15640) | company GUC `null`, project GUC `null` |
| Cross-project 404 under Octane | still refused |

`SET LOCAL` inside a mandatory transaction is what makes worker reuse safe: the
GUC dies at commit, so a worker cannot carry identity forward even though the
process, the container and the connection all persist.

### And the counterfactual is genuinely dangerous

To prove the rule earns its keep, a route deliberately used a plain `SET`
instead — the pattern the standing rules forbid:

- The identity persisted into **eight subsequent unrelated requests** on the
  same worker.
- An unbound read on the poisoned connection returned
  `["BB2-SV-0001","PH14-SV-0007"]` — another tenant's real certificates —
  where a clean worker correctly returned `[]`.

That is a full cross-tenant data breach, reproduced on demand. It is now pinned
by `tests/Feature/ConnectionReuseTest.php` (4 tests, 38 assertions), which
asserts both that `SET LOCAL` does not survive its transaction *and* that a
plain `SET` does. If the second test ever stops leaking, the reasoning behind
the rule has changed and it must be re-derived rather than quietly dropped.

### Performance

Same URL (`/projects/PH-14?as=EPC-PARS&lang=fa`), 60 samples each, real
PostgreSQL queries and RTL Blade rendering:

| Server | p50 | p95 | mean |
|---|---|---|---|
| Octane / FrankenPHP (2 workers) | **10.1 ms** | 11.5 ms | 10.1 ms |
| `artisan serve` (single process) | 28.2 ms | 32.7 ms | 28.7 ms |

**2.8× at p50, 2.9× mean** — inside the 2.5–3× band ADR-003 cited. Note the
baseline is `artisan serve`, not tuned PHP-FPM, so treat this as directional.

### Operational notes

- The FrankenPHP binary is **166 MB**. It is symlinked to
  `/home/user/.cache/epcc-bin/frankenphp` and gitignored — left in the project
  root it would blow the workspace snapshot budget on its own.
- `TenantContext` is a singleton whose PHP properties *do* survive requests.
  This is safe only because authorization reads the database session
  (`currentGuc()`), never the property — standing rule 6, now load-bearing
  rather than stylistic.

### Standing rules (added)

9. **Never use plain `SET` for tenant state.** Demonstrated to leak across
   eight requests and expose another tenant's rows. `SET LOCAL` + an open
   transaction, always.
10. **Ship Octane with `--max-requests`.** Worker recycling bounds the blast
    radius of any state that does escape.

### Remaining open items

PgBouncer in transaction pooling mode (untested — `SET LOCAL` is compatible in
theory, but this must be verified before production), PostgreSQL 18 re-check,
and `EXPLAIN` on the EXISTS predicate at realistic row counts.

## 10. PgBouncer outcome (2026-09-09) — production-blocking item, now CLOSED

The last unverified deployment assumption. **PgBouncer 1.24.1**, `pool_mode =
transaction`, `default_pool_size = 2` (deliberately tiny, to force many tenants
and many client connections onto very few backends).

### Result: safe, and for a specific reason

| Probe | Result |
|---|---|
| `SET LOCAL` in tx1, read in tx2 on the same pooled backend | **NULL** — cleared at COMMIT |
| Full Laravel suite through the pooler | **54/54 passed** |
| 60 tenant-switching transactions via Eloquent | 0 leaks |
| Octane (2 workers) + PgBouncer, 400 interleaved requests | **0 leaks** |
| Octane + PgBouncer, 240 concurrent requests | **0 leaks** |
| Write survives COMMIT through the pooler | yes |

Transaction pooling is safe **only because** tenant state is `SET LOCAL` inside
a mandatory transaction. PgBouncer returns the backend to the pool at COMMIT —
the same instant PostgreSQL discards the GUC. The two boundaries coincide
exactly, which is why the scheme composes.

### The counterfactual is worse here than under Octane

Repeating the forbidden plain `SET` through the pooler:

```
client 1: SET epcc.company_id = '<Pars>'   -> backend 16565
client 2 (SEPARATE connection, nothing bound):
          select ... from payment_certificates
          -> "PH14-SV-0007, BB2-SV-0001"
control (clean backend): -> (nothing)
```

Under Octane a leak was confined to one worker process. Through a transaction
pooler it crosses **client connections** — a different user, on a different
worker, on a different machine, inherits the identity. Same root cause, larger
blast radius.

### Cost

| Stack | p50 | p95 | mean |
|---|---|---|---|
| Octane direct | 10.1 ms | 11.5 ms | 10.1 ms |
| Octane + PgBouncer | 11.6 ms | 12.6 ms | 11.6 ms |

**~1.5 ms (15%)** for the extra hop, in exchange for connection multiplexing.

### What this constrains, permanently

Transaction pooling breaks anything session-scoped. The codebase currently uses
none of it, and `tests/Feature/PoolerCompatibilityTest.php` (5 tests, 65
assertions) now guards the source tree against `pg_advisory_lock(`, `LISTEN`
and `NOTIFY` creeping in — mutation-verified: injecting a session advisory lock
fails the guard. If one of these is ever genuinely needed it must go on a
separate, session-pooled connection, as a deliberate decision.

`config/database.php` (which had been lost in the snapshot truncation and was
silently falling back to framework defaults) is now published and carries these
constraints as a comment on the pgsql connection.

### Standing rules (added)

11. **Tenant state must be transaction-scoped, because the pooler recycles at
    the transaction boundary.** This is the same rule as #9, but the pooler
    raises the penalty from cross-request to cross-connection.
12. **No session-scoped PostgreSQL features on the main connection.** Guarded
    by test.

### Remaining open items

PostgreSQL 18 re-verification (Debian trixie ships 17; the ADR's "PG 18" is
still aspirational) and `EXPLAIN` on the EXISTS predicate at realistic row
counts. Neither is deployment-blocking.
