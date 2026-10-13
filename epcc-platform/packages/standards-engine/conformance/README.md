# Conformance corpus

**Purpose (ADR-003 Annex A §4 and §7).** Annex A made Laravel the backend but noted the
cost: the offline field PWA cannot run PHP, so the release-gate evaluator must exist
twice — once in PHP on the server, once in TypeScript in the browser. Two
implementations that must never disagree are normally a defect generator.

This corpus removes that risk. It is the **language-neutral contract** for the
evaluator. `cases.json` contains no PHP, no JS, and no framework types — only
`(pack, objectType, facts) -> expected verdict`.

Any implementation, in any language, must produce these verdicts.
**Divergence is a build failure, not a field data conflict.**

## Running it

```bash
php packages/standards-engine/conformance/run.php   # PHP reference implementation
```

Also runs as part of `bash prototype/test/run-all.sh`.

## When the TypeScript PWA is built

Write a second runner that reads the **same** `cases.json` and asserts the same
verdicts. Both runners must be in CI. Do not fork the corpus, and do not let one
implementation add a case the other cannot satisfy.

## Case format

```jsonc
{
  "id": "SV-02-no-measurement-sheets",
  "why": "Why this case exists - keep it, it is the documentation.",
  "pack": "ips-iran",              // a shipped pack, OR use "inlinePack" for engine-level cases
  "objectType": "interimPaymentCertificate",
  "facts": { },
  "expect": {
    "passed": false,
    "blocked": true,
    "waiverAllowed": false,
    "absolutelyBlocked": true,      // optional
    "waivable": false,              // optional
    "waiverCapability": "...",      // optional
    "justificationRequired": true,  // optional
    "failedChecks": ["measurementSheetsAttached"],
    "requireBilingualMessages": true // optional: assert both en and fa are present
  }
}
```

`failedChecks` is compared order-independently — rule order in a pack must not
change the verdict.

## Properties this corpus pins

| Property | Cases |
|---|---|
| Happy paths clear the gate | SV-01, TAHVIL-01, IWP-01 |
| **Fail closed on missing facts** | MISC-02 |
| **Fail closed on unimplemented checks** | SAFETY-01, SAFETY-02 |
| Non-waivable gates are absolute (صورت‌وضعیت) | SV-08 |
| Waivable gates expose capability + justification | TAHVIL-06 |
| Numeric boundaries (`<=` not `<`) | SV-06, IWP-04 |
| Check arguments are honoured (`:A`, `:IFC`, `:100`) | TAHVIL-02/03, IWP-03/04 |
| Vacuous truth is rejected (empty list ≠ all satisfied) | TAHVIL-04 |
| All failures reported, not just the first | SV-07 |
| Every failure is bilingual EN+FA | MISC-03 |
| Engine stays generic across packs (ADR-002 seam) | IWP-01…05 |

## Mutation testing

The corpus was validated by deliberately breaking the PHP engine. Six mutants:

| Mutant | Caught by v1.0.0 | Caught by v1.1.0 |
|---|---|---|
| Empty ITR list passes (vacuous truth) | yes (1 case) | yes |
| Authority boundary `<` instead of `<=` | yes (2) | yes |
| **Unknown check silently passes** | **NO — survived** | **yes (2)** |
| Predicate argument dropped | yes (6) | yes |
| Only first failure reported | yes (2) | yes |
| Non-waivable gate becomes waivable | yes (10) | yes |

The third mutant survived v1.0.0 and is the most dangerous of the set: a gate that
silently ignores a check it does not implement **stops being a gate**. On an
air-gapped install running an older engine than the pack was authored for
(risk IR-R4), that failure mode is invisible. SAFETY-01/02 were added in v1.1.0
to close it.

**If you add a predicate, add a mutant and prove the corpus catches it.**
A corpus that never fails is proving nothing.
