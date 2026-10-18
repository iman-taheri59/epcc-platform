#!/usr/bin/env python3
"""Locate the pages of an ASME B31.3-2024 PDF that carry the data the
platform needs for pad-thickness validation (P-24): Table A-1 / A-1M
allowable stresses, Table 304.1.2 (Y), para 302.3.5 (W), para 328.5.4
(minimum welds) and Appendix H worked examples.

Usage:
    .cache/ve/bin/python scripts/extract_b313_inspect.py <pdf> [keyword ...]

Prints page numbers (0-based) whose extracted text contains any keyword.
This is the reconnaissance step; the actual table parsers are written once
the layout of the uploaded copy is known.
"""

import sys

from pypdf import PdfReader


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2

    path = sys.argv[1]
    keywords = sys.argv[2:] or [
        "Table A-1", "A-1M", "304.1.2", "302.3.5", "328.5.4",
        "Appendix H", "EXAMPLE 4", "EXAMPLE 5",
    ]

    reader = PdfReader(path)
    print(f"{path}: {len(reader.pages)} pages")

    hits: dict[str, list[int]] = {k: [] for k in keywords}
    for i, page in enumerate(reader.pages):
        try:
            text = page.extract_text() or ""
        except Exception as e:  # noqa: BLE001 - report and continue
            print(f"  page {i}: extraction failed: {e}")
            continue
        for kw in keywords:
            if kw.lower() in text.lower():
                hits[kw].append(i)

    for kw, pages in hits.items():
        print(f"{kw}: {len(pages)} pages -> {pages[:40]}{'…' if len(pages) > 40 else ''}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
