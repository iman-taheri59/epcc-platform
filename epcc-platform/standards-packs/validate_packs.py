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

import re
import math
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
    "flange-bolting": REF_DIR / "flange-bolting.ref.json",
    "component-templates": REF_DIR / "component-templates.ref.json",
    "units": REF_DIR / "units.ref.json",
    "materials": REF_DIR / "materials.ref.json",
    "actuators": REF_DIR / "actuators.ref.json",
    "flange-pt-ratings": REF_DIR / "flange-pt-ratings.ref.json",
    "valve-construction": REF_DIR / "valve-construction.ref.json",
    "valve-pt-ratings": REF_DIR / "valve-pt-ratings.ref.json",
    "valve-face-to-face": REF_DIR / "valve-face-to-face.ref.json",
    "valve-testing": REF_DIR / "valve-testing.ref.json",
    "fluid-service": REF_DIR / "fluid-service.ref.json",
    "allowable-stress": REF_DIR / "allowable-stress.ref.json",
    "fitting-dimensions": REF_DIR / "fitting-dimensions.ref.json",
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
        fail("jsonschema not installed - schema validation is REQUIRED")
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
        fail("jsonschema not installed - reference schema validation is REQUIRED")
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
            # A portless template is legitimate but must say why. Only
            # fasteners qualify: a bolt closes a flanged joint, it does not
            # connect two runs, which is why PCF gives BOLT co-ordinates but
            # no END-POINT. Anything else with no ports is an authoring slip.
            if not t["ports"]:
                if t["code"] not in ("BOLT", "NUT"):
                    fail(f"[ref] template {t['code']} has no ports; only "
                         f"fasteners may be portless")
                elif "note" not in t:
                    fail(f"[ref] portless template {t['code']} must carry a "
                         f"note explaining why")
                elif t.get("sizeRule") != "SAME_AS_FIRST":
                    fail(f"[ref] portless template {t['code']} must be "
                         f"SAME_AS_FIRST; it has no second port to differ from")

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



def check_flange_bolting(refs: dict) -> None:
    """What one flanged joint needs. Exists to floor an MTO, not to write one."""
    pack = refs.get("flange-bolting")
    if pack is None:
        fail("[ref] flange-bolting pack missing")
        return

    sizes = refs.get("pipe-sizes")
    known_nps = {s["nps"] for s in sizes["sizes"]} if sizes else set()

    def to_in(text: str) -> float:
        """'1 1/8' -> 1.125. Bolt diameters are written the way a fitter says them."""
        total = 0.0
        for part in text.split(" "):
            if "/" in part:
                num, _, den = part.partition("/")
                total += float(num) / float(den)
            else:
                total += float(part)
        return total

    seen_ratings = set()
    seen_lanes = set()
    declared_gap = set(pack.get("sizesBeyondPipeSizesPack", {}).get("nps", []))
    if known_nps:
        stale = sorted(declared_gap & set(known_nps))
        if stale:
            fail(f"[ref:flange-bolting] {stale} are declared as beyond pipe-sizes "
                 f"but pipe-sizes now carries them; the declaration is stale")
    rows = 0
    by_size: dict = {}
    for entry in pack["ratings"]:
        rating = entry["rating"]
        # A rating is unique per standard AND series, not on its own: Class
        # 150 exists in B16.5 and in both B16.47 series, and they are three
        # different sets of flanges that happen to share a pressure class.
        lane_key = (entry.get("standard"), entry.get("series"), rating)
        if lane_key in seen_lanes:
            fail(f"[ref:flange-bolting] {entry.get('standard')} "
                 f"{entry.get('series') or ''} rating {rating} declared twice")
        seen_lanes.add(lane_key)
        seen_ratings.add(rating)

        seen_nps = set()
        prev_nps = None
        prev_count = None
        prev_dia = None
        for row in entry["bolting"]:
            rows += 1
            nps = row["nps"]

            # A DEFERRAL row. Class 400 below NPS 4 and Class 900 below NPS 3
            # have no dimensions of their own; the standard says "use Class 600
            # / Class 1500 dimensions in this size". Such a row carries a
            # pointer and nothing else - if it also carried numbers, two
            # answers would exist for one size.
            # A size the standard does not offer at all. Series B stops at
            # NPS 36 in Classes 400, 600 and 900; Series A Class 900 stops at
            # NPS 48. Recording the absence is not the same as leaving the row
            # out: one says "the standard declines", the other says nothing.
            if row.get("notOffered"):
                numeric = [k for k in row
                           if k not in ("nps", "verified", "source", "readOn",
                                        "notOffered")]
                if numeric:
                    fail(f"[ref:flange-bolting] {rating} {nps} is notOffered but "
                         f"carries {sorted(numeric)}")
                seen_nps.add(nps)
                continue

            if "deferredToClass" in row:
                target = row["deferredToClass"] + "#"
                numeric = [k for k in row
                           if k not in ("nps", "verified", "source", "readOn",
                                        "deferredToClass")]
                if numeric:
                    fail(f"[ref:flange-bolting] {rating} {nps} defers to "
                         f"{target} but also carries {sorted(numeric)}; a "
                         f"deferral is a pointer, not a partial row")
                if not any(e["rating"] == target for e in pack["ratings"]):
                    fail(f"[ref:flange-bolting] {rating} {nps} defers to "
                         f"{target}, which this pack does not carry")
                elif not any(
                    b["nps"] == nps and "deferredToClass" not in b
                    for e in pack["ratings"] if e["rating"] == target
                    for b in e["bolting"]
                ):
                    fail(f"[ref:flange-bolting] {rating} {nps} defers to "
                         f"{target}, which has no dimensioned row at {nps}")
                seen_nps.add(nps)
                continue

            # A bolting row for a size the project cannot draw is unreachable.
            # B16.47 goes to NPS 60 and pipe-sizes stops at NPS 48, so the pack
            # may DECLARE the gap - the same discipline as a cross-standard
            # discrepancy: an exception has to be written down and dated before
            # it stops being an error. An undeclared unreachable row is still a
            # failure, and a declaration for a size that IS reachable is a stale
            # declaration and fails too.
            if known_nps and nps not in known_nps:
                if nps not in declared_gap:
                    fail(f"[ref:flange-bolting] {rating} {nps} is not a size in "
                         f"pipe-sizes and is not a declared gap")
            if nps in seen_nps:
                fail(f"[ref:flange-bolting] {rating} lists {nps} twice")
            seen_nps.add(nps)

            # Ascending order, so a reader can scan it and a bisect can search it.
            dec = next((s["npsDecimal"] for s in sizes["sizes"]
                        if s["nps"] == nps), None) if sizes else None
            if dec is not None and prev_nps is not None and dec <= prev_nps:
                fail(f"[ref:flange-bolting] {rating} is out of size order at {nps}")
            if dec is not None:
                prev_nps = dec

            # Bolt count usually climbs as the flange grows, but not always:
            # B16.47 Class 300 Series A really does drop from 32 bolts at NPS 44
            # to 28 at NPS 46, and B16.5 drops 24 inch 1500# to 16. In every
            # such case the bolts get FATTER - fewer, thicker studs carrying the
            # same load. So the honest rule is not "count never falls", it is
            # "count may fall only if the diameter rises". A drop at an unchanged
            # or smaller diameter is a transcription slip, and a slip here
            # under-orders bolting.
            #
            # CORRECTED 2026-09-24: the old rule said count never falls. That was
            # a rule about B16.5's small sizes, not about flanges.
            this_dia = to_in(row["boltDiameterIn"])
            if (prev_count is not None and row["boltCount"] < prev_count
                    and prev_dia is not None and this_dia <= prev_dia):
                fail(f"[ref:flange-bolting] {rating} bolt count falls from "
                     f"{prev_count} to {row['boltCount']} at {nps} without the "
                     f"bolt getting fatter ({prev_dia} to {this_dia} in)")
            prev_count = row["boltCount"]
            prev_dia = this_dia

            # The length must at least cross two flanges and a gasket. A
            # number below the bolt diameter is not a bolt, it is a typo.
            if row["minLengthMm"] < to_in(row["boltDiameterIn"]) * 25.4 * 2:
                fail(f"[ref:flange-bolting] {rating} {nps} length "
                     f"{row['minLengthMm']} mm is too short for a "
                     f"{row['boltDiameterIn']} in bolt")

            # Keyed by standard and series as well as size: a Series A NPS 30
            # and a Series B NPS 30 are different flanges, and B16.5 never
            # meets either of them (it stops at NPS 24).
            lane = (entry.get("standard"), entry.get("series"))
            by_size.setdefault((lane, nps), []).append(
                (rating, to_in(row["boltDiameterIn"])))

            # A machine bolt is measured from under the head and carries one
            # nut instead of two, so it is always the shorter of the pair. A
            # machine figure at or above the stud figure means the two columns
            # have been transposed.
            machine = row.get("minLengthMachineBoltMm")
            if machine is not None and machine >= row["minLengthMm"]:
                fail(f"[ref:flange-bolting] {rating} {nps} machine bolt "
                     f"{machine} mm is not shorter than the {row['minLengthMm']} "
                     f"mm stud; the columns look transposed")

            # B16.5 permits machine bolts at Class 150 and 300 only. Carrying a
            # length above that would invite a joint the standard forbids.
            # Machine bolts are a B16.5 idea and only at its two lowest
            # classes. B16.47 prints no machine bolt column at any class, so
            # the "must have one" half of this rule must not be asked of it.
            is_b165 = entry.get("standard") == "B16.5"
            if machine is not None and not (is_b165 and rating in ("150#", "300#")):
                fail(f"[ref:flange-bolting] {rating} {nps} carries a machine "
                     f"bolt length; only B16.5 at 150# "
                     f"and 300# has them")
            if machine is None and is_b165 and rating in ("150#", "300#"):
                fail(f"[ref:flange-bolting] {rating} {nps} has no machine bolt "
                     f"length, but the standard permits one at this class")

            # The whole pack is uncheckable prose until someone buys B16.5.
            # RELAXED 2026-09-24 when the B16.5-2025 drilling templates were
            # transcribed. Same contract as the ratings pack: verified is a
            # citation - a named document, a named table, a date - never a flag.
            if row["verified"]:
                missing = [k for k in ("source", "readOn") if not row.get(k)]
                if missing:
                    fail(f"[ref:flange-bolting] {rating} {nps} is verified but "
                         f"has no {' or '.join(missing)}; verified is a citation")
            elif row.get("source") or row.get("readOn"):
                fail(f"[ref:flange-bolting] {rating} {nps} cites a source but is "
                     f"not verified")

            # The three stud lengths are for three facings of the SAME joint, so
            # they sit within a few millimetres of each other. A ring-joint or
            # tongue-and-groove figure far from the raised-face one means the
            # two column layouts have been crossed: at Class 150 and 300 the
            # last column is a MACHINE BOLT, and from Class 400 up it is a stud
            # for a ring joint. Reading one as the other is silent and wrong.
            for alt in ("minLengthRingJointMm", "minLengthTongueGrooveMm"):
                v = row.get(alt)
                if v is not None and abs(v - row["minLengthMm"]) > 0.25 * row["minLengthMm"]:
                    fail(f"[ref:flange-bolting] {rating} {nps} {alt} {v} mm is "
                         f"nowhere near the {row['minLengthMm']} mm raised-face "
                         f"stud; the column layouts look crossed")

    # Holding the size still and raising the class, the bolt never gets
    # thinner - a heavier flange is a harder squeeze. Bolt COUNT is not
    # checked the same way: B16.5 really does drop 24 inch 1500# to 16
    # bolts of 3 1/2 in, fewer and far fatter. Within one rating the
    # diameter is not monotonic either (300# jumps 1 1/2 in to 3/4 then
    # back to 5/8 at 2 in, where the count goes 4 to 8), so this is the
    # only direction in the table that can be asserted.
    # Rank by the class NUMBER, not by position in the ratings list. Once
    # B16.47 was appended, a rating string appeared more than once and
    # list.index() kept returning the first (B16.5) entry, which ordered
    # Class 900 ahead of Class 75.
    for (lane, nps), pairs in by_size.items():
        ranked = sorted(pairs, key=lambda pr: float(pr[0].rstrip("#")))
        for (lo_r, lo_d), (hi_r, hi_d) in zip(ranked, ranked[1:]):
            if hi_d < lo_d:
                fail(f"[ref:flange-bolting] {lane[0]} {lane[1] or ''} {nps} bolt "
                     f"diameter shrinks from {lo_r} to {hi_r}")

    # Every rating a piping class may carry must be answerable.
    for required in ("150#", "300#", "600#"):
        if required not in seen_ratings:
            fail(f"[ref:flange-bolting] rating {required} is missing; a piping "
                 f"class may carry it")

    n_off = sum(1 for e in pack["ratings"] for b in e["bolting"]
                if b.get("notOffered"))
    n_def = sum(1 for e in pack["ratings"] for b in e["bolting"]
                if "deferredToClass" in b)
    n_ver = sum(1 for e in pack["ratings"] for b in e["bolting"] if b["verified"])
    notes.append(f"[ref:flange-bolting] {len(seen_ratings)} ratings, {rows} size "
                 f"rows ({n_def} deferrals, {n_off} not offered), {n_ver} verified")


