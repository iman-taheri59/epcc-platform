#!/usr/bin/env python3
"""Extract B31.3-2024 material data (P-24 Step B) from the owner-uploaded
standard into standards-packs/reference/b313-materials.ref.json.

Table A-1 (SI, MPa) is position-parsed: every page carries its own
temperature ladder in its header row, so the ladder is read per page and
each stress value is placed by its x-coordinate. Numeric engineering values
only — the code text itself is never reproduced.

Usage:  .cache/ve/bin/python scripts/build_b313_materials_pack.py [pdf] [out]
"""

import json
import re
import sys

import pdfplumber

PDF = sys.argv[1] if len(sys.argv) > 1 else ".cache/ASME-B31.3-2024.pdf"
OUT = sys.argv[2] if len(sys.argv) > 2 else \
    "standards-packs/reference/b313-materials.ref.json"

A1_PAGES = range(206, 316)          # Table A-1 (SI), before A-1C starts
LINE_NO_X = 70                        # left row-number column
LINE_NO_REPEAT_X = 700                # repeated row number right of metadata
NUM = re.compile(r"^-?\d+(?:\.\d+)?$")

TARGETS = {
    # spec as printed (no space): {grades}, form keyword required
    "A53": ({"A", "B"}, "pipe"),
    "A106": ({"A", "B", "C"}, "pipe"),
    "API5L": ({"A", "B", "L245", "L290", "L320", "L360", "L390",
              "L415", "L450", "L485"}, "pipe"),
    "A333": ({"1", "6"}, "pipe"),
    "A312": ({"TP304", "TP304H", "TP304L", "TP316", "TP316H", "TP316L",
              "TP321", "TP321H", "TP347", "TP347H"}, "pipe"),
    "A335": ({"P5", "P9", "P11", "P22", "P91"}, "pipe"),
    "A516": ({"55", "60", "65", "70"}, "plate"),
    "A285": ({"A", "B", "C"}, "plate"),
}



TABLE_304_1_1_Y = {
    "title": "Table 304.1.1-1 Coefficient Y for t < D/6 (SI, deg C)",
    "temps_c": [482, 510, 538, 566, 593, 621, 649, 677],
    "temp_semantics": "First column covers <=482 deg C; last covers >=677 deg C.",
    "interpolation": "Y may be interpolated for intermediate temperatures.",
    "rows": [
        {"family": "ferritic_steels", "y": [0.4, 0.5, 0.7, 0.7, 0.7, 0.7, 0.7, 0.7]},
        {"family": "austenitic_steels", "y": [0.4, 0.4, 0.4, 0.4, 0.5, 0.7, 0.7, 0.7]},
        {"family": "nickel_alloys_N06617_N08800_N08810_N08825",
         "y": [0.4, 0.4, 0.4, 0.4, 0.4, 0.4, 0.5, 0.7]},
        {"family": "gray_iron", "y": [0.0, None, None, None, None, None, None, None]},
        {"family": "other_ductile_metals",
         "y": [0.4, 0.4, 0.4, 0.4, 0.4, 0.4, 0.4, 0.4]},
    ],
}

TABLE_302_3_5_W = {
    "title": "Table 302.3.5-1 Weld joint strength reduction factor W (SI, deg C)",
    "temps_c": [427, 454, 482, 510, 538, 566, 593, 621, 649, 677, 704, 732,
                760, 788, 816],
    "temp_semantics": "First column covers <=427 deg C. W = 1.0 below the "
                      "tabulated range of a group (General Note (a)).",
    "interpolation": "Linear interpolation allowed (General Note (e)).",
    "rows": [
        {"group": "carbon_steel", "w": [1, 1, 1, 1, 1, 1, 1, None, None, None,
                                         None, None, None, None, None]},
        {"group": "cr_mo_normalised_or_subcritical_PWHT",
         "w": [1, 0.95, 0.91, 0.86, 0.82, 0.77, 0.73, 0.68, 0.64, None, None,
               None, None, None, None]},
        {"group": "csef_normalised_and_tempered",
         "w": [None, None, None, 1, 0.95, 0.91, 0.86, 0.82, 0.77, None, None,
               None, None, None, None]},
        {"group": "csef_subcritical_PWHT",
         "w": [None, None, 1, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, None, None, None,
               None, None, None]},
        {"group": "autogenous_austenitic_and_N066xx_N088xx_solution_annealed",
         "w": [None, None, None, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]},
        {"group": "filled_austenitic_and_N088xx",
         "w": [None, None, None, 1, 0.95, 0.91, 0.86, 0.82, 0.77, 0.73, 0.68,
               0.64, 0.59, 0.55, 0.5]},
    ],
    "other_materials_note": (
        "For other materials: W = 1.0 for Ti <= Tcr, and for SI units "
        "W = 1 - 0.00164 * (Ti - Tcr) for Tcr < Ti <= 816 deg C "
        "(Note 9). Tcr per General Note (b)."),
}

