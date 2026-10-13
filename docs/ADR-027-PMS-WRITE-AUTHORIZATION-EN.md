# ADR-027 — PMS write authorization on the shared piping-class register

Status: **PROPOSED — owner decision needed before schema enforcement**  
Date: 2026-10-13  
Depends on: ADR-008 (P1 classes/rules), ADR-013 (archive freeze), ADR-026 (standalone author nominations), ADR-025 (parallel PMS schema conflict).

## Context and observed boundary

Run 38386417769 passed all six CI jobs, but that does **not** mean PMS authorship is protected. The append-only `pms_author_policies` register and `epcc_pms_author_policy(project,user,company)` currently support nomination and revocation only. `PipingClassService` has six mutation methods: `create`, `setFluidServiceCategory`, `setDesignBasis`, `addRule`, `addBranchRule`, `publish`. None checks the author policy. The two rule tables have direct SQL write paths; `piping_classes` is **shared** with the older piping-line register, which can insert a class without PMS fields. A blanket trigger on every class insert would break legitimate legacy line entry. There is no `pms_entry_events` or `piping_class_notes` table on this branch, and inventing either to satisfy a parallel migration is prohibited.

## Requirements for implementation

1. Default-deny for every PMS service mutation unless an active, participating PIPING member matches the **current** version of the project author policy. Revocation and suspension take effect before the next write. No owner, admin or legacy editor gains an implicit PMS exception.
2. Bind the project ID, company ID, actor ID and current policy version/ID within one transaction. Do not trust a client-provided ID, role, policy version or an Eloquent class instance that may be stale.
3. Serialize policy replacement with PMS writes using a shared project advisory transaction lock, followed by a lock on the affected class row for edits/publication. Read the current policy **after** the project lock. Make a writer who races a suspension lose or finish before that suspension commits; never permit a write after suspension commits.
4. Preserve archive freeze, RLS tenant isolation, draft-only behavior, publish coverage and B31.3 branch-pad validation. Do not weaken existing checks to simplify authorization.
5. Stamp every new PMS class/rule/branch-rule change with the author and author-policy identity (versioned evidence). A single `created_by` field is insufficient for later classification/design edits and publication: immutable event provenance or an equivalent explicit audit trail is needed. Historical/legacy rows stay distinguishable, not falsely attributed.
6. No database-only mutation route should be able to bypass PMS authorization by directly writing PMS-specific fields, rule tables or publication state. Any database guard must distinguish ordinary legacy class creation from PMS-specific writes on the same table. A security-definer helper must not bypass tenant RLS inadvertently; actor context cannot be accepted from an untrusted HTTP field.
7. Test: no policy/default denial; nominated author success for **each** of the six mutations; suspended/deactivated/removed-discipline/wrong-project/wrong-company refusals; legacy line registration still works; direct SQL mutation refusal; archived project refusal; stale class and concurrent publication/revocation; immutable, correctly scoped provenance. Run on PostgreSQL/RLS, not SQLite.

## Options

**A — service-only checks (not recommended as a complete gate).** Low migration cost but direct SQL and any future writer can bypass them; no reliable provenance for edits. Useful only as an interim, explicitly partial safeguard.

**B — dedicated PMS draft aggregate and tables.** Cleaner separation from legacy class registration, but this replaces current P1 data shape and foreign-key consumers. It is a schema/product decision, not an incidental CI fix.

**C — shared register with explicit PMS activation + DB backstop (recommended).** Keep `piping_classes` IDs/FKs and legacy creation. Define a distinct PMS activation boundary (for example an immutable activation event or a separate one-to-one PMS authoring record), with policy-bound provenance for each PMS write; use project advisory locks in policy configuration and all mutation transactions, class row locks for edits/publish, and carefully scoped database triggers/policies to reject direct PMS-only changes. Treat legacy rows without declared DN coverage as unactivated drafts; converting one into a PMS-authored class must be an explicit authorized operation. This is more work than adding `allows()` calls, but it is the first option that can substantiate a full authoring-security claim without breaking the line register.

## Decision requested

Should the existing shared `piping_classes` table remain the long-term identity for BOTH the line register and PMS, with explicit authorized activation (option C), or should PMS classes become a separate aggregate with an explicit mapping to the line register (option B)? Until decided, do not add a service-only check and advertise it as complete authoring enforcement. No new table, trigger or policy has been introduced by this PROPOSED ADR.

## Falsifiers / acceptance evidence

A green migration is not sufficient. Demonstrate a legacy line can still use its class, then show all six PMS mutations denied before nomination and after suspension, accepted when nominated, and blocked by direct SQL without the authorized actor context; demonstrate project/company cross-tenant negatives and concurrent suspension ordering. The saved policy/version and author provenance must be inspectable after a new policy replaces the old one. CI must finish with no skips.

## 2026-10-13 owner correction: class precedes line registration

The owner clarified: **piping classes must be created and registered before
lines are entered into the line register**. The class is therefore upstream
master data, not a side effect of line creation. This supersedes the context
paragraph's implication that ordinary line entry should be allowed to create
new class identities on demand. Existing `piping_lines.piping_class_id` is
nullable and `PipingLineService` can currently register a line without a
class; tests also call `PipingClass::firstOrCreate` as a fixture convenience.
Those facts are current implementation, not the approved target workflow.

Revised preferred direction: keep the shared `piping_classes` identity, but
make class registration an explicit preceding operation. A line must select an
existing class **from the same project**; neither number composition nor
import may silently invent one. Registration/activation/publication are
separate states: the owner has NOT yet specified whether selecting a class on
a line requires a published PMS, or whether a registered draft is sufficient.
Nor has an exception for historical line imports lacking class mapping been
approved. Resolve those two details before adding a NOT NULL constraint or
rejecting existing-line imports. Author nomination is still not an enforced
PMS write gate.
