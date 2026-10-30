# The hold that finally holds — A.5b slice 1 (English)

> Status: implemented 2026-10-30, commits `bc81536` and `233edb7`. Slice of ADR-048.
> همزاد فارسی: [`PACK-NOTIFICATION-GATE-FA.md`](PACK-NOTIFICATION-GATE-FA.md).
> The sign-off register this makes load bearing is documented in
> [`ITP-STAGE-SIGN-OFFS-EN.md`](ITP-STAGE-SIGN-OFFS-EN.md); the dossier it now
> feeds is [`ITR-DOSSIER-SCREEN-EN.md`](ITR-DOSSIER-SCREEN-EN.md).

## The gap was a missing consequence, not a missing feature

Since A.4d an ITP stage can be signed off — accepted, accepted with comments,
rejected, or waived because nobody attended. Since A.4h slice 2 it can be signed
from a screen, by a nominated signer, with the point derived and the employer's
hold protected from a contractor's waiver. Every one of those rules is real and
enforced in the database.

And none of them stopped anything. A test pack could be marked TESTED and then
ACCEPTED while the client's HOLD point on that very test sat unsigned, or sat
REJECTED. The sign-off register was a diary: it recorded what happened at the
inspection and had no bearing on the claim the pack was about to make.

**A record that gates nothing is decoration, and this one was decorating the
exact claim a client relies on.** This slice is the missing consequence.

## Two things, because one without the other is theatre

### The link

`piping_test_pack_notifications` says *this notice was raised about this pack*.
Without it there is nothing to gate on: the schema had no path from a pack to a
notice, which is also why A.5 slice 1 declared that ITP notifications could not
be assembled into the dossier.

The link is append-only, and **there is no unlink**. That is deliberate: the row
is a fact about what was asked for, and a request that was withdrawn is already
recorded as a cancellation, which the gate honours. An "unlink" would let the
record say the notice was never raised about this pack, which is a different —
and false — claim.

Who may make it: **the company the pack belongs to, or the project employer.**
The pack is the first company's own claim, so it may name the inspection of its
own work; the employer owns the holds, so it may name any. Nobody else names
someone else's inspection against someone else's pack.

### The gate

Transitioning a pack into **TESTED or ACCEPTED** — the two states that claim
physical work was done and then taken over — now requires every linked, issued,
uncancelled HOLD (`H`) notice to be **cleared**. READY stays the
self-declaration A.4b left it: *"we are ready to test"* is a promise, not yet a
falsifiable claim.

Cleared means one of two things:

| | |
|---|---|
| **Every notified party signed** | the latest sign-off from each recipient is ACCEPTED, ACCEPTED_WITH_COMMENTS or WAIVED_NOT_ATTENDED |
| **The employer waived the hold** | WAIVED_NOT_ATTENDED by the project's owner company |

The second is not a loophole, it is the same rule A.4d already enforces from the
other side: only the employer may waive an `H` point, because waiving the
client's own control is the client's to do. Read together, the two say *a HOLD
is released either by the parties who were asked to attend, or by the party who
owns the stop*.

A **REJECTED** sign-off is cleared by nothing. A rejection stands until a
correction is recorded, and a correction is a new chained sign-off, never an
edit — the same lineage rule the pack's own results follow, where FAIL → repair
→ PASS can culminate in acceptance but a lone FAIL never can.

## What is deliberately not gated

**Witness, review and monitor points (`W`, `R`, `M`) gate nothing.** They are
recorded in the dossier with their sign-offs, and that is the whole of it. A
hold stops work; the others are notices that work is happening. Gating on them
would make a pack un-acceptable because somebody did not turn up to something
that never required them to — a gate that blocks legitimate work is a gate
people route around, which is worse than no gate.

**Freezing a dossier is not gated.** A snapshot records reality, including its
gaps, and the completeness report enumerates an uncleared hold rather than
hiding it. What is gated is the *claim*, not the record of it.

## One function, three callers

`epcc_pack_notice_state(pid, nid)` answers what a notice means for the work:
`CLEARED`, `NOT_SIGNED`, `REJECTED`, `CANCELLED`, `NOT_ISSUED` or `NOT_VISIBLE`.
The trigger, the ITR dossier and the ITR screen all ask it, so they cannot
drift: the screen cannot show *cleared* where the transition would be refused,
and the dossier cannot print *complete* over a hold the gate would block.

That is the whole reason it is a function and not three queries. Two readings of
"is this hold cleared?" in two places is two rules, and two rules disagree
eventually.

## What the dossier gained

`epcc_itr_test_pack_dossier` now carries a `notifications` array — each notice
with its stage, point, number, work reference, whether it was cancelled, its
`hold_state`, and every sign-off recorded against it. The completeness report
gained `counts.notifications`, `counts.hold_points` and
`missing.hold_notifications_not_cleared`, and `is_complete` is false while a
hold is uncleared.

