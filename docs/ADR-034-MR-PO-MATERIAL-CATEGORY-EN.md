> Follow-on 2026-10-19: [approved own-company MR → PO draft copying](PO-MR-DRAFT-LINK-EN.md) is now available under independent PO nomination, with exact source IDs and **no MR consumption/issuance**. Prior verification below belongs to its earlier source; current targeted result is131/1067.

# ADR-034 — MR and PO separation by material category

Date: 2026-10-19. **Domain rule ACCEPTED by explicit owner instruction; manual entry separation IMPLEMENTED / TARGETED VERIFIED.**

## Owner correction
MR and PO must be separated by material category, like MTO: e.g. MR for Pipes, PO for Pipes, PO for Flanges. This corrects the category-free manual entry delivered previously; a discipline or descriptive document title is not a substitute for a category.

## Accepted rule
Each MR and each PO covers one material category, not a mixture of categories. Multiple MRs/POs may exist for the same category; this is not a uniqueness rule on project + category. Independent PO draft author nomination and existing MR authority remain unchanged. Category separation grants no issuance, spending, MTO authoring or admission authority.

## Implementation direction (original owner-correction record; current status below)
- Reuse the stable MTO/material catalogue category vocabulary (`MaterialItem::categories()`), not a new taxonomy or `ProjectMaterialSchema::FAMILIES` (whose BOLTING code differs from BOLT_NUT).
- Require a structured header category for new MR/PO entry, show it on forms/register/detail, and support filtering/grouping by category. “PIPING” is a discipline, whereas PIPE and FLANGE are distinct categories within it.
- Validate actual line classification against the document category when stable material identity is available. Free-text descriptions/item codes alone cannot establish category identity; do not claim that a header dropdown proves every item's physical classification. Define and visibly distinguish declared/manual classification from catalogue-verified classification in the implementation.
- Future MR-to-PO import/linking and MTO comparison must preserve category as well as material identity, scope and compatible unit; do not use category totals as a substitute for item-level matching. Current manual PO entry remains unlinked and consumes no MR.
- Retain historical documents and numbers; do not infer a historical category from titles, descriptions or code prefixes, or silently split issued documents. Migration should retain explicit unclassified legacy data pending reviewed classification; mixed historical documents need a separate correction plan.
- Keep the current numbering scheme unless separately required; owner examples describe category separation, not an approved new numbering convention.

## Current implementation status
Implemented: required structured category on authenticated MR/PO entry; shared catalogue vocabulary; header storage, register category/filter, PO detail and MR line-basis display; optional exact catalogue UUID validation; SQL category/source/attribution guards; unchanged numbering/authority; historical data retained explicitly unclassified. Legacy trusted primitives remain compatibility paths and are not new human endpoints. Category is inherited by lines; manual text is declared, not catalogue-verified. No new import/consumption or historical classification workflow.

116 targeted tests/957 assertions PASS, including 13 new cases/247 assertions. See [implementation and limits](PROCUREMENT-MATERIAL-CATEGORIES-EN.md). Remaining: searchable catalogue selection, reviewed historical correction, and explicit item-level MR-to-PO linking. Earlier ADR-033 evidence remains historical, not proof of current full-suite compliance.

References: ADR-005 engineering/MTO ingestion; ADR-018 category/scope/compatible-unit comparison; ADR-033 independent PO draft authority. Companion: ADR-034-MR-PO-MATERIAL-CATEGORY-FA.md.
