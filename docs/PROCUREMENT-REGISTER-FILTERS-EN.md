> **Current delivery — 2026-10-19:** [exact money + independent register pagination](REGISTER-PAGINATION-MONEY-EN.md) / [فارسی](REGISTER-PAGINATION-MONEY-FA.md). **41 affected tests /499 assertions PASS**, eight new cases. Exact stored decimal money/null≠zero; independent25-row MR/PO pages, filtered totals/late count, stable ordering and preserved filters/original caller RLS. This closes the two earlier recommended increments, not indexed/load-certified search or inspection pagination. No new endpoint/schema/role/action or full-suite/production claim. Prior evidence retained; older current/follow-up notices below are historical.

# Procurement register: independent MR / PO search and state filters

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED** · [فارسی](PROCUREMENT-REGISTER-FILTERS-FA.md)

## Use

Open Project → Procurement. The existing category control now shares a GET form with:

- **Requisition filters:** document number/title query and recorded MR state.
- **Purchase order filters:** independent document number/title query and recorded PO state.

Apply filters to get a bookmarkable URL. Number, English title and Persian title are searched as literal substrings. PostgreSQL lower-case behavior follows its database locale; ASCII case folding and Persian text matching were verified. There is no transliteration, Persian/Arabic letter folding, accent normalization or localized-digit normalization. Blank text means no text restriction; `0` is a real query. Percent, underscore, backslash and quotes remain literal data, not SQL/LIKE wildcards.

State is exact and uses the existing MR_ALL / PO_ALL vocabulary. For example: category PIPE + MR SUBMITTED + MR query, independently of PO DRAFT + PO query. Category continues to narrow both registers. UNCLASSIFIED still means an actual null historical category; no title-based reclassification occurs. A recorded MR ORDERED state is merely filterable historical state, not a new MR mutation or proof of current issued allocation.

Reset clears search/state while retaining category and language. Input values and selected state persist through the submitted URL, HTML-escaped. Empty results say no *visible matching* documents rather than implying no document exists.

MR, PO and late-order counters describe the **server-filtered visible results**, not project-wide totals, tabletools' additional local filters, commitments or remaining purchasing capacity. The inspection register/count remains unchanged. Browser tabletools remain supplementary and do not update these server counters. No JavaScript is needed for the new GET controls.

## Reviewed decisions and implementation

ADR-034 requires category-separated MR/PO presentation and filters without historical inference or new MTO authority. The existing FR-UX-04 tabletools contract remains; new server filters complement rather than replace it. ADR-033/035–040 independent drafting, full-copy source, three-person/versioned execution, pricing and original-caller evidence boundaries are unchanged. This is a presentation/query increment under accepted authorities, not a new workflow/role decision.

`MaterialsController::procurement` validates independent nullable strings `mr_q`, `po_q` (max120 characters), and `mr_state`, `po_state` (existing exact vocabulary), alongside the existing `material_category`. The original project resolution/access, caller-company TenantContext, forced RLS, procurement/pack entitlement and existing mutation services are unchanged. The new OR search clauses are grouped within AND project/category/state predicates. Only fixed header columns are searched, with bound parameters (`strpos(lower(coalesce(column,'')),lower(?))`); there is no buyer impersonation, identity hatch, dynamic column input or source/line/vendor-directory search. Eager loading happens after the header restrictions.

No new endpoint, schema/index, permission, financial/issue action, MTO right, hidden audit delegation, quantity/source rewrite, state transition, reservation or complete-commitment claim. Filtered visibility never grants drafting; a competing buyer still cannot create a PO. A vendor can match its visible orders, not hidden MRs. Scope preserved here means the register's preexisting read boundary, not a newly designed per-user register/discipline permission model.

Invalid queries use the existing Laravel request validation behavior (JSON requests422; ordinary web validation redirect/errors). Unsupported shapes, oversized text and MR/PO state-vocabulary mixups are refused, not interpolated or silently treated as all states.

## Verification

**33 affected tests /310 assertions PASS,51.400s**, including **eight new feature cases**. Suites: `ProcurementRegisterFilterTest`, `ProcurementCategoryTest`, `PurchaseOrderEntryTest`. No routine full suite. Final authoritative evidence: `docs/evidence/2026-10-19-procurement-register-filters/related.log` and `summary.json`.

Coverage: independent header-only number/EN/FA search; blank and zero query; category/state conjunction under all OR branches; literal `%`, `_`, backslash and SQL-like text; invalid shape/length/vocabulary; competing-buyer RLS/nondelegation and vendor-visible-PO/hidden-MR; real BB-2 cross-project records; archived read-only search with seven-table no-mutation snapshot; escaped bilingual controls/reset URL; existing category/entry regressions. Initial fixture-only failures (missing required Persian line description; empty POST stopped by required-array validation before permission check) were corrected, with guards unchanged. Initial logs are retained and superseded by the combined final run.

Four PHP lint/Pint checks, whitespace diff check, role evidence and five implementation hashes are recorded. Private PHP8.4/PostgreSQL17, UTF8/localeC, epcc NOSUPERUSER/NOBYPASSRLS, Unix socket only; stopped after verification. No browser, current CI, full-suite, production migration/deployment, commit/push or load/concurrency certification.

## Recommendations / explicitly not implemented

1. **Scalability:** results still use the existing complete matching collections; server-side pagination, independently named page parameters, unfiltered/filtered count design and indexed/trigram search need a separate bounded increment and performance evidence. Do not claim20k-document readiness from this slice.
2. **Existing monetary display deficiency found during review:** the register's preexisting `$money` formatter uses float and integer `number_format`, losing decimal display precision. This slice does not touch that formatter. Recommended next correction: exact decimal-string rendering (as on PO detail/history), EN/FA and large/fractional/unknown-amount checks. This is a presentation fix, not a currency conversion or new financial policy.
3. General legacy/manual/MTO authority, post-issue amendment, physical receipt and write-based source reconciliation remain deferred; search results/counts cannot settle them.
