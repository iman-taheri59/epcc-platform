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
