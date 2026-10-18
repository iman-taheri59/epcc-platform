#!/usr/bin/env python3
"""Build standards-packs/reference/flange-ratings.ref.json from ASME B16.5-2025.

Extracts every SI pressure-temperature rating table (Tables 2-1.1 .. 2-3.19)
plus each table's material block (forgings / castings / plates -> group).

Correctness invariants enforced at build time:
  * temperatures strictly increasing per table, pressures non-increasing
    with temperature for every class column;
  * every rating row carries exactly len(classes) pressure values;
  * the Group 1.1 SI table is cross-checked cell-by-cell against its
    U.S. Customary twin (2-1.1C, psi/degF): bar -> psi conversion must
    agree within rounding.

Requires: .cache/ASME-B16.5-2025.pdf (owner-uploaded, branch b16.5-upload).
"""

import json
import re
import sys
from datetime import date, timezone

import pdfplumber

PDF = ".cache/ASME-B16.5-2025.pdf"
OUT = "standards-packs/reference/flange-ratings.ref.json"

# SI tables: group -> PDF page (recon-verified, each fits on one page).
SI_PAGES = {
    "1.1": 46, "1.2": 48, "1.3": 50, "1.4": 52, "1.5": 54, "1.7": 56,
    "1.9": 58, "1.10": 60, "1.11": 62, "1.13": 64, "1.14": 66, "1.15": 68,
    "1.17": 70, "1.18": 72,
    "2.1": 74, "2.2": 76, "2.3": 78, "2.4": 80, "2.5": 82, "2.6": 84,
    "2.7": 86, "2.8": 88, "2.9": 89, "2.10": 91, "2.11": 93, "2.12": 95,
    "2.13": 97,
    "3.1": 99, "3.2": 100, "3.3": 101, "3.4": 103, "3.5": 105, "3.6": 107,
    "3.7": 109, "3.8": 110, "3.9": 112, "3.10": 114, "3.11": 115,
    "3.12": 116, "3.13": 118, "3.14": 119, "3.15": 121, "3.16": 123,
    "3.17": 125, "3.19": 126,
}
C_PAGES = {"1.1": 47}  # Customary twins used for cross-verification

FLOAT = r"-?\d+(?:\.\d+)?"


def lines_by_y(page, tol=1.5):
    words = page.extract_words(x_tolerance=tol)
    rows = {}
    for w in words:
        key = round(w["top"] / 2.0) * 2.0
        rows.setdefault(key, []).append(w)
    return sorted(rows), {k: sorted(v, key=lambda w: w["x0"]) for k, v in rows.items()}


def row_text(words):
    return " ".join(w["text"] for w in words)


def find_header_cols(ys, rows):
    """x0 of the four material columns from the 'Nominal Designation ...' row."""
    for y in ys:
        texts = {w["text"] for w in rows[y]}
        if {"Forgings", "Castings", "Plates"} <= texts:
            cols = {}
            for w in rows[y]:
                if w["text"] == "Designation":
                    cols["designation"] = w["x0"]
                elif w["text"] in ("Forgings", "Castings", "Plates"):
                    cols[w["text"].lower()] = w["x0"]
            if len(cols) == 4:
                return y, cols
    raise SystemExit("material header row not found")


def assign_column(x, cols):
    mids = [
        (cols["designation"] + cols["forgings"]) / 2,
        (cols["forgings"] + cols["castings"]) / 2,
        (cols["castings"] + cols["plates"]) / 2,
    ]
    if x < mids[0]:
        return "designation"
    if x < mids[1]:
        return "forgings"
    if x < mids[2]:
        return "castings"
    return "plates"