def check_materials(refs: dict) -> None:
    """Semantic checks on the material library that JSON Schema cannot express."""
    pack = refs.get("materials")
    if pack is None:
        fail("[ref:materials] pack not loaded")
        return

    forms = {f["code"]: f for f in pack["productForms"]}
    if len(forms) != len(pack["productForms"]):
        fail("[ref:materials] duplicate product form code")

    templates = refs.get("component-templates", {})
    known_components = {t["code"] for t in templates.get("templates", [])}

    n_specs = n_grades = 0
    seen_specs = set()

    for group in pack["groups"]:
        # a group may only supply component types that actually exist
        for ct in group["componentTypes"]:
            if known_components and ct not in known_components:
                fail(f"[ref:materials] group {group['code']} supplies "
                     f"unknown component type {ct}")

        for spec in group["specs"]:
            n_specs += 1
            key = (group["code"], spec["code"])
            if key in seen_specs:
                fail(f"[ref:materials] spec {spec['code']} declared twice "
                     f"in group {group['code']}")
            seen_specs.add(key)

            # every product form a spec claims must be defined
            for fc in spec["productForms"]:
                if fc not in forms:
                    fail(f"[ref:materials] spec {spec['code']} claims "
                         f"undefined product form {fc}")

            # a grade is identified by code AND class/type together, because
            # A358 304 CL 1 and A358 304 CL 3 share a code and differ only in
            # class - the pair is what an MTO row must store.
            seen_grades = set()
            for grade in spec["grades"]:
                n_grades += 1
                gkey = (grade["code"], grade["classOrType"])
                if gkey in seen_grades:
                    fail(f"[ref:materials] spec {spec['code']} repeats "
                         f"grade {grade['code']} / {grade['classOrType']}")
                seen_grades.add(gkey)

                for pname, prop in grade["properties"].items():
                    # a numeric property is meaningless without a unit
                    if isinstance(prop["value"], (int, float)) and \
                            not isinstance(prop["value"], bool) and \
                            "unit" not in prop and \
                            pname not in ("pNumber", "groupNumber"):
                        fail(f"[ref:materials] {spec['code']} {grade['code']}: "
                             f"{pname} is numeric but carries no unit")
                    # RELAXED 2026-09-24 when ASME B16.5-2025 was purchased and
                    # read. "verified" used to be unreachable because nothing
                    # had been read; it now means exactly one thing - somebody
                    # opened a named document and a named table, on a date. A
                    # bare verified:true with no citation is still refused,
                    # because that is the failure mode this rule was built for.
                    if prop["verified"]:
                        missing = [k for k in ("source", "readOn") if not prop.get(k)]
                        if missing:
                            fail(f"[ref:materials] {spec['code']} {grade['code']}: "
                                 f"{pname} is marked verified but has no "
                                 f"{' or '.join(missing)}; verified is a citation, "
                                 f"not a flag")
                    elif prop.get("source") or prop.get("readOn"):
                        fail(f"[ref:materials] {spec['code']} {grade['code']}: "
                             f"{pname} cites a source but is not marked verified; "
                             f"a citation nobody stands behind is worse than none")

    # A non-metallic form has no weld joint quality factor, and a metallic one
    # must have a number. Getting this backwards is how the metallic formula
    # ends up being run on GRE pipe.
    for group in pack["groups"]:
        for spec in group["specs"]:
            for fc in spec["productForms"]:
                if fc not in forms:
                    continue
                factor = forms[fc]["weldJointQualityFactor"]
                # Ew is a term in the B31.3 WALL THICKNESS formula, so the
                # question is not "is this steel?" but "is this sized by that
                # formula?". A spiral wound gasket is steel and has no wall.
                if group["pressureDesigned"] and factor is None:
                    fail(f"[ref:materials] pressure-designed spec {spec['code']} "
                         f"uses form {fc}, which has no weld joint quality factor")
                if not group["metallic"] and factor is not None:
                    fail(f"[ref:materials] non-metallic spec {spec['code']} uses "
                         f"form {fc}, which carries a weld joint quality factor of "
                         f"{factor}; Chapter VII designs from a hydrostatic basis")

            # A surface condition is a real choice or it is not offered at all.
            if not group["metallic"] and spec["surfaceConditions"] != ["NONE"]:
                fail(f"[ref:materials] non-metallic spec {spec['code']} "
                     f"declares a metallic surface condition")
            # NONE means "the coating question does not arise". True for GRE
            # pipe and equally true for a rubber gasket; refusing it on a
            # metallic spec is only right where that spec is a pressure part.
            if group["pressureDesigned"] and "NONE" in spec["surfaceConditions"]:
                fail(f"[ref:materials] pressure-designed spec {spec['code']} "
                     f"declares surface condition NONE")

    # The family a grade belongs to drives welding and PWHT rules, so it is a
    # closed vocabulary, not free text.
    families = {"FERRITIC", "AUSTENITIC", "DUPLEX", "MARTENSITIC", "NICKEL"}
    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                fam = grade["properties"].get("family")
                if fam is not None and fam["value"] not in families:
                    fail(f"[ref:materials] {spec['code']} {grade['code']}: "
                         f"unknown family {fam['value']!r}")

    # A pressure rating belongs to the COMPONENT under B16.5/B16.47, and
    # piping_classes.pressure_rating already holds it. A105 is A105 whether the
    # flange is 150# or 1500#; duplicating it here would create a second truth.
    # Checked against the GRADE PROPERTIES only - the conventions block is
    # prose and is allowed to name the thing it forbids.
    banned = {"pressurerating", "pressureclass", "flangerating", "rating"}
    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                for pname in grade["properties"]:
                    if pname.lower() in banned:
                        fail(f"[ref:materials] {spec['code']} {grade['code']} "
                             f"carries {pname}; a rating is a property of the "
                             f"component, not of the material")

    # Two groups MAY supply one component type - a butt-weld A234 elbow and a
    # socket-weld A105 one are both elbows, and the group is how a user says
    # which. But one specification must not sit in two groups, or a stored
    # selection could not say where it came from.
    spec_home = {}
    for group in pack["groups"]:
        for spec in group["specs"]:
            if spec["code"] in spec_home:
                fail(f"[ref:materials] spec {spec['code']} appears in both "
                     f"{spec_home[spec['code']]} and {group['code']}")
            spec_home[spec["code"]] = group["code"]

    # A cast grade that names its wrought equivalent must name one the library
    # actually holds - a requisition often says TP316 and the material arrives
    # marked CF8M, so the mapping has to resolve or it is worse than absent.
    known_grades = set()
    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                known_grades.add(grade["code"])
                uns = grade["properties"].get("unsNumber")
                if uns:
                    known_grades.add(uns["value"])

    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                eq = grade["properties"].get("wroughtEquivalent")
                if eq is None:
                    continue
                # Proprietary names (Monel 400, Hastelloy C) have no ASTM grade
                # in the library; only check the ones that look like grades.
                v = eq["value"]
                looks_like_grade = v.startswith(("TP", "S3", "S1")) or v.startswith("N0")
                if looks_like_grade and v not in known_grades:
                    fail(f"[ref:materials] {spec['code']} {grade['code']}: wrought "
                         f"equivalent {v!r} is not a grade the library holds")

    # allowable stress must NOT be smuggled in unverified
    if "allowableStress" in json.dumps(pack):
        fail("[ref:materials] pack contains allowable stress data; "
             "it must be loaded separately once transcribed from the standard")

    # 15. A bolting grade must say whether it is the bolt or the nut. Ordering
    #     A194 2H as a bolt, or A193 B7 as a nut, is a real requisition error
    #     and the take-off reconciler counts the two separately.
    for group in pack["groups"]:
        if group["code"] != "BOLTING":
            continue
        for spec in group["specs"]:
            for grade in spec["grades"]:
                role = grade["properties"].get("fastenerRole", {}).get("value")
                if role not in ("BOLT", "NUT"):
                    fail(f"[ref:materials] bolting grade {spec['code']} "
                         f"{grade['code']} has fastenerRole {role!r}; must be "
                         f"BOLT or NUT")

    # 16. The BOLTING group's component types must be exactly the portless
    #     templates. If a future editor gives BOLT a port the two packs
    #     disagree and the MTO silently starts demanding a connection.
    if templates:
        portless = {t["code"] for t in templates["templates"] if not t["ports"]}
        for group in pack["groups"]:
            if group["code"] != "BOLTING":
                continue
            claimed = set(group["componentTypes"])
            if claimed != portless:
                fail(f"[ref:materials] BOLTING claims {sorted(claimed)} but the "
                     f"portless templates are {sorted(portless)}")

    # 17. A material is what it IS, not what it used to be. Provenance - the
    #     plate a welded pipe was rolled from, the bar a fitting was machined
    #     from - belongs to the mill certificate on the LOT, not to the
    #     library. It was carried as free text on 42 grades, resolved against
    #     nothing, and was removed. This rule stops it coming back.
    banned_provenance = ("baseplatespecification", "basematerial", "madefrom",
                         "rolledfrom", "forgedfrom", "parentmaterial")
    for group in pack["groups"]:
        for spec in group["specs"]:
            for key in spec:
                if key.lower() in banned_provenance:
                    fail(f"[ref:materials] spec {spec['code']} carries "
                         f"provenance key {key!r}; a material is recorded as "
                         f"what it is, not what it was made from")
            for grade in spec["grades"]:
                for key in grade["properties"]:
                    if key.lower() in banned_provenance:
                        fail(f"[ref:materials] grade {spec['code']} "
                             f"{grade['code']} carries provenance key {key!r}; "
                             f"that history belongs to the lot's mill "
                             f"certificate, not to the library")

    # 18. A structural plate must not be offered for a pressure-retaining
    #     component. A283 is in the library because it appears on real MTOs as
    #     base plates and gussets, not because a line blank may be cut from it,
    #     and a blind cut from structural plate is a rupture waiting to happen.
    pressure_retaining_components = {"BLIND", "SPECTACLE_BLIND", "PIPE",
                                     "FLANGE", "VALVE", "TEE", "ELBOW",
                                     "REDUCER", "CAP", "CROSS", "NOZZLE"}
    for group in pack["groups"]:
        supplies_pressure_part = bool(
            set(group["componentTypes"]) & pressure_retaining_components)
        for spec in group["specs"]:
            structural = any(
                grade["properties"].get("pressureRetaining", {}).get("value") is False
                for grade in spec["grades"])
            if not structural:
                continue
            # Every grade of such a spec must agree - a spec cannot be half
            # structural, because the cascade offers the SPEC before the grade.
            if not all(
                grade["properties"].get("pressureRetaining", {}).get("value") is False
                for grade in spec["grades"]
            ):
                fail(f"[ref:materials] spec {spec['code']} marks only some "
                     f"grades pressureRetaining false; the cascade offers the "
                     f"spec before the grade, so the whole spec must agree")
            if supplies_pressure_part:
                fail(f"[ref:materials] spec {spec['code']} is structural but "
                     f"its group supplies pressure-retaining components "
                     f"{sorted(set(group['componentTypes']) & pressure_retaining_components)}")

    # 19. A group that supplies nothing is legitimate but must be DELIBERATE,
    #     and reachedBy is where it says so. Until the trim round this rule
    #     assumed the only honest reason to supply nothing was being structural,
    #     which was true of exactly one group and stopped being true the moment
    #     a group existed that is reached by a different route entirely. An
    #     empty componentTypes with no stated route is still a group somebody
    #     forgot to wire up, and it would sit in the library invisible.
    for group in pack["groups"]:
        if group["componentTypes"]:
            if group["reachedBy"] != "CASCADE":
                fail(f"[ref:materials] group {group['code']} supplies component "
                     f"types but claims reachedBy {group['reachedBy']}; anything "
                     f"the cascade can reach must say CASCADE")
            continue
        if group["reachedBy"] == "CASCADE":
            fail(f"[ref:materials] group {group['code']} claims reachedBy "
                 f"CASCADE but supplies no component type; it would be "
                 f"unreachable from every dropdown")
        if group["reachedBy"] == "REQUISITION_ONLY":
            all_structural = all(
                grade["properties"].get("pressureRetaining", {}).get("value") is False
                for spec in group["specs"] for grade in spec["grades"])
            if not all_structural:
                fail(f"[ref:materials] group {group['code']} claims "
                     f"REQUISITION_ONLY but not every grade is pressureRetaining "
                     f"false; a pressure part must be reachable by a route that "
                     f"can be validated")

    # 20. A gasket names its constituents, and every constituent that claims a
    #     specification must resolve against a grade this library actually
    #     holds. Same discipline as wroughtEquivalent on the castings, which
    #     caught a genuine error in its author's data the first time it ran.
    #     Free text here would rot silently: the winding says ASTM A240 316
    #     while the library holds no such grade, and nobody finds out until a
    #     requisition is rejected at the mill.
    held = {}
    for group in pack["groups"]:
        for spec in group["specs"]:
            held.setdefault(spec["code"], set())
            for grade in spec["grades"]:
                held[spec["code"]].add(grade["code"])

    # Pairs of (specification property, grade property) that must agree.
    constituent_pairs = [
        ("windingSpecification", "windingGrade"),
        ("outerRingSpecification", "outerRingGrade"),
        ("innerRingSpecification", "innerRingGrade"),
        ("coreSpecification", "coreGrade"),
        ("ringSpecification", "ringGrade"),
        ("jacketSpecification", "jacketGrade"),
        # A trim number implies four machined parts. Same discipline: if it
        # names a specification it must resolve, or the trim chart rots into
        # free text the way it does on every vendor website.
        ("seatSpecification", "seatGrade"),
        ("discSpecification", "discGrade"),
        ("backseatSpecification", "backseatGrade"),
        ("stemSpecification", "stemGrade"),
        ("ballSpecification", "ballGrade"),
        ("plugSpecification", "plugGrade"),
        ("hingePinSpecification", "hingePinGrade"),
    ]
    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                props = grade["properties"]
                for spec_key, grade_key in constituent_pairs:
                    if spec_key not in props:
                        continue
                    if grade_key not in props:
                        fail(f"[ref:materials] {spec['code']} {grade['code']} "
                             f"has {spec_key} but no {grade_key}")
                        continue
                    designation = str(props[spec_key]["value"])
                    gcode = str(props[grade_key]["value"])
                    # 'ASTM A240' -> 'A240'. A dash means the spec has no
                    # graded product (soft iron to A36), which is legitimate.
                    short = designation.replace("ASTM ", "").replace("ASME ", "").strip()
                    if gcode == "-":
                        continue
                    # Two sentinels a standard prints itself. 'MFG Standard' is
                    # API 600 Table 8 declining to name a specification;
                    # 'WITHDRAWN' marks a trim the standard has obsoleted and
                    # the pack keeps only so an old sheet can be recognised.
                    # Neither is a specification and neither is a gap.
                    if designation in ("MFG Standard", "WITHDRAWN", "NOT SPECIFIED"):
                        if gcode != designation:
                            fail(f"[ref:materials] {spec['code']} {grade['code']}: "
                                 f"{spec_key} is the sentinel {designation!r} but "
                                 f"{grade_key} is {gcode!r}; a sentinel must be "
                                 f"carried through both halves or it reads as data")
                        continue
                    if short not in held:
                        fail(f"[ref:materials] {spec['code']} {grade['code']}: "
                             f"{spec_key} names {designation!r}, which is not a "
                             f"specification this library holds")
                    elif gcode not in held[short]:
                        fail(f"[ref:materials] {spec['code']} {grade['code']}: "
                             f"{grade_key} {gcode!r} is not a grade of {short}")

    # 21. A gasket is rated by the joint, never by itself, and its real ceiling
    #     comes from the filler - PTFE gives up around 260 C while the flange
    #     is untroubled. Every gasket grade must therefore state a temperature
    #     ceiling, and none may carry a pressure rating.
    for group in pack["groups"]:
        if group["code"] != "GASKET":
            continue
        for spec in group["specs"]:
            for grade in spec["grades"]:
                props = grade["properties"]
                if "maxTemperature" not in props and "maxHardness" not in props:
                    fail(f"[ref:materials] gasket {spec['code']} "
                         f"{grade['code']} states neither a temperature ceiling "
                         f"nor a hardness limit; one of the two is what fails "
                         f"first in service")
                if "permittedFacings" not in props:
                    fail(f"[ref:materials] gasket {spec['code']} "
                         f"{grade['code']} does not say which flange facing it "
                         f"seats on")

    # 22. pressureDesigned switches off the Ew and surface-condition guards, so
    #     it is exactly the flag an editor would flip to silence a complaint.
    #     A group may only claim false if it has EARNED it: either nothing it
    #     supplies is a pressure boundary (a gasket, a structural plate), or it
    #     is non-metallic and rated from a hydrostatic basis instead. Setting
    #     it false on the pipe group survived a mutation until this rule
    #     existed - every guard in check_materials() would have gone quiet.
    pressure_boundary_components = {"PIPE", "FLANGE", "VALVE", "CONTROL_VALVE",
                                    "THREE_WAY_VALVE", "TEE", "ELBOW", "CROSS",
                                    "REDUCER", "CAP", "BLIND", "SPECTACLE_BLIND",
                                    "OLET", "NOZZLE", "COUPLING", "UNION",
                                    "NIPPLE", "SWAGE"}
    for group in pack["groups"]:
        if group["pressureDesigned"]:
            continue
        boundary = set(group["componentTypes"]) & pressure_boundary_components
        if boundary and group["metallic"]:
            fail(f"[ref:materials] group {group['code']} claims "
                 f"pressureDesigned false but supplies metallic pressure "
                 f"boundary components {sorted(boundary)}; that flag disables "
                 f"the weld joint factor and surface condition guards")

    # 23. A CONSTITUENT group exists only to be named by another grade, so if
    #     nothing names it, it is dead weight that no screen can reach and no
    #     requisition can order. This is the counterpart of rule 19: that one
    #     stops a group being unreachable by accident, this one stops a group
    #     being unreachable on purpose and then forgotten.
    constituent_suffixes = ("Specification",)
    named_specs = set()
    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                for pname, prop in grade["properties"].items():
                    if pname.endswith(constituent_suffixes):
                        named_specs.add(str(prop["value"]).replace("ASTM ", "")
                                        .replace("ASME ", "").strip())
    for group in pack["groups"]:
        if group["reachedBy"] != "CONSTITUENT":
            continue
        codes = {spec["code"] for spec in group["specs"]}
        if not (codes & named_specs):
            fail(f"[ref:materials] group {group['code']} is reachable only as a "
                 f"constituent but no grade anywhere names any of its "
                 f"specifications; it is unreachable from everything")

    # 24. A trim must name every part ITS OWN STANDARD defines - and only a
    #     gate or globe valve has four. This rule used to hard-code the API 600
    #     parts and demanded a backseat and a stem from every trim in the
    #     library, which is exactly the error that shipped: API 600 covers
    #     BOLTED BONNET GATE VALVES ONLY, and a ball valve has no backseat, a
    #     check valve has no stem at all. requiredParts is per specification,
    #     so the shape of a trim now follows the valve it belongs to.
    for group in pack["groups"]:
        if group["reachedBy"] != "TRIM":
            continue
        for spec in group["specs"]:
            parts = spec["requiredParts"]
            for grade in spec["grades"]:
                props = grade["properties"]
                for part in parts:
                    if f"{part}Material" not in props:
                        fail(f"[ref:materials] {spec['code']} trim "
                             f"{grade['code']} does not state its {part} material")
                        continue
                    # every part resolves to a held grade, a declared
                    # hardfacing, or a named polymer - never to nothing.
                    # 'MFG Standard' and 'NOT SPECIFIED' are sentinels the
                    # standards print themselves - API 594 Table 4 leaves the
                    # hinge pin columns of trim AA empty. 'MFG Standard' is the
                    # sentinel API 600 Table 8 prints
                    # where it declines to name a specification. "The standard
                    # defers to the manufacturer" is an answer, and a different
                    # one from "nobody filled this in".
                    if (props.get(f"{part}Specification", {}) or {}).get("value") \
                            in ("MFG Standard", "NOT SPECIFIED"):
                        continue
                    # A part may name SEVERAL specifications where the
                    # standard offers several routes to it - API 600 Table 8
                    # gives a seating surface a cast, a forged and a welded
                    # option. Plural resolves as surely as singular.
                    if f"{part}Specification" not in props and \
                            f"{part}Specifications" not in props and \
                            f"{part}Hardfacing" not in props and \
                            f"{part}Polymer" not in props:
                        fail(f"[ref:materials] {spec['code']} trim "
                             f"{grade['code']} names a {part} material but "
                             f"neither a specification, a hardfacing nor a "
                             f"polymer; it resolves to nothing")
                # a trim must not name a part its standard does not have
                for pname in props:
                    for suffix in ("Material",):
                        if not pname.endswith(suffix):
                            continue
                        part = pname[: -len(suffix)]
                        if part not in parts:
                            fail(f"[ref:materials] {spec['code']} trim "
                                 f"{grade['code']} names a {part}, which a "
                                 f"{'/'.join(spec['appliesToValveTypes'])} "
                                 f"valve does not have")

    # 25. A hardfaced seat is a WELD OVERLAY, and the only thing a receiving
    #     inspector can actually measure on it is hardness. AMS 5894 is not an
    #     ASTM product specification and is deliberately not in this library,
    #     so hardfacing is exempt from rule 20 - which means hardness is the
    #     ONLY check left. A trim that claims hardfacing without a minimum
    #     hardness has bought an exemption and given nothing back.
    for group in pack["groups"]:
        if group["reachedBy"] != "TRIM":
            continue
        for spec in group["specs"]:
            for grade in spec["grades"]:
                props = grade["properties"]
                hardfaced = any(k.endswith("Hardfacing") for k in props)
                # The seating surface is called seatMaterial in the charts this
                # library grew from and seatingSurfaceMaterial in the one
                # transcribed from API 600 Table 8, which does not split a seat
                # from a disc. Both spellings are the same fact.
                seat_text = str(props.get("seatMaterial", {}).get("value", "")) \
                    + str(props.get("seatingSurfaceMaterial", {}).get("value", ""))
                hardened = "Hard" in seat_text or "hardened" in seat_text
                states_hardness = any(
                    k in props for k in ("minSeatHardness",
                                         "minSeatingSurfaceHardness",
                                         "seatingSurfaceHardness"))
                # AND NOT EVERY STANDARD SPECIFIES ONE. API 594 states no seat
                # hardness at all - the words hardness, HB and Brinell do not
                # appear in it - where API 600 and API 623 both give a column.
                # This rule was written from the API 600 shape; a spec that
                # declares the absence is not hiding a gap, it is reporting one.
                if spec.get("hardnessNotSpecified"):
                    continue
                if (hardfaced or hardened) and not states_hardness:
                    fail(f"[ref:materials] trim {grade['code']} has a hardfaced "
                         f"or hardened seat but states no minimum seat hardness; "
                         f"hardness is the only property of an overlay an "
                         f"inspector can verify")

    # 26. A trim standard must say which valve types it covers, and must not
    #     claim one another standard already claims. API 600 is quoted for
    #     every valve in the industry, but its scope is gate valves; letting
    #     two specs both claim GATE would make "the trims for a gate valve" an
    #     ambiguous question the UI cannot answer.
    valve_qualifiers = set()
    for t in templates.get("templates", []):
        if t["code"] == "VALVE":
            valve_qualifiers = set(t.get("qualifiers", []))

    claimed_types = {}
    for group in pack["groups"]:
        if group["reachedBy"] != "TRIM":
            continue
        for spec in group["specs"]:
            for vt in spec["appliesToValveTypes"]:
                if valve_qualifiers and vt not in valve_qualifiers:
                    fail(f"[ref:materials] {spec['code']} claims valve type "
                         f"{vt}, which the VALVE template does not offer")
                if vt in claimed_types:
                    fail(f"[ref:materials] valve type {vt} is claimed by both "
                         f"{claimed_types[vt]} and {spec['code']}")
                claimed_types[vt] = spec["code"]

    # 27. A soft seat is limited by its POLYMER, not by the body - the same
    #     truth the gasket round found about fillers. A polymer seat with no
    #     ceiling would let a PTFE-seated ball valve be specified for 400 C
    #     steam, where it would extrude out of the seat pocket.
    for group in pack["groups"]:
        if group["reachedBy"] != "TRIM":
            continue
        for spec in group["specs"]:
            for grade in spec["grades"]:
                props = grade["properties"]
                if any(k.endswith("Polymer") for k in props) and \
                        "maxTemperature" not in props:
                    fail(f"[ref:materials] {spec['code']} trim "
                         f"{grade['code']} has a polymer seat but states no "
                         f"temperature ceiling; the polymer is what fails first")

    # 28. A trim may not be both soft seated and hardfaced. They are opposite
    #     answers to the same question and a grade claiming both is a copy
    #     and paste of one chart into another.
    for group in pack["groups"]:
        if group["reachedBy"] != "TRIM":
            continue
        for spec in group["specs"]:
            for grade in spec["grades"]:
                props = grade["properties"]
                if any(k.endswith("Polymer") for k in props) and \
                        any(k.endswith("Hardfacing") for k in props):
                    fail(f"[ref:materials] {spec['code']} trim "
                         f"{grade['code']} is both soft seated and hardfaced")

    # 29. A cross-reference to another grade must resolve. matchingLinePipeGrade
    #     pairs a flange or fitting grade with the line pipe grade it is meant to
    #     be welded to (A694 F60 -> API 5L X60), and it is the only place in the
    #     pack where one grade names another. A dangling one is worse than an
    #     absent one: it reads as an answer and delivers nothing, which is the
    #     defect ADR-008 sec 11.14 found in flange-bolting. Nothing consumed
    #     these until now, so five of the fifteen had rotted unnoticed.
    known_grades = set()
    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                known_grades.add(f"{spec['designation']} {grade['code']}")
    for group in pack["groups"]:
        for spec in group["specs"]:
            for grade in spec["grades"]:
                ref = grade["properties"].get("matchingLinePipeGrade")
                if ref is None:
                    continue
                if ref["value"] not in known_grades:
                    fail(f"[ref:materials] {spec['code']} {grade['code']} points "
                         f"matchingLinePipeGrade at '{ref['value']}', which is not "
                         f"a grade in this pack")

    # 30. A B16.5 material group must look like one. The group number is the
    #     hinge of the whole pressure-temperature lookup (ADR-008 sec 11 finding
    #     2): grade -> group, then (group x class) -> curve. A typo here does not
    #     fail, it silently returns a different pressure.
    b165_shape = re.compile(r"^[123]\.\d{1,2}$")
    for group in pack["groups"]:
        for spec in group["specs"]:
            holders = [(spec["code"], None, spec.get("properties") or {})]
            holders += [(spec["code"], g["code"], g["properties"])
                        for g in spec["grades"]]
            for spec_code, grade_code, props in holders:
                mg = props.get("b165MaterialGroup")
                if mg is None:
                    continue
                where = f"{spec_code} {grade_code}" if grade_code else spec_code
                if not b165_shape.match(str(mg["value"])):
                    fail(f"[ref:materials] {where} claims B16.5 material group "
                         f"'{mg['value']}', which is not a group number")

    # 31. A grade may not contradict its own specification. If a spec declares a
    #     group AND its grades declare one, two answers exist for one question
    #     and the resolver's order of lookup silently decides which is right.
    for group in pack["groups"]:
        for spec in group["specs"]:
            spec_mg = (spec.get("properties") or {}).get("b165MaterialGroup")
            if spec_mg is None:
                continue
            for grade in spec["grades"]:
                if "b165MaterialGroup" in grade["properties"]:
                    fail(f"[ref:materials] {spec['code']} declares a B16.5 "
                         f"material group and so does its grade "
                         f"{grade['code']}; only one of them may")

    # 32. Only a GRADELESS spec may carry spec-level properties. A105 is the
    #     whole material and has nowhere else to put a fact; every other spec
    #     resolves through a grade, and a fact parked on the spec would be
    #     invisible to anything that looked up the grade.
    for group in pack["groups"]:
        for spec in group["specs"]:
            if (spec.get("properties") or {}) and spec["grades"]:
                fail(f"[ref:materials] {spec['code']} has {len(spec['grades'])} "
                     f"grades, so it may not carry spec-level properties; move "
                     f"them onto the grades")

    notes.append(f"[ref:materials] {n_specs} specs, {n_grades} grades, "
                 f"{len(forms)} product forms, all unverified")



