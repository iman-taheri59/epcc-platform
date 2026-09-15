#!/usr/bin/env python3
"""
Standards pack CI gate.

Three jobs:
  1. Validate every pack against the schema.
  2. Guard the AWP seams (ADR-002). The AWP pack is deferred, NOT deleted.
     It stays in CI as a regression test proving the standards engine has not
     silently grown Iran-specific assumptions. If this file starts failing,
     someone has broken the pack abstraction and reviving AWP for the
     international release just got expensive.
  3. Validate the REFERENCE packs (ADR-007): pipe sizes, schedules, end types
     and component templates. These are universal engineering constants, not
     per-project configuration, and the checks below are the only thing
     standing between a transcription typo and a wrong wall thickness on a
     large-bore line.

Usage:  python3 validate_packs.py
Exit:   0 = clean, 1 = problems found
"""

import json
import sys
from pathlib import Path

HERE = Path(__file__).parent
SCHEMA = HERE / "schema" / "standards-pack.schema.json"
PACKS = {
    "iso": HERE / "iso.pack.json",
    "awp": HERE / "awp.pack.json",
    "ips-iran": HERE / "ips-iran.pack.json",
}

REF_DIR = HERE / "reference"
REF_PACKS = {
    "pipe-sizes": REF_DIR / "pipe-sizes.ref.json",
    "pipe-schedules": REF_DIR / "pipe-schedules.ref.json",
    "end-types": REF_DIR / "end-types.ref.json",
    "component-templates": REF_DIR / "component-templates.ref.json",
}

# Packs that must remain loadable even though the module is deferred.
DEFERRED_BUT_GUARDED = ["awp"]

problems: list[str] = []
notes: list[str] = []


def fail(msg: str) -> None:
    problems.append(msg)


def load(path: Path):
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


# ---------------------------------------------------------------- schema ----
def check_schema() -> dict:
    try:
        import jsonschema
    except ImportError:
        notes.append("jsonschema not installed - schema validation SKIPPED")
        return {name: load(p) for name, p in PACKS.items()}

    schema = load(SCHEMA)
    validator = jsonschema.Draft202012Validator(schema)
    loaded = {}
    for name, path in PACKS.items():
        pack = load(path)
        errors = sorted(validator.iter_errors(pack), key=lambda e: list(e.path))
        if errors:
            for err in errors[:5]:
                fail(f"[{name}] schema: {list(err.path)} -> {err.message[:160]}")
        else:
            notes.append(f"[{name}] schema valid (v{pack['version']})")
        loaded[name] = pack
    return loaded


