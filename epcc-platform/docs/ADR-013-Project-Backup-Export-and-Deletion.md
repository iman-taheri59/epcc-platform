# ADR-EPCC-013 — Per-project backup, export and deletion

**Status:** **PARTLY ACCEPTED AND BUILT** — the user chose **archive** (§9), and archive is now built and shipped (see §10 and HANDOFF §5q). Export and ceremonial hard delete remain PROPOSED.
**Date:** 2026-09-15
**Depends on:** ADR-011 (one database per project — answered "no"), ADR-001 (RLS)
**Relates to:** ADR-012 (the policy change proposed there does not affect this)

**Trigger (user):** «آیا می‌توان از یک پروژه بکاپ گرفت و بعد اون پروژه رو کامل حذف کرد؟»

**Answer: yes — and I proved the full cycle end to end (export → delete → restore → verify) on a
throwaway copy of the real database. But four things break if it is done the obvious way, and one
of them silently destroys the backup.**

---

## 1. What was actually tested

A byte-for-byte copy of the live database (38 tables, all 24 RLS policies) was restored into a
scratch database `deltest`, then: PH-14 exported, deleted, restored, and re-validated. The scratch
database was dropped afterwards and the real one verified untouched (38 tables, 3 projects).

| Step | Result |
|---|---|
| Export PH-14 | **93 rows across 26 tables**, 25/25 `project_id` tables reconciled |
| Delete PH-14 | `DELETE FROM projects WHERE id=…` → **53 ms**, 23 cascades |
| Orphan hunt | **zero** rows left referencing the dead project |
| Other projects | KISH-1 and BB-2 intact; `users` 9, `companies` 8 untouched |
| Restore | all 26 tables match the manifest exactly |
| Integrity | 0 dangling revisions; every FK re-validated with `VALIDATE CONSTRAINT` |

---

## 2. Trap 1 — `pg_dump` as the application user silently truncates

This is the finding that matters most, because the naive answer to the user's question is
*"just run `pg_dump`"*.

```
$ pg_dump -U epcc -d epcc -f backup.sql
pg_dump: error: query failed: ERROR:  query would be affected by row-level
security policy for table "areas"
```

**It aborts — and still exits 0, leaving a 29 KB file** where the real dump is 166 KB. A backup
script that checks the exit code would report success and hand the customer a truncated file.

`FORCE ROW LEVEL SECURITY` applies to the table owner too (this is the same property recorded in
ADR-004: *a migration cannot read an RLS-forced table*). So:

- A per-project backup **cannot** be taken by the application's own database user.
- It must run as a superuser or a role with `BYPASSRLS`, which means **the backup job is a
  privileged component** and must be treated as one — not a feature the tenant can invoke directly
  against the database.

## 3. Trap 2 — `pg_dump --where` does not exist in PostgreSQL 17

Row-filtered dumps (`--table=documents --where=…`) are a **PostgreSQL 18** feature. On 17.11:

```
pg_dump: unrecognized option '--where=…'
```

So a per-project export must be built explicitly, table by table, with `\copy (SELECT … WHERE
project_id = …)`.

## 4. Trap 3 — a topological sort silently drops the entire document register

The obvious way to order 25 tables for a consistent restore is to sort them by foreign-key
dependency. I wrote that recursive query. It returned **21 tables, not 25**, and the four it
dropped were:

```
documents  document_revisions  document_files  document_review_comments
```

Because `documents.current_revision_id → document_revisions` and
`document_revisions.document_id → documents` form a **genuine cycle**, they have no topological
position and fall out of the recursion. The export would have completed, reported success, and
**contained no documents at all** — the single most valuable thing in an EPCC project.

Two fixes, both applied:
1. The table order is **explicit and reviewed**, not derived.
2. The restore runs under `SET session_replication_role = replica`, which defers FK checks so the
   cycle loads in one pass. Constraints are then re-validated explicitly.

**The manifest is what makes this safe**: every table's row count is recorded at export and
re-checked at restore. A silently-empty table is caught by a count of 0 against an expected 5.

## 5. Trap 4 — deleting the rows does not delete the files

```
DELETE FROM projects WHERE id = '…';     -- 53 ms, 23 cascades, zero orphans
$ find storage/app -path "*<project-uuid>*" -type f | wc -l
4                                         -- still there
```

No foreign key reaches the filesystem. The database is spotless and the client's drawings, MTCs
and certificates are still on disk. For a "delete the project" feature this is the **worst**
failure mode: it looks complete, it satisfies every database-level check, and it quietly retains
exactly the data the customer asked to have removed — which in a GDPR-style or contractual
deletion request is the entire point.