def check_flange_pt_ratings(refs: dict) -> None:
    """
    ASME B16.5 / B16.47 pressure-temperature ratings.

    This pack answers the question a piping class exists to answer, so its
    failure mode is the dangerous kind: a wrong number here does not crash, it
    returns a plausible pressure. Every rule below is an attempt to make the
    pack disprove itself, because we cannot verify it against the standard.
    """
    pack = refs.get("flange-pt-ratings")
    if pack is None:
        return

    known_classes = set()
    for std in pack["standards"]:
        known_classes.update(std["classes"])

    # 1. RELAXED 2026-09-24, deliberately, when the purchased copy of
    #    ASME B16.5-2025 arrived. The rule used to be a blanket refusal
    #    because nothing had been read. It is now the thing it was always
    #    meant to become: verified is a CITATION, not a mood. A curve may
    #    claim it only if it names the edition it was read from and the
    #    numbered table it was read out of, and that edition must be one the
    #    pack actually declares. Anything unverified must stay silent about
    #    a table, so a stale citation cannot outlive the reading.
    declared = {(s["code"], s["editionYear"]) for s in pack["standards"]}
    for curve in pack["curves"]:
        who = f"curve {curve['standard']} group {curve['materialGroup']}"
        if curve["verified"]:
            if not curve.get("sourceTable"):
                fail(f"[ref:flange-pt-ratings] {who} claims to be verified but "
                     f"cites no table; verified means somebody read a numbered "
                     f"table in a purchased document")
            if (curve["standard"], curve.get("editionYear")) not in declared:
                fail(f"[ref:flange-pt-ratings] {who} is verified against edition "
                     f"{curve.get('editionYear')}, which this pack does not declare")
        elif curve.get("sourceTable"):
            fail(f"[ref:flange-pt-ratings] {who} is not verified yet cites table "
                 f"{curve['sourceTable']}; an unread table is not a citation")
    #    RELAXED AGAIN 2026-09-25, for the same reason and on the same terms,
    #    when the purchased copy of API 602-2015 arrived. Class 800 used to be
    #    a formula off the web and could not be verified by anyone; clause
    #    4.1.3 of API 602 now DEFINES it. A derived class may claim verified
    #    only if it names the clause and the date it was read, and says which
    #    standard it derives FROM - Class 800 was quietly deriving a valve
    #    rating from the flange tables until this reading caught it.
    for d in pack["derivedClasses"]:
        if not d["verified"]:
            if d.get("definedByClause"):
                fail(f"[ref:flange-pt-ratings] derived class {d['class']} is not "
                     f"verified yet cites a clause; an unread clause is not a "
                     f"citation")
            continue
        for field in ("definedByClause", "readOn", "appliesToStandard"):
            if not d.get(field):
                fail(f"[ref:flange-pt-ratings] derived class {d['class']} claims "
                     f"to be verified without {field}")
        corr = d.get("corroboration")
        if corr and corr.get("rowsCompared"):
            bad = corr["rowsCompared"] - corr.get("rowsWithin0_023MPa", 0)
            if bad != len(corr.get("deviatingRows", [])):
                fail(f"[ref:flange-pt-ratings] derived class {d['class']} says "
                     f"{bad} row(s) deviate but lists "
                     f"{len(corr.get('deviatingRows', []))}")

    # 2. Above Class 150 a B16.5 rating is MOSTLY linear in the class number,
    #    and while this pack was a reconstruction that was the only independent
    #    check available: a mistyped digit breaks the line.
    #
    #    REVISED 2026-09-24 against the purchased ASME B16.5-2025. Linearity is
    #    a property of how most groups were computed, NOT a law of the standard.
    #    Group 2.8 (20Cr-18Ni-6Mo: A182 F44, A351 CK3MCuN, A240 S31254) breaks
    #    it by up to 10 per cent - Class 2500 holds 430.9 bar all the way to
    #    100 C while Class 300 has already started falling, so the high classes
    #    are clipped by a ceiling the low ones never reach. Group 1.18 breaks it
    #    by 1.05 per cent at 600 C. Both are printed that way in the document.
    #
    #    So the rule now depends on who is speaking. On an UNVERIFIED curve it
    #    is still the only typo detector there is, and it stays fatal. On a
    #    VERIFIED curve the document outranks my model of the document: the
    #    deviation is reported as a note, never as a failure. A rule that
    #    contradicts a transcription is a rule about the transcriber.
    for curve in pack["curves"]:
        for point in curve["points"]:
            ratios = {c: v / float(c) for c, v in point["byClass"].items()
                      if c != "150"}
            if len(ratios) < 2:
                continue
            spread = (max(ratios.values()) - min(ratios.values())) / max(ratios.values())
            if spread > 0.01:
                worst = max(ratios, key=ratios.get), min(ratios, key=ratios.get)
                where = (f"group {curve['materialGroup']} at "
                         f"{point['temperatureC']} C ({spread * 100:.2f}% spread, "
                         f"worst pair {worst[0]} vs {worst[1]})")
                if curve["verified"]:
                    notes.append(f"[ref:flange-pt-ratings] {where} departs from "
                                 f"class-linearity, as {curve['sourceTable']} "
                                 f"genuinely does; transcribed, not corrected")
                else:
                    fail(f"[ref:flange-pt-ratings] {where} is not linear in class "
                         f"number; one of these numbers is mistyped")

    # 3. Class 150 must sit BELOW the line the higher classes make. If a
    #    transcription ever lands it on the line, the value was extrapolated
    #    rather than read.
    for curve in pack["curves"]:
        for point in curve["points"]:
            by = point["byClass"]
            if "150" not in by or "300" not in by:
                continue
            on_the_line = by["300"] * 150.0 / 300.0
            if by["150"] >= on_the_line:
                fail(f"[ref:flange-pt-ratings] group {curve['materialGroup']} at "
                     f"{point['temperatureC']} C has Class 150 at {by['150']} kPa, "
                     f"which is not below the {on_the_line:.0f} kPa the higher "
                     f"classes extrapolate to; Class 150 is B16.5's outlier and "
                     f"must be lower")

    # 4. A rating must fall as temperature rises, for every class. Steel does
    #    not get stronger when heated.
    #
    #    REVISED 2026-09-24. B16.5 prints bar to one decimal place, and a
    #    rounded sequence can tick UP by one digit in the last place while the
    #    underlying curve falls: Group 3.3 Class 2500 goes 128.2 bar at 350 C to
    #    128.3 at 375 C. That is an artefact of the printing, not a material
    #    getting stronger. A verified curve is therefore allowed to rise by one
    #    rounding step (0.1 bar = 10 kPa) and no more; anything larger, and any
    #    rise at all on an unverified curve, is still a failure.
    ROUNDING_STEP_KPA = 10

    # A cell already declared as a cross-standard discrepancy is a KNOWN printing
    # error in its own document. It may break this rule, because refusing it
    # would mean refusing to record what the standard says - and recording it is
    # the whole policy. Anything not declared is still a failure.
    declared_cells = {
        (c["materialGroup"], c["temperatureC"], c["class"])
        for c in pack.get("crossStandardDiscrepancies", {}).get("cells", [])
    }

    for curve in pack["curves"]:
        points = sorted(curve["points"], key=lambda p: p["temperatureC"])
        for cls in sorted({c for p in points for c in p["byClass"]}):
            seq = [(p["temperatureC"], p["byClass"][cls]) for p in points
                   if cls in p["byClass"]]
            for (t0, v0), (t1, v1) in zip(seq, seq[1:]):
                if v1 <= v0:
                    continue
                rise = v1 - v0
                known = ((curve["materialGroup"], t0, cls) in declared_cells
                         or (curve["materialGroup"], t1, cls) in declared_cells)
                if curve["verified"] and rise <= ROUNDING_STEP_KPA:
                    notes.append(f"[ref:flange-pt-ratings] group "
                                 f"{curve['materialGroup']} class {cls} ticks up "
                                 f"{rise} kPa from {t0} C to {t1} C; one rounding "
                                 f"step in {curve['sourceTable']}, transcribed")
                elif curve["verified"] and known:
                    notes.append(f"[ref:flange-pt-ratings] {curve['standard']} group "
                                 f"{curve['materialGroup']} class {cls} RISES "
                                 f"{rise} kPa from {t0} C to {t1} C in "
                                 f"{curve['sourceTable']} - a DECLARED cross-standard "
                                 f"discrepancy, transcribed as printed. This rule "
                                 f"found it independently of the comparison.")
                else:
                    fail(f"[ref:flange-pt-ratings] group {curve['materialGroup']} "
                         f"class {cls} RISES from {v0} kPa at {t0} C to {v1} kPa "
                         f"at {t1} C")

    # 4b. Two standards that rate the SAME material group must agree, cell for
    #     cell, unless the disagreement is DECLARED. This is the rule that stops
    #     a future transcription error from hiding inside a 99.5% match, and it
    #     is symmetric: a declared discrepancy that no longer disagrees is also a
    #     failure, so the record cannot go stale after a correction.
    by_std: dict = {}
    for curve in pack["curves"]:
        for point in curve["points"]:
            for cls, v in point["byClass"].items():
                by_std.setdefault(
                    (curve["materialGroup"], point["temperatureC"], cls), {}
                )[curve["standard"]] = v

    actually_differ = set()
    for key, vals in by_std.items():
        if len(vals) < 2 or len(set(vals.values())) == 1:
            continue
        actually_differ.add(key)
        if key not in declared_cells:
            g, t, cls = key
            shown = ", ".join(f"{s} {v} kPa" for s, v in sorted(vals.items()))
            fail(f"[ref:flange-pt-ratings] group {g} at {t} C class {cls} is rated "
                 f"differently by two standards ({shown}) and the disagreement is "
                 f"not declared in crossStandardDiscrepancies")

    for key in declared_cells - actually_differ:
        g, t, cls = key
        fail(f"[ref:flange-pt-ratings] a cross-standard discrepancy is declared for "
             f"group {g} at {t} C class {cls}, but the packs do not disagree there; "
             f"a stale discrepancy is a false accusation against a standard")

    # 4c. A class may only be claimed for a series that actually has it. B16.47
    #     Class 75 exists in Series B alone, so a Series A drawing calling for a
    #     Class 75 flange is asking for something nobody makes.
    for std in pack["standards"]:
        series = std.get("classesBySeries")
        if not series:
            continue
        union = {c for cs in series.values() for c in cs}
        if union != set(std["classes"]):
            fail(f"[ref:flange-pt-ratings] {std['code']} lists classes "
                 f"{sorted(std['classes'])} but its series between them offer "
                 f"{sorted(union)}; a class belongs to at least one series")

    # 5. A derived class must derive from classes that exist, with weights that
    #    sum to one, and must not also be tabulated. Class 800 is a formula
    #    (ADR-008 section 11.13); if it ever appears in a curve as well, two
    #    answers exist for one question.
    for d in pack["derivedClasses"]:
        total = 0.0
        for part in d["derivedFrom"]:
            src, weight = part["class"], part["weight"]
            total += weight
            if src not in known_classes:
                fail(f"[ref:flange-pt-ratings] derived class {d['class']} is built "
                     f"from class {src}, which no standard in this pack defines")
        if abs(total - 1.0) > 1e-6:
            fail(f"[ref:flange-pt-ratings] derived class {d['class']} has weights "
                 f"summing to {total}, not 1")
        for curve in pack["curves"]:
            for point in curve["points"]:
                if d["class"] in point["byClass"]:
                    fail(f"[ref:flange-pt-ratings] class {d['class']} is both "
                         f"derived and tabulated in group "
                         f"{curve['materialGroup']}; pick one")

    # 6. B16.47 must never acquire a series axis. Series A and B share their
    #    ratings and differ dimensionally (ADR-008 section 11.15). Someone will
    #    eventually "fix" this pack by adding one; this rule is the argument.
    for std in pack["standards"]:
        if std["code"] == "B16.47" and not std.get("seriesShareRatings"):
            fail("[ref:flange-pt-ratings] B16.47 must declare seriesShareRatings; "
                 "Series A and B have the SAME pressure-temperature ratings and "
                 "the series axis belongs on flange-bolting, not here")
    for curve in pack["curves"]:
        if "series" in curve:
            fail(f"[ref:flange-pt-ratings] curve for {curve['standard']} carries a "
                 f"series; ratings do not vary by series (ADR-008 11.15)")

    # 7. Every curve must name an edition year. Three separate findings in one
    #    day turned on an edition: the B16.5/B16.47 few-psi drift, B16.11's
    #    table moving from 7 to 8, and API 6D restating Class 600 from 1440 to
    #    1480 psi. A citation without an edition is not a citation.
    for curve in pack["curves"]:
        if not curve.get("editionYear"):
            fail(f"[ref:flange-pt-ratings] curve {curve['standard']} group "
                 f"{curve['materialGroup']} states no edition year")
        if curve["standard"] not in {s["code"] for s in pack["standards"]}:
            fail(f"[ref:flange-pt-ratings] curve names standard "
                 f"{curve['standard']}, which the pack does not describe")

    n_pts = sum(len(c["points"]) for c in pack["curves"])
    n_ver = sum(1 for c in pack["curves"] if c["verified"])
    notes.append(f"[ref:flange-pt-ratings] {len(pack['curves'])} curve(s), "
                 f"{n_ver} verified / {len(pack['curves']) - n_ver} not, "
                 f"{n_pts} temperature points, {len(pack['derivedClasses'])} "
                 f"derived class(es)")


