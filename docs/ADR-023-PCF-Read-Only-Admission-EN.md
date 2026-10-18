# ADR-023 — Bounded read-only PCF admission before any model import

Status: IMPLEMENTED (read-only boundary only; not an upload endpoint)
Date: 2026-10-12
Related: ADR-005, ADR-021, ADR-022

## Context and decision

The parallel PCF parser deliberately preserves raw input and defaults missing coordinate units to MM. That is useful for inspection, but unsafe for a future model importer: a unit guess can multiply dimensions and corrupt joints. A parser's successful return does **not** mean the source is admissible for engineering use.

Add `PcfAdmission` as a separate pure wrapper, leaving the ported parser and its tests unchanged. Before returning a `PcfFile`, it rejects content over 5,000,000 bytes, NUL bytes, more than 100,000 lines or 20,000 component blocks, missing line reference, missing/duplicate/unsupported bore or coordinate unit declarations, non-finite keypoint values and nonpositive specified bores. Supported bore units: MM, INCH, INCH-SIXTEENTH; coordinate units additionally MM-HUNDREDTHS. Bounds are conservative resource limits for this stage, not a code compliance claim. The pure `PcfParser` remains usable for inspection of incomplete files, but future model-writing paths must pass through an admission and engineering validation boundary.

No upload endpoint or persistence is introduced. Tests use PHPUnit without DB and cover both accepted and rejected text. `php artisan test --filter=PcfAdmissionTest` must run on Laragon; PHP is unavailable here. Still open before any upload: file storage/quarantine, UTF-8 policy, long-line cap, authenticated authorization, filename handling, duplicate component identities, geometry compatibility, PMS publish/revision checks, immutable accepted-MTO reconciliation and protected joint behavior. Do not mistake bounded parsing for acceptance of model writes.
