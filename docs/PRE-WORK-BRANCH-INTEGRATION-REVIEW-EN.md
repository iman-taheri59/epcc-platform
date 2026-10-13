# `pre-work` branch integration review

Date: 2026-10-13. Source tip: `878d34f` (`pre-work`); reviewed without switching branches or changing that remote branch. Comparison base: `efd6992` (main at branch point). Target: `arena/782120e4-epcc-platform`.

## Inventory and provenance

`pre-work` has two upload/extraction commits after the shared base. Relative to that base, it adds 1,450 paths and does not update the tracked application paths at the repository root. Its tree has 1,881 paths: a **nested `epcc-platform/` copy** (1,047 paths), 241 root files, 75 `analysis/` files, 48 `acceptance/` files and other screenshot/support directories. The root application is therefore an older baseline, not the integrated version of the nested tree. A whole-tree merge or copying the nested tree over root would erase later PMS, PCF, standards, order-gap and security changes.

Four source archives remain in the branch: `engineering-screening-source.tar.gz` (6 entries), `material-recovery-source.tar.gz` (25), `finding-response-source.tar.gz` (41), and `finding-disposition-source.tar.gz` (613, including a full repository snapshot). Another archive in `acceptance/backups/` is a demo attachment backup. Other root files include run logs, XML, JSON, screenshots and a large standards PDF. These are historical evidence, not independently deployable source. The nested snapshot also contains generated public assets and features from a parallel PMS/baseline/ITP model.

## Reconciliation against current branch

| Source family | Finding | Disposition |
| --- | --- | --- |
| Material screening and fitting review | Current root already has `MaterialScreening`, `ProjectMaterialSchema`, fitting review, snapshot migrations and tests. Archives contain earlier versions plus CLI diagnostics and a synthetic JSON example. | Do not overwrite integrated services/migrations. CLI and fixture can be adapted later with a PHP regression run; not needed for the current PMS integration blocker. |
| Finding responses/dispositions | Unique migration/service/UI/tests exist in archives and nested snapshot, but extend the parallel material workflow and require schema, authorization and RLS reconciliation. | Defer as a distinct feature integration; do not import a migration without its prerequisites. |
| PMS authoring and NDT | Nested snapshot has a separate PMS entry/event/note model and later migration series. Current branch's author-policy migration deliberately avoids absent `pms_entry_events` and `piping_class_notes`; the current P1 class/rule schema is different. | Do not copy wholesale. Treat designs and tests as reference when the owner chooses a complete write-path migration and gate. Author lookup alone is not authoring enforcement. |
| Equipment, baseline, model import, ITP, standards | Additional controllers, models, migrations, seeds and tests form a connected later-phase graph; some conflict with present line/PMS/standards schema. | Out of this step's scope; review feature by feature with migration order, tenant isolation and executable tests before import. |
| Evidence, PDFs, backups, screenshots, vendor-built assets | Historical and/or generated files, including database/attachment backups. | No transfer into Git. Avoid accidentally publishing project data or bloating the repository. |

**Files copied into the current branch from `pre-work`: none.** This is intentional: the useful source either already exists in a later integrated form or depends on a divergent schema and cannot safely be transferred as isolated files. The branch remains available by SHA for subsequent selective feature work. The useful result of this review is the explicit provenance and integration map, not an unverified bulk import.

## Better path (proposal, not yet authorized)

Create a feature-by-feature import matrix starting with the *read-only* material-screening CLI and synthetic example (low risk), then separately scope finding dispositions; leave PMS event/history enforcement to a dedicated ADR and end-to-end tenant tests. For every slice compare the nested snapshot's schema to current migrations, prove positive and negative tenant paths, run migration + Laravel tests in CI and update HANDOFF. Avoid a `git merge pre-work` or archive extraction into the application root.

### 2026-10-13 first selective recovery

Recovered the framework-free `scripts/screen-material.php`, its synthetic JSON
example and its standalone PHP regression test from the nested snapshot at
`878d34f`. The screening service itself was **not** replaced. CI's standards
job now runs the test and CLI JSON/HTML smoke independently of Laravel database
migrations; the degraded sandbox test runner includes the standalone test when
PHP is available. JSON syntax and `git diff --check` pass locally; PHP is not
installed in this sandbox, so CI is the first executable confirmation. This
addition does not expose a web route, approve a material or grant PMS access.