def check_actuators(refs: dict) -> None:
    """Valve actuators. Reference data only - not offered on any screen yet."""
    pack = refs.get("actuators")
    if pack is None:
        fail("[ref:actuators] pack not loaded")
        return

    templates = refs.get("component-templates") or {}
    valve_qualifiers = set()
    for t in templates.get("templates", []):
        if t["code"] == "VALVE":
            valve_qualifiers = set(t.get("qualifiers", []))

    travels = {t["code"]: t for t in pack["travels"]}
    fail_modes = {f["code"] for f in pack["failSafeModes"]}

    # 1. A travel may only name valve types the VALVE template actually offers.
    #    Same discipline as the trim round: this pack does not get to invent
    #    valve types the rest of the system has never heard of.
    for t in pack["travels"]:
        for vt in t["valveTypes"]:
            if valve_qualifiers and vt not in valve_qualifiers:
                fail(f"[ref:actuators] travel {t['code']} names valve type "
                     f"{vt}, which the VALVE template does not offer")

    # 2. No valve type may sit on two travels. A gate valve is multi-turn and
    #    nothing else; if it appeared on both, "which actuators fit this valve"
    #    would have two answers and the UI could not choose.
    seen_types = {}
    for t in pack["travels"]:
        for vt in t["valveTypes"]:
            if vt in seen_types:
                fail(f"[ref:actuators] valve type {vt} appears on both "
                     f"{seen_types[vt]} and {t['code']}; travel is exclusive")
            seen_types[vt] = t["code"]

    # 3. Every actuator must name a travel that exists. An actuator whose
    #    travel does not resolve can never be matched to a valve.
    for a in pack["actuators"]:
        for tc in a["travels"]:
            if tc not in travels:
                fail(f"[ref:actuators] actuator {a['code']} claims unknown "
                     f"travel {tc}")

    # 4. ONLY A SPRING RETURN HAS A FAIL-SAFE. This is the rule the pack exists
    #    to carry. A double-acting pneumatic actuator and an electric MOV both
    #    stay exactly where they are on failure, and datasheets call that
    #    "fail safe" all the time - which is how a safety case ends up resting
    #    on a valve that will not move. Only an actuator that stores its own
    #    energy may claim it.
    for a in pack["actuators"]:
        if a["supportsFailSafe"] and "SPRING" not in a["code"]:
            fail(f"[ref:actuators] actuator {a['code']} claims a fail-safe but "
                 f"stores no energy; only a spring return drives the valve "
                 f"anywhere on loss of power")

    # 5. A manual actuator has no power to lose, so its fail state is always
    #    FAIL_LAST. Recording FC on a handwheel is a category error.
    for a in pack["actuators"]:
        if a["power"] == "MANUAL":
            if a["supportsFailSafe"]:
                fail(f"[ref:actuators] manual actuator {a['code']} claims a "
                     f"fail-safe; it has no power to lose")
            if a["defaultFailSafe"] != "FAIL_LAST":
                fail(f"[ref:actuators] manual actuator {a['code']} must fail "
                     f"last, not {a['defaultFailSafe']}")
            if a["supportsModulating"]:
                fail(f"[ref:actuators] manual actuator {a['code']} cannot "
                     f"modulate; nobody stands at a handwheel holding setpoint")

    # 6. A defaultFailSafe must resolve, and an actuator that CAN fail safe
    #    must not pretend to have a default: FC and FO are opposite safe
    #    states chosen per valve from the process, never from the hardware.
    for a in pack["actuators"]:
        dfs = a["defaultFailSafe"]
        if dfs is not None and dfs not in fail_modes:
            fail(f"[ref:actuators] actuator {a['code']} names unknown fail "
                 f"mode {dfs}")
        if a["supportsFailSafe"] and dfs is not None:
            fail(f"[ref:actuators] actuator {a['code']} is spring return, so "
                 f"its fail state is a per-valve process decision (FC or FO) "
                 f"and must not be defaulted here")
        if not a["supportsFailSafe"] and dfs is None:
            fail(f"[ref:actuators] actuator {a['code']} has no fail-safe and "
                 f"no default; it must state FAIL_LAST explicitly")

    # 7. Nothing in this pack is verified until someone checks it against a
    #    purchased standard or a vendor catalogue.
    for section in ("travels", "failSafeModes", "controlModes", "actuators"):
        for row in pack[section]:
            if row["verified"]:
                fail(f"[ref:actuators] {section} {row['code']} is marked "
                     f"verified; nothing here has been checked")

    # Defensive: an actuator may name a travel rule 3 just rejected, and this
    # summary must not crash before the failures are printed.
    n_pairs = sum(len(travels[tc]["valveTypes"])
                  for a in pack["actuators"] for tc in a["travels"]
                  if tc in travels)
    notes.append(f"[ref:actuators] {len(pack['actuators'])} actuators, "
                 f"{len(travels)} travels, {n_pairs} actuator/valve-type "
                 f"pairs, all unverified")