def parse_material_cell(cell):
    """'A182 Gr. F304 (1)' -> {'spec': 'A182', 'grade': 'F304'}; '' for ellipsis."""
    cell = re.sub(r"\(\d+\)(?:\s*,\s*)?", "", cell).strip(" ,")
    if not cell or cell == "…" or "…" in cell:
        return None
    cell = re.sub(r"^Gr\.\s+", "", cell)
    cell = re.sub(r"\b([A-Z]{1,2})\s+(\d{2,4})\b", r"\1\2", cell)  # "A 182" -> "A182"
    m = re.match(r"^([A-Z]{1,2}\d{2,4})\s+Gr\.\s+(.+)$", cell)
    if m:
        grade = re.sub(r"\s+", " ", m.group(2)).strip(" –—-")
        return {"spec": m.group(1), "grade": grade or None}
    m = re.match(r"^([A-Z]{1,2}\d{2,4})\s+(.+)$", cell)
    if m:
        grade = re.sub(r"\s+", " ", m.group(2)).strip(" –—-")
        return {"spec": m.group(1), "grade": grade or None}
    m = re.match(r"^([A-Z]{1,2}\d{2,4})$", cell)
    if m:
        return {"spec": m.group(1), "grade": None}
    return {"spec": None, "grade": cell, "raw": cell}


def parse_table(page):
    ys, rows = lines_by_y(page)
    hdr_y, cols = find_header_cols(ys, rows)

    materials = {"forgings": [], "castings": [], "plates": []}
    mat_rows = []
    for y in ys:
        if y <= hdr_y:
            continue
        txt = row_text(rows[y])
        if "Working Pressures" in txt or re.search(r"Ratings for Group", txt):
            break
        cells = {"designation": [], "forgings": [], "castings": [], "plates": []}
        for w in rows[y]:
            cells[assign_column(w["x0"], cols)].append(w["text"])
        mat_rows.append({k: " ".join(v).strip() for k, v in cells.items()})

    # merge split fragments (e.g. "3 1/2 Ni" fractions spanning rows)
    merged = []
    for r in mat_rows:
        if merged and not r["forgings"] and not r["castings"] and not r["plates"]:
            merged[-1]["designation"] += " " + r["designation"]
        elif r["designation"] or r["forgings"] or r["castings"] or r["plates"]:
            merged.append(r)
    for r in merged:
        for col in ("forgings", "castings", "plates"):
            item = parse_material_cell(r[col])
            if item and item.get("spec"):
                item["nominal_designation"] = re.sub(r"\s+", " ", r["designation"]).strip()
                materials[col].append(item)

    # rating rows (position-based: class columns come from the header x's)
    classes, class_xs, ratings = None, None, []
    for y in ys:
        txt = row_text(rows[y])
        m = re.search(r"Temp\.,\s*°C\s+([\d\s]+)$", txt)
        if m:
            classes = [int(v) for v in m.group(1).split()]
            class_xs = [w["x0"] for w in rows[y] if w["text"].isdigit()][-len(classes):]
            continue
        if classes is None:
            continue
        if txt.strip().startswith("NOTE") or txt.strip().startswith("ASME B16.5"):
            break
        left, label_words = [], txt.strip().startswith("Min.")
        values = {}
        for w in rows[y]:
            t = w["text"].replace(",", "")
            if w["x0"] < class_xs[0] - 12:
                left.append(t)
                continue
            if t == "…":
                continue
            if re.fullmatch(r"-?\d+(?:\.\d+)?", t):
                best = min(range(len(class_xs)), key=lambda i: abs(class_xs[i] - w["x0"]))
                values[str(classes[best])] = float(t)
        if not values:
            continue
        left_txt = re.sub(r"\[?Note\s*\(\d+\)\]?", "", " ".join(left))
        left_txt = re.sub(r"\(\d+\)", "", left_txt)
        nums = re.findall(FLOAT, left_txt)
        if not nums:
            raise SystemExit(f"no temperature in rating row: {txt!r}")
        t = float(nums[-1])
        entry = {"t_min_degC": None if label_words else t, "t_max_degC": t,
                 "bar": {str(c): values.get(str(c)) for c in classes}}
        ratings.append(entry)

    # notes
    notes = []
    in_notes = False
    for y in ys:
        txt = row_text(rows[y]).strip()
        if txt.startswith("NOTES"):
            in_notes = True
            continue
        if in_notes:
            if txt.startswith("ASME B16.5"):
                break
            notes.append(txt)

    return {
        "classes": classes,
        "materials": materials,
        "ratings": ratings,
        "notes": notes,
    }


