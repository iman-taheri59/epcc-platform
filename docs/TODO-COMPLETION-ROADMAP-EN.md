# TODO — EPCC System Completion Roadmap

> **Completion roadmap & task list (EN).** Generated 2026-10-26 from the full code-level
> review (`EPCC-REVIEW-FA.md`, `EPCC-BRANCH-REVIEW-FA.md`) and the process assessment
> (`EPCC-PROCESS-ASSESSMENT-FA/EN.md`). Twelve decision skeletons are filed alongside as
> **ADR-047 … ADR-058 (status: PROPOSED)**. House method per increment: owner decisions in
> the ADR → design/guide (EN+FA) → migrations with RLS+FORCE → services with the existing
> discipline (append-only, freshness checks, fail-closed) → tests → evidence per
> `docs/EVIDENCE-POLICY.md`. Persian twin: `TODO-COMPLETION-ROADMAP-FA.md`.

## Conventions for this file

- Every task has a checkbox; when done, tick it and write the commit SHA next to it.
- "Acceptance criteria" per task = the tests that must go green (the same test-first pattern already in use).
- Sizes: S ≈ one small increment (a few days), M ≈ medium (1–2 weeks), L ≈ large (a full cycle).
- Dependencies are explicit; do not start a task with open dependencies.

---

## 0) Immediate work and open review debts (no new ADR needed)

- [ ] **0.1 Monitor Actions billing** — zero-step runs (3-second failures with no steps)
      mean the billing wall, not code. Check the run status in Actions after every push. (S)
      Acceptance: the CI run on the latest main commit is green or explicitly zero-step (not a mid-run failure).
- [ ] **0.2 Parallelize the suite** — the serial suite takes 59 minutes; it makes every
      increment's feedback loop expensive. Options: Paratest, or a scoped CI matrix
      (Piping / Inspection / Procurement / Core, one job each). (S–M)
      Acceptance: full-suite wall time (local or CI) under ~25 minutes with no tests removed.
- [ ] **0.3 Risky test without assertion** — from the phase-1 review: one test has no
      assertion. Identify with `--display-risky` and add a real assertion or remove it consciously. (S)
- [ ] **0.4 Promote the Python harnesses into CI** — `scripts/tests/*.py` (MTO journal
      concurrency, notification time UI) run outside any runner (debt B7). Either add them
      to the workflow or to `scripts/run-tests.sh` with loud skip reporting. (S)
- [ ] **0.5 Convert disclaimers into status badges** — pages are heavy with "this is not a
      technical acceptance / not a release" text; render the same boundaries as visual
      badges/states (requirement: keep the legal wording in tooltips/guides, not five
      paragraphs on the page). Update PDF/view tests. (M)
- [ ] **0.6 Role-based process manuals** — one end-to-end bilingual guide per active module
      ("one material item from MTO to GRN: who, where, when") — pattern: the existing
      PIPING-*-EN/FA guides, but narrating the workflow instead of legal boundaries. (M, gradual per module)
- [ ] **0.7 Keep the README status matrix alive** — the "Current truth" table is the only
      status source; no new banners (archiving in `docs/STATUS-BULLETIN.md` continues). (ongoing)

---

## Phase A — Close the materials backbone (depends on: nothing; recommended start: immediately)

> Goal: end the "two parallel material truths" and close the quality loop. ADRs: **047, 048** (+ implementing the existing ADR-031).

- [x] **A.1 Decide ADR-047** — the four decision keys (target core, legacy fate, stock
      ledger model, opening physical count). (2026-10-27: on the owner's start-execution
      instruction all four recommended options were adopted and the ADR moved to
      ACCEPTED; revising any key is an append-only amendment to that ADR.)