def check_units(refs: dict) -> None:
    """
    Physical checks on the units pack that a JSON-schema cannot express.

    Every assertion here is a way the pack can be well-formed and still
    silently produce a wrong number on a drawing.
    """
    units = refs.get("units")
    if not units:
        return

    seen_quantities = set()

    for q in units["quantities"]:
        name = q["quantity"]
        if name in seen_quantities:
            fail(f"[ref:units] quantity {name} is declared twice")
        seen_quantities.add(name)

        codes = [u["code"] for u in q["units"]]

        # 1. The canonical unit must actually exist, or every conversion for
        #    this quantity divides by a unit that is not there.
        if q["canonical"] not in codes:
            fail(f"[ref:units] {name}: canonical '{q['canonical']}' is not one of its units")
            continue

        canon = next(u for u in q["units"] if u["code"] == q["canonical"])

        # 2. The canonical unit must be the identity. A factor of anything but
        #    1.0 means converting a value to its OWN unit changes it.
        if canon["factor"] != 1.0:
            fail(f"[ref:units] {name}: canonical {canon['code']} has factor "
                 f"{canon['factor']}, must be exactly 1.0")
        if canon.get("offset", 0) != 0:
            fail(f"[ref:units] {name}: canonical {canon['code']} has a non-zero offset")

        # 3. No duplicate codes, and no alias that collides with any code or
        #    with another alias. A collision makes resolution order decide the
        #    answer, which is how 'bar' quietly becomes 'bara'.
        dupes = {c for c in codes if codes.count(c) > 1}
        if dupes:
            fail(f"[ref:units] {name}: duplicate unit codes {sorted(dupes)}")

        lowered = {}
        for u in q["units"]:
            for spelling in [u["code"], *u.get("aliases", [])]:
                key = spelling.lower().replace(" ", "").replace("-", "").replace("_", "")
                if key in lowered and lowered[key] != u["code"]:
                    fail(f"[ref:units] {name}: '{spelling}' resolves to both "
                         f"{lowered[key]} and {u['code']}")
                lowered[key] = u["code"]

        # 4. An offset outside TEMPERATURE is almost certainly a mistake: no
        #    other engineering quantity here has a shifted zero.
        for u in q["units"]:
            if u.get("offset", 0) != 0 and name != "TEMPERATURE":
                fail(f"[ref:units] {name}: unit {u['code']} has an offset, but only "
                     f"temperature is affine - check this is not a typo")

        # 5. Datum is a pressure concept. Marking a length GAUGE would make the
        #    converter refuse conversions for no reason.
        for u in q["units"]:
            if u.get("datum", "NONE") != "NONE" and name != "PRESSURE":
                fail(f"[ref:units] {name}: unit {u['code']} declares datum "
                     f"{u['datum']}, but only pressure has a datum")

    # 5b. A quantity must offer at least one selectable unit, and its default
    #     display unit must be one of them. Retiring units is a pack edit, so
    #     it is entirely possible to retire the very unit the screen falls back
    #     to - which renders a dropdown whose current value is not in the list.
    for q in units["quantities"]:
        name = q["quantity"]
        codes = [u["code"] for u in q["units"]]
        selectable = [u["code"] for u in q["units"] if u.get("selectable", True)]

        if not selectable:
            fail(f"[ref:units] {name}: every unit is marked unselectable - "
                 f"the user would face an empty dropdown")
            continue

        default = q.get("default_display", q["canonical"])
        if default not in codes:
            fail(f"[ref:units] {name}: default_display '{default}' is not one of its units")
        elif default not in selectable:
            fail(f"[ref:units] {name}: default_display '{default}' is not selectable - "
                 f"an unconfigured project would show a unit the user cannot choose "
                 f"(selectable: {selectable})")

        # The canonical unit may be unselectable - that is the point of
        # separating storage from display - but it must still exist.
        if q["canonical"] not in codes:
            fail(f"[ref:units] {name}: canonical '{q['canonical']}' is not one of its units")

    # 5c. The user decision of 2026-09-17: pressure is offered as bar or psi,
    #     temperature as C or F. Encoded here so that widening the menu is a
    #     deliberate edit to this file rather than an accident in the pack.
    expected_menu = {
        "PRESSURE": {"bar", "psi"},
        "TEMPERATURE": {"C", "F"},
    }
    for name, want in expected_menu.items():
        q = next((x for x in units["quantities"] if x["quantity"] == name), None)
        if not q:
            continue
        got = {u["code"] for u in q["units"] if u.get("selectable", True)}
        if got != want:
            fail(f"[ref:units] {name} offers {sorted(got)}, but the agreed menu is "
                 f"{sorted(want)} - update validate_packs.py if this was intended")

    # 5d. A retired unit must still CONVERT. The whole reason for retiring via
    #     a flag rather than deletion is that stored and imported values in
    #     that unit stay readable.
    for q in units["quantities"]:
        for u in q["units"]:
            if u.get("selectable", True) is False and "factor" not in u:
                fail(f"[ref:units] {q['quantity']}: retired unit {u['code']} has no factor - "
                     f"historic values in it could not be read")

    # 6. The quantities the application names in code must exist. Renaming one
    #    in the pack without renaming it in PHP fails here rather than at the
    #    moment a user opens the units screen.
    required = {"PRESSURE", "TEMPERATURE", "LENGTH", "MASS", "FLOW", "WEIGHT_PER_LENGTH"}
    missing = required - seen_quantities
    if missing:
        fail(f"[ref:units] missing quantities referenced by App\\Support\\Units\\Quantity: "
             f"{sorted(missing)}")

    # 7. Temperature must be affine and floored. Both were real bugs during
    #    development: a multiply-only converter is wrong by 32 degrees
    #    everywhere, and without a floor -500 F stores as a valid temperature.
    temp = next((q for q in units["quantities"] if q["quantity"] == "TEMPERATURE"), None)
    if temp:
        if "minimum_canonical" not in temp:
            fail("[ref:units] TEMPERATURE has no minimum_canonical - absolute zero is unguarded")
        elif abs(temp["minimum_canonical"] + 273.15) > 1e-9:
            fail(f"[ref:units] TEMPERATURE floor is {temp['minimum_canonical']}, expected -273.15")

        f = next((u for u in temp["units"] if u["code"] == "F"), None)
        if f is None:
            fail("[ref:units] TEMPERATURE has no Fahrenheit unit")
        else:
            got = 100 * f["factor"] + f.get("offset", 0)
            if abs(got - 37.77777777777778) > 1e-9:
                fail(f"[ref:units] 100 F converts to {got} C, expected 37.7777...")

        k = next((u for u in temp["units"] if u["code"] == "K"), None)
        if k and abs((0 * k["factor"] + k.get("offset", 0)) + 273.15) > 1e-9:
            fail("[ref:units] 0 K does not convert to -273.15 C")

    # 8. Anchor conversions, spot-checked against published values. These are
    #    the numbers a piping engineer would recognise on sight.
    anchors = [
        ("PRESSURE", "psi", 285, 1965.0058277, 1e-6),
        ("PRESSURE", "bar", 1, 100.0, 1e-9),
        ("PRESSURE", "kg/cm2", 1, 98.0665, 1e-9),
        ("LENGTH", "in", 1, 25.4, 1e-9),
        ("LENGTH", "ft", 1, 304.8, 1e-9),
        ("MASS", "lb", 1, 0.45359237, 1e-9),
    ]
    for qname, code, value, expected, tol in anchors:
        q = next((x for x in units["quantities"] if x["quantity"] == qname), None)
        if not q:
            continue
        u = next((x for x in q["units"] if x["code"] == code), None)
        if not u:
            fail(f"[ref:units] {qname} has no unit '{code}' - anchor cannot be checked")
            continue
        got = value * u["factor"] + u.get("offset", 0)
        if abs(got - expected) > tol:
            fail(f"[ref:units] {value} {code} = {got}, expected {expected}")

    total = sum(len(q["units"]) for q in units["quantities"])
    notes.append(f"[ref:units] {len(units['quantities'])} quantities, {total} units, "
                 f"anchors and absolute zero verified")


# --------------------------------------------------------------- main ------

def check_valve_construction(refs: dict) -> None:
    """
    How a manual valve is built. ADR-008 section 11.9.

    Every rule here guards a way this pack could quietly contradict a pack that
    already exists, because three packs now describe valves and the failure
    mode is that they stop agreeing.
    """
    pack = refs.get("valve-construction")
    if pack is None:
        fail("[ref:valve-construction] pack not loaded")
        return

    templates = refs.get("component-templates") or {}
    valve_qualifiers = set()
    for t in templates.get("templates", []):
        if t["code"] == "VALVE":
            valve_qualifiers = set(t.get("qualifiers", []))

    sizes = refs.get("pipe-sizes") or {}
    size_rows = sizes.get("sizes", [])
    known_nps = set()
    for s in size_rows:
        for key in ("nps_decimal", "npsDecimal", "nps_dec"):
            if key in s:
                known_nps.add(float(s[key]))
                break

    materials = refs.get("materials") or {}
    known_groups = {g["code"] for g in materials.get("groups", [])}

    codes = set()
    for std in pack["standards"]:
        code = std["code"]

        # 1. No duplicate standards. Two entries for API 600 would make
        #    "which valve types does API 600 cover" answer twice.
        if code in codes:
            fail(f"[ref:valve-construction] {code} appears twice")
        codes.add(code)

        # 2. A standard may only name valve types the VALVE template offers.
        #    The same discipline the trim group and the actuator pack are held
        #    to: a reference pack does not get to invent valve types.
        #    RELAXED 2026-09-25 for a DECLARED gap only. API 6D's 25th edition
        #    added AXIAL valves and nothing else in this repo knows the type.
        #    Truncating the standard to fit our component vocabulary would make
        #    our gap look like the standard's scope; widening the VALVE
        #    template as a side effect of reading a document would change the
        #    PMS body, the MTO and every dropdown that reads it. So the
        #    standard says what it covers and the gap is declared.
        declared_types = (std.get("valveTypesBeyondTemplate") or {}).get("types", [])
        for vt in std["valveTypes"]:
            if vt in declared_types:
                notes.append(f"[ref:valve-construction] {code} covers {vt}, a valve "
                             f"type the VALVE template does not offer - declared")
                continue
            if valve_qualifiers and vt not in valve_qualifiers:
                fail(f"[ref:valve-construction] {code} names valve type {vt}, "
                     f"which the VALVE template does not offer")

        # 3. bodyGroups must be real material-library groups, and must be
        #    groups a valve BODY can actually be made from. The standing
        #    constraint is that castings are body material only, and a forged
        #    small-bore body is the other half of that pair - nothing else is
        #    a valve body.
        for g in (std["bodyGroups"] or []):
            if known_groups and g not in known_groups:
                fail(f"[ref:valve-construction] {code} names body group {g}, "
                     f"which the material library does not have")
            elif g not in ("CASTINGS", "FORGINGS"):
                fail(f"[ref:valve-construction] {code} names body group {g}; "
                     f"a valve body is cast or forged and nothing else")

        # 4. A size scope must be a size the platform can draw, or the scope
        #    can never be evaluated against a real row.
        #    A standard may legitimately reach PAST our size vocabulary - API
        #    609 runs to NPS 60 and pipe-sizes stops at 48. Declaring that
        #    keeps the gap visible (ours, not the standard's) instead of
        #    truncating the standard to fit the pack.
        declared_over = std.get("npsMaxBeyondPipeSizes", {}).get("value", False)
        for key in ("npsMin", "npsMax"):
            v = std[key]
            if v is None or not known_nps or float(v) in known_nps:
                continue
            if key == "npsMax" and declared_over and float(v) > max(known_nps):
                notes.append(f"[ref:valve-construction] {code} reaches NPS {v}, "
                             f"past the largest size pipe-sizes holds "
                             f"(NPS {max(known_nps):g}) - declared")
                continue
            fail(f"[ref:valve-construction] {code} {key} = {v} is not an "
                 f"NPS in the pipe-sizes pack")

        # 5. An empty window covers nothing and would silently reject every row.
        if std["npsMin"] is not None and std["npsMax"] is not None \
                and float(std["npsMin"]) > float(std["npsMax"]):
            fail(f"[ref:valve-construction] {code} has npsMin > npsMax")

        # 6. NOTHING HERE IS VERIFIED, AND THAT MUST STAY VISIBLE. None of
        #    these standards is in the repo. The allowable-stress round set the
        #    precedent: unverified data is held and reported, never cited. If a
        #    future round buys API 600, flipping this flag is the deliberate act
        #    that makes its findings citable.
        if not isinstance(std["verified"], bool):
            fail(f"[ref:valve-construction] {code} has a non-boolean verified")

    # 7. Every valve type the template offers should be reachable, or a
    #    transcriber has no standard to name. NEEDLE and DIAPHRAGM are the
    #    known gap - they have no construction standard here and no trim chart
    #    in the material library either, which is consistent and worth stating
    #    out loud rather than being discovered from an empty dropdown.
    covered = {vt for std in pack["standards"] for vt in std["valveTypes"]}
    uncovered = sorted(valve_qualifiers - covered)
    if uncovered and uncovered != ["DIAPHRAGM", "NEEDLE"]:
        fail(f"[ref:valve-construction] valve types with no construction "
             f"standard: {uncovered}; expected exactly DIAPHRAGM and NEEDLE")

    n_ver = sum(1 for s in pack["standards"] if s["verified"])
    notes.append(f"[ref:valve-construction] {len(pack['standards'])} standard(s), "
                 f"{n_ver} verified / {len(pack['standards']) - n_ver} not, "
                 f"covering {len(covered)} of {len(valve_qualifiers)} valve types")


