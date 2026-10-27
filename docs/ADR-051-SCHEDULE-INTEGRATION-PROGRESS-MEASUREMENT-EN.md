# ADR-051 — Schedule integration (XER/MPP import) and event-based physical progress

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). Addresses the SCH=0 gap: without a schedule, nothing in the platform can be "late", and per-work-package material readiness (the SRS AWP promise) cannot be expressed. Principle from the process assessment: **import, don't build** — no scheduling engine inside the platform.

## Context

The platform holds dense, timestamped execution events but no plan to compare them against: fitted joints (`JointService`), accepted MTO rows (acceptance journal), issued POs with `promised_date`/`forecast_date` (slip already computed on the model), GRNs, custody records, issued/acknowledged ITP notifications, punch items. Deliverables have a register (plan dates are absent). The SRS commits to ISO 21500-style controls and AWP work-package taxonomy (CWP/EWP/PWP/IWP/TWP).

## Decision points (explicit owner choices required)

1. `schedule_source=xer_import_only | xer_and_mpp_xml | xer_mpp_and_ical_milestones`
   Recommended: **xer_and_mpp_xml** — Primavera P6 XER (the EPC standard) and MS Project XML; both are parseable offline and fail-closed (the house CLI pattern: exit 0/1/2 like `plan-mto-backfill.php`).
2. `schedule_role=reference_baseline_mirror | authoritative_schedule`
   Recommended: **reference_baseline_mirror** — the platform stores imported baselines + periodic updates as immutable snapshots (ordinal-versioned), never edits activities; P6/MSP remain the scheduling authority. This matches the platform's epistemics (a mirror says "as imported at T", not "the truth").
3. `activity_binding=manual_code_binding | rule_based_auto_binding | both`
   Recommended: **both** — activities bind to platform objects (activity code ⇄ line/joint-range/test-pack/MR/PO/deliverable) manually where needed, with rule-based proposals (code-mask parsing) that a human confirms. Unbound activities stay unbound and visible as such.
4. `progress_measurement=event_weighted_rules | manual_percent | both_with_audit`
   Recommended: **event_weighted_rules** — physical progress computed from bound events with published weight rules (e.g., joint: fitted 40% / welded+NDT-accepted 90% / tested 100%; PO: issued 20% / received 70% / accepted 100%), plus manual override **only** as an audited adjustment entry with reason. Rule sets are project-configured, versioned, never retroactively edited (append-only, digest-bound like PMS snapshots).
5. `reporting=s_curve_and_lookahead | full_planning_suite`
   Recommended: **s_curve_and_lookahead** — planned-vs-actual S-curves per system/area/discipline and a constraint-aware lookahead feed (what ADR-055 work packages need); not a full planning suite.

## Sketch of the decision

- Immutable import pipeline: file → parse → validate (fail-closed report) → `schedule_snapshots` (activity rows, relationships, calendars) with digest + ordinal version; diff report between snapshots (new/moved/deleted activities — the classic "schedule update games" become visible).
- Binding register: activity ⇄ platform object, append-only with supersession (a rebinding is a new row, not an edit).
- Progress engine: STABLE SQL/service functions computing weighted progress from bound events at a given cutoff — reproducible, explainable per activity ("which events produced this percent"), never a stored mutable number without journal.
- Late/forecast signals: promise-slip (exists on PO), deliverable plan-vs-actual (needs plan dates on the register — small extension), activity float from the latest snapshot.

## Consequences

- Aging/late-ness finally has a meaning platform-wide; ADR-049 deadlines and ADR-055 work-package release can reference float; dashboards (process assessment §3.2) gain their most requested curves.
- Import fidelity is the risk: calendars/relationships/lags are deep in XER; start with activities+dates+codes+hierarchy, add relationships incrementally.
- Test surface: parser conformance corpus (house style: golden files), snapshot immutability, binding supersession, progress reproducibility (same events+cutoff ⇒ same number), fail-closed on malformed imports.

## Out of scope

Resource/cost loading in P6 terms (cost is ADR-054 from platform data), schedule editing, what-if simulation, earned-value schedule variance beyond progress curves (ADR-054 covers cost side).
