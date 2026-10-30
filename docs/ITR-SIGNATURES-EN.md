# The name on the dossier — A.5b slice 2 (English)

> Status: implemented 2026-10-31, commit `a0ac504`. Slice of ADR-048.
> همزاد فارسی: [`ITR-SIGNATURES-FA.md`](ITR-SIGNATURES-FA.md).
> The document this signs is [`ITR-COMPILER-EN.md`](ITR-COMPILER-EN.md); the
> screen it lives on is [`ITR-DOSSIER-SCREEN-EN.md`](ITR-DOSSIER-SCREEN-EN.md);
> the gate that made sign-offs load bearing is
> [`PACK-NOTIFICATION-GATE-EN.md`](PACK-NOTIFICATION-GATE-EN.md).

## A compilation nobody can sign is a draft nobody can close

Since A.5 the platform compiles an ITR dossier: the lines, joints, welds,
examinations, pack results and (since slice 1) the inspection notices raised
about the work, frozen under a server-derived digest that no caller can declare.
It is the artefact a client asks for at the end of the job, and its `kind` says
`ITR_COMPILATION_NOT_A_CERTIFICATE`.

What it could not do was end. Someone could compile it, freeze it, print it —
and nobody could put their name to it. Every other layer of this chapter had a
way to say *I reviewed this and here is my word*: results are recorded by a
nominated person, stages are signed, certificates are bound. The dossier itself
had none, so the finished document was the least consequential record in the
chain.

So a signature layer now exists. The interesting question is not whether it
exists but **whether it means anything** — because the previous slice's whole
argument was that a register which gates nothing is decoration, and a signature
is the most decorative thing in the world when nothing depends on it.

## Three rules, because a signature without them is a stamp

### 1. A signature binds a document

You cannot sign "an ITR". You can sign *this* frozen dossier, and there is
exactly one way to name it: its digest.

So the digest on a signature row is **derived by the trigger from the snapshot**,
never accepted from the caller. And before it derives it, the guard re-computes
the snapshot's own digest and compares: `itr_snapshots` is append-only, so a
stored dossier that no longer hashes to its digest was changed underneath. A
signature over that would be a signature over something else, so it is refused
(`ITR_SIGN_DIGEST_INVALID`) rather than recorded with a shrug.

```text
new row  ->  guard reads the snapshot
         ->  digest = sha256(dossier::text)          (derived, not declared)
         ->  digest != stored digest?               refuse
         ->  new row carries the snapshot's digest
```

The screen shows the same arithmetic per row — each snapshot is re-verified on
every page load, as it has been since A.4h slice 6 — so a reader and a signer
are looking at the same fact from the same expression.

### 2. An endorsement is a claim that the document is finished

The completeness report exists precisely so the platform never has to guess
whether a dossier is done: unreleased lines, unfitted joints, joints with no
weld or no examination, open rejections, uncleared HOLD notices, a pack with no
passed result — each with the line and joint numbers that caused it.

So `ENDORSED` — *this document is complete and I stand behind it* — **is refused
while the report still enumerates a gap** (`ITR_SIGN_ENDORSEMENT_OVER_GAPS`).
The completeness report is the authority, not the person signing, and a platform
that let someone endorse over a gap the same screen lists three rows above would
be helping them make a false statement.

The honest way past it is `ENDORSED_WITH_COMMENTS`, whose comments are
**mandatory**: a dossier may be accepted with known punch items, but never
silently. This is the same rule A.4d already enforces on an ITP sign-off — an
outcome that changes what the record means must say why — and it is enforced by
a CHECK, not by the service:

```sql
CHECK (outcome IN ('ENDORSED','ENDORSED_WITH_COMMENTS','REFUSED')
       AND (outcome <> 'ENDORSED') = (reason IS NOT NULL)
       AND (length(btrim(COALESCE(reason,''))) > 0) = (reason IS NOT NULL))
```

The third clause is the one that matters twice: a reason that is only whitespace
is not a reason, and **`ENDORSED` is refused a reason it does not have**. A clean
endorsement carrying "fine, apart from the missing chart" is worse than no
endorsement, because the qualification is now in a column nobody audits.

`REFUSED` is a first-class outcome, not the absence of a signature. *I looked and
I am not signing* is information, and it needs its cause exactly as an
endorsement with comments needs its comments.

### 3. Signing is default-deny, and the authorisation is stamped on the row

Nobody signs because they are a project member. Signing is a new action in the
nomination register, `sign_itr_snapshot` — the eighth — asked of
`epcc_result_nomination()` on every insert, with the same default-deny posture
every other quality action obeys.

And the policy that authorised the signature is **stamped on the row**
(`signer_policy_id`), exactly as `recorder_policy_id` and `signer_policy_id` are
on results and stage sign-offs: *who was allowed to say this* stays auditable
after they have said it, including after the nomination is suspended or
re-versioned.

## A second signature is a new row, never an edit

One company, one chain. A later signature names the head
(`previous_signature_id`), and the guard refuses anything else:

| Submission | Result |
|---|---|
| `previous` = the company's current head | recorded |
| `previous` = an older row of the same company | `ITR_SIGN_PREVIOUS_STALE` |
| `previous` set, but the company has never signed | `ITR_SIGN_PREVIOUS_UNKNOWN` |
| `previous` omitted, but the company has signed | `ITR_SIGN_PREVIOUS_STALE` |

Both rows stand. The **latest is the company's word** — that is what the screen
shows and what the printed dossier carries — and the history is the audit trail.
The reason for the chaining rule is not tidiness: without it, one company could
hold two contradictory signatures, and which one it "really" signed would become
a matter of opinion.

