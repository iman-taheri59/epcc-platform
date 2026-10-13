# DEFECT-001 — The baseline RLS policies silently bind to the outer table

**Status:** FIXED IN SOURCE AND VERIFIED ON TEST DATABASES (2026-09-28); not deployed to an application database. **Found:** 2026-09-23. **Recorded in the repo:** 2026-09-24.
**Severity:** wrong isolation on five tables — restrictive one way, stale-permissive the other.

> **Why this file exists.** This write-up lived in `/tmp/rls_defect.md` for a day
> and a half. It was flagged four times as "volatile, move it into the repo" and
> never was, and a sandbox reset destroyed it. It had to be re-derived from
> scratch. **A finding that is not in the repository does not exist.**

## The five tables

`baseline_batches` · `baseline_discrepancies` · `engineering_items` ·
`model_imports` · `model_import_diffs`

All five have `ENABLE` and `FORCE ROW LEVEL SECURITY`, and all five have a
policy. The policy is the problem.

## What was written

Migration `2026_09_??_090000_create_engineering_baseline.php`:

```sql
CREATE POLICY {$table}_tenant ON {$table}
USING (project_id IN (SELECT project_id FROM project_memberships
       WHERE company_id = current_setting('epcc.company_id', true)::uuid))
```

The intent is clear: *"you may see a row if your company participates in its
project."*

## What PostgreSQL actually stored

`project_memberships` has **no `company_id` column**. Its columns are:

```
id · user_id · project_id · role_codes · discipline_scope · authority_limit · timestamps
```

An unqualified column in a subquery that does not exist on the inner table does
**not** raise an error. SQL resolves it against the **outer** query, turning an
independent subquery into a correlated one. `pg_policies` shows what was really
created:

```sql
project_id IN (
  SELECT project_memberships.project_id
  FROM project_memberships
  WHERE baseline_batches.company_id = current_setting('epcc.company_id')::uuid
)
```

The `WHERE` clause no longer mentions the inner table at all.

## What that means in practice

The policy now reads: *"the row's own `company_id` must equal the current
company, and its `project_id` must appear somewhere in `project_memberships`."*

Two consequences, in opposite directions:

| | effect |
| --- | --- |
| **Too restrictive** | `company_id` on a baseline row records **who imported it**, not who may read it. A contractor imports an MTO and the owner, the consultant, the MC and the TPI — all legitimately on the project — **cannot see it at all**. |
| **Too permissive** | A company removed from a project still matches its **own** old rows, because nothing re-checks participation. |

It is not a cross-tenant data leak, which is why no test caught it. It is the
wrong question, answered confidently.

## Why no test caught it

The baseline tests write and read as **one** company inside
`runAs($companyId, …)`. Under that condition the broken policy and the correct
policy give the same answer. Isolation was never exercised from a *second*
company on the *same* project — which is exactly the case the policy exists for.

This is standing rule 12 recurring: **never claim isolation without a mirrored
test that proves the rows ARE visible to whoever should see them.** A test that
only proves "I see my own rows" passes against a policy that shows nothing to
anyone else.

## The correct shape

Standing rule 6 already states it: **participation is not membership.**
`project_memberships` is a *user*-to-project fact. `project_company` is the
**commercial** fact, and it is what every working policy in the codebase uses.
`piping_lines`, for instance, goes through `epcc_project_is_writable(project_id)`.

The fix is to route these five tables through the same helper rather than to
patch the subquery, so there is one definition of participation and not six.

## Before fixing

1. **Write the failing test first** — two companies on one project, one imports a
   baseline, the other must see it. It must fail against the current policy.
2. Remember that **blocked RLS writes are silent**: zero rows affected, no error,
   and `save()` still returns `true`.
3. **Never RLS-test on SQLite** — it has no RLS and the test would prove nothing.
4. Check whether the same unqualified-column mistake exists in any other policy
   written with a heredoc and an interpolated `{$table}`.

## The general lesson

> An unqualified column reference inside a subquery is a **silent correlation
> bug**. If the column does not exist on the inner table, SQL will find it on the
> outer one and the query will run, return plausible rows, and mean something
> else entirely. **Qualify every column in a policy subquery.**


## Repair — 2026-09-28

Forward migration: `2026_09_28_100000_fix_baseline_tenant_policies.php`.
Reads require actual commercial participation, optionally narrowed to the bound
project. Separate SELECT/INSERT/UPDATE/DELETE policies retain creator-company
write ownership, prohibit ownership transfer, and freeze all three write
operations on archived projects. Every policy subquery qualifies its columns;
unset/empty tenant settings fail closed without a UUID cast error.

`BaselineIsolationTest` covers mirrored shared reads, portfolio reads without a
project scope, nonparticipants, wrong-project scope, removal with stale user
membership, shared-read/non-owner writes, archive reads/inserts/updates/deletes,
row ownership transfer and explicit service/HTTP refusals. Baseline accept and
resolve now refuse a non-creator rather than returning apparent success for an
RLS UPDATE 0. Import-diff acknowledgement likewise rejects non-owner and archived
writes. This does not introduce cross-company approval powers.

The repair's initial archive test incorrectly used mass assignment on guarded
Project state fields. It was corrected to archive through ProjectArchiveService
and assert the persisted archived state before exercising RLS.

Original findings above are retained as the pre-repair analysis.