- [x] **A.2 Append-only stock ledger** — `stock_entries` (GRN +, issues −, adjustments ±
      with reason and dual authority) + balance projection + ordinal clock (the
      `epcc_mto_clock_step` pattern). Depends: A.1. (Slice 1, service-only, done — commit
      `b648866`: `stock_ledger_entries` journal + clock + `epcc_stock_balance` +
      insufficient-balance guard under an advisory lock + dual-person authority +
      automatic RECEIPT from the controlled GRN credit; 10 tests/81 assertions green +
      60-test regression green. Guides: `docs/STOCK-LEDGER-EN.md`/`-FA.md`)
      Acceptance: Σentries=balance invariant under concurrency ✅; RLS+FORCE ✅; separation
      of duties at person level ✅ (role level → A.2b); fail-closed test on legacy writes
      after cutover ✅.
- [ ] **A.2b Stock ledger — slice 2** — role-scoped `stock_ledger_author_policies` table
      (the `piping_receipt_author_policies` pattern), an HTTP/UI surface for posting and
      reading the journal, and a per-project cutover register (authority-routed, replacing
      the global config flag). Depends: A.2 ✅. (M)
- [x] **A.3 Legacy core cutover** — `WarehouseService` writes → fail-closed refusal behind
      a cutover flag; history stays readable; EN/FA guide. Depends: A.2. (Done — commit
      `b648866`: `EPCC_WAREHOUSE_LEGACY_WRITES_REFUSED` fail-closes all five legacy stock
      writers before any other validation; reads stay open; piping was already closed.
      Per-project granularity → A.2b)
- [x] **A.4 Inspection/test result records (ADR-048) — slice 1** — `joint_weld_records`
      (WELD/REPAIR_WELD per FITTED joint, stamp-code identity), `joint_ndt_results`
      (per joint per method with repair + re-examination lineage), `test_pack_results`
      (hydro/pneumatic per READY pack with retest lineage), `material_certificates`
      (MTR to exact heat, optional lot). Two-mode internal/external-register per
      ADR-031. (Done — commit `29c138d`: `InspectionResultService` + append-only
      RLS+FORCE tables + ordering/lineage/provenance triggers; `InspectionResultTest`
      10/69 green + regression 77/275 green. Guides:
      `docs/INSPECTION-RESULTS-EN.md`/`-FA.md`. ITP stage sign-off split to A.4b)
      Acceptance: inspector ≠ notifier ≠ acceptor separation ✅ at person level (role
      level → A.4b); result immutability ✅; external-register honesty ✅ (both
      directions CHECK-enforced); piece→heat→MTR traceability ✅ (exact-heat reader).
- [x] **A.4b Inspection results — slice 2, part 1: nominated authority + evidence-gated
      pack states** (Done — commit `d257468`)
      * `result_recorder_policies` (versioned, suspendable, RLS+FORCE, selectors
        `user:<uuid>` / `role:<company>:<code>`) + `epcc_result_nomination()`; the three
        slice-1 guards now STAMP and REQUIRE the authorising nomination
        (`recorder_policy_id`, `RESULT_AUTHORITY_NOT_NOMINATED` 42501). Recording was
        membership-implied — any project member could create the record a client reads;
        it is now default-deny, and `discipline_scope` excluding PIPING disqualifies.
      * `epcc_guard_test_pack_transition()` on `piping_test_packs`: TESTED requires a
        recorded result; ACCEPTED requires the LATEST result to be PASS; ACCEPTED is
        terminal; DRAFT→READY→TESTED→ACCEPTED cannot be skipped — enforced against
        direct SQL, with `PipingTestPackService` mapping refusals to bilingual errors.
        READY stays an application-level declaration on purpose.
      * Tests: `ResultAuthorityAndGatingTest` 13/40 green; `InspectionResultTest` (10/69)
        + `ItrSnapshotTest` (10/72) + Joint/PipingGrn/StockLedger/Cde/Component/UnitPref
        green together (218 tests / 819 assertions). Fixture:
        `tests/Support/ResultRecorderPolicyFixture.php`.
      Acceptance: un-nominated recording refused with nothing written ✅; nomination
        stamped on the row ✅; suspension revokes ✅; TESTED/ACCEPTED held to evidence ✅;
        accepted pack cannot change ✅.