# ------------------------------------------------------- seam guards -------
def check_awp_seams(packs: dict) -> None:
    """ADR-002 seams. These assertions are the whole point of keeping awp.pack.json."""
    awp = packs.get("awp")
    if awp is None:
        fail("SEAM: awp.pack.json is missing. Per ADR-002 it must stay in the repo "
             "as a genericity regression test, even though the module is deferred.")
        return

    # Seam 1 - generic work package hierarchy expressed as pack DATA, not code.
    structures = {s["code"]: s for s in awp.get("breakdownStructures", [])}
    for code in ("CWA", "CWP", "EWP", "PWP", "IWP"):
        s = structures.get(code)
        if not s:
            fail(f"SEAM 1: AWP pack no longer declares '{code}'. The work-package "
                 f"hierarchy must stay expressible as pack data.")
        elif not s.get("enabled"):
            fail(f"SEAM 1: '{code}' is disabled in the AWP pack - the pack must still "
                 f"describe a working AWP configuration.")

    iwp = structures.get("IWP", {})
    if iwp.get("mustBelongTo") != "CWP":
        fail("SEAM 1: 'IWP mustBelongTo CWP' is gone. Hierarchy rules must live in "
             "pack data so enabling AWP later needs no schema migration.")

    # Seam 1b - the ISO/IPS packs must be able to DISABLE these structures.
    for name in ("iso", "ips-iran"):
        pack = packs.get(name)
        if not pack:
            continue
        st = {s["code"]: s for s in pack.get("breakdownStructures", [])}
        if "IWP" in st and st["IWP"].get("enabled"):
            fail(f"SEAM 1: '{name}' pack enables IWP. Deferred AWP structures must be "
                 f"off in the launch packs.")

    # Seam 3 - constraint engine stays pack-driven and is NOT AWP-only.
    ips = packs.get("ips-iran", {})
    ips_constraints = ips.get("constraintTypes", [])
    if len(ips_constraints) < 3:
        fail("SEAM 3: the IPS pack defines fewer than 3 constraint types. The "
             "constraint engine is core product (ADR-002 item 1) and the Iranian "
             "configuration depends on it - it must not drift into AWP-only territory.")
    else:
        notes.append(f"[seam] constraint engine used by IPS pack "
                     f"({len(ips_constraints)} types) - stays core")

    if not any(c.get("autoCheck") for c in ips_constraints):
        fail("SEAM 3: no IPS constraint declares 'autoCheck'. Event-driven clearance "
             "must remain a pack-level capability.")

    # Seam 4 - quantity-based progress must remain available to all packs.
    for name, pack in packs.items():
        methods = {r.get("method") for r in pack.get("progressRules", [])}
        if "quantityBased" not in methods and name != "iso":
            notes.append(f"[seam] note: '{name}' declares no quantityBased progress rule")

    # Revival smoke test - can a resolver still build an AWP project from this pack?
    required_for_revival = ["lifecycles", "constraintTypes", "releaseGates",
                            "progressRules", "kpis", "terminology"]
    missing = [k for k in required_for_revival if not awp.get(k)]
    if missing:
        fail(f"SEAM: AWP pack lost sections needed to revive the module: {missing}")
    else:
        notes.append(f"[seam] AWP pack still complete for revival "
                     f"({len(awp['lifecycles'])} lifecycles, "
                     f"{len(awp['constraintTypes'])} constraint types, "
                     f"{len(awp['kpis'])} KPIs)")


# ------------------------------------------------------ general hygiene ----
def check_bilingual(packs: dict) -> None:
    """Every user-facing string must carry both en and fa. Enforced, not hoped for."""
    def walk(node, path, pack_name):
        if isinstance(node, dict):
            # An i18n object is one with 'en' - it must also have 'fa'.
            if "en" in node and isinstance(node.get("en"), str):
                if "fa" not in node or not str(node.get("fa", "")).strip():
                    fail(f"[{pack_name}] i18n: missing Persian at {path}")
            for k, v in node.items():
                walk(v, f"{path}.{k}", pack_name)
        elif isinstance(node, list):
            for i, v in enumerate(node):
                walk(v, f"{path}[{i}]", pack_name)

    for name, pack in packs.items():
        walk(pack, name, name)


def check_progress_weights(packs: dict) -> None:
    """Rule-of-credit weights must sum to 100 or progress reporting lies."""
    for name, pack in packs.items():
        for rule in pack.get("progressRules", []):
            total = sum(s.get("weight", 0) for s in rule.get("steps", []))
            if abs(total - 100) > 0.01:
                fail(f"[{name}] progress rule '{rule['id']}' weights sum to {total}, not 100")


def check_extends(packs: dict) -> None:
    for name, pack in packs.items():
        ext = pack.get("extends")
        if not ext:
            continue
        parent = ext["packId"]
        if not any(p.get("packId") == parent for p in packs.values()):
            fail(f"[{name}] extends unknown pack '{parent}'")
        else:
            notes.append(f"[{name}] extends '{parent}' v{ext['version']}")