TABLE_A3_EJ = {
    "title": "Table A-3 Basic quality factors Ej, subset for target specs "
             "(pp.398-401)",
    "rows": [
        {"spec": "A53", "type": "S", "description": "Seamless pipe", "ej": 1.00},
        {"spec": "A53", "type": "E", "description": "Electric resistance welded pipe", "ej": 0.85},
        {"spec": "A53", "type": "F", "description": "Furnace butt welded pipe", "ej": 0.60},
        {"spec": "A106", "type": None, "description": "Seamless pipe", "ej": 1.00},
        {"spec": "API5L", "type": None, "description": "Seamless pipe", "ej": 1.00},
        {"spec": "API5L", "type": None, "description": "Electric fusion welded pipe, 100% radiographed", "ej": 1.00},
        {"spec": "API5L", "type": None, "description": "Electric welded pipe", "ej": 0.85},
        {"spec": "API5L", "type": None, "description": "Electric fusion welded pipe, double butt seam", "ej": 0.95},
        {"spec": "API5L", "type": None, "description": "Continuous welded (furnace butt welded) pipe", "ej": 0.60},
        {"spec": "A333", "type": None, "description": "Seamless pipe", "ej": 1.00},
        {"spec": "A333", "type": None, "description": "Electric resistance welded pipe", "ej": 0.85},
        {"spec": "A335", "type": None, "description": "Seamless pipe", "ej": 1.00},
        {"spec": "A312", "type": None, "description": "Seamless pipe", "ej": 1.00},
        {"spec": "A312", "type": None, "description": "Electric fusion welded pipe, double butt seam", "ej": 0.85},
        {"spec": "A312", "type": None, "description": "Electric fusion welded pipe, single butt seam", "ej": 0.80},
        {"spec": "A312", "type": None, "description": "Electric fusion welded pipe, 100% radiographed (also HCW per Note 5)", "ej": 1.00},
        {"spec": "A516", "type": None, "description": "Plate (fabricated pipe / pads); no longitudinal weld credit reduction", "ej": 1.00},
        {"spec": "A285", "type": None, "description": "Plate (fabricated pipe / pads); no longitudinal weld credit reduction", "ej": 1.00},
    ],
}

SPEC_RE = re.compile(r"^(A\d{2,3}|API5L|B\d{3})$")
UNS_RE = re.compile(r"^[KSNEPJ]\d{5}$")


def page_lines(pg):
    lines = {}
    for w in pg.extract_words(x_tolerance=1.5):
        key = round(w["top"] / 3) * 3
        lines.setdefault(key, []).append(w)
    return {k: sorted(v, key=lambda w: w["x0"]) for k, v in lines.items()}


def find_ladders(lines):
    """Per-page temperature ladders: [(header_y, [(temp, x), ...])]."""
    ladders = []
    for y in sorted(lines):
        labels = []
        for w in lines[y]:
            t = w["text"]
            if w["x0"] < 690:
                continue
            if t.startswith("to") and t[2:].isdigit():
                labels.append((int(t[2:]), w["x0"]))
            elif re.match(r"^\d{2,3}$", t) and 40 <= int(t) <= 850:
                labels.append((int(t), w["x0"]))
        labels.sort(key=lambda l: l[1])
        temps = [t for t, _ in labels]
        if (len(labels) >= 10 and temps[0] == 40 and 65 in temps
                and all(b > a for a, b in zip(temps, temps[1:]))
                and all(b - a in (25, 35, 50) for a, b in zip(temps, temps[1:]))):
            ladders.append((y, labels))
    return ladders


def merged_ladder(ladders):
    """One label grid per page: stacked table segments share the same
    physical columns, so union every header's labels by temperature."""
    by_temp = {}
    for _, labels in ladders:
        for temp, x in labels:
            by_temp.setdefault(temp, x)
    return sorted(by_temp.items())