- [x] **A.4c Inspection results — slice 2, part 2: units + nomination authorship**
      (Done — commit `53d1942`)
      * `test_pack_results_pressure_unit_is_pressure`: a CHECK generated at migration
        time from the same `units.ref.json` the `UnitRegistry` reads (case-insensitive),
        so DB and app cannot drift and a missing reference pack fails the install.
        `InspectionResultService` rejects unknown units AND known units of the wrong
        quantity; `PipingTestPackService` gained the same quantity check for the
        PLANNED pressure, which previously accepted a temperature as a pressure.
      * `epcc_guard_result_policy_author()`: a nomination may be authored only by the
        nominated company itself or the project employer, and only by a project member
        — a rival contractor can no longer nominate someone else's inspectors.
      * Tests: `ResultUnitsAndPolicyAuthorshipTest` 10/31 green; related regression
        175 tests / 540 assertions green (owner instruction: the full suite now runs in
        CI on every push instead of blocking the local loop — RUNBOOK §12).
      Acceptance: unknown unit and wrong-quantity unit refused, incl. direct SQL ✅;
        every PRESSURE unit in the pack accepted in any case ✅; third-party authorship
        refused ✅; non-member and forged authorship refused ✅.
- [x] **A.4d Inspection results — slice 2, part 3: ITP hold/witness sign-offs**
      (Done — commit `d7d5ce8`)
      * The gap: a notification only ever said "we asked you to attend". Nothing recorded
        what happened at the stage, so a hold point nobody attended and one the client's
        inspector attended and accepted looked identical in the data.
      * `inspection_stage_signoffs` (append-only, RLS+FORCE, forward-only migration) plus
        `epcc_guard_stage_signoff()`: `stage_id` and `point` are DERIVED from the notice,
        so a sign-off cannot mis-state what kind of point it answers; signing is
        default-deny behind the same `result_recorder_policies` register (new action
        `sign_off_stage`) and the authorising policy is stamped on the row
        (`signer_policy_id`); an outcome that changes the work must say why (REJECTED ->
        cause, ACCEPTED_WITH_COMMENTS -> the comments, WAIVED_NOT_ATTENDED -> why nobody
        was there); **a HOLD ('H') point can be waived only by the project employer** —
        a contractor waiving the client's hold is the removal of a control, not a waiver;
        a correction is a new chained sign-off (`previous_signoff_id` must point at a
        REJECTED sign-off of the same notice and company), never an edit; `request_id`
        is unique so a retry cannot double-sign; `recorded_at` is `timestamptz(6)`
        because append order is the record.
      * Visibility fix: reading an ITP stage required read access to the ITP *document
        revision*, so the very companies a notice was sent to could not see — and
        therefore could not sign — the stage named in it. An additive SELECT policy lets
        a notified project member read a stage a notice it can read refers to. Nothing
        new leaks (the notice already names the stage) and no write path opens.
      * Tests: `ItpSignOffTest` 11 tests / 48 assertions green (run twice, stable);
        related regression (`InspectionNotification`, `InspectionResult`, `ItrSnapshot`,
        `ResultAuthorityAndGating`, `ResultUnitsAndPolicyAuthorship`) 245 tests / 2198
        assertions green. The pre-A.4d upgrade fixture in `InspectionNotificationTest`
        now drops the sign-off table and the additive policy first.
      * Guide: `docs/ITP-STAGE-SIGN-OFFS-EN.md` / `-FA.md`.
      Acceptance: default-deny with nothing written ✅; stage and point derived, never
        declared ✅; rejection without a cause / waiver without a reason / conditional
        acceptance without comments refused ✅ (CHECK, so direct SQL too); a contractor
        cannot waive the employer's hold and the employer can ✅; a witness point can be
        waived by the notified party ✅; a correction chains to a REJECTED sign-off and
        the rejection stays on the record ✅; append-only against direct SQL ✅; duplicate
        request ids refused ✅; another member can read but nobody can sign in another
        name ✅; a draft notice and an invisible notice cannot be signed ✅.
      Declared boundary (still open): sign-offs are RECORDED, not yet GATED — there is no
        notification<->test-pack link, so a pack can still be accepted without a hold
        sign-off. That gate is tracked as A.5b, not claimed here. HTTP/UI also still open.
