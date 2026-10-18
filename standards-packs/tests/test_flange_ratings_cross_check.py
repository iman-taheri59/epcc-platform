"""Cross-validation guard between the two independent B16.5 flange packs.

`flange-ratings.ref.json` (this branch) was position-extracted from the
owner-uploaded ASME B16.5-2025 PDF by scripts/build_flange_ratings_pack.py.
`flange-pt-ratings.ref.json` was transcribed from the project's purchased
copy in a parallel session. The two provenances were audited cell-by-cell
on 2026-10-12: 4,725 shared cells, 4,725 exact matches, zero mismatches.

This test pins that result so any later edit to either pack that breaks
agreement fails loudly. It never rewrites data; it only compares.
"""
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REF = ROOT / "reference"

KPA_PER_BAR = 100.0


class FlangeRatingsCrossCheckTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.extracted = json.loads((REF / "flange-ratings.ref.json").read_text())
        cls.transcribed = json.loads((REF / "flange-pt-ratings.ref.json").read_text())

    def test_every_shared_b16_5_cell_matches_exactly(self):
        curves = {
            c["materialGroup"]: {p["temperatureC"]: p["byClass"] for p in c["points"]}
            for c in self.transcribed["curves"]
            if c["standard"] == "B16.5"
        }

        compared = 0
        mismatches = []

        for group, table in self.extracted["groups"].items():
            points = curves.get(group)
            if points is None:
                continue
            for row in table["ratings"]:
                t = row["t_max_degC"]
                t = int(t) if t == int(t) else t
                theirs = points.get(t)
                if theirs is None:
                    continue  # their ladder is shorter here; no shared cell
                for cls, bar in row["bar"].items():
                    kpa = theirs.get(cls)
                    if bar is None and kpa is None:
                        compared += 1
                        continue
                    if bar is None or kpa is None:
                        mismatches.append((group, t, cls, bar, kpa))
                        continue
                    compared += 1
                    if abs(kpa / KPA_PER_BAR - bar) > 1e-9:
                        mismatches.append((group, t, cls, bar, kpa))

        self.assertEqual([], mismatches, "B16.5 packs diverge")
        # The 2026-10-12 audit compared 4,725 cells; never accept less.
        self.assertGreaterEqual(compared, 4725)


if __name__ == "__main__":
    unittest.main()