The one piece of good news: blob paths are **project-scoped**
(`documents/<project-uuid>/ab/cd/<sha256>.pdf`), and no blob is shared between projects (verified:
no `sha256` appears under two `project_id`s). So the file side is a directory removal, not a
reference-counted garbage collection — **provided that ordering is respected**: files first while
the rows still name them, or capture the path list before deleting.

---

## 6. What is destroyed, and why deletion must not be a button

For PH-14 alone, the delete removed:

| Artefact | Count |
|---|---|
| MC certificates (handover evidence) | 1 |
| **Payment certificates (money)** | 2 |
| Transmittals (formal correspondence) | 5 |
| Material inspections (QA evidence) | 3 |
| Punch items | 9 |

These are contractual records. In an EPCC context a payment certificate or an MC certificate may
need to be produced years later in a claim or a dispute. **"Complete deletion" and "we can still
prove what we certified" are in direct conflict**, and the product must not let one person resolve
that conflict with a single click.

Also: `projects_code_unique` means a deleted project's code becomes **reusable**. Creating a new
`PH-14` afterwards would produce two unrelated projects with one code in the archive — the audit
trail cannot distinguish them.

---

## 7. Proposed design

Three distinct operations, deliberately **not** one feature:

### 7.1 Export (safe, frequent, tenant-facing)
A read-only per-project archive: the 26 CSVs plus the blob tree plus `manifest.txt`, wrapped with a
`README` naming the schema version and the reference pack version (ADR-007 §7). Runs as a
privileged job, not as the tenant's own DB user (§2). **This is also the handover deliverable** and
is worth building regardless of whether deletion is ever implemented.

### 7.2 Archive / close-out (the usual answer)
`projects.state = ARCHIVED`: read-only, hidden from pickers, excluded from quotas and dashboards,
still queryable and still restorable. **This is what most customers mean by "remove the project"**,
and it is reversible. It also keeps `plans.max_projects` honest without destroying evidence.

### 7.3 Hard delete (rare, ceremonial, two-person)
Ordered, and each step verified before the next:

1. **Export** and verify the manifest — refuse to proceed if any count mismatches.
2. **Verify the archive is readable** from a different location (an unreadable backup is not a backup).
3. Record an entry in a **retention log that survives the deletion** — who authorised it, when,
   the manifest hash, the project code. This log must live outside the project's own tables, or it
   dies with the evidence.
4. **Delete blobs**, using the paths captured in step 1.
5. **Delete the row**; the 23 cascades do the rest.
6. **Re-run the orphan hunt** and confirm zero.
7. **Reserve the project code** so it cannot be silently reused.

Requirements: owner-company only, a second approver, a typed confirmation of the project code, and
a **cooling-off period** (soft-delete first, purge after N days) — because every irreversible
operation eventually gets clicked by accident.

---

## 8. Consequences

- The export/restore scripts are proven but live only in this ADR's evidence; they must become a
  tested artisan command (`project:export`, `project:purge`) with the manifest check as a hard gate.
- The privileged-role requirement (§2) means these commands need their own DB credentials, distinct
  from the app's. That is a deployment change, not just code.
- `authorization_denials` and `gate_evaluations` carry `project_id` with **no FK to projects**, so
  they are not cascaded. They are included explicitly in the export list, and the purge must delete
  them explicitly too — the orphan hunt in §1 only passed because the export script names them.
- Nothing here conflicts with ADR-012; the policy change proposed there does not affect export,
  which runs with RLS bypassed.

---

## 9. Open question for the user — ANSWERED

**What should the default be for a finished project — archive, or delete?**

My recommendation was **archive**, with hard delete available only on explicit contractual request,
because the artefacts in §6 are the ones a company needs when a claim arrives two years later.
If the requirement is driven by a client demanding their data leave your servers entirely, then
hard delete is right — but it should produce a signed export and a retention-log entry first.

**The user answered «آرشیو» (archive) on 2026-09-15.** Archive is therefore built (§10); hard
delete is *not* built, and deliberately so.

---

## 10. As built — archive

`ACTIVE ↔ ARCHIVED`, reversible. There is **no `DELETED` state**: putting deletion in the same
enum would make an irreversible act one dropdown position away from a reversible one.