- [x] **A.4e Inspection results — slice 2, part 4: MTR <-> custody bindings**
      (Done — commits `bb744e0` + `815a1ab`)
      * The gap: the certificate registry spoke free-text heat/lot, the piping custody
        chain attested what was received by heat only, and the legacy warehouse spoke
        (lot_code, heat_number) — nothing joined them. A certificate could be registered
        for a heat nobody ever received, and the turnover question (which heats we hold
        have no certificate) had no answer at all.
      * `piping_custody_amendments.lot_ref` (additive, nullable) + `epcc_piping_effective_receipt()`
        now reporting the effective lot: custody can attest a lot as well as a heat, and
        old amendments keep their hashes and their NULL rather than being back-filled.
      * `material_certificate_bindings` (append-only, RLS+FORCE, forward-only) with
        `epcc_guard_certificate_binding()`: heat and lot DERIVED from the certificate,
        custody/amendment/item DERIVED from custody (a raw insert claiming another heat
        is corrected to the certificate's own claim); default-deny behind the same
        nomination register (new action `bind_certificate`, stamped `bound_policy_id`);
        exact matching, never fuzzy; a lot custody does not attest is REFUSED
        (`MTR_LOT_NOT_ATTESTED`) rather than downgraded to heat level; a certificate for
        material that is not here is keepable as `UNRESOLVED` with a mandatory reason;
        `request_id` unique and (certificate, custody) unique.
      * `coverage()` answers the turnover question: every (heat, lot, item) custody
        attests with the number of certificates bound to it — uncovered heats reported,
        never omitted — plus every unresolved binding with its reason.
      * Two maintainer notes: the binding session carries BOTH session contracts
        (`epcc.result_actor_id` and `epcc.receipt_actor_id`, because custody is readable
        only by a receipt actor), and an additive key had to be *defaulted* in
        `PipingCustodyAmendmentService` since the amendment payload is validated by a
        strict `array:` rule (`815a1ab`, after it broke 16 Piping tests).
      * Tests: `MtrCustodyBindingTest` 11 tests / 48 assertions green (run twice,
        stable); related regression (Piping custody/amendment/GRN/QA/traceability,
        StockLedger, Warehouse, MTR bindings) green together — 273 tests / 1632 assertions.
      * Guide: `docs/MTR-CUSTODY-BINDING-EN.md` / `-FA.md`.
      Acceptance: heat/lot derived, never declared (incl. direct SQL) ✅; un-nominated
        binding refused with nothing written ✅; exact (lot, heat) bind ✅; unattested lot
        refused instead of downgraded ✅; unknown heat refused unless a reason is given,
        then recorded as UNRESOLVED ✅; append-only ✅; duplicate request id and duplicate
        (certificate, custody) refused ✅; another company cannot bind in another name ✅;
        coverage reports an uncovered heat instead of hiding it ✅.
      Declared boundaries: PIPING custody only (legacy `material_lots` reconciliation is
        the ADR-047 cutover item), no HTTP/UI, and coverage counts rather than judges.
- [ ] **A.4f Inspection results — slice 2, part 5** — HTTP/UI surface for results,
      nominations, sign-offs, MTR bindings and ITR export; reconciliation of the legacy
      `material_lots` warehouse with the custody chain (ADR-047 cutover). Depends:
      A.4e ✅. (M)
- [x] **A.5 ITR compiler — slice 1 (TEST_PACK scope)** — on-demand assembler +
      digest-bound frozen snapshots; explicit completeness report (missing items
      enumerated, never silently omitted) with the existing bilingual renderer.
      (Done — commit `2a6e2fa`: `ItrCompilerService` (compile/freeze/snapshot/
      renderPdf) + `itr_snapshots` (append-only RLS+FORCE, trigger-derived
      dossier+completeness+sha256 digest — client content never declarable) +
      `print.itr-dossier` bilingual blade; test 10/72 green + regression 87/344
      green. Guides: `docs/ITR-COMPILER-EN.md`/`-FA.md`. Non-pack scopes split
      to A.5b)
      Acceptance: preview ≡ frozen snapshot (same SQL body) ✅; digest binds
      stored dossier ✅ + reader re-verification ✅; gaps enumerated not hidden
      ✅; freeze of a moment immutable against later evidence ✅.
- [ ] **A.5b ITR compiler — slice 2** — subsystem/system and full-dossier scopes
      (meeting ADR-057), ITP-notification and receipt/MTR binding into the
      dossier (depends: A.4b piece-level custody; the notification<->test-pack gate
      needs A.4d sign-offs ✅), formal signature/approval
      layer on snapshots, HTTP/UI surface for compile/freeze/export. Depends:
      A.5 ✅, A.4b, F.1. (M)
- [ ] **A.6 Implement ADR-031: TQ/NCR** — the spec is written (two modes
      IN_SYSTEM/EXTERNAL_REGISTER); it only needs implementation: record, routing (via
      ADR-049 if ready, otherwise a simple authority-gated state transition), disposition,
      linkage to line/piece/PMS snapshot. (M)

---

## Phase B — The management layer (parallel with A; ADRs: **049, 050**)

> Goal: answer "what waits for me? what is late?" — turn the system from a record into management.

- [ ] **B.1 Decide ADR-049** — five keys (engine scope, acceptance strategy, SLA,
      delegation, projection). (S)
- [ ] **B.2 Minimal workflow engine** — `workflow_policies` (role/company per step,
      separation, deadline) + `workflow_transitions` (append-only with ordinal clock) +
      time-boxed delegation register. Depends: B.1. (L)
      Acceptance: chained approval A→B→C with SLA; expired delegation fail-closed; ledger
      immutability; separation of duties under concurrency.
- [ ] **B.3 Proof on the document review cycle** — IDC→IFR→IFA→IFC routing on the B.2
      engine wired to the existing `ReviewComment`; add per-deliverable plan-vs-actual
      dates to the register. (M)
- [ ] **B.4 Global "waiting for my action" inbox** — a projection over the workflow ledger
      + the existing domain queues (company ack, QC/QA, approvals); bilingual; GET with
      no-store (existing pattern). (M)
      Acceptance: the inbox never invents or hides work (projection-honesty test).
- [ ] **B.5 Aging/exception dashboard** — PO slip (the field and computation already
      exist!), unanswered notifications, open punch, undecided receipts; age buckets;
      minimal KPIs. (M)
- [ ] **B.6 Decide ADR-050 + email transport** — `notification_deliveries` ledger
      (channel/recipient/digest/provider-id/bounce events); the first four flows:
      issue/cancel/ack/approval-request; bilingual templates; time-boxed signed links;
      webhook with hash_equals; transport failure must never mutate the business record. (M)
      Acceptance: no cross-company leakage in body/attachment; delivery-ledger
      immutability; fail-open on delivery / fail-closed on record.
- [ ] **B.7 SLA reminders** — wire B.2 deadlines to the B.6 channel (a reminder is a
      delivery-ledger event, never a state change). Depends: B.2+B.6. (S)

---

## Phase C — Both ends of procurement (depends on: B.2 for approvals; ADRs: **052, 053**)

- [ ] **C.1 Decide ADR-052** — five keys (vendor model, prequalification, RFQ flow, bid
      comparison, award→PO). (S)
- [ ] **C.2 Vendor register + matrix qualification** — vendor = company with a VEN type;
      qualification per (vendor, material category, level) with validity windows and
      append-only status history; the AVL becomes a query. (M)
- [ ] **C.3 RFQ → offers → tabulation → award** — two-mode (IN_SYSTEM/EXTERNAL_REGISTER);
      structured tabulation (commercial via the existing BigDecimal machinery + technical
      checklist); append-only clarification rounds; award with an authority chain;
      conversion into the existing PO draft entry (ADR-033) with digest-bound lineage.
      Depends: C.1, B.2. (L)
      Acceptance: category-scoped qualification enforced at RFQ invitation; offer
      immutability after deadline; tabulation determinism; per-project disclosure policy.
- [ ] **C.4 PO change orders** — a reason-coded, authority-routed transition between PO
      versions + diff snapshot (the notifications draft-history pattern); never a silent
      re-issue. (M)
- [ ] **C.5 Invoicing + three-way match** — dual-provenance entry (vendor/buyer) with
      provenance marking; tolerant automatic match (project tolerances) freezing its
      inputs (digest); out-of-tolerance lines → held with a human route; determinism:
      re-running after new receipts produces a NEW result, never mutates the old. (L)
- [ ] **C.6 Real payment certificates** — period, matched invoices, retention/advance/set-off
      in BigDecimal, approval chain, two signature modes (in-system/external-register);
      the existing demo `PaymentCertificate` model either migrates to the real entity or
      is retired (decision inside ADR-053). (M)

---

## Phase D — Schedule and cost with an integration approach (depends on: B.4/B.5 for display; ADRs: **051, 054**)

- [ ] **D.1 Decide ADR-051** — five keys (import sources, schedule role, binding,
      progress measurement, reporting). (S)
- [ ] **D.2 XER/MPP importer** — parse→validate→immutable snapshot (digest+ordinal); diff
      report between snapshots; fail-closed CLI with exit codes (the
      `plan-mto-backfill.php` pattern); a golden-file corpus for parser tests. (L)
- [ ] **D.3 Activity⇄platform-object binding** — append-only register with supersession;
      rule-based proposals (code-mask) confirmed by a human. (M)
- [ ] **D.4 Event-weighted physical progress** — per-project versioned weight rules
      (joint: fitted/welded+NDT/tested; PO: issued/received/accepted); STABLE,
      reproducible computation at any cutoff explaining "which events produced this
      percent"; manual override only as an audited adjustment. Depends: D.2/D.3 (+A.4 for
      weld weights). (M)
