# Recording weld, NDT and test-pack results — A.4h slice 1 (English)

> Status: implemented 2026-10-30. Slice of ADR-048.
> همزاد فارسی: [`QUALITY-RESULT-RECORDING-FA.md`](QUALITY-RESULT-RECORDING-FA.md).
> The rules live in [`ADR-048`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md); the
> register that authorises every write here is documented in
> [`QUALITY-NOMINATIONS-EN.md`](QUALITY-NOMINATIONS-EN.md).

## The hole this closes

The result layer has been built for days in the wrong order, and this slice is
where the order is finally repaired:

- **A.4a** built the services (`recordWeld`, `recordNdt`, `recordTestResult`) and
  the append-only tables. Correct, and unreachable.
- **A.4b** made recording default-deny: a result may only be written when
  `epcc_result_nomination()` finds a current, un-suspended policy naming the
  actor. Right — and it switched the whole layer off at the wall.
- **A.4g** gave the register a surface, so a project could finally nominate a
  recorder.

What was still missing was the door the key fits. A QC inspector standing at a
fitted joint could be nominated for `record_weld` in one screen and then had no
screen to record the weld in — the only way in was SQL. Authority that cannot be
exercised is not authority; it is paperwork about authority.

This slice is that door: one screen under
`/projects/{projectCode}/quality/results` that records a weld, an examination
(NDT) or a pressure-test result, and that refuses to show you a form you are not
nominated for.

## What was built

| Piece | What it is |
|---|---|
| `InspectionResultService::dashboard()` | Everything the screen needs: joints with their line numbers, packs that can be tested, the selected joint's weld and NDT history, the selected pack's results, this actor's three nominations, and the pressure units the registry will accept. |
| `InspectionResultService::ACTIONS` | `record_weld`, `record_ndt`, `record_pack_result` — the three actions of this layer, stated once instead of being retyped in the controller. |
| `InspectionResultController` | One no-store screen and three POSTs. Refuses a non-member of the project at the door. |
| `resources/views/quality/results.blade.php` | The screen: your authority, the joint and pack lists, and the forms — each one only if you are nominated for it. |
| `lang/{fa,en}/quality_results.php` | Bilingual copy, as everywhere else. |
| `tests/Support/QualityResultFixture.php` | A fitted joint and a READY test pack, built through the real services inside the session contract. |
| `InspectionResultHttpTest` | 15 tests / 61 assertions, all recording through HTTP. |

Routes: `quality.results` (GET), `quality.results.weld`, `quality.results.ndt`,
`quality.results.pack` (POST).

## The screen is built around the register, not around the forms

This is the one design decision worth writing down, because the alternative looks
identical in a screenshot and is wrong.

The obvious implementation renders three forms and lets the guard refuse the
submit. That is default-deny enforced, which sounds like the requirement — but it
is default-deny *discovered*: the inspector fills in a weld record, presses save,
and is told they have no authority. The information was available before they
started typing.

So the screen asks `epcc_result_nomination()` — through
`ResultRecorderPolicyService::nominationFor()` — once per action, and renders a
form only where the answer is yes. Where it is no, it says so, in place of the
form, and points at the register. One action does not confer another: a person
nominated to record welds sees the weld form and, for NDT, the same refusal
everyone else sees.

The guard is still the authority. Nothing about this screen is trusted: a forged
POST with a valid session still meets `epcc_result_nomination()` in the database
and is refused. The screen is honest, not load-bearing.

## The invariants this surface holds

1. **A form appears only for a nominated actor**, asked of the same function the
   guards call. The screen and the database cannot disagree.
2. **A non-member of the project is refused at the door** (403), before anything
   is read — not shown an empty screen that looks like a bug.
3. **A repair weld carries a reason; a normal weld carries none.** `REPAIR_WELD`
   without a cause is refused; the cause is why the joint was cut open, and a
   repair with no cause is not reviewable.
4. **A REJECTED examination carries a defect description, and an ACCEPTED one
   carries none.** Both directions are enforced: a rejection with no defect is
   refused, and so is an acceptance that tries to keep a defect note.
5. **An examination is refused until the joint has a weld.** You cannot
   radiograph a weld that does not exist yet.
6. **A pressure unit must be a pressure unit.** The database CHECK is generated
   from the same unit registry the application reads, so "21.5 DEG_C" is refused
   at both layers and the two cannot drift.
7. **A PASS carries no failure cause, and a FAIL must carry one.**
8. **A test cannot finish before it starts.**
9. **A resubmission is refused, not duplicated.** `request_id` is unique per
   project, so a double-click or a retried request writes one record — the second
   attempt comes back as a refusal, which is what the user is actually asking
   about when they click twice.
10. **Nothing is edited.** The tables are append-only and there is no UPDATE
    policy; a re-examination chains to the result it replaces
    (`previous_result_id`) rather than overwriting it.

## What this slice deliberately does not do

- **No ITP stage sign-off surface.** `ItpSignOffService` exists from A.4d and has
  no screen; it is the next slice of A.4h.
- **No certificate binding, no legacy reconciliation, no ITR export.** Each has
  its own service and waits for its own slice.
- **No completeness judgement.** This screen records results; deciding whether a
  pack has enough evidence to be frozen is the ITR compiler's job (A.5).
- **No bulk or offline entry.** One record, one submit, one authorising
  nomination — the same shape as the rest of the quality layer.
- **The joint and pack lists are capped at 500 rows** with no pagination. A
  project with more joints than that needs search before it needs this screen,
  and both are a later slice.
- The screen follows the compact house pattern used by the piping and materials
  screens; it is a working surface, not a design system.

## Tests

`InspectionResultHttpTest` — 15 tests / 61 assertions:

- the screen is visible to a project member, in Persian and English, and reports
  honestly that nothing is nominated yet;
- the weld form appears only once you are nominated, and a weld nomination does
  not conjure an NDT form;
- a nominated inspector records a weld and it appears in the joint's history;
- recording a weld without a nomination writes nothing;
- a repair weld without a reason is refused; the same `request_id` is not
  recorded twice;
- an examination is refused until the joint has a weld; a REJECTED examination
  without a defect description is refused, and with one is recorded;
- a hydrostatic result is recorded against a READY pack and appears in its
  results; a temperature cannot be recorded as a pressure; a PASS carrying a
  failure cause is refused; a test that completes before it starts is refused;
- a guest is sent to login, and someone outside the project is refused.

Two notes for whoever writes the next test here, both learned the hard way:

- **These tables are RLS+FORCE.** A bare `DB::table(...)->count()` outside the
  session contract returns zero and "proves" a refusal that never happened. Count
  inside `TenantContext::runAs()`, the way these tests do.
- **A fixture that bypasses the services would prove nothing.** `QualityResultFixture`
  builds its joint through `ComponentService`, `JointService` and an approved PMS
  class, inside the employer's session — the same path a real project takes — so a
  screen bug cannot hide behind an unrealistic fixture.