def check_valve_construction_sources(refs: dict) -> None:
    """
    Owning a standard is not the same as being able to read it.

    API 600-2021 arrived as a 109 dpi scan: the prose OCRs cleanly and the
    numeric tables do not. `sourceQuality` records that, so "verified" never
    quietly comes to mean "we have a PDF of it somewhere".
    """
    pack = refs.get("valve-construction")
    if pack is None:
        return

    for s in pack["standards"]:
        q = s.get("sourceQuality", "NOT_HELD")

        if s["verified"] and q == "NOT_HELD":
            fail(f"[ref:valve-construction] {s['code']} is verified but its "
                 f"source quality says the standard is not held")
        if s["verified"] and not s.get("verifiedFrom"):
            fail(f"[ref:valve-construction] {s['code']} is verified without "
                 f"naming the clause or table it was verified from")
        if not s["verified"] and q != "NOT_HELD" and "tablesNotTranscribed" not in s:
            fail(f"[ref:valve-construction] {s['code']} is held ({q}) but not "
                 f"verified and does not say what is still missing")

        # A scan can verify prose and must declare what it could NOT give.
        if q == "SCANNED_IMAGE" and "tablesNotTranscribed" not in s:
            fail(f"[ref:valve-construction] {s['code']} came from a scan but "
                 f"does not record which tables were left untranscribed")

        # A correction must actually differ from what it replaced.
        for field, c in (s.get("correctedFromGuess") or {}).items():
            if c["was"] == c["now"]:
                fail(f"[ref:valve-construction] {s['code']} records a "
                     f"correction to {field} that changed nothing")
            if field not in s:
                fail(f"[ref:valve-construction] {s['code']} corrects {field}, "
                     f"which the entry no longer has")
            elif s[field] != c["now"]:
                fail(f"[ref:valve-construction] {s['code']} says {field} was "
                     f"corrected to {c['now']} but holds {s[field]}")

    held = [s["code"] for s in pack["standards"]
            if s.get("sourceQuality", "NOT_HELD") != "NOT_HELD"]
    corrected = sum(len(s.get("correctedFromGuess") or {})
                    for s in pack["standards"])
    notes.append(f"[ref:valve-construction] {len(held)} standard(s) held "
                 f"({', '.join(held) or 'none'}), {corrected} guess(es) "
                 f"corrected on contact with a purchased copy")


def check_valve_pt_ratings(refs: dict) -> None:
    """
    ASME B16.34 valve ratings. ADR-008 section 11.9.

    The thing this guards: a VALVE is not rated by a FLANGE standard. Every
    rule below is a way the two packs could silently start answering each
    other's questions.
    """
    pack = refs.get("valve-pt-ratings")
    if pack is None:
        fail("[ref:valve-pt-ratings] pack not loaded")
        return

    classes = pack["standard"]["pressureClasses"]
    n_groups = pack["standard"]["materialGroupCount"]

    # 1. CLASS 400 IS NOT IN B16.34, AND CLASS 4500 IS NOT IN ANY FLANGE
    #    STANDARD HERE. If either of those ever stops being true the two packs
    #    have been crossed.
    if "400" in classes:
        fail("[ref:valve-pt-ratings] class 400 is present; B16.34 does not "
             "tabulate it and a consumer relying on that will mis-rate a valve")
    if "4500" not in classes:
        fail("[ref:valve-pt-ratings] class 4500 is missing; it is the class "
             "only B16.34 carries")

    # 2. Every group must carry BOTH tabulated rating classes. A group with
    #    only Standard would silently make Special unanswerable.
    seen = {}
    for c in pack["curves"]:
        seen.setdefault(c["materialGroup"], set()).add(c["ratingClass"])
    for g, kinds in sorted(seen.items()):
        if kinds != {"STANDARD", "SPECIAL"}:
            fail(f"[ref:valve-pt-ratings] group {g} has {sorted(kinds)}, "
                 f"expected both STANDARD and SPECIAL")
    if len(seen) != n_groups:
        fail(f"[ref:valve-pt-ratings] {len(seen)} groups present but the "
             f"standard block declares {n_groups}")

    anomaly_cells = {(a["materialGroup"], a["ratingClass"], a["temperatureC"],
                      a["pressureClass"]) for a in pack.get("anomalies", [])}

    for c in pack["curves"]:
        g, rc = c["materialGroup"], c["ratingClass"]

        # 3. Every point must price every class the standard declares. A row
        #    that lost a column to a page break parses cleanly and is wrong.
        for p in c["points"]:
            missing = [k for k in classes if k not in p["byClass"]]
            if missing:
                fail(f"[ref:valve-pt-ratings] group {g} {rc} at "
                     f"{p['temperatureC']} C is missing class(es) {missing}")

        # 4. Pressure falls as temperature rises. Any rise is either a real
        #    quirk of the standard or a transcription fault, and both must be
        #    looked at - so anything not already declared as an anomaly fails.
        pts = sorted(c["points"], key=lambda p: p["temperatureC"])
        for prev, cur in zip(pts, pts[1:]):
            for k in classes:
                if k in prev["byClass"] and k in cur["byClass"]:
                    if cur["byClass"][k] > prev["byClass"][k]:
                        if (g, rc, cur["temperatureC"], k) in anomaly_cells or \
                           (g, rc, prev["temperatureC"], k) in anomaly_cells:
                            continue
                        fail(f"[ref:valve-pt-ratings] group {g} {rc} class {k} "
                             f"RISES from {prev['temperatureC']} C to "
                             f"{cur['temperatureC']} C")

        # 5. Within one row, a higher pressure class never prices lower. This
        #    is what caught the two transposed digits.
        for p in c["points"]:
            vals = [(int(k), v) for k, v in p["byClass"].items()]
            vals.sort()
            for (ka, va), (kb, vb) in zip(vals, vals[1:]):
                if vb < va and (g, rc, p["temperatureC"], str(kb)) not in anomaly_cells \
                        and (g, rc, p["temperatureC"], str(ka)) not in anomaly_cells:
                    fail(f"[ref:valve-pt-ratings] group {g} {rc} at "
                         f"{p['temperatureC']} C: class {kb} ({vb}) prices below "
                         f"class {ka} ({va})")

        # 6. A note reference on a material must resolve to a note on its own
        #    curve, or the reader is sent to a footnote that is not there.
        note_refs = {n["ref"] for n in c.get("notes", [])}
        for m in c["materials"]:
            for r in m["noteRefs"]:
                if r not in note_refs:
                    fail(f"[ref:valve-pt-ratings] group {g} {rc}: material "
                         f"{m['spec']} cites note ({r}), which the table lacks")

    # 7. An anomaly must be a cell that actually exists, must carry its
    #    corroborated value, and must be marked uncitable. A declared anomaly
    #    nobody can act on is decoration.
    cells = {(c["materialGroup"], c["ratingClass"], p["temperatureC"], k): v
             for c in pack["curves"] for p in c["points"]
             for k, v in p["byClass"].items()}
    for a in pack.get("anomalies", []):
        key = (a["materialGroup"], a["ratingClass"], a["temperatureC"],
               a["pressureClass"])
        if key not in cells:
            fail(f"[ref:valve-pt-ratings] anomaly names a cell that is not in "
                 f"the pack: {key}")
        elif cells[key] != a["printedKpa"]:
            fail(f"[ref:valve-pt-ratings] anomaly for {key} says the table "
                 f"prints {a['printedKpa']} but it prints {cells[key]}")
        if not a["doNotCite"]:
            fail(f"[ref:valve-pt-ratings] anomaly for {key} is citable; a cell "
                 f"the standard prints wrongly must not be quoted to a vendor")

    # 8. THE CROSS-STANDARD CLAIM. B16.34 Standard Class is supposed to
    #    reproduce the flange ratings for the same group. Check it here rather
    #    than trusting the number the builder wrote into the pack.
    flange = refs.get("flange-pt-ratings")
    if flange is not None:
        b165 = {c["materialGroup"]: {p["temperatureC"]: p["byClass"]
                                     for p in c["points"]}
                for c in flange["curves"] if c["standard"] == "B16.5"}
        std = {c["materialGroup"]: {p["temperatureC"]: p["byClass"]
                                    for p in c["points"]}
               for c in pack["curves"] if c["ratingClass"] == "STANDARD"}
        declared = {(d["materialGroup"], d["temperatureC"], d["pressureClass"])
                    for d in pack.get("crossStandardDiscrepancies", [])}
        n_cells = n_diff = 0
        for g in set(std) & set(b165):
            for t, byc in std[g].items():
                if t not in b165[g]:
                    continue
                for k, v in byc.items():
                    if k not in b165[g][t]:
                        continue
                    n_cells += 1
                    if b165[g][t][k] != v:
                        n_diff += 1
                        if (g, t, k) not in declared:
                            fail(f"[ref:valve-pt-ratings] group {g} at {t} C "
                                 f"class {k} differs from B16.5 "
                                 f"({v} vs {b165[g][t][k]}) and is NOT declared")
        stale = len(declared) - n_diff
        if stale > 0:
            fail(f"[ref:valve-pt-ratings] {stale} declared cross-standard "
                 f"discrepancy/ies no longer differ; a stale declaration hides "
                 f"the next real one")
        notes.append(f"[ref:valve-pt-ratings] B16.34 Standard Class vs B16.5: "
                     f"{n_cells - n_diff}/{n_cells} cells agree, {n_diff} declared")

    n_pts = sum(len(c["points"]) for c in pack["curves"])
    n_ver = sum(1 for c in pack["curves"] if c["verified"])
    notes.append(f"[ref:valve-pt-ratings] {len(pack['curves'])} curve(s) over "
                 f"{len(seen)} material group(s), {n_ver} verified, {n_pts} "
                 f"temperature points, {len(pack.get('anomalies', []))} "
                 f"anomaly/ies withheld from citation")


