# MR source commitment review — read only

2026-10-19 · [فارسی](PO-SOURCE-REVIEW-FA.md) · [ADR-040](ADR-040-PO-SOURCE-REVIEW-EN.md)

## Delivered

Open a source-linked PO in Procurement → PO detail → **MR source commitment review — read only**. The dedicated GET route is `/projects/{projectCode}/procurement/orders/{orderId}/source-review`. The execution workspace also links to it when the caller can already read that generic PO in its original tenant/discipline scope. No new authority or schema/migration is introduced.

The report displays the visible source MR/current line quantities, related visible orders (25 per page), source relationship, review classification and visible source issue/allocation evidence. Line-only legacy references are included only when the actual source lines are already visible; duplicate item codes do not establish identity. Current quantities and historical approved-at-issue quantities are distinct.

### Classifications

- **DRAFT without issuance markers:** non-reserving. This does not approve purchasing or promise future capacity.
- **Legacy review required:** a potential historical commitment without verified source issue evidence in an actually visible buyer journal. Cancelled records are not assumed released/zero.
- **Recorded issue/allocations:** the retained source issue and each current active PO line have matching actual IDs, exact quantity/unit, source hash and approved-at-issue evidence. It is not a new financial/issuance certificate and does not revalidate current authority/source state.
- **Incomplete evidence:** retained issue evidence exists but complete matching allocation evidence is absent; review is required, not automatic repair.
- **Evidence hidden/unknown:** your current context cannot see the buyer-only journal/ledger. Hidden evidence is not reported as missing or zero.

## Access and quantity boundaries

The service queries only the original caller's tenant/project RLS; it never delegates to the buyer. Current active membership, discipline, procurement module and standards pack are checked. Existing owner/PMC/MC PO oversight remains, but does not automatically disclose the buyer-only issue/allocation tables. Explicitly nominated competing EPC personnel do not acquire generic source-review access. A named vendor sees only its existing visible order subset, no MR contents/lines and no competing vendor's orders. Other buyers/projects are excluded.

The report is always scoped and live, not a frozen exhaustive reconciliation. Discipline restrictions or hidden source/evidence can omit records; no project-wide clearance is inferred. Recorded allocations are grouped by actual source-line UUID **and historical unit**, summed as exact database decimals and displayed with their retained units. A later unit change does not silently convert historical quantities. **No official remaining quantity is calculated**, and zero visible recorded allocations is not assumed-zero legacy consumption.

Reports are read-only, including archived projects. There are no resolve/approve/release/price actions or POST route. Source state/signatures, PO state/prices, pricing/financial history and allocation ledgers are not changed. Allocation/issuance guards from ADR-039 remain authoritative and unchanged. A report does not unblock an order.

## Verification

**57 related tests /502 assertions PASS**,102.245s:12 new source-review cases plus existing PO detail, manual financial/issue and MR allocation regressions (including the prior targeted real two-connection scenario). This run does not constitute new exhaustive concurrency certification or a full-suite/recovery rerun.

New coverage: unchanged seven-table commercial/source/audit snapshots before/after reports; exact source IDs; cancelled and headerless legacy review; recorded active-line evidence after source metadata evolution; hidden oversight evidence not misclassified as missing; competing-EPC nomination isolation; current discipline restrictions; read-only archived EN/FA and HTML escaping; vendor/other-vendor/MR isolation;25-row pagination/language; guest/current-plan/foreign-project/manual-order refusal; preserved historical units without conversion.

Scoped8-file PHP lint, Pint/diff checks and11 implementation hashes: `docs/evidence/2026-10-19-po-source-review/summary.json`; runtime `related.log`. Private UTF8 PostgreSQL uses the non-superuser/NOBYPASSRLS application role and Unix socket only; the test server is stopped after verification. Prior delivery evidence remains untouched. No production migration/deployment, current CI, browser acceptance, commit or push claim.

## Next / recommendations

This makes blocked legacy commitments inspectable, not resolved. A controlled write-based reconciliation or release requires explicit semantics and authority; no guessed quantity, retro-certification or ledger deletion should be used. Legacy OrderGap project/line lock ordering, issue-versus-amend/revocation races, controlled post-issue changes and exact physical receipt attribution remain separate work. DocumentRegister numbering and MTO/G01–G07 deferrals remain unchanged.