| Concern | Decision |
|---|---|
| Read access | **Unchanged.** Every row stays visible and exportable. An archive you cannot read is a slow delete. |
| Write access | Refused for everyone, including the owner company. |
| Visibility | Hidden from the portfolio by default, behind a clickable count — never silently. |
| Plan quota | **Excluded from `plans.max_projects`.** |
| Who may do it | Owner company only (`TenantType::OWN`). |
| Provenance | `archived_at`, `archived_by`, `archive_reason`, enforced by CHECK constraint. |

**The quota exclusion is not a nicety.** A customer on a one-project plan who finishes a job and
cannot start the next without deleting the first will delete the first — the licence terms would be
quietly instructing them to destroy the evidence in §6. Archive only functions as an alternative to
deletion if it is free.

### Two layers, because each covers the other's failure

- **`ProjectArchiveService`** refuses first, loudly, in the user's language.
- **RLS** (`<table>_archive_freeze` + `<table>_archive_no_delete`, 22 tables) is the backstop, so a
  write path that forgets the service call is rude rather than destructive.

### Two findings that changed the design

1. **A blocked UPDATE/DELETE is silent.** INSERT raises `42501` naming the policy; UPDATE and
   DELETE match zero rows with no error, so `save()` returns `true` and the user is told their edit
   was saved. RLS can therefore never be the only thing refusing a write a human is waiting on.
2. **`WITH CHECK` is never evaluated for DELETE.** The first policy — `FOR ALL USING (true) WITH
   CHECK (...)` — blocked inserts and updates and let a DELETE empty an archived project's punch
   list (`DELETE 9`). `USING (true)` is deliberate, since a RESTRICTIVE `USING` clause also filters
   `SELECT`; the fix is a second policy scoped `FOR DELETE`. Verified: archived → `DELETE 0`, rows
   intact; reopened → `DELETE 9`.

Also required: `epcc_project_is_writable(uuid)` must be `SECURITY DEFINER`, or it is itself subject
to RLS on `projects` and the freeze **fails open**.

### Still proposed, not built

Export (§7.1) and ceremonial two-person hard delete (§7.3), including the four traps in §2–§5 —
the truncated `pg_dump` that exits 0, `--where` being PG18-only, the topological sort that drops FK
cycles, and blobs surviving the cascade. Archiving needs none of them, which is part of why it went
first.

## 11. PMS notes: missing SQL archive backstop found in source review (2026-09-29)

**Open conformity finding, not a new archive policy.** §10 requires both application refusal and database protection. The new `PmsEntryService::saveNote()` reaches its project-writable service check, but static inspection found the existing `piping_class_notes` write policy (migration `2026_09_24_120000_enrich_piping_class_header.php`) checks project/company scope without `epcc_project_is_writable`. Migration `2026_09_29_235000_add_pms_entry_provenance.php` adds archive restrictions to items, not notes.

Therefore the delivered note-edit route's refusal is not evidence of raw-SQL archive protection on the notes table. Track this as **PMS-ADR-05**; add and test the missing INSERT/UPDATE and separate DELETE backstops while retaining SELECT access. The old policy also directly casts restored GUC strings to UUID, unlike the corrected item policy; include empty/unbound-context regression coverage in that fix. No runtime SQL bypass experiment or application change was performed during this documentation review.

New append-only material review/disposition and PMS audit tables also need explicit treatment in any future export/retention tooling. This record does not turn the previously proposed export/hard-delete workflow into a delivered capability.

## 12. Recovery design and tool-claim review (2026-09-29)