def check_valve_face_to_face(refs: dict) -> None:
    """
    ASME B16.10 valve lengths. ADR-008 section 11.9.2.

    The rules below are all about one risk: the column map in the builder was
    read off five lines of centred text, and a single mis-read column would put
    a cast-iron length on a Class 900 gate valve with nothing to show for it.
    """
    pack = refs.get("valve-face-to-face")
    if pack is None:
        fail("[ref:valve-face-to-face] pack not loaded")
        return

    dims = pack["dimensions"]

    # 1. STEEL ONLY. Cast iron is deliberately out of scope; if a cast-iron
    #    column is ever let in, the rating pack has nothing to pair it with.
    if pack["standard"]["materialsCovered"] != ["STEEL"]:
        fail("[ref:valve-face-to-face] materialsCovered is not STEEL only")

    # 2. Every pressure class must be one ASME B16.34 rates a valve at. The two
    #    packs have to be able to talk about the same valve.
    ratings = refs.get("valve-pt-ratings")
    if ratings is not None:
        valve_classes = set(ratings["standard"]["pressureClasses"])
        seen = {d["pressureClass"] for d in dims if d["pressureClass"]}
        stray = sorted(seen - valve_classes, key=int)
        if stray:
            fail(f"[ref:valve-face-to-face] class(es) {stray} are dimensioned "
                 f"here but not rated by B16.34")
        # 4500 has dimensions nowhere: worth saying out loud rather than
        # discovering it when a spool will not close.
        missing = sorted(valve_classes - seen, key=int)
        if missing:
            notes.append(f"[ref:valve-face-to-face] class(es) {missing} are rated "
                         f"by B16.34 but B16.10 gives them no length")

    # 3. A symbol implies an end type. A is a flanged face-to-face and B is a
    #    buttwelding end-to-end; crossing them silently swaps two dimensions
    #    that differ by the thickness of two flanges.
    allowed = {"A": {"RF", "WAFER", "LUG_WAFER", "GROOVED", "FF"},
               "B": {"BW"}, "D": {"RF"}, "E": {"BW"}}
    for d in dims:
        if d["endType"] not in allowed[d["symbol"]]:
            fail(f"[ref:valve-face-to-face] symbol {d['symbol']} with end type "
                 f"{d['endType']} ({d['sourceTable']} col {d['column']})")

    # 4. Millimetres and inches must agree to the rounding the standard uses.
    #    Three printed pairs do not. They are declared, and a declaration that
    #    stops matching the data is as bad as no declaration at all.
    anomalies = pack.get("anomalies", {})
    declared_units = {(a["sourceTable"], a["column"], a["dn"], a["symbol"])
                      for a in anomalies.get("unitPairsThatDoNotConvert", [])}
    found_units = set()
    for d in dims:
        if abs(d["mm"] - d["in"] * 25.4) > 1.0:
            key = (d["sourceTable"], d["column"], d["dn"], d["symbol"])
            found_units.add(key)
            if key not in declared_units:
                fail(f"[ref:valve-face-to-face] {d['mm']} mm is not {d['in']} in "
                     f"({d['sourceTable']} col {d['column']}, DN {d['dn']}) and is "
                     f"NOT declared")
    for key in sorted(declared_units - found_units):
        fail(f"[ref:valve-face-to-face] stale unit-pair declaration {key}")

    # 5. A valve gets longer as it gets bigger, and as the class goes up. Both
    #    are true throughout B16.10 and both catch a shifted column.
    by_col = {}
    for d in dims:
        by_col.setdefault((d["sourceTable"], d["column"], d["symbol"]), []).append(d)
    declared_mono = {(m["sourceTable"], m["column"], m["symbol"], m["toNps"])
                     for m in anomalies.get("lengthFallsWithSize", [])}
    found_mono = set()
    for (tbl, col, sym), rows in sorted(by_col.items()):
        rows.sort(key=lambda r: r["npsDecimal"])
        for a, b in zip(rows, rows[1:]):
            if b["mm"] < a["mm"]:
                key = (tbl, col, sym, b["nps"])
                found_mono.add(key)
                if key not in declared_mono:
                    fail(f"[ref:valve-face-to-face] {tbl} col {col} symbol {sym} "
                         f"shortens from NPS {a['nps']} ({a['mm']}) to NPS "
                         f"{b['nps']} ({b['mm']}) and is NOT declared")
    for key in sorted(declared_mono - found_mono):
        fail(f"[ref:valve-face-to-face] stale length-falls declaration {key}")

    # 6. THE CROSS-CLASS CHECK. A gate valve of one size never gets shorter as
    #    the class rises - the body has to get thicker. This is the rule that
    #    would catch a whole table mapped to the wrong class.
    gates = {}
    for d in dims:
        if "GATE" in d["valveTypes"] and d["symbol"] == "A" \
                and d["pattern"] in ("LONG", None) and d["pressureClass"]:
            gates.setdefault(d["nps"], {})[d["pressureClass"]] = d["mm"]
    order = ["150", "300", "600", "900", "1500", "2500"]
    cross = 0
    for nps, byclass in sorted(gates.items()):
        seq = [(c, byclass[c]) for c in order if c in byclass]
        for (ca, va), (cb, vb) in zip(seq, seq[1:]):
            if vb < va:
                # Class 900 at DN 65 and below carries CLASS 1500 dimensions by
                # note (2) of Table 1.5-2, so 900 is longer than 1500 there by
                # design. Anywhere else a gate valve shortening with class means
                # a table has been mapped to the wrong class.
                if (ca, cb) == ("900", "1500"):
                    cross += 1
                    continue
                fail(f"[ref:valve-face-to-face] NPS {nps} gate valve is SHORTER "
                     f"in class {cb} ({vb} mm) than in class {ca} ({va} mm)")
    if cross:
        notes.append(f"[ref:valve-face-to-face] {cross} size(s) where class 900 "
                     f"is longer than class 1500 - note (2) of Table 1.5-2")

    # 7. DN and NPS must agree with the pipe-size pack the rest of the platform
    #    uses. Two size vocabularies is one too many.
    sizes = refs.get("pipe-sizes")
    if sizes is not None:
        known = {s["dn"]: s["nps"] for s in sizes["sizes"]}
        bad = set()
        for d in dims:
            if d["dn"] in known and known[d["dn"]] != d["nps"]:
                bad.add((d["dn"], d["nps"], known[d["dn"]]))
        for dn, here, there in sorted(bad):
            fail(f"[ref:valve-face-to-face] DN {dn} is NPS {here} here and "
                 f"NPS {there} in pipe-sizes")

    # 8. A ring joint X dimension is an ADJUSTMENT, not a length. Anything the
    #    size of a valve in that list means the two tables have been confused.
    for r in pack.get("ringJoint", []):
        if abs(r["mm"]) > 60:
            fail(f"[ref:valve-face-to-face] ring joint {r['symbol']} of "
                 f"{r['mm']} mm at DN {r['dn']} class {r['pressureClass']} is "
                 f"too large to be an adjustment")

    n_rj = len(pack.get("ringJoint", []))
    classes = sorted({d["pressureClass"] for d in dims if d["pressureClass"]}, key=int)
    types = sorted({t for d in dims for t in d["valveTypes"]})
    notes.append(f"[ref:valve-face-to-face] {len(dims)} dimension(s) over "
                 f"class(es) {'/'.join(classes)} and {len(types)} valve type(s), "
                 f"{n_rj} ring joint adjustment(s), "
                 f"{len(pack.get('facingAdjustments', []))} facing rule(s)")


def check_valve_testing(refs: dict) -> None:
    """
    API 598 valve inspection and testing. ADR-008 section 11.9.3 / ADR-015.

    This is the first pack that feeds the ITP rather than a drawing, so the
    rules below guard the things an inspector would be sent to site on.
    """
    pack = refs.get("valve-testing")
    if pack is None:
        fail("[ref:valve-testing] pack not loaded")
        return

    cols = pack["columns"]
    reqs = pack["requirements"]

    # 1. Every requirement must name a column that exists. A typo here silently
    #    removes a test from a valve type.
    for r in reqs:
        if r["column"] not in cols:
            fail(f"[ref:valve-testing] requirement names unknown column "
                 f"{r['column']!r}")

    # 2. Every column must be answered for every test, in every band. A gap is
    #    indistinguishable from "no test needed" at the point of use.
    tests = {r["test"] for r in reqs}
    for test in sorted(tests):
        bands = {(r["sizeBand"]["label"], r["classBand"]["label"])
                 for r in reqs if r["test"] == test}
        for band in sorted(bands):
            for col in cols:
                hit = [r for r in reqs
                       if r["test"] == test and r["column"] == col
                       and (r["sizeBand"]["label"], r["classBand"]["label"]) == band]
                if len(hit) != 1:
                    fail(f"[ref:valve-testing] {test} / {col} / {band} has "
                         f"{len(hit)} entries, expected exactly 1")

    # 3. THE SHELL TEST IS NEVER OPTIONAL. If this ever parses as optional the
    #    pack has been corrupted in a way no downstream test would notice.
    for r in reqs:
        if r["test"] == "SHELL" and r["requirement"] != "REQUIRED":
            fail(f"[ref:valve-testing] shell test is {r['requirement']} for "
                 f"{r['column']}; API 598 requires it on every valve")

    # 4. The high- and low-pressure closure rows invert. Wherever low is
    #    optional, high is required, and the other way about - that is the
    #    structure of Table 1, and it is worth asserting because a
    #    copy-paste between the two rows would otherwise be invisible.
    def at(test, col, size, klass):
        for r in reqs:
            if r["test"] == test and r["column"] == col \
                    and r["sizeBand"]["label"] == size \
                    and r["classBand"]["label"] == klass:
                return r["requirement"]
        return None

    inverted = same = 0
    for col in cols:
        for r in [x for x in reqs if x["test"] == "LOW_PRESSURE_CLOSURE"
                  and x["column"] == col]:
            hi = at("HIGH_PRESSURE_CLOSURE", col,
                    r["sizeBand"]["label"], r["classBand"]["label"])
            if hi is None:
                continue
            if {hi, r["requirement"]} == {"REQUIRED", "OPTIONAL"}:
                inverted += 1
            else:
                same += 1
    if inverted == 0:
        fail("[ref:valve-testing] no valve type inverts between the low- and "
             "high-pressure closure rows; Table 1 has been flattened")

    # 5. Duration bands must tile the size range without a gap or an overlap.
    #    A valve that falls between two bands has no test duration at all.
    bands = sorted(pack["duration"], key=lambda b: b["dnFrom"] or 0)
    if bands[0]["dnFrom"] is not None:
        fail("[ref:valve-testing] the smallest duration band has a lower bound")
    if bands[-1]["dnTo"] is not None:
        fail("[ref:valve-testing] the largest duration band has an upper bound")
    for a, b in zip(bands, bands[1:]):
        if a["dnTo"] is None or b["dnFrom"] is None:
            continue
        if b["dnFrom"] <= a["dnTo"]:
            fail(f"[ref:valve-testing] duration bands overlap at DN {b['dnFrom']}")

    # 6. Duration never falls as the valve gets bigger.
    for key in ("shell", "backseat", "closureCheck", "closureOther"):
        seq = [b[key] for b in bands]
        if seq != sorted(seq):
            fail(f"[ref:valve-testing] {key} duration falls with size: {seq}")

    # 7. Leakage must rise with size and a resilient seat must always be zero -
    #    that is the definition of the seat type, not a tabulated figure.
    rows = sorted(pack["leakage"], key=lambda r: r["dn"])
    for r in rows:
        if r["resilientSeated"] != 0:
            fail(f"[ref:valve-testing] resilient seat at DN {r['dn']} allows "
                 f"{r['resilientSeated']}; it must be zero")
    for key in ("metalLiquidDropsPerMin", "metalGasBubblesPerMin",
                "checkLiquidMlPerMin", "checkGasM3PerH"):
        seq = [r[key] for r in rows]
        if seq != sorted(seq):
            fail(f"[ref:valve-testing] {key} does not rise with size")

    # 8. The published proportions: liquid 1 mL = 16 drops, gas 1 mL = 100
    #    bubbles (Table 5 note a). Checks the two unit columns against
    #    each other rather than trusting each on its own.
    for r in rows:
        if r["dn"] == 50:
            continue
        if abs(r["metalLiquidDropsPerMin"] / 16 - r["metalLiquidMlPerMin"]) > 0.02:
            fail(f"[ref:valve-testing] DN {r['dn']}: "
                 f"{r['metalLiquidDropsPerMin']} drops/min is not "
                 f"{r['metalLiquidMlPerMin']} mL/min at 16 drops per mL")
        if abs(r["metalGasBubblesPerMin"] / 100 - r["metalGasMlPerMin"]) > 0.02:
            fail(f"[ref:valve-testing] DN {r['dn']}: gas columns disagree at "
                 f"100 bubbles per mL")

    # 9. Sizes must exist in the platform's own size vocabulary.
    sizes = refs.get("pipe-sizes")
    if sizes is not None:
        known = {s["dn"]: s["nps"] for s in sizes["sizes"]}
        for r in rows:
            if r["dn"] in known and known[r["dn"]] != r["nps"]:
                fail(f"[ref:valve-testing] DN {r['dn']} is NPS {r['nps']} here "
                     f"and NPS {known[r['dn']]} in pipe-sizes")

    # 10. The shell test pressure for steel is a RULE, and the rule needs the
    #     B16.34 ratings to be resolvable. If that pack is missing, say so here
    #     rather than letting a consumer discover it.
    if refs.get("valve-pt-ratings") is None:
        fail("[ref:valve-testing] valve-pt-ratings is absent; the steel shell "
             "test pressure is 1.5x the B16.34 rating and cannot be computed")

    n_var = len({(c["valveType"], c["variant"]) for c in cols.values()
                 if c["variant"]})
    notes.append(f"[ref:valve-testing] {len(reqs)} requirement(s) over "
                 f"{len(cols)} column(s), {len(rows)} leakage row(s), "
                 f"{len(bands)} duration band(s), {n_var} valve variant(s) the "
                 f"PMS does not yet record")


