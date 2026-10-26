> Follow-on 2026-10-19: [approved own-company MR → PO draft copying](PO-MR-DRAFT-LINK-EN.md) is now available under independent PO nomination, with exact source IDs and **no MR consumption/issuance**. Prior verification below belongs to its earlier source; current targeted result is131/1067.

# MR / PO material-category separation — 2026-10-19

**Implemented and targeted-verified**, following the explicit owner correction in [ADR-034](ADR-034-MR-PO-MATERIAL-CATEGORY-EN.md). [فارسی](PROCUREMENT-MATERIAL-CATEGORIES-FA.md).

## Use
Project → Procurement → open the new MR or PO draft form. **Material category is required**, with no default. Use separate documents for Pipes, Flanges, etc.; multiple documents for one category are allowed. Categories reuse MTO/catalogue codes: PIPE, FITTING, FLANGE, VALVE, GASKET, BOLT_NUT, SPECIALTY and SUPPORT. PIPING remains the engineering discipline, not a category.

The register has a category column and shared **Filter MR / PO** selector, including **Unclassified legacy document**. Example: `/projects/PH-14/procurement?lang=en&material_category=PIPE`. PO detail shows its category; MR's expandable lines show their classification basis. EN/FA labels and RTL remain supported. Inspection records are not filtered by this MR/PO selector. Current numbering is unchanged and project-wide, not reset for each category.

## One category, two explicit evidence levels
- **Manually declared:** without an exact catalogue UUID, all manual lines are declared under the header category. This is not physical/specification verification. The system cannot infer that an arbitrary free-text code/description really represents a pipe; it does not guess categories or silently map aliases.
- **Catalogue-linked:** optionally enter the exact MaterialItem UUID on a line. The entry facade checks active availability, project/global scope, category, discipline, exact commodity code and exact base unit, under the tenant transaction and shared material-row lock. A conflicting explicit line category or mismatching catalogue identity is rejected before allocating a number. No conversion is performed. This proves the checked classification at entry, not technical/commercial approval or MTO acceptance.

Category is persisted on each header and inherited by its lines; it is not an editable free-text line classification. Saved classified documents cannot be silently reclassified. SQL guards also reject conflicting catalogue-line categories and classified MR/PO source-category mismatches. The existing trusted approved-MR conversion inherits its source category; **no new HTTP conversion/import or MR consumption is introduced** by this slice. Future explicit linking must additionally preserve item identity, scope and compatible units; category totals are not an order-gap calculation.

## Legacy / authority / migration boundaries
New category columns are nullable solely to retain historical/legacy-compatible data. Migration does not infer categories from titles, split documents, renumber, change states/counters/policy history or issue grants. Existing policy-attributed drafts remain readable and do not acquire a forced category on unrelated updates. Historical mixed-category documents remain explicitly unclassified pending a separate reviewed correction path.

The authenticated human MR/PO entry facades require category. SQL requires it when a new human author-policy attribution is assigned, while grandfathering existing attributed rows. Trusted internal legacy primitives/fixtures can still produce unclassified rows; this is **not** universal SQL per-user authorization or a complete retirement of legacy write paths. The migration is forward-only; no production migration was performed.

Independent PO nomination, existing MR nomination, live tenant/membership/discipline/module/archive checks and all financial-authority deferrals remain unchanged. No MTO write/admission permission, PO issuance, receipt, inspection or usable-stock approval is granted. G01–G07 and the unrelated DocumentRegister numbering defect remain open.

## Evidence
**116 related tests /957 assertions, exit0; no failures, errors or skips.** Includes **13 new category/upgrade cases /247 assertions** and 103 existing cases/710 assertions: MR/PO entry, both prior upgrades, PO details, Procurement, OrderGap attribution/amendment and shared shell. Final JUnit ~155.6s. Evidence: `docs/evidence/2026-10-19-procurement-category/related.{log,xml}`, `summary.json` with 20 feature-source hashes, scoped PHP lint (15 files), Pint check and diff-check. Initial development API/fixture/import errors were corrected; final related run is clean.

Covers required/invalid category and all 8 catalogue categories; multiple documents per category; mixed declared category and wrong/foreign/missing/withdrawn identities; exact code/unit/discipline checks; bilingual forms, filter/detail/basis labels; tenant isolation/no authority inheritance; SQL category requirement/immutability/source mismatch; existing amendment inheritance; and archived populated upgrade preserving even misleading “Pipes” titles without inference. Full suite, browser smoke, real parallel/crash test, commit/push, deployment and recovery bundle were not performed.

## Next proposed work — not implemented
A searchable catalogue selector would improve the optional UUID field. The next workflow slice remains controlled approved-MR → PO draft linking/import with exact item provenance and no premature consumption; issuance/spending authority requires its separate decision. Historical classification/correction remains a separate reviewed operation, not an automatic migration.
