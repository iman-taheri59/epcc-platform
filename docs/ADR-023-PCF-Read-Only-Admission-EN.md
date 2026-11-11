# ADR-023 — Bounded read-only PCF admission before any model import

Status: IMPLEMENTED (read-only boundary only; not an upload endpoint)
Date: 2026-10-12
Related: ADR-005, ADR-021, ADR-022

## Context and decision

The parallel PCF parser deliberately preserves raw input and defaults missing coordinate units to MM. That is useful for inspection, but unsafe for a future model importer: a unit guess can multiply dimensions and corrupt joints. A parser's successful return does **not** mean the source is admissible for engineering use.

Add `PcfAdmission` as a separate pure wrapper, leaving the ported parser and its tests unchanged. Before returning a `PcfFile`, it rejects content over 5,000,000 bytes, NUL bytes, more than 100,000 lines or 20,000 component blocks, missing line reference, missing/duplicate/unsupported bore or coordinate unit declarations, non-finite keypoint values and nonpositive specified bores. Supported bore units: MM, INCH, INCH-SIXTEENTH; coordinate units additionally MM-HUNDREDTHS. Bounds are conservative resource limits for this stage, not a code compliance claim. The pure `PcfParser` remains usable for inspection of incomplete files, but future model-writing paths must pass through an admission and engineering validation boundary.

No upload endpoint or persistence is introduced. Tests use PHPUnit without DB and cover both accepted and rejected text. Still open before any upload: file storage/quarantine, UTF-8 policy, long-line cap, authenticated authorization, filename handling, duplicate component identities, geometry compatibility, PMS publish/revision checks, immutable accepted-MTO reconciliation and protected joint behavior. Do not mistake bounded parsing for acceptance of model writes.

## Follow-up — standard tap connections are recognized, still read-only (2026-10-26)

The parser now normalizes both standard PCF forms: `TAP-CONNECTION` associated
with its containing component, and a standalone `TAP-CONNECTION` entry with
`CO-ORDS` plus `UNIQUE-COMPONENT-IDENTIFIER` naming the host. A separate entry's
UCI is resolved against a unique component in the same parsed file; absent,
dangling or duplicate host references are warnings. A tap point is not added to
the host's `connectingKeypoints()` and a standalone tap record is excluded from
material components. The invented `TAP-POINT` spelling is not accepted as a tap
and is reported as unsupported.

For branch component types, the admission path consults `SKEY` where PCF uses
broad aliases: `TESO`/`TERF` → `STUB_ON`, `TSSO`/`TSRF` → `STUB_IN`. A missing or
unknown SKEY, a multi-leg `CROSS-*` shape, and generic `OLET` remain visible as
unresolved warnings; named WELDOLET/SOCKOLET keep their explicit identities.
This is classification of source text only, not a class verdict or model write.

**Acceptance for this increment:** `PcfParserTest`, `PcfAdmissionTest` and
`PcfBranchClassificationTest` cover both tap forms, host UCI resolution and
failure modes, tap-vs-port behavior, legacy keyword rejection, and SKEY-driven
branch classification. `JointBoundaryTest` uses a standard tap record and
continues to assert that admission creates no joint.

**Deliberately not built:** no upload route, file persistence/quarantine,
preview/commit workflow, `JointService` application, geometry projection onto
the host, class/PMS revision lookup, OLET template selection, or PCF-to-MTO
reconciliation. The roadmap item “PCF upload → preview → commit” is therefore
still open.