The dossier's `schema_version` moves from 1 to 2. Its shape changed, and that is
what a version is for: a consumer that does not know the new key should be able
to tell, rather than quietly reading an older document.

## The invariants this slice holds

1. **A link is append-only, and there is no unlink** — a withdrawal is a
   cancellation, which the gate honours.
2. **Only the pack's company or the employer may link.**
3. **A notice this company cannot see cannot be linked** — RLS decides
   visibility, and the guard says so rather than letting a missing row look like
   a missing feature.
4. **Only an ISSUED notice can be linked** — a draft is not yet a request.
5. **A HOLD on a linked notice stops TESTED and ACCEPTED** until it is cleared.
6. **A rejection is cleared only by a chained correction**, never by a waiver.
7. **W/R/M points are recorded and never gated.**
8. **The gate, the dossier and the screen ask one SQL function.**
9. **A double submit is refused, not duplicated** (`request_id` is unique per
   project; so is the pack↔notice pair).

## Declared boundaries

- The gate is evaluated over the notices **this session can see** — RLS applies
  inside the trigger. A company that was never notified and does not own the
  pack is not bound by a hold it cannot read, and by the link rule it is also
  not a company that can create one.
- **Scope is still TEST_PACK.** Subsystem, system and full-dossier scopes are
  later A.5b work.
- **Receipts (GRN) and certificates (MTR) are still not assembled** into the
  dossier: binding receipts needs piece-level custody (A.4b), and no pack↔MTR
  link exists.
- **The formal signature/approval layer on snapshots is still open.** This slice
  makes an existing sign-off consequential; it does not make a snapshot a
  certificate. The dossier's `kind` still says
  `ITR_COMPILATION_NOT_A_CERTIFICATE`.

## Tests

`PackNotificationGateTest` — 18 tests / 73 assertions, driven through the real
services and through HTTP:

- a linked HOLD stops the pack claiming TESTED, **with a PASS result already
  recorded**, so the result rule is provably not what refused it; the state is
  unchanged afterwards;
- the employer signing releases the claim; the employer waiving its own hold
  releases it;
- a rejection keeps it shut, and a **chained correction** opens it — both rows
  standing;
- a hold gates ACCEPTED as well as TESTED, and linking after TESTED closes that
  door too;
- an unsigned **witness** point records and does not gate;
- a draft notice cannot be linked (proved with a contractor-owned pack, the only
  actor that can see its own draft and so can be refused for the honest reason);
- a cancelled notice stops gating but cannot be linked again;
- only the pack's company or the employer may link, and the contractor who owns
  the pack **may** link its own notice;
- a notice your company cannot see cannot be linked;
- the same notice twice, and the same `request_id` twice, write one row;
- the screen shows the notice and reports the hold as not cleared; it offers
  only the notices that could be linked and says when none are left;
- a guest is sent to login and a non-member is refused;
- the frozen dossier carries the notice with `hold_state = NOT_SIGNED`, the
  completeness report enumerates it as a gap, and once signed the gap is gone.

Related regression green together — 407 tests / 3,041 assertions across
`ItrDossier`, `ItrSnapshot`, `GuardDefinitions`, `ResultAuthorityAndGating`,
`InspectionResult` (unit and HTTP), `ItpSignOffHttp`, `InspectionNotification*`,
`ComponentModelling`, `MtrBindingHttp`, `LegacyReconciliationHttp`,
`InspectionEntry`, `TableTools`, `CookielessPreview`.

## Two implementation notes worth the space

1. **A plpgsql variable must not share a name with a table alias.** `SELECT
   n.id, n.state … INTO n FROM inspection_notifications n` fails with a bare
   `record "n" is not assigned yet`, because the select-list `n.id` resolves to
   the unassigned record before it considers the alias. The function now calls
   it `notice_row`.
2. **A refused insert aborts the transaction, so the GUC restore in a `finally`
   needs a savepoint.** The service wraps its work in a nested
   `DB::transaction()` exactly as `MaterialCertificateBindingService` does: the
   rollback then stops at the savepoint, the session transaction survives, and
   the `set_config` that restores `epcc.result_actor_id` still runs. Without it
   the restore throws `25P02` and masks the real refusal message — which is
   precisely the kind of second error that sends you looking in the wrong place.

A third, smaller one: `InspectionNotificationTest` models a database that
predates A.4d by dropping `inspection_notifications` and re-running its
migration. The new foreign key made that drop fail, so the fixture drops the
link table first — and the comment names the slice that added it, so the next
dependent is recorded rather than discovered.