- [ ] **D.5 S-curve and lookahead** — planned-vs-actual per system/area/discipline;
      lookahead feed for work-package release (D→E). (M)
- [ ] **D.6 Decide ADR-054 + cost engine** — `budget_revisions` (CBS mirror with versioned
      snapshots) + `cost_journal` (commitment ladder: estimate→MR→award→PO→change
      orders; actuals: GRN accrual→invoice settlement) + light EVM indices (SPI/CPI/VAC)
      as pure functions of digest-bound inputs; exchange rates = dated snapshots (shared
      with C.5). (L)
      Acceptance: Σjournal=rollup invariant; determinism/explainability of every index;
      end-to-end BigDecimal exactness.

---

## Phase E — Construction and field (depends on: A.2 and D.3–D.5; ADRs: **055, 056**)

- [ ] **E.1 Decide ADR-055** — five keys (package scope basis, constraint model, release
      authority, progress capture, subcontractor binding). (S)
- [ ] **E.2 IWP work packages + constraint resolvers** — `work_packages` (state machine
      on the B.2 engine) + `constraints` with STABLE resolvers (stock from A.2, IFC
      documents from the CDE, inspections from ITPs, permits manual-evidenced); a waiver
      is an authority-routed append-only act. (L)
      Acceptance: readiness determinism at any cutoff; proposer ≠ releaser separation.
