#!/usr/bin/env python3
"""
Standards pack CI gate.

Two jobs:
  1. Validate every pack against the schema.
  2. Guard the AWP seams (ADR-002). The AWP pack is deferred, NOT deleted.
     It stays in CI as a regression test proving the standards engine has not
     silently grown Iran-specific assumptions. If this file starts failing,
     someone has broken the pack abstraction and reviving AWP for the
     international release just got expensive.

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


# --------------------------------------------------------------- main ------
def main() -> int:
    packs = check_schema()
    check_extends(packs)
    check_bilingual(packs)
    check_progress_weights(packs)
    check_awp_seams(packs)

    for n in notes:
        print(f"  ok  {n}")

    if problems:
        print(f"\n{len(problems)} PROBLEM(S):\n")
        for p in problems:
            print(f"  FAIL  {p}")
        print("\nSee docs/ADR-002-Defer-AWP.md - the AWP pack is deliberately retained")
        print("as a genericity guard. A failure here means the pack abstraction is eroding.")
        return 1

    print(f"\nAll {len(packs)} packs valid. AWP seams intact (ADR-002).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