Changing your mind is therefore a signature, not an edit — as with pack results,
stage sign-offs and certificate bindings. `UPDATE` and `DELETE` are refused
outright (`itr_signature_immutable`, 55000), and `request_id` is unique per
project, so a retried submit cannot double-sign.

Two companies sign independently: a contractor's QC and a client's inspector may
both sign the same dossier, and neither overwrites the other.

## The screen is built around the guard, not around a form

Everything this chapter has argued about forms applies here, and it is why the
sign form looks the way it does:

- **A dossier with gaps offers no `ENDORSED` at all.** Not "offers it and
  refuses on submit" — the option is not in the HTML, and a note says why. The
  service reads `is_complete` with the same `completeness->>'is_complete'`
  expression the guard uses, so the screen and the database cannot disagree
  about which outcomes are honestly available.
- **A snapshot that no longer matches its digest offers no signature at all.**
  It gets a sentence, not a form.
- **A company with no live nomination sees no form**, and sees the reason in its
  place. `maySign` is asked of the same register function the guard asks.
- A company that has already signed sees what it signed, and its next signature
  silently carries the head.

## What the document says about itself

A signed dossier is still not a certificate — the `kind` is unchanged and a test
asserts it stays unchanged. But the words around it were a static warning that
no longer matched reality, and **static language on a document that has changed
state is a lie by omission in both directions**: an unsigned dossier that says
"this is not a certificate" tells you nothing about whether anyone read it, and a
signed one that still says only that hides the fact that three companies put
their names to it.

So the printed dossier now follows its own state:

| | Unsigned | Signed |
|---|---|---|
| Strap | *Quality-evidence compilation — explicit, not a certificate* | *… compilation — signed as evidence, not a certificate* |
| Band | *This document is NOT a certificate … Nobody has signed it.* | *… not a certificate of acceptance; it is evidence, signed by the parties listed at the end, and a signature carries no opinion beyond the outcome stated.* |
| Signatures | *No signature has been recorded on this document.* | one row per company: company, signer, time, outcome, comments, digest prefix |

It also carries the **linked inspection notices** and their hold state — the
evidence the dossier was compiled against. A signed dossier that hid the HOLD
record would be less honest than an unsigned one, because a signature is an
invitation to stop reading.

## Declared boundaries

- **This does not make the dossier a certificate.** The `kind` is
  `ITR_COMPILATION_NOT_A_CERTIFICATE` before and after, and nothing here claims
  the work was accepted. A signature is a statement about a document, not a
  statement about the welds.
- **The platform does not decide who must sign.** A contractor's QC and a
  client's inspector are both legitimate signers; the nomination register, not
  this table, decides who may. There is no "all parties signed" verdict, because
  computing one would mean the platform deciding whose signature counts.
- **A signature is scoped to a snapshot.** Signing does not unfreeze, revise or
  supersede anything — a later revision is a new snapshot with its own digest
  and its own signatures.
- **Not yet:** subsystem/system scopes and the full-dossier assembly
  (ADR-057), and receipts (GRN) and certificates (MTR) inside the dossier,
  which needs piece-level custody.
- **Visibility is RLS, as everywhere:** a signature is readable by project
  members and insertable only by its own company, under `FORCE ROW LEVEL
  SECURITY`.

## What is enforced where

| Rule | Enforced in |
|---|---|
| digest derived, never declared | `epcc_guard_itr_signature()` |
| snapshot must match its own digest | `epcc_guard_itr_signature()` |
| `ENDORSED` refused over enumerated gaps | `epcc_guard_itr_signature()` |
| reason mandatory for the two qualified outcomes, forbidden for `ENDORSED` | CHECK on the table |
| nomination required, and stamped on the row | `epcc_guard_itr_signature()` + `epcc_result_nomination()` |
| chain head must be named | `epcc_guard_itr_signature()` |
| append-only | `epcc_reject_itr_signature_mutation()` (55000) |
| one request signs once | unique `(project_id, request_id)` |
| member read / self-insert | RLS policies, `FORCE` |

The guard is a file — `database/guards/epcc_guard_itr_signature.sql` — applied
by `GuardSql::apply()` before the trigger binds it, per ADR-059.

## Tests

`tests/Feature/ItrSignatureTest.php`, 22 tests / 98 assertions:

- a nominated signer endorses a complete dossier; the digest on the row is the
  document's, and the authorising policy is stamped;
- `ENDORSED` over a gap is refused; the same dossier can be endorsed *with the
  comments that make it honest*;
- a refusal carries its cause; a clean endorsement is refused a qualification;
  both qualified outcomes are refused without one;
- no nomination signs nothing, and neither does a suspended one;
- chaining: a second signature names the head, both rows stand, the latest is
  the company's word; a stale head is refused;
- two companies sign independently and both are read;
- one `request_id` signs once;
- a signature row cannot be edited or deleted, and the trigger that says so is
  installed;
- a tampered snapshot — immutability and RLS lifted to get at it, as a database
  administrator could — cannot be signed;
- the `kind` does not change when a signature appears;
- the printed document says *NOT a certificate / Nobody has signed it* before,
  and *signed as evidence* with the signer's row after, through `renderPdf`
  with a renderer that echoes its own input;
- the screen offers signing only to a nominated company, offers no clean
  endorsement on a dossier with gaps, and a posted signature shows on the
  screen; an outsider is forbidden.

Related regression green together: `ItrSnapshot`, `ItrDossierHttp`,
`GuardDefinitions`, `PackNotificationGate`, `ResultAuthorityAndGating`,
`ResultRecorderNominations`, `ItpSignOffHttp`, `InspectionResultHttp`,
`MtrBindingHttp`, `LegacyReconciliationHttp`, `RowLevelSecurity`,
`RlsSargability`, `IdentityIsolation`, `ReleaseGate`, `CdeEvidenceIntegrity`,
`TableTools`, `InspectionNotification`.
