# ADR-022 — Read-only PCF branch classification without fabricated fittings

Status: IMPLEMENTED (read-only classification only; model application deferred)
Date: 2026-10-12
Related: ADR-006, ADR-008, ADR-021, P-25

## Conflict

The parallel session's `PcfVocabulary::templateFor('TEE-SET-ON')` returns `OLET`. In this branch, the owner explicitly defined stub-on and stub-in as **P2P** outcomes: the branch pipe is welded to the header without a fitting. Mapping either to an OLET would fabricate a purchasable component and corrupt take-off. Conversely, a named WELDOLET/SOCKOLET is a fitting; a generic `OLET` token does not identify which type and cannot be resolved safely.

## Decision

Keep the verbatim pure parser from ADR-021, but do not port `PcfVocabulary` or its six incompatible vocabulary tests. A separate read-only `PcfBranchClassification::fromType()` classifies explicit PCF types into `(connection_type, p2p_kind)` or returns null. `TEE-SET-ON` → `(P2P, STUB_ON)`, `TEE-SET-IN` → `(P2P, STUB_IN)`, `TEE`/`TEE-SKEWED` → TEE, named WELDOLET/SOCKOLET → matching fitting. `OLET`, underspecified `TEE-SET` and unknown extensions stay unresolved. This classifier deliberately does not select a component template, create ports/joints, or authorize model import. Do not infer a P2P kind from geometry alone.

## Verification and future gate

New pure PHPUnit tests cover both P2P kinds, fitting distinctions and unknown/ambiguous types. They require PHP on Laragon: `php artisan test --filter=PcfBranchClassificationTest`; no PHP runtime is available in this sandbox. Before applying a PCF model, compare the branch classification to the current PMS branch rule (size footprint, pad and revision), protect existing fitted/manual joints, reject unsupported units and bound uploads. The current read-only classification by itself is **not** an acceptance of PCF-to-model writes. A better alternative to an all-purpose template mapping is this explicit discriminated branch outcome, because it cannot accidentally turn a no-fitting weld into inventory.