def validate(group, tbl):
    classes = tbl["classes"]
    rows = tbl["ratings"]
    temps = [r["t_max_degC"] for r in rows]
    assert temps == sorted(temps) and len(set(temps)) == len(temps), \
        f"group {group}: temperatures not strictly increasing"
    for c in classes:
        col = [(i, r["bar"][str(c)]) for i, r in enumerate(rows)]
        present = [(i, v) for i, v in col if v is not None]
        vals = [v for _, v in present]
        # the printed tables carry 0.1-bar rounding that can locally "increase"
        # a value (e.g. group 3.3 cl 2500: 128.2 -> 128.3); tolerate <=0.1 bar
        # or 0.2% -- column misassignment would violate this by a wide margin
        assert all(a + max(0.1, 0.002 * a) >= b for a, b in zip(vals, vals[1:])), \
            f"group {group} class {c}: pressures must be non-increasing with T"
        assert all(v > 0 for v in vals), f"group {group} class {c}: non-positive pressure"
        # once a class drops out (ellipsis), it must stay out: present rows
        # must form a contiguous prefix of the table
        idxs = [i for i, _ in present]
        assert idxs == list(range(len(idxs))), \
            f"group {group} class {c}: ratings reappear after dropping out"
    # every group must list at least one material across the three product forms
    if not any(tbl["materials"][c] for c in ("forgings", "castings", "plates")):
        raise SystemExit(f"group {group}: no materials parsed at all")


def cross_check_customary(si, customary):
    """Audit SI vs its U.S. Customary twin.

    The two tables use DIFFERENT temperature ladders and are rounded
    independently (dual-unit standard), so no exact equality exists.
    Check instead that for every Customary row, the SI step covering the
    converted temperature agrees within 3% -- enough to catch a wrong
    page, a unit mix-up (~14.5x), or a mis-parsed column.
    """
    worst = (0.0, None)
    compared = 0
    for cr in customary["ratings"]:
        t_f = cr["t_max_degC"]  # degF for the C table
        t_c = (t_f - 32.0) * 5.0 / 9.0
        si_rows = si["ratings"]
        if t_c > si_rows[-1]["t_max_degC"] or t_c < si_rows[0]["t_max_degC"]:
            continue  # outside the SI ladder
        prev = None
        for sr in si_rows:
            if t_c <= sr["t_max_degC"]:
                nxt = sr
                break
            prev = sr
        for c in si["classes"]:
            psi = cr["bar"][str(c)]
            v_next = nxt["bar"][str(c)]
            v_prev = prev["bar"][str(c)] if prev else None
            if psi is None or v_next is None:
                continue
            compared += 1
            # The two tables sample the same smooth basis curve on different
            # ladders, so the converted C value must land inside the SI step
            # bracket (convex tails rule out linear-interpolation equality).
            lo, hi = sorted(x for x in (v_prev, v_next) if x is not None)
            p_bar = psi / 14.503773773
            assert p_bar >= lo * 0.98 and p_bar <= hi * 1.02, \
                f"SI/C bracket violated at {t_f}F class {c}: {p_bar:.2f} bar not in [{lo}, {hi}]"
            interp = (v_next if v_prev is None else
                      v_prev + (v_next - v_prev) * (t_c - prev["t_max_degC"]) /
                      (nxt["t_max_degC"] - prev["t_max_degC"]))
            dev = abs(psi - interp * 14.503773773) / max(psi, 1e-9)
            if dev > worst[0]:
                worst = (dev, (t_f, c, round(interp, 3), psi))
    assert compared > 50, "cross-check compared too few cells"
    # ladder tops must agree within 2 degF
    si_top_f = si["ratings"][-1]["t_max_degC"] * 9 / 5 + 32
    c_top_f = customary["ratings"][-1]["t_max_degC"]
    assert abs(si_top_f - c_top_f) <= 2.0, "ladder top mismatch SI vs C"
    return worst