- [ ] **E.3 Daily reports + reconciliation** — append-only daily report per package-day
      (crew/hours/completed joints/coded blockers); divergence from event-derived
      progress (D.4) as a managed signal. (M)
- [ ] **E.4 Decide ADR-056** — five keys (capture surface, sync model, conflict
      semantics, attachments, device/identity). (S)
- [ ] **E.5 Offline PWA + device journal** — signed client journal (device-ordinal);
      server-side admission through the normal authority/validation path (capture-time ≠
      record-time, both visible); reconciliation queue for rejected entries;
      content-addressed attachments with resumable upload; bounded read snapshots with
      visible staleness. (L)
- [ ] **E.6 Promote the persistence prototype harnesses** — `prototype/persistence` and
      `demand-atomicity` become real tests/CI (part of debt B7/0.4). (S)

---

## Phase F — Completion and handover (depends on: A.4–A.5, E.2; ADR: **057**)

- [ ] **F.1 Decide ADR-057** — five keys (completion hierarchy, MC rules, dossier form,
      commissioning scope, owner acceptance). (S)
- [ ] **F.2 Completion state machine per subsystem** — declarative gates (packages
      complete, category-A punch closed, test packs accepted, as-built Published,
      prerequisites closed) with automatic resolvers + authority-routed waivers; a gate
      snapshot at every transition. (M)
