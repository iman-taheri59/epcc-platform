# The quality nomination register gets a surface — A.4g slice 1 (English)

> Status: implemented 2026-10-29. Slice of ADR-048.
> همزاد فارسی: [`QUALITY-NOMINATIONS-FA.md`](QUALITY-NOMINATIONS-FA.md).
> The register's rules live in [`ADR-048`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md).

## The hole this closes

Recording inspection evidence has been default-deny since A.4b: a result may
only be written when `epcc_result_nomination()` finds a current, un-suspended
policy naming the actor for that action. That is the right rule, and it is
enforced in the database where it cannot be argued with.

It also had no way to be satisfied. The only code in the repository that had
ever written to `result_recorder_policies` was `ResultRecorderPolicyFixture` —
a test helper that inserts rows directly. So in production the entire quality
stack (results, sign-offs, certificate bindings, legacy reconciliations) was
switched off at the wall, with the only key being SQL.

A register nobody can write to is not a register. It is a lock with the key
inside the room.

## What was built

| Piece | What it is |
|---|---|
| `ResultRecorderPolicyService` | `dashboard()`, `configure()`, `nominationFor()`. The key. |
| `QualityNominationController` | Two routes: show the register, record a nomination or a suspension. |
| `resources/views/quality/nominations.blade.php` | The screen: your authority, the register, the forms. |
| `lang/{fa,en}/quality_nominations.php` | Bilingual copy, as everywhere else. |
| `ResultRecorderNominationsHttpTest` | 15 tests / 59 assertions, all nominating through HTTP. |

Route: `/projects/{projectCode}/quality/nominations` (`quality.nominations`),
POST to `quality.nominations.store`.

## Seven actions, one register

The table's CHECK is the authority; the service mirrors it:

| Action | Meaning |
|---|---|
| `record_weld` | Record a weld |
| `record_ndt` | Record an NDT result |
| `record_pack_result` | Record a test-pack result (hydro) |
| `register_certificate` | Register a material certificate (MTR) |
| `sign_off_stage` | Sign off an ITP stage |
| `bind_certificate` | Bind a certificate to custody |
| `reconcile_legacy` | Decide about legacy material |

## The invariants

1. **A nomination is (project, target company, action, version).** It is never
   edited. A new version supersedes; the old row stays in the history.
2. **Suspension is a new version with `suspended = true`.** There is no UPDATE
   policy on the table, by design — this is the only way to withdraw authority,
   and the withdrawal is itself on the record.
3. **A company nominates its own people. The project employer may nominate
   anyone. Nobody else may.** Enforced twice: in the service for a clean
   refusal, and again in `epcc_guard_result_policy_author()` for anyone who
   reaches the table another way.
4. **Selectors are offered, not accepted.** `user:<uuid>` for one named person,
   `role:<company uuid>:<role code>` for every member holding that project role.
   Each is checked against the project's actual memberships — and only members
   whose discipline scope is unrestricted or covers PIPING are offered, because
   that is exactly whom `epcc_result_nomination()` would recognise. Offering a
   selector the guard ignores would be a nomination that says one thing and does
   another.
5. **"Am I nominated?" is answered by `epcc_result_nomination()`** — the same
   function the guards call. The screen and the database cannot disagree about
   what authority exists.
6. **A reason is mandatory**, for a nomination and for a suspension. A
   nomination is an act of delegation; an unexplained delegation is not reviewable.
7. **Stale submissions are refused.** The form quotes the version it was looking
   at; if the register moved on, nothing is written and the screen says to
   refresh, rather than silently overwriting a newer nomination.

## What this slice deliberately does not do

- It does not record results, sign stages, bind certificates or reconcile lots.
  Those have their own services; all of them obey this register. Their surfaces
  are the next slice.
- It does not import or bulk-assign nominations. Every nomination is an act by a
  named person, and that is the point.
- The screen is a working surface, not a design system: it follows the compact
  house pattern used by the piping and materials screens.

## Tests

`ResultRecorderNominationsHttpTest` — 15 tests / 59 assertions:

- the register is visible to a project member, in Persian and English, and
  reports honestly that nothing is nominated yet;
- a guest is sent to login; someone outside the project does not get the screen;
- a company nominates its own person and then holds the authority — verified
  with `epcc_result_nomination()`, not with our own idea of it;
- one action does not confer another;
- nominating again writes version 2 and leaves version 1 standing;
- the employer may nominate another company; a company may not nominate another
  company;
- an empty nomination, a nominee outside the project, a blank reason and an
  unknown action are all refused with nothing written;
- a stale version is refused rather than overwriting a newer nomination;
- suspension writes a new version, keeps the old one, and withdraws the
  authority from that moment on; a suspension without a reason is refused.

A note for whoever writes the next HTTP test here: this table is RLS+FORCE, so a
bare `DB::table(...)->count()` outside the session contract returns zero and
proves nothing. Count inside `TenantContext::runAs()`, the way these tests do.
