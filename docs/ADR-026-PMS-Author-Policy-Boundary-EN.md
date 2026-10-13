# ADR-026 — Standalone PMS author nomination on this branch's schema

Status: IMPLEMENTED IN PART — policy catalogue/lookup only; PMS write enforcement deferred
Date: 2026-10-12
Related: ADR-008, ADR-025, P-25 step 4

## Context

The parallel migration `2026_09_29_235700_create_pms_author_policies.php` creates an append-only policy and a SQL lookup, **but also** alters `pms_entry_events`, installs an event-author trigger, and modifies `piping_class_notes` RLS. Those latter two tables are absent from this branch's independent PMS schema; importing the parallel PMS migrations would collide with this branch's `piping_class_rules` and invalidate its existing publish gate. GitHub CI run 38347536130 failed during `migrate:fresh` at the absent entry-event table.

## Decision and implemented boundary

Adapt 235700 to keep only `pms_author_policies`, its owner/participant RLS, append-only version/actor trigger, and fail-closed `epcc_pms_author_policy(pid,aid,cid)` lookup. Remove **only** the references to nonexistent parallel PMS event/notes tables and their associated triggers/policies. This is an adaptation, not a verbatim port. `PmsAccessService` already calls this SQL lookup; `PmsAuthorPolicyTest` covers unconfigured denial, nomination, suspension and history without requiring the parallel PMS schema. No default author is granted by migration or seeder.

**Security boundary:** absence of the parallel `pms_entry_events` trigger must NOT be described as a working authoring gate. This branch has no parallel event table to protect, and existing `PipingClassService` writes are not yet checked against this policy. The standalone policy currently represents a nomination decision/query only; it does **not** authorize or enforce the present branch's PMS write methods. Before enabling an HTTP PMS authoring surface, integrate a mandatory current-policy check into every PMS write operation (create, design/classification edits, size-band rules, branch rules, publish as appropriate), pin the policy identity in audit provenance, enforce checks within transactions against role/revocation races, and add negative/positive tenant tests. Do not wire only one endpoint and call the system protected.

## Verification

Migration and `PmsAuthorPolicyTest` require the GitHub Actions PostgreSQL/RLS job. The earlier CI blocker should be removed; further integration failures may become visible and must be fixed independently. The policy does not depend on or substitute for MR-specific `mr_author_policies` — those remain separate. No production migration rollbacks are promised for append-only policy history.

## First CI result after adaptation (run 38361131058)

Migration 235700 now passes. The next unrelated blocker is `2026_10_11_110000_make_rls_project_scope_sargable.php`: its generic `STANDARD_CHECK` assumes `mto_staging_rows.company_id`, but that child table has no such column. Its original RLS delegated through `mto_revisions`. Fixed the sargability migration with an explicit staging policy preserving parent revision and project/participant checks (no fabricated column). This is an adjacent CI-discovered schema defect, not a reason to weaken PMS policy isolation. Full PHPUnit still awaits a clean migration.

### 2026-10-13 integration follow-up: existing class register

CI run 38361941268 passed the author-policy, RLS and extension migrations but
failed at PMS P1: `piping_classes` already exists since the piping-line register
migration (2026-09-15). The P1 migration now **extends** that shared register
rather than recreating it, preserving line/MTO/estimate foreign keys, IDs and
legacy descriptive fields. Legacy rows receive names from their descriptions
(or code), remain DRAFT and have an unknown `dn_span` (NULL); publication
rejects an unspecified span rather than inventing engineering coverage. The
pre-existing tenant and archive policies on the register are retained; P1 adds
policies only for its new child rule tables. Migration rollback removes only
P1's additions, not the shared register. CI verification remains pending.

### 2026-10-13 legacy class insert compatibility

The pre-existing line editor creates `piping_classes` with `description_en`
and without the later PMS `name_en`/`name_fa` fields. The initial P1 extension
made both new names NOT NULL, causing older line/component tests to fail. A
small BEFORE INSERT/UPDATE trigger now fills absent names from the legacy
bilingual descriptions, falling back to code; explicitly supplied PMS names
remain intact. This does **not** fabricate DN coverage or authorize publication.
A regression test checks a legacy insert under tenant scope. CI verification
pending. This is a compatibility adapter for the shared register, not a new
PMS authoring path.

Repeated `migrate:fresh` on one PostgreSQL database does not remove a
standalone PL/pgSQL function when its trigger table is dropped. CI run
38370511965 exposed duplicate-function errors on subsequent tests. The
function definition is now replaced on re-migration; the trigger is recreated
on the freshly created table. `down()` still drops the trigger and function.
