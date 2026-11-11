# ADR-022 — Read-only PCF branch classification without fabricated fittings

Status: IMPLEMENTED (read-only classification only; model application deferred)
Date: 2026-10-12
Related: ADR-006, ADR-008, ADR-021, P-25

## Conflict

The parallel session's `PcfVocabulary::templateFor('TEE-SET-ON')` returns `OLET`. In this branch, the owner explicitly defined stub-on and stub-in as **P2P** outcomes: the branch pipe is welded to the header without a fitting. Mapping either to an OLET would fabricate a purchasable component and corrupt take-off. Conversely, a named WELDOLET/SOCKOLET is a fitting; a generic `OLET` token does not identify which type and cannot be resolved safely.

## Decision

Keep the pure parser from ADR-021, but do not port `PcfVocabulary` or its six incompatible vocabulary tests. A read-only `PcfBranchClassification` maps explicit source vocabulary into `(connection_type, p2p_kind)` or returns null. Only unambiguous type-only names are classified by `fromType()`; the broad PCF aliases `TEE-SET-ON` and `TEE-STUB` require component-level SKEY interpretation. Ordinary `TEE`/`TEE-SKEWED` map to TEE, named WELDOLET/SOCKOLET map to their fitting types, and generic `OLET`, underspecified `TEE-SET` and unknown extensions stay unresolved. This classifier deliberately does not select a component template, create ports/joints, or authorize model import. Do not infer a P2P kind from geometry alone.

## Verification and future gate

Pure PHPUnit tests cover both P2P kinds, fitting distinctions, unknown/ambiguous types and SKEY-based tee aliases. Focused verification on 2026-10-26: `PcfParserTest|PcfAdmissionTest|PcfBranchClassificationTest` — 49 passed (95 assertions); `JointBoundaryTest` — 9 passed (16 assertions). The final combined targeted regression (`PcfParserTest|PcfAdmissionTest|PcfBranchClassificationTest|JointBoundaryTest|JointTest|BranchWallJointTest|BranchClassVerdictTest|PmsPinnedBranchResolutionTest`) passed 132 tests (267 assertions). Before applying a PCF model, compare the branch classification to the current PMS branch rule (size footprint, pad and revision), protect existing fitted/manual joints, reject unsupported units and bound uploads. The current read-only classification by itself is **not** an acceptance of PCF-to-model writes. A better alternative to an all-purpose template mapping is this explicit discriminated branch outcome, because it cannot accidentally turn a no-fitting weld into inventory.

## Follow-up — use PCF SKEY for broad tee aliases (2026-10-26)

The PCF reference uses both `TEE-SET-ON` and `TEE-STUB` for more than one
fabricated branch configuration. The parser's component-level classifier now
uses the SKEY rather than guessing from the type token: `TESO`/`TERF` mean
`STUB_ON`; `TSSO`/`TSRF` mean `STUB_IN`. A missing or unfamiliar SKEY remains
unresolved. `TEE-SET-IN` remains an explicit type-level `STUB_IN` signal.

`CROSS-SET-ON` and `CROSS-STUB` are visible as branch-specific PCF types, but a
single classification cannot represent their multiple branch legs; they are
reported unresolved pending a per-leg design. A generic `OLET` also remains
unresolved; only explicitly named `WELDOLET` and `SOCKOLET` classify here. None
of these source classifications creates a fitting, port, or joint.