def check_fluid_service(refs: dict) -> None:
    """
    ASME B31.3 fluid services. ADR-008 section 11.2.

    The rule this pack exists to enforce is the one the platform got wrong: a
    CONDITION is not a SERVICE. Everything below defends that boundary.
    """
    pack = refs.get("fluid-service")
    if pack is None:
        fail("[ref:fluid-service] pack not loaded")
        return

    services = {s["code"] for s in pack["fluidServices"]}
    conditions = {c["code"] for c in pack["conditions"]}

    # 1. NOTHING may be both. This is the whole point.
    both = services & conditions
    if both:
        fail(f"[ref:fluid-service] {sorted(both)} appear as both a fluid service "
             f"and a condition; that conflation is the defect this pack fixes")

    # 2. SEVERE_CYCLIC must be a condition and must NOT be a service. It was a
    #    service in the piping class header for five rounds.
    if "SEVERE_CYCLIC" in services:
        fail("[ref:fluid-service] SEVERE_CYCLIC is listed as a fluid service; "
             "B31.3 300.2 defines it as a condition the owner designates")
    if "SEVERE_CYCLIC" not in conditions:
        fail("[ref:fluid-service] SEVERE_CYCLIC is missing from conditions")

    # 3. A service claiming to be testable must carry the numbers to test with,
    #    and one that is not must not pretend to.
    for s in pack["fluidServices"]:
        if s["definitionIsTestable"] and not s["bounds"]:
            fail(f"[ref:fluid-service] {s['code']} says its definition is testable "
                 f"but carries no bounds")
        if not s["definitionIsTestable"] and s["bounds"]:
            fail(f"[ref:fluid-service] {s['code']} carries bounds but its "
                 f"definition is a judgement; numbers there would be invented")

    # 4. Category D's bounds must agree across unit systems - 1035 kPa is 150
    #    psi and 186 C is 366 F, and a transcription slip in either is silent.
    d = next((s for s in pack["fluidServices"] if s["code"] == "CATEGORY_D"), None)
    if d and d["bounds"]:
        b = d["bounds"]
        for kpa, psi, what in [(b["maxDesignGaugePressureKpa"],
                                b["maxDesignGaugePressurePsi"], "pressure")]:
            if abs(kpa / 6.894757 - psi) > 1.0:
                fail(f"[ref:fluid-service] Category D {what}: {kpa} kPa is not "
                     f"{psi} psi")
        for c, f_, what in [(b["maxDesignTemperatureC"], b["maxDesignTemperatureF"], "max temp"),
                            (b["minFluidTemperatureC"], b["minFluidTemperatureF"], "min temp")]:
            if abs(c * 9 / 5 + 32 - f_) > 1.0:
                fail(f"[ref:fluid-service] Category D {what}: {c} C is not {f_} F")

    # 5. Every examination extent must point at a service or a condition that
    #    exists, or an ITP row will be generated for nothing.
    for e in pack["examinationExtent"]:
        if e["appliesTo"] not in services | conditions:
            fail(f"[ref:fluid-service] examination extent for "
                 f"{e['appliesTo']!r} names no known service or condition")
        if e["verified"] and not e.get("readOn"):
            fail(f"[ref:fluid-service] extent for {e['appliesTo']} is verified "
                 f"without a date read")

    # 6. Category D is visual only. If a percentage ever appears against it,
    #    someone has borrowed a Normal Fluid Service figure.
    for e in pack["examinationExtent"]:
        if e["appliesTo"] != "CATEGORY_D":
            continue
        for r in e["requirements"]:
            if r.get("extentPercent"):
                fail(f"[ref:fluid-service] Category D carries an examination "
                     f"percentage; B31.3 341.4.2 requires visual examination only")

    # 7. SLICE 2. The equation is only as good as its guard rails: if the
    #    validity limits ever go missing, a consumer will happily apply a thin
    #    wall formula to a thick wall and get a plausible wrong number.
    wt = pack.get("wallThickness")
    if wt:
        if abs(wt["validity"]["pOverSeLimit"] - 0.385) > 1e-9:
            fail("[ref:fluid-service] the P/SE limit is not 0.385; 304.1.2(b) "
                 "says the formula stops being valid there")
        for sym in ("t", "tm", "P", "D", "S", "E", "W", "Y", "c"):
            if sym not in wt["symbols"]:
                fail(f"[ref:fluid-service] the thickness equation does not define "
                     f"{sym}; an undefined symbol is an invitation to guess")
        if "NOT TRANSCRIBED" not in wt["symbols"]["S"]:
            fail("[ref:fluid-service] the S symbol no longer says it is "
                 "untranscribed; if Table A-1 has been read, say so in the pack "
                 "and move it out of notBuiltInThisSlice")

    # 8. Coefficient Y: every row must have one value per temperature column,
    #    and the ferritic/austenitic rows must actually differ - they are the
    #    two most used and transposing them is silent.
    cy = pack.get("coefficientY")
    if cy:
        n = len(cy["temperaturesC"])
        rows = {r["material"]: r["values"] for r in cy["rows"]}
        for mat, vals in rows.items():
            if len(vals) != n:
                fail(f"[ref:fluid-service] coefficient Y row {mat} has "
                     f"{len(vals)} values against {n} temperature columns")
        if rows.get("FERRITIC_STEEL") == rows.get("AUSTENITIC_STEEL"):
            fail("[ref:fluid-service] the ferritic and austenitic Y rows are "
                 "identical; they differ from 510 C upward in Table 304.1.1-1")
        if rows.get("GRAY_IRON", [None])[0] != 0.0:
            fail("[ref:fluid-service] gray iron Y must be 0.0 at and below 482 C")

    notes.append(f"[ref:fluid-service] {len(services)} fluid service(s), "
                 f"{len(conditions)} condition(s), "
                 f"{len(pack['examinationExtent'])} examination extent rule(s)")



def check_allowable_stress(refs: dict) -> None:
    """
    ASME B31.3 Table A-1. ADR-008 section 11.2.3.

    The most consequential number in the Code, joined across page blocks by a
    line number that restarts in every material section. Everything here
    guards that join.
    """
    pack = refs.get("allowable-stress")
    if pack is None:
        fail("[ref:allowable-stress] pack not loaded")
        return

    mats = pack["materials"]
    tol = pack["basisClause"]["toleranceFraction"]

    seen = set()
    for m in mats:
        key = (m["sectionIndex"], m["lineNo"])
        if key in seen:
            fail(f"[ref:allowable-stress] section {key[0]} line {key[1]} appears "
                 f"twice; the join key must be unique or two materials share a "
                 f"stress curve")
        seen.add(key)

        # 1. ALLOWABLE STRESS NEVER RISES WITH TEMPERATURE. A rise means two
        #    materials' rows were merged under one line number.
        pts = m["points"]
        for a, b in zip(pts, pts[1:]):
            if b["temperatureC"] <= a["temperatureC"]:
                fail(f"[ref:allowable-stress] {m['spec']} {m['grade']}: "
                     f"temperatures are not increasing")
            if b["stressMpa"] > a["stressMpa"] + 1e-9:
                fail(f"[ref:allowable-stress] {m['spec']} {m['grade']}: stress "
                     f"RISES from {a['temperatureC']} to {b['temperatureC']} C - "
                     f"the signature of a mis-joined row")

        # 2. VERIFIED MEANS CHECKED AGAINST SOMETHING ELSE. A value may claim
        #    it only with a basis check that actually agrees.
        if m["verified"]:
            if not m["basisCheck"]:
                fail(f"[ref:allowable-stress] {m['spec']} {m['grade']} is verified "
                     f"with no basis check behind it")
            elif abs(m["basisCheck"]["deltaFraction"]) >= tol:
                fail(f"[ref:allowable-stress] {m['spec']} {m['grade']} is verified "
                     f"but sits {m['basisCheck']['deltaFraction']:.1%} from the "
                     f"302.3.2 basis")
            if not m["source"] or not m["readOn"]:
                fail(f"[ref:allowable-stress] {m['spec']} {m['grade']} is verified "
                     f"without a citation and a date")
        else:
            if m["source"]:
                fail(f"[ref:allowable-stress] {m['spec']} {m['grade']} is not "
                     f"verified yet cites a source")
            if not m["heldNotCitableBecause"]:
                fail(f"[ref:allowable-stress] {m['spec']} {m['grade']} is held "
                     f"without saying why")

        # 3. NOTHING MAY EXCEED THE BASIS. The Code does not permit it, and a
        #    value above it is a parse fault wearing a plausible face.
        if m["basisCheck"] and m["basisCheck"]["deltaFraction"] > tol:
            fail(f"[ref:allowable-stress] {m['spec']} {m['grade']} is ABOVE the "
                 f"302.3.2 basis, which B31.3 never allows")

    citable = sum(1 for m in mats if m["verified"])
    notes.append(f"[ref:allowable-stress] {len(mats)} material(s) joined and "
                 f"structurally sound, {citable} independently confirmed against "
                 f"the 302.3.2 basis and citable, {len(mats) - citable} held")



def check_fitting_dimensions(refs: dict) -> None:
    """
    ASME B16.9 buttwelding fitting dimensions. ADR-008 section 11.9.

    The guard here is the geometry itself. A long radius elbow is 1.5 x NPS
    centre to end and a 45 degree one is 0.625 x NPS, so a mis-parsed column
    shows up immediately - which is how the short radius table was caught
    producing a 32 inch take-out for an NPS 8 elbow.
    """
    pack = refs.get("fitting-dimensions")
    if pack is None:
        fail("[ref:fitting-dimensions] pack not loaded")
        return

    # The take-out of a 45 degree elbow is NOT 0.625 x NPS - that is the
    # industry shorthand. The geometry is R x tan(22.5 deg) = 1.5 x 0.41421 =
    # 0.6213, and B16.9 then rounds to a quarter of an inch, which is why NPS
    # 22 prints 13.50 where the shorthand gives 13.75. Checking against the
    # shorthand called four correctly transcribed rows mis-parsed.
    RULE = {"LONG_RADIUS_90": (1.5, 0.06), "LONG_RADIUS_45": (0.6213, 0.25),
            "SHORT_RADIUS_90": (1.0, 0.06)}

    def nps_decimal(n):
        n = n.strip()
        if " " in n:
            w, f = n.split()
            a, b = f.split("/")
            return float(w) + float(a) / float(b)
        if "/" in n:
            a, b = n.split("/")
            return float(a) / float(b)
        return float(n)

    sizes = refs.get("pipe-sizes")
    known = {s["nps"]: s for s in sizes["sizes"]} if sizes else {}

    if not known:
        fail("[ref:fitting-dimensions] PIPE_REFERENCE missing or empty")
        return

    # Explicit exceptions pinned to the supplied v1.0.0 transcription, not
    # newly verified ASME values. Never interpret an arbitrary oversized
    # dimension as a minimum. Alter these only with source evidence.
    small_size_values = {
        ("LONG_RADIUS_90", "1/2"): 38.0,
        ("LONG_RADIUS_90", "3/4"): 38.0,
        ("LONG_RADIUS_45", "1/2"): 16.0,
        ("LONG_RADIUS_45", "3/4"): 19.0,
    }
    kinds = {
        ("ELBOW", "LONG_RADIUS_90"): ("CENTRE_TO_END", "A", "Table 6.1-1"),
        ("ELBOW", "LONG_RADIUS_45"): ("CENTRE_TO_END", "B", "Table 6.1-1"),
        ("CAP", None): ("LENGTH", "E", "Table 6.1-10"),
    }
    declared = pack["standard"]["sourceTables"]
    actual = {r["sourceTable"] for r in pack["dimensions"]}
    if len(declared) != len(set(declared)) or set(declared) != actual:
        fail("[ref:fitting-dimensions] SOURCE_TABLES manifest differs from rows")

    seen = set()
    checked = floored = 0
    for d in pack["dimensions"]:
        key = (d["fitting"], d["qualifier"], d["nps"], d["dimension"])
        if key in seen:
            fail(f"[ref:fitting-dimensions] DUPLICATE dimensional key {key}")
        seen.add(key)
        expected = kinds.get((d["fitting"], d["qualifier"]))
        if expected != (d["dimension"], d["symbol"], d["sourceTable"]):
            fail(f"[ref:fitting-dimensions] ROW_KIND inconsistent row {key}")
        if d["nps"] in known and d["dn"] != known[d["nps"]]["dn"]:
            fail(f"[ref:fitting-dimensions] DN_MISMATCH for NPS {d['nps']}")

        # 1. THE SIZE MUST BE ONE THE PLATFORM DRAWS. A fitting at a size the
        #    pipe pack does not carry cannot be put on an isometric.
        if known and d["nps"] not in known:
            fail(f"[ref:fitting-dimensions] NPS {d['nps']} is not in pipe-sizes")
        elif known and abs(float(known[d["nps"]]["odMm"]) - d["odMm"]) > 0.4:
            fail(f"[ref:fitting-dimensions] NPS {d['nps']} outside diameter "
                 f"{d['odMm']} does not match pipe-sizes "
                 f"{known[d['nps']]['odMm']}")

        # Scope is B16.9 clause 1.1, independent of ingestion coverage.
        size_value = known.get(d['nps'], {}).get('npsDecimal')
        if size_value is not None and not 0.5 <= size_value <= 48:
            fail(f"[ref:fitting-dimensions] OUTSIDE_STANDARD_RANGE {d['nps']}")
        cap_fields = ('limitingWallThicknessMm', 'e1Mm', 'requiresManufacturerAgreement')
        if d['fitting'] == 'CAP':
            if size_value is not None and size_value > 24:
                if (d.get('requiresManufacturerAgreement') is not True
                    or any(f not in d or d[f] is not None for f in cap_fields[:2])):
                    fail(f"[ref:fitting-dimensions] CAP_AGREEMENT_REQUIRED {d['nps']}")
            else:
                valid = all(type(d.get(f)) in (int, float)
                            and math.isfinite(d[f]) and d[f] > 0 for f in cap_fields[:2])
                if (not valid or (valid and d['e1Mm'] < d['mm'])
                    or d.get('requiresManufacturerAgreement') is not False):
                    fail(f"[ref:fitting-dimensions] CAP_APPLICABILITY incomplete/inconsistent for {d['nps']}")
        elif any(f in d for f in cap_fields):
            fail(f"[ref:fitting-dimensions] CAP_APPLICABILITY fields on non-cap {key}")

        # 2. THE GEOMETRY. Above the floor B16.9 sets at the small end, the
        #    take-out is a fixed multiple of the size.
        spec = RULE.get(d["qualifier"] or "")
        if spec is None:
            continue
        rule, slack = spec
        inches = d["mm"] / 25.4
        want = rule * nps_decimal(d["nps"])
        exception = small_size_values.get((d["qualifier"], d["nps"]))
        if exception is not None:
            if abs(d["mm"] - exception) < 1e-9:
                floored += 1
            else:
                fail(f"[ref:fitting-dimensions] GEOMETRY changed pinned small-size "
                     f"value for {d['qualifier']} NPS {d['nps']}")
        elif abs(inches - want) <= slack + 0.01 * want:
            checked += 1
        else:
            fail(f"[ref:fitting-dimensions] GEOMETRY {d['qualifier']} NPS "
                 f"{d['nps']} is outside the existing plausibility tolerance; "
                 "requires source review, not an inferred floor")

    # 3. Short radius must stay out until it can be parsed by column position.
    if any(d["qualifier"] == "SHORT_RADIUS_90" for d in pack["dimensions"]):
        fail("[ref:fitting-dimensions] short radius elbows are present; B16.9 "
             "prints that table side by side with the returns table and a "
             "line-based parse mixes the two")

    notes.append(f"[ref:fitting-dimensions] {len(pack['dimensions'])} dimension(s), "
                 f"{checked} confirmed against the take-out geometry, {floored} at "
                 f"the standard's small-size floor")



def main() -> int:
    packs = check_schema()
    check_extends(packs)
    check_bilingual(packs)
    check_progress_weights(packs)
    check_awp_seams(packs)

    refs = check_reference_schema()
    check_bilingual(refs)
    check_reference_integrity(refs)
    check_units(refs)
    check_materials(refs)
    check_actuators(refs)
    check_valve_construction(refs)
    check_valve_construction_sources(refs)
    check_valve_pt_ratings(refs)
    check_valve_face_to_face(refs)
    check_valve_testing(refs)
    check_fluid_service(refs)
    check_allowable_stress(refs)
    check_fitting_dimensions(refs)
    check_flange_bolting(refs)
    check_flange_pt_ratings(refs)

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