# ------------------------------------------------- reference packs ---------
def check_reference_schema() -> dict:
    """Each reference pack has its own schema in reference/schema/."""
    loaded = {}
    for name, path in REF_PACKS.items():
        if not path.exists():
            fail(f"[ref:{name}] pack missing at {path}")
            continue
        loaded[name] = load(path)

    try:
        import jsonschema
    except ImportError:
        notes.append("jsonschema not installed - reference schema validation SKIPPED")
        return loaded

    for name, pack in loaded.items():
        spath = REF_DIR / "schema" / f"{name}.schema.json"
        if not spath.exists():
            fail(f"[ref:{name}] no schema at {spath}")
            continue
        validator = jsonschema.Draft202012Validator(load(spath))
        errors = sorted(validator.iter_errors(pack), key=lambda e: list(e.path))
        if errors:
            for err in errors[:5]:
                fail(f"[ref:{name}] schema: {list(err.path)} -> {err.message[:160]}")
        else:
            notes.append(f"[ref:{name}] schema valid (v{pack['version']}, {pack['source'][:44]}...)")
    return loaded


def check_reference_integrity(refs: dict) -> None:
    """
    Cross-pack and physical checks a JSON-schema cannot express.

    Every assertion here corresponds to a way the data can be wrong while still
    looking perfectly well-formed.
    """
    sizes = refs.get("pipe-sizes")
    scheds = refs.get("pipe-schedules")
    ends = refs.get("end-types")
    templates = refs.get("component-templates")

    if not sizes or not scheds:
        return

    rows = sizes["sizes"]
    by_nps = {r["nps"]: r for r in rows}

    # --- 1. NPS labels unique, DN unique, and both strictly ascending --------
    if len(by_nps) != len(rows):
        fail("[ref] duplicate NPS label in pipe-sizes")
    dns = [r["dn"] for r in rows]
    if len(set(dns)) != len(dns):
        fail("[ref] duplicate DN in pipe-sizes")
    for a, b in zip(rows, rows[1:]):
        if b["dn"] <= a["dn"]:
            fail(f"[ref] pipe-sizes not ascending by DN: {a['nps']} then {b['nps']}")
        if b["npsDecimal"] <= a["npsDecimal"]:
            fail(f"[ref] pipe-sizes not ascending by npsDecimal: {a['nps']} then {b['nps']}")
        if b["odMm"] <= a["odMm"]:
            fail(f"[ref] pipe-sizes not ascending by OD: {a['nps']} then {b['nps']}")

    # --- 2. npsDecimal must actually equal the label it claims to encode -----
    # This is the column dia-inch is summed from (ADR-007 9a, ADR-010 1).
    # A mismatch here silently corrupts every progress figure on the project.
    from fractions import Fraction
    for r in rows:
        label = r["nps"]
        try:
            parts = label.split()
            value = sum(Fraction(p) for p in parts)
        except (ValueError, ZeroDivisionError):
            fail(f"[ref] NPS label '{label}' is not parseable as a number")
            continue
        if float(value) != r["npsDecimal"]:
            fail(f"[ref] npsDecimal mismatch for '{label}': "
                 f"label parses to {float(value)}, column says {r['npsDecimal']}")

    # --- 3. odIn and odMm must agree (25.4 mm/in, 0.6 mm tolerance) ---------
    for r in rows:
        derived = r["odIn"] * 25.4
        if abs(derived - r["odMm"]) > 0.6:
            fail(f"[ref] OD inconsistent for {r['nps']}: "
                 f"{r['odIn']} in = {derived:.1f} mm but odMm is {r['odMm']}")

    # --- 4. every size in the schedule table exists in the size table -------
    ladder = set(scheds["schedules"])
    orphan = set(scheds["walls"]) - set(by_nps)
    if orphan:
        fail(f"[ref] pipe-schedules references unknown sizes: {sorted(orphan)}")
    uncovered = set(by_nps) - set(scheds["walls"])
    if uncovered:
        fail(f"[ref] sizes with no schedule row at all: {sorted(uncovered)}")

    pair_count = 0
    for nps, row in scheds["walls"].items():
        if nps not in by_nps:
            continue
        od = by_nps[nps]["odMm"]
        for sch, wall in row.items():
            pair_count += 1
            if sch not in ladder:
                fail(f"[ref] {nps}: schedule '{sch}' is not in the declared ladder")
            # --- 5. physically possible: two walls must leave a bore --------
            if 2 * wall >= od:
                fail(f"[ref] {nps} SCH {sch}: wall {wall} leaves no bore (OD {od})")

    # --- 6. the STD/SCH40 and XS/SCH80 divergence (ADR-007 section 2) -------
    # Published tables disagree here and several print STD under a "SCH 40"
    # heading. If someone "tidies" the data from one of those sources, these
    # assertions are what catches it.
    def wall(nps, sch):
        return scheds["walls"].get(nps, {}).get(sch)

    for nps in ("1/2", "2", "6", "8"):
        if wall(nps, "40") != wall(nps, "STD"):
            fail(f"[ref] {nps}: STD should still equal SCH 40 at this size")
        if wall(nps, "80") != wall(nps, "XS"):
            fail(f"[ref] {nps}: XS should still equal SCH 80 at this size")

    if wall("10", "80") == wall("10", "XS"):
        fail("[ref] 10 inch: XS must NOT equal SCH 80 (15.09 vs 12.70)")
    for nps in ("12", "14", "16", "18", "20", "24"):
        if wall(nps, "STD") == wall(nps, "40"):
            fail(f"[ref] {nps}: STD must NOT equal SCH 40")

    for nps, row in scheds["walls"].items():
        if by_nps.get(nps, {}).get("npsDecimal", 0) >= 14:
            if row.get("STD") != 9.53:
                fail(f"[ref] {nps}: STD must be pinned at 9.53 above NPS 12, got {row.get('STD')}")
            if row.get("XS") != 12.70:
                fail(f"[ref] {nps}: XS must be pinned at 12.70 above NPS 8, got {row.get('XS')}")

    # --- 7. monotonic numbered ladder within a size -------------------------
    numeric = ["5", "10", "20", "30", "40", "60", "80", "100", "120", "140", "160"]
    for nps, row in scheds["walls"].items():
        present = [(s, row[s]) for s in numeric if s in row]
        for (s1, t1), (s2, t2) in zip(present, present[1:]):
            if t2 <= t1:
                fail(f"[ref] {nps}: SCH {s2} ({t2}) is not heavier than SCH {s1} ({t1})")

    notes.append(f"[ref] {len(rows)} sizes, {pair_count} (size,schedule) pairs, "
                 f"divergence assertions hold")

    # --- 8. end types ------------------------------------------------------
    if ends:
        codes = [e["code"] for e in ends["endTypes"]]
        if len(set(codes)) != len(codes):
            fail("[ref] duplicate end-type code")
        for e in ends["endTypes"]:
            # A weld that cannot be numbered cannot be reported as dia-inch,
            # and a numbered joint that is not a weld is a contradiction.
            if e["numbered"] and not e["weldable"]:
                fail(f"[ref] end type {e['code']}: numbered but not weldable - "
                     f"a joint number implies a weld (ADR-006 section 4)")
            if e.get("wildcard") and e["weldable"]:
                fail(f"[ref] end type {e['code']}: a wildcard must not be weldable")
        if "**" not in codes:
            fail("[ref] end-types must declare the PCF wildcard '**' so import can reject it")
        notes.append(f"[ref] {len(codes)} end types, "
                     f"{sum(1 for e in ends['endTypes'] if e['numbered'])} numbered")

    # --- 9. component templates -------------------------------------------
    if templates:
        tcodes = [t["code"] for t in templates["templates"]]
        if len(set(tcodes)) != len(tcodes):
            fail("[ref] duplicate component template code")
        roles = set(templates["portRoles"])
        rules = set(templates["sizeRules"])
        end_codes = {e["code"] for e in ends["endTypes"]} if ends else set()

        for t in templates["templates"]:
            seqs = [p["seq"] for p in t["ports"]]
            if seqs != list(range(1, len(seqs) + 1)):
                fail(f"[ref] template {t['code']}: port seq must be 1..n, got {seqs}")
            if t["sizeRule"] not in rules:
                fail(f"[ref] template {t['code']}: unknown sizeRule {t['sizeRule']}")
            for p in t["ports"]:
                if p["role"] not in roles:
                    fail(f"[ref] template {t['code']}: unknown port role {p['role']}")
                if end_codes and p["defaultEnd"] not in end_codes:
                    fail(f"[ref] template {t['code']} port {p['seq']}: "
                         f"defaultEnd '{p['defaultEnd']}' is not a known end type")
                if p["defaultEnd"] == "**":
                    fail(f"[ref] template {t['code']}: defaultEnd must not be the wildcard")

            # Size rules that need more than one port, or a specific role.
            if t["sizeRule"] == "BRANCH_LE_RUN":
                if not any(p["role"] == "BRANCH" for p in t["ports"]):
                    fail(f"[ref] template {t['code']}: BRANCH_LE_RUN but no BRANCH port")
            if t["sizeRule"] == "LARGE_GT_SMALL":
                got = {p["role"] for p in t["ports"]}
                if not {"LARGE", "SMALL"} <= got:
                    fail(f"[ref] template {t['code']}: LARGE_GT_SMALL needs LARGE and SMALL ports")
            if t.get("terminates") and len(t["ports"]) != 1:
                fail(f"[ref] template {t['code']}: terminating component must have exactly 1 port")
            if t.get("directional"):
                got = {p["role"] for p in t["ports"]}
                if not {"INLET", "OUTLET"} <= got:
                    fail(f"[ref] template {t['code']}: directional needs INLET and OUTLET roles")

        # The decisions the ADRs turn on, asserted as data.
        tee = next((t for t in templates["templates"] if t["code"] == "TEE"), None)
        if not tee:
            fail("[ref] no TEE template")
        else:
            if len(tee["ports"]) != 3:
                fail(f"[ref] TEE must have 3 ports, has {len(tee['ports'])} - "
                     f"this is the universal fact ADR-010 section 2 refuses to let a project override")
            if not tee.get("qualifierDerived"):
                fail("[ref] TEE: equal vs reducing must be DERIVED, not chosen "
                     "(user decision, ADR-009 section 6)")
        if any(t["code"] in ("REDUCING_TEE", "EQUAL_TEE") for t in templates["templates"]):
            fail("[ref] equal and reducing tees must NOT be separate templates "
                 "(user decision, ADR-009 section 6)")

        nozzle = next((t for t in templates["templates"] if t["code"] == "NOZZLE"), None)
        if not nozzle:
            fail("[ref] no NOZZLE template - ADR-010 section 3 needs it as a port owner")
        elif nozzle.get("ownerKind") != "NOZZLE":
            fail("[ref] NOZZLE template must declare ownerKind NOZZLE")

        notes.append(f"[ref] {len(tcodes)} component templates, "
                     f"{sum(len(t['ports']) for t in templates['templates'])} port definitions")


# --------------------------------------------------------------- main ------
def main() -> int:
    packs = check_schema()
    check_extends(packs)
    check_bilingual(packs)
    check_progress_weights(packs)
    check_awp_seams(packs)

    refs = check_reference_schema()
    check_bilingual(refs)
    check_reference_integrity(refs)

    for n in notes:
        print(f"  ok  {n}")

    if problems:
        print(f"\n{len(problems)} PROBLEM(S):\n")
        for p in problems:
            print(f"  FAIL  {p}")
        print("\nSee docs/ADR-002-Defer-AWP.md - the AWP pack is deliberately retained")
        print("as a genericity guard. A failure here means the pack abstraction is eroding.")
        return 1

    print(f"\nAll {len(packs)} standards packs + {len(REF_PACKS)} reference packs valid. AWP seams intact (ADR-002).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