**Safety review: the earlier spike narrative is not an approved general production-restore procedure.** [DR-05 and D-03](ADR-DECISION-REVIEW-2026-09-29.md) identify a technical error in §4: `session_replication_role=replica` disables ordinary FK checks; it is not constraint deferral. `VALIDATE CONSTRAINT` does nothing for a constraint already marked valid, so calling it after bypassed writes is not necessarily a rescan. See [PostgreSQL replication-role documentation](https://www.postgresql.org/docs/17/runtime-config-client.html#GUC-SESSION-REPLICATION-ROLE) and [1](https://www.postgresql.org/docs/17/sql-altertable.html).

The proposed recovery design must specify genuinely checked constraints, consistent database/file snapshots, dependency closure and content integrity, not row counts alone. No restore experiment or fix was performed in this review.

The §3 statement that PostgreSQL 18 provides `pg_dump --where` is not supported by the [official option list](https://www.postgresql.org/docs/18/app-pgdump.html); `--filter` selects object patterns. §2's absolute claim about RLS-user dumps is also too broad: [PostgreSQL 17 documents `--enable-row-security`](https://www.postgresql.org/docs/17/app-pgdump.html), which dumps visible rows, not necessarily a complete project backup. The reported historical exit=0 needs reproduction with the wrapper/pipeline and actual subprocess exit status; no general pg_dump behavior is asserted from it here.

Archive remains the accepted normal project lifecycle. These findings do not authorize or implement hard delete/export/restore, nor do they reverse the need for explicit completeness/security checks.

## 13. Accepted DR-05 — verified restore protocol (2026-09-29)

User selected `verified_restore_protocol`. Replace the §4 replica/VALIDATE recipe as an operational basis: disabling FK triggers is not deferring checks, and validating an already-valid constraint does not rescan restored data. Historical text/results are retained, but this recipe is not an approved safe restore procedure.

The replacement must actually enforce or validate applicable constraints, maintain a consistent database/files dependency snapshot, verify restored content rather than row counts alone, and report failure on integrity failures. Before claiming backup/restore readiness, isolated restore tests must demonstrate detection of orphan records, missing/corrupt files and interrupted/partial failures. The concrete export/restore mechanism remains to be designed and reviewed; this does not prescribe indiscriminate constraint drops or trigger disabling. Past spike exit-status claims were not rerun or verified here. No backup, restore, DB mutation, implementation or restore test was performed in this decision step.

## 14. PMS notes archive backstop implemented (2026-09-29)

Migration `2026_09_29_235700_create_pms_author_policies.php` closes §11/PMS-ADR-05 for `piping_class_notes`: NULL-safe company/project GUCs, participating-company restriction, archive INSERT/UPDATE checks and independent DELETE protection; reads remain. `PmsAccessTest::test_notes_sql_archive_backstop_preserves_reads_and_handles_empty_context` verifies INSERT/UPDATE refusal, DELETE 0 with retained content, reads and empty context. Existing ProjectArchive/FittingReview related set: 44 tests / 149 assertions passed. This is not a backup/restore implementation or a blanket direct-SQL per-user authoring authorization layer; see ADR-008 §18 and its report.


## 15. Manual-valve SQL archive backstop (2026-09-29)

During explicitly selected manual-valve entry (ADR-008 §23), source inspection found piping_class_valve_specs had no archive guards. This is the same kind of implementation omission as §11/14 notes; §10's accepted two-layer/read-preserving contract is unchanged.

Migration2026_09_29_235900 replaces unsafe UUID casts in scope/owned policies with NULLIF, adds project-company participation restriction, restrictive FOR ALL WITH CHECK epcc_project_is_writable(project_id), and restrictive FOR DELETE USING that function. Existing rows/read access under authorized project context remain; no data rewrite. The service still checks archive state first. Raw SQL UPDATE/INSERT refusal, DELETE0, retained read, successful reopened UPDATE and empty-context read were executed in PmsValveEntryTest. No universal human author SQL RBAC or restore proof is claimed. Forward-only down refuses to remove protection automatically. Full increment225/1085; dedicated24/158 included. [Evidence](PMS-MANUAL-VALVES-2026-09-29.md).


## 16. New PMS NDT source table archive protection (2026-09-29)

ADR-008 §25/25.1 adds piping_class_ndt_requirements, with null-safe project/company scope, participant read restriction, parent class/company/project consistency in write CHECK, restrictive archive write and separate delete guards. Service checks archive state before human writes. Dedicated NDT tests directly verify archived INSERT/UPDATE42501, DELETE0, retained read, reopened UPDATE, mismatched-parent refusal and empty-context read. No data backfill or automatic source deletion; migration235950 is forward-only. Human author policy remains service/audit-owned, and this is not verified backup restoration. [Evidence](PMS-NDT-REQUIREMENTS-2026-09-29.md).


## 17. MR author policies and scoped entry (2026-09-30)

ADR-018 adds append-only mr_author_policies. SELECT retains scoped participant access on archived projects; INSERT requires owner-company scope and epcc_project_is_writable, and no UPDATE/DELETE policies exist. Human configuration/creation re-read the project under the project row lock. Existing material requisition/item archive SQL guards remain. New nullable policy/source fields preserve legacy rows; the global numbering counter is initialized even for archived historical requests without resetting them. The archived upgrade fixture and HTTP read/write refusal are tested in the MR suite. This does not establish a new backup format or verified restore protocol; §13 remains open. Detailed evidence: [MR delivery](MATERIAL-REQUISITION-ENTRY-2026-09-30.md).
