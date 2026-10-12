# B16.9 source review — applicability defect found

> **Resolved for existing NPS 1/2–24 coverage:** see
> [B16-9-CAP-APPLICABILITY-2026-09-28.md](B16-9-CAP-APPLICABILITY-2026-09-28.md).
> The findings below describe the pre-fix state. Larger sizes remain unsupported.


The user supplied `ASME B16.9-2018 - Factory-Made Wrought Buttwelding Fittings.pdf` (41 pages, text extractable). SHA-256: `f254b756cf54dc4115fba10c659f205cee913c816068736ee76f59b608052ab4`.

## Limited numeric comparison

All 65 existing SI dimension values agree with the selected source table columns when matched by outside diameter: 44 elbow A/B dimensions and 21 cap **E-column values**. Table 6.1-1 is printed page 7 / PDF page 18; Table 6.1-10 is printed page 20 / PDF page 31. The cap page, headings and notes were also visually inspected.

This comparison does NOT certify all engineering applicability, NPS/DN mappings, tolerances or the full standard. Script and per-row results: `/home/user/organization-report/b16-source-review/{compare.py,comparison.json}`. No engineering data or application code was changed in this source-review turn.

## Important defect: cap length depends on thickness

Table 6.1-10 has E, a limiting wall thickness for E, and E1. Notes (1) and (2) distinguish their applicability. For NPS 24 and smaller, thickness above the tabulated threshold uses E1, not E. For NPS 26 and larger, E1 requires manufacturer/purchaser agreement; a missing table cell must not become zero or an invented value.

The current reference pack stores only E, omitting the thickness threshold and E1. The new reader/PMS consumer does not select based on wall thickness. Consequently, its CAP `AVAILABLE` result is **not sufficient to determine the applicable cap length**. The earlier software tests proved consistency with the incomplete pack, not completeness against the source standard. Do not consume these cap results as unconditional model dimensions or cut lengths.

Example from the supplied table: NPS 6 has E=89 mm, limiting thickness 10.92 mm, E1=102 mm. Selecting 89 mm without evaluating the thickness condition is unsafe as an engineering inference.

## Next work, before more UI integration

1. Extend the cap reference schema/data with threshold and E1, source notes and explicit applicability semantics.
2. Require an explicitly sourced applicable thickness for cap length selection; report missing/unsupported applicability rather than defaulting to E. Do not silently substitute schedule or measured minimum thickness without defining what the input represents.
3. Add source-backed tests at, below and above the threshold, including missing thickness and manufacturer-agreement cases.
4. Only then use cap results in PMS/piping interfaces. The source tables extend beyond the currently transcribed ranges, but additional sizes and other fitting types should remain a separately tested expansion.

The supplied file resolves the previous absence-of-original-source limitation for this standard only. It is not a blanket verification of every dataset or a professional engineering certification. No further standard upload is needed to address this immediate cap defect.
