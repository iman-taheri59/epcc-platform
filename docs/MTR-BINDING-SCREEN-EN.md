# Registering and binding material certificates — A.4h slice 3 (English)

> Status: implemented 2026-10-30, commit `67b2734`. Slice of ADR-048.
> همزاد فارسی: [`MTR-BINDING-SCREEN-FA.md`](MTR-BINDING-SCREEN-FA.md).
> The rules live in [`ADR-048`](ADR-048-INSPECTION-TEST-RESULTS-ITR-EN.md); the
> binding invariant was written in A.4e and is documented in
> [`MTR-CUSTODY-BINDING-EN.md`](MTR-CUSTODY-BINDING-EN.md).

## The gap this closes

A.4e built the binding: the connection between a certificate that says *"heat H
of this item meets the specification"* and a custody chain that says *"we
received heat H"*. It was derived, never declared, exact rather than fuzzy, and
default-deny behind a nomination. All of that is still true and all of it still
lives in the database.

None of it could be done. And there was a second gap behind the first: **the
certificate registry itself had no way in either**, so the thing the binding
machinery exists to connect could only be created by SQL. Two ends of one loop,
both unreachable.

One screen now holds both:

| Route | What it does |
|---|---|
| `GET /projects/{projectCode}/quality/mtr` (`quality.mtr`) | The registry, the coverage report, and the two forms. |
| `POST .../quality/mtr/certificates` | Register a certificate the project was given. |
| `POST .../quality/mtr/bindings` | Bind a certificate to the custody that attests its heat. |

## Why both forms are on one screen

Because they are one story. You are handed an MTR, you register it, you connect
it to the material it describes. Splitting them across two screens would mean
sending someone to a second page to finish the thought they started on the
first — and, more importantly, would leave one of the two still unreachable
while looking finished.

They are still **two authorities**. `register_certificate` is a claim about a
document; `bind_certificate` is a claim about material we hold. The register
treats them separately, the screen shows them as two rows in the authority
panel, and holding one does not confer the other.

## What the screen does not let you say

This is the part worth understanding, because most implementations get it wrong
in the convenient direction. The bind form has **no custody field, no heat
field and no lot field**:

- `heat_ref` and `lot_ref` are read off the **certificate** by the guard;
- `custody_id` and `custody_amendment_id` are found by the **guard**, which
  looks for custody in this project attesting exactly that (lot, heat);
- `item_code` comes from that custody record's purchase-order source line.

A caller could declare all of them in the insert and the guard would overwrite
every one. Which is the point: a binding states a connection between two facts
the project already holds. It is not an opportunity to assert a new one.

## The one refusal that is *meant* to be worked around

When custody attests the heat but **not the lot** the certificate claims, the
binding is refused (`MTR_LOT_NOT_ATTESTED`) rather than downgraded to heat
level. Downgrading would make the data say *"this lot is covered"* when custody
only ever knew the heat — a dossier claiming coverage it does not have.

There are two honest ways forward, and the screen supports the second
immediately: amend the custody chain so it attests the lot (the amendment
service, A.4e), or keep the certificate as `UNRESOLVED` **with a reason**. The
bind form carries that reason field, and the coverage report lists every
unresolved certificate with it. An unexplained gap is exactly how a dossier
quietly overclaims.

## Coverage, next to the registry

The turnover question — *which heats we hold have no certificate?* — is not a
report you run at the end; it is the reason the binding exists. So it sits on
the same screen, under the registry:

- **heats custody attests**, with the number of custody records and the number
  of certificates bound to each. A row with **zero certificates is the answer**:
  an uncovered heat is reported, never omitted.
- **certificates kept as unresolved**, each with the reason it is unresolved.

## The invariants this surface holds

1. **A form appears only for a nominated actor**, and there are two actions —
   one does not imply the other.
2. **A non-member of the project is refused at the door** (403).
3. **Nothing about a binding is declared by the binder**: heat and lot from the
   certificate, custody from the project, item from custody's source line.
4. **Matching is exact, never fuzzy.** `HEAT-7` never merges with `HEAT-77`.
5. **A certificate for a heat nobody received is refused** — unless someone
   says why, in which case it is kept as unresolved.
6. **A certificate that claims a lot custody does not attest is refused**, not
   downgraded.
7. **Nothing is edited.** Bindings are append-only; a re-bind is a later fact.
   The registry shows the latest per certificate; the history keeps them all.
8. **A resubmission is refused, not duplicated** (`request_id` is unique per
   project).
9. **The registry is whatever RLS lets this session read**, for the same reason
   the sign-off worklist is: one copy of the visibility rule, in the database.

## What this slice deliberately does not do

- **No document upload or PDF parsing.** A certificate row names an issuer, a
  number, a heat and an optional lot, and may point at a document already in
  the register; it does not hold a file.
- **No bulk import of certificates.** One certificate, one submit, one
  authorising nomination.
- **No legacy reconciliation surface.** The legacy `material_lots` warehouse
  (ADR-047 cutover) keeps its own slice.
- **No ITR export.** The dossier compiler is A.5.
- **Coverage counts; it does not judge.** Deciding *which* heats require a
  certificate is a standards question this screen does not answer — it reports
  every gap and lets a human read them.
- The registry is capped at 200 rows with no pagination, as on the other two
  quality screens.

## Tests

`MtrBindingHttpTest` — 13 tests / 57 assertions, all through HTTP:

- the registry is empty and says so, and both forms are withheld while nothing
  is nominated;
- each form appears with its own nomination — registering does not confer
  binding;
- a nominated actor registers a certificate and it appears in the registry;
  registering without a nomination writes nothing; a certificate without a heat
  is refused;
- a certificate binds to the custody that attests its heat, and the binding's
  heat **and item code** are asserted as derived values;
- a certificate whose heat nobody received is refused, and with a reason is
  kept as unresolved and appears in the unresolved table;
- binding without a nomination writes nothing; the same `request_id` is refused
  rather than duplicated;
- the coverage report shows the covered heat and the bare one side by side, with
  the bare one reporting zero certificates.

Related regression green together — 125 tests / 884 assertions:
`MtrCustodyBinding`, `InspectionResult`, `InspectionResultHttp`, `ItpSignOffHttp`,
`PipingCustody`, `PipingReceivingReview`, `PipingQaDecision`, `TableTools`,
`CookielessPreview`.

Two notes for whoever extends this:

- **`tests/Support/MtrBindingFixture` refuses to guess people.** Issuing a
  purchase order needs two named actors — a financial reviewer and an issuer —
  and the issuer may not be the person who drafted the order or the one who
  reviewed it. An earlier version picked them silently and failed with a bare
  403 that looked like a rule about purchase orders; it was a rule about two
  names being distinct. Pass them.
- **Count inside `TenantContext::runAs()`.** These tables are RLS+FORCE; a bare
  count outside the session contract returns zero and "proves" a refusal that
  never happened.