def parse_row(ws, line_no, labels):
    """Layout-independent: S values map to ladder labels by x; metadata is
    everything else, parsed structurally."""
    meta, s_by_temp = [], {}
    def push(t):
        if meta and meta[-1] == "API" and t == "5L":
            meta[-1] = "API5L"
        elif meta and meta[-1] == "PSL" and t.isdigit():
            meta[-1] = "PSL" + t
        else:
            meta.append(t)
    for w in ws[1:]:
        x, text = w["x0"], w["text"]
        if text == line_no and x > 660:
            continue                            # repeated row-number column
        if labels:
            nearest = min(labels, key=lambda l: abs(l[1] - x))
            if abs(nearest[1] - x) < 20 and 40 <= nearest[0] <= 850:
                s_by_temp.setdefault(nearest[0], text)
                continue
        push(text)
    if len(s_by_temp) < 3 or len(meta) < 8:
        return None

    spec_i = next((i for i, t in enumerate(meta) if SPEC_RE.match(t)), None)
    if spec_i is None:
        return None
    spec = meta[spec_i]
    grade = meta[spec_i + 1] if spec_i + 1 < len(meta) else None
    if grade is None:
        return None
    psl = next((t for t in meta[spec_i + 2:spec_i + 4]
                if t.upper().startswith("PSL")), None)

    nums = [t for t in meta if NUM.match(t.replace("\u2212", "-"))]
    if len(nums) < 3:
        return None
    try:
        max_temp = int(nums[-1].replace("\u2212", "-"))
        yield_ = int(nums[-2].replace("\u2212", "-"))
        tensile = int(nums[-3].replace("\u2212", "-"))
    except ValueError:
        return None
    if not (100 <= max_temp <= 900 and 100 <= tensile <= 1500 and 50 <= yield_ <= 1300):
        return None
    ti = meta.index(nums[-3])
    min_temp = meta[ti - 1] if ti > 0 else None

    order = [t for t, _ in labels if t in s_by_temp]
    return {
        "line": int(line_no),
        "spec": spec,
        "type_grade": grade,
        "psl": psl,
        "descriptor": " ".join(meta[:spec_i]),
        "uns": next((t for t in meta if UNS_RE.match(t)), None),
        "min_temp_c": min_temp,
        "tensile_mpa": tensile,
        "yield_mpa": yield_,
        "max_temp_c": max_temp,
        "temps": order,
        "s_mpa": [None if s_by_temp[t] == "…" else float(s_by_temp[t].replace("\u2212", "-"))
                  for t in order],
    }


def parse_page(pg):
    lines = page_lines(pg)
    grid = merged_ladder(find_ladders(lines))
    for y in sorted(lines):
        ws = lines[y]
        if not ws or ws[0]["x0"] > 70 or not ws[0]["text"].isdigit():
            continue
        rec = parse_row(ws, ws[0]["text"], grid)
        if rec:
            yield rec


def keep(rec):
    spec = (rec["spec"] or "").replace(" ", "")
    if spec not in TARGETS:
        return None
    grades, form_kw = TARGETS[spec]
    tg = (rec["type_grade"] or "").split("/")
    grade = next((g for g in tg if g in grades), None)
    if grade is None:
        return None
    form = (rec.get("descriptor") or "").lower()
    if form_kw == "plate" and "plate" not in form:
        return None
    if form_kw == "pipe" and not ("pipe" in form or "tube" in form):
        return None
    return spec, grade


def main():
    rows, drops = [], 0
    with pdfplumber.open(PDF) as pdf:
        for p in A1_PAGES:
            for rec in parse_page(pdf.pages[p]):
                hit = keep(rec)
                if hit is None:
                    continue
                if len(rec["temps"]) < 3:
                    drops += 1
                    continue
                rec["spec"], rec["grade"] = hit
                rows.append(rec)

    rows.sort(key=lambda r: (r["spec"], r["grade"], r["line"]))
    print(f"kept {len(rows)} rows, dropped {drops} thin rows")
    seen = {}
    for r in rows:
        key = f"{r['spec']} {r['grade']} {r.get('psl') or '-'} {r['type_grade']}"
        seen.setdefault(key, 0)
        seen[key] += 1
    for k in sorted(seen):
        print(f"  {k}: {seen[k]}")

    pack = {
        "code": "ASME B31.3-2024",
        "provenance": (
            "Owner-uploaded licensed copy of ASME B31.3-2024; numeric "
            "values only, extracted under review 2026-10-12. Table A-1 (SI) "
            "pp.206-315 position-parsed; Table A-3 pp.398-401; Tables "
            "304.1.1-1 and 302.3.5-1 transcribed visually. Anchors: "
            "Appendix H examples H301/H303/H311."
        ),
        "mill_tolerance_default": 0.875,
        "mill_tolerance_note": (
            "Owner decision 2026-10-12: effective wall = 0.875 x nominal "
            "(12.5% mill tolerance), the convention of Appendix H."
        ),
        "s_column_semantics": (
            "Basic allowable stress S in MPa at metal temperature up to and "
            "including the column value; interpolate linearly between "
            "columns; the last listed column applies to its own temperature "
            "only (higher temperatures need the time-dependent rules)."
        ),
        "api5l_iso_grade_aliases": {
            "L245": "B", "L290": "X42", "L320": "X46", "L360": "X52",
            "L390": "X56", "L415": "X60", "L450": "X65", "L485": "X70"
        },
        "table_304_1_1_y": TABLE_304_1_1_Y,
        "table_302_3_5_w": TABLE_302_3_5_W,
        "table_a3_ej": TABLE_A3_EJ,
        "table_a1_si": rows,
    }
    with open(OUT, "w", encoding="utf-8") as f:
        json.dump(pack, f, indent=1, ensure_ascii=False)
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