- [ ] **F.3 Punch linkage to subsystem/package** — A/B/C categorisation, clearance
      evidence, binding the existing MC certificate into the state machine. (S–M)
- [ ] **F.4 Turnover dossier compiler** — a live completeness index (the project handover
      matrix vs present items; missing records explicit) + frozen digest-bound bundles at
      milestones (MC/handover) with the bilingual renderer. (M)
- [ ] **F.5 Pre-commissioning checklists** — structured records (flushing/cleaning/
      tightness) bound to subsystems. (M)

---

## Phase G — Adoption multipliers (depends on: B.6 and existing server-side flows; ADR: **058**)

- [ ] **G.1 Decide ADR-058** — five keys (portal topology, external identity, phase-1
      action set, TPI witness, commercial exposure). (S)
- [ ] **G.2 External-session surface layer** — a session-kind claim (`external:company`)
      enforced in middleware, fail-closed; a minimal surface (RFQs/offers, PO+forecast,
      invoices, shipping documents, notification inbox); no navigation to EPC screens at
      all. (M)
- [ ] **G.3 External identity lifecycle** — authority-routed invitation → verified
      contact (B.6 channel) → scoped membership → append-only revocation ledger; extend
      the existing throttles/gates to external sessions + a dedicated abuse test suite. (M)
- [ ] **G.4 Vendor self-service phase 1** — PO acknowledgment, forecast updates (feeding
      the existing slip computation), invoice entry (C.5 with vendor provenance),
      shipping documents. (M)
- [ ] **G.5 TPI witness sign-off** — external identities with hold/witness sign-off
      rights on A.4. (S)

---

## Suggested execution order (one-line roadmap)

```
0 (immediate) ─► A.1–A.3 (cutover/stock) ─► B.1–B.2 (workflow engine) ─► B.4–B.6 (inbox/dashboard/email)
        └─► A.4–A.6 (results/ITR/TQ-NCR) ─► C (sourcing→money) ─► D (schedule/cost) ─► E (IWP/field) ─► F (handover) ─► G (portals)
```

Ordering rule: first close the open data loops (materials/quality), then the management
layer underneath everything, then breadth via integration, not construction. No phase
loosens the existing disciplines (append-only/RLS/separation/fail-closed/display
honesty) — every new module follows the same ADR+guide+tests+evidence pattern.

## Index of proposed ADRs (all status=PROPOSED)

| ADR | Topic | Phase |
|---|---|---|
| 047 | Warehouse cutover + single stock ledger | A |
| 048 | Inspection/test results (MTR/NDT/hydro) + ITR | A |
| 049 | Minimal workflow engine + transition ledger + delegation | B |
| 050 | Notification transport (email/SMS) + delivery ledger | B |
| 051 | Schedule integration (XER/MPP) + event-based progress | D |
| 052 | Sourcing: vendor register, prequalification, RFQ, award | C |
| 053 | Money: change orders, invoicing, three-way match, payment certificates | C |
| 054 | Cost commitment/actual + light EVM | D |
| 055 | IWP work packages + constraint-free release | E |
| 056 | Field capture + offline sync (promotes the persistence prototype) | E |
| 057 | System/subsystem completion + turnover dossier | F |
| 058 | External-party portals VEN/SUB/TPI | G |