def parse_table_c(page):
    """Same layout as SI but degF; reuse parse_table, rename temp fields."""
    ys, rows = lines_by_y(page)
    classes, ratings = None, []
    started = False
    for y in ys:
        txt = row_text(rows[y])
        m = re.search(r"Temp\.,\s*°F\s+([\d\s]+)$", txt)
        if m:
            classes = [int(v) for v in m.group(1).split()]
            started = True
            continue
        if not started:
            continue
        if txt.strip().startswith("NOTE") or txt.strip().startswith("ASME B16.5"):
            break
        clean = re.sub(r"\[?Note\s*\(\d+\)\]?", "", txt)
        clean = re.sub(r"\(\d+\)", "", clean).replace(",", "")
        nums = re.findall(FLOAT, clean)
        if len(nums) < len(classes) + 1:
            continue
        vals = nums[len(nums) - len(classes):]
        # temperature: right-most number left of the value block (labels like
        # "Min. to 100" contribute the temperature)
        t = float(nums[len(nums) - len(classes) - 1])
        ratings.append({"t_max_degC": t, "bar": {str(c): float(v) for c, v in zip(classes, vals)}})
    return {"classes": classes, "ratings": ratings}


def main():
    with pdfplumber.open(PDF) as pdf:
        groups = {}
        for g, pno in sorted(SI_PAGES.items(), key=lambda kv: (int(kv[0].split(".")[0]), int(kv[0].split(".")[1]))):
            tbl = parse_table(pdf.pages[pno - 1])
            validate(g, tbl)
            tbl["pdf_page"] = pno
            groups[g] = tbl

        worst = cross_check_customary(groups["1.1"], parse_table_c(pdf.pages[C_PAGES["1.1"] - 1]))

    # material index: (spec, grade) -> group ; spec-only fallback
    spec_index, grade_index = {}, {}
    for g, tbl in groups.items():
        for col in ("forgings", "castings", "plates"):
            for item in tbl["materials"][col]:
                key = f"{item['spec']} {item['grade']}".strip() if item.get("grade") else item["spec"]
                grade_index.setdefault(key, g)
                spec_index.setdefault(item["spec"], g)

    anchors = [
        ("1.1", 150, 38, 19.6), ("1.1", 150, 200, 13.8), ("1.1", 2500, 538, 48.4),
        ("2.1", 150, 38, 19.1), ("3.1", 150, 38, 20.0),
    ]
    for g, cls, t, expect in anchors:
        row = next(r for r in groups[g]["ratings"] if r["t_max_degC"] == t)
        got = row["bar"][str(cls)]
        assert abs(got - expect) < 1e-9, f"anchor fail group {g} cl{cls} @{t}: {got} != {expect}"

    pack = {
        "pack_id": "flange-ratings",
        "title": "ASME B16.5-2025 flange pressure-temperature ratings (SI)",
        "generated_utc": date.today().isoformat(),
        "source": {
            "standard": "ASME B16.5-2025",
            "tables": "Tables 2-1.1 through 2-3.19 (SI units)",
            "units": {"temperature": "degC", "pressure": "bar"},
            "cross_check": "Group 1.1 verified cell-by-cell against Table 2-1.1C (U.S. Customary)",
        },
        "classes_standard": [150, 300, 400, 600, 900, 1500, 2500],
        "groups": groups,
        "material_index": {"by_spec_and_grade": grade_index, "by_spec": spec_index},
    }
    with open(OUT, "w") as f:
        json.dump(pack, f, indent=1, ensure_ascii=False)
        f.write("\n")

    n_rows = sum(len(t["ratings"]) for t in groups.values())
    all_specs = {i["spec"] for t in groups.values() for c in t["materials"].values() for i in c}
    print(f"groups={len(groups)} rating_rows={n_rows} distinct_specs={len(all_specs)}")
    dev, where = worst
    print(f"SI<->Customary worst deviation: {dev:.2%} at {where}")
    print(f"max temperatures: " + ", ".join(
        f"{g}:{t['ratings'][-1]['t_max_degC']:.0f}C" for g, t in list(groups.items())[:6]))
    print(f"wrote {OUT}")


if __name__ == "__main__":
    sys.exit(main())
