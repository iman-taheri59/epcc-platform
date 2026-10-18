# ADR-021 — Evaluate the parallel engineering baseline and PCF model import

Status: PARTIALLY IMPLEMENTED — owner approved parser-only follow-up; model import/baseline still deferred
Date: 2026-10-12
Related: ADR-005, ADR-020, P-25

## Source and scope

Read the parallel session's `workspace` archive (`workspace-01a10f9e-54f0-7317-953b-4b9b6bd73d89.zip` on the owner-published branch). Relevant files: `BaselineService`, `ModelImportService`, `BaselineBatch`, `EngineeringItem`, `BaselineDiscrepancy`, `ModelImport`, `ModelImportDiff`, `PcfParser` and related PCF types; baseline migrations `2026_09_19_090000` and `2026_09_28_100000`, model-import migration `2026_09_18_220000`, and dedicated feature/parser tests. Nothing was copied into the running application.

## Compatibility assessment

| Capability | Parallel session | This branch | Decision |
|---|---|---|---|
| Accepted quantities | `baseline_batches` + `engineering_items`: immutable source claims (MTO, model, manual) with source revision, scoped line/subsystem and accept/reconcile workflow | `mto_staging_*` + `accepted_mto_batches/rows` already preserve accepted MTO snapshots; procurement and order-gap consume this contract | **Conflict**: direct port creates two independent accepted-MTO authorities. Map existing accepted rows into a neutral evidence/query layer or explicitly supersede the old consumer contract; never maintain two silent winners. |
| Source conflicts | `BaselineDiscrepancy`, explicit human resolution; no automatic winner | Accepted MTO quantity snapshot and model quantities have no unified reconciliation | **Useful**, but design the cross-source key, UOM normalization, revision lineage, and resolution ownership first. Preserve both source claims. |
| PCF parsing | Standalone `PcfParser` + typed PCF value objects/fixtures | No PCF reader | **Candidate for isolated parser-only step** (pure parsing, no writes), with original parser tests and fixture; verify bore/coordinate units and limits. |
| Model apply | `ModelImportService` diffs components/ports/joints, protects fitted/manual edits and quarantines unknown lines | Shared component/port/joint foundation but independent PMS, release and accepted MTO workflows | **Not a blind port**. Requires review of write paths, permissions, frozen joints, quarantine and transactional dry-run semantics. |
| Migration/RLS | Baseline tables + later policy fix; model-import tables | Current sargable project RLS convention | Adapt policy to this branch; do not copy the old migration chain verbatim. |

Concrete dependencies absent here include `App\Support\Piping\Pcf\PcfParser` and `App\Support\Tabular\SpreadsheetReader`. `JointService` exists. The parallel baseline migration also changes `project_piping_config`, and its services expect those columns. There is no direct table-name collision for `engineering_items`, but **semantic** collision with `accepted_mto_batches/rows` is enough to stop the port. The parallel PCF importer writes model and joint state and must be compared to the current protection/release policy before use.

## Recommended decision / staged gate

**No-go on full baseline/model-import port now.** Prefer (1) an isolated PCF parser/value-object port with original parser tests, provided its input is bounded and no DB writes; (2) an explicit mapping ADR between immutable accepted MTO snapshots and cross-source claims/reconciliation, including item identity, revision precedence, UOM and ownership; (3) only then a model-import apply design/implementation with protected field edits and quarantine. Alternative: defer all of step 7 until the current MTO/procurement contract and PMS integration are verified on Laragon.

Owner decision requested: approve parser-only evaluation/port as the next step, or defer step 7 entirely. Neither choice implicitly approves copying `BaselineService`, `ModelImportService` or their migrations. No PHP tests executed here (PHP unavailable); previous Laragon verification gates remain open.

## Parser-only follow-up (owner approved, 2026-10-12)

Copied five pure PCF value/parser types (`PcfParser`, `PcfFile`, `PcfComponent`, `PcfKeypoint`, `PcfWarning`) and their 1,795-byte PCF fixture from the parallel archive without changing parsing behavior. Kept the first 13 parser tests verbatim in `PcfParserTest` (removed only the unrelated six vocabulary assertions and their import). `PcfVocabulary` was **not** copied: its `TEE-SET-ON`/`TEE-SET-IN` → `OLET` mapping conflicts with this session's explicit P2P stub-on/stub-in outcome and would invent a purchasable fitting. No route, model-write service, migration, DB integration or file upload endpoint was added. PCF coordinates default to millimetres in the exported value object; this is only a parse result, never permission to apply a model without explicit unit validation. For any future upload, enforce size/line/component limits, explicit supported bore and coordinate units, and safe error reporting at the boundary before persistence. Six PHP files passed independent syntax parsing; PHPUnit was not runnable because PHP is absent. Laragon gate: `php artisan test --filter=PcfParserTest`.

Follow-up: ADR-022 resolves the parallel vocabulary conflict with an isolated read-only PCF branch classifier; it does not authorize model application.

Follow-up ADR-023 adds bounded read-only admission (explicit units and resource limits) ahead of any future model application.

Follow-up ADR-024 specifies a single accepted-MTO authority and cross-source claim/reconciliation design; implementation awaits effective-revision/scope decisions.
