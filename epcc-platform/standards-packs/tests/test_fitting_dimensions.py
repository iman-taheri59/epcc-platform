"""In-memory regression probes; never rewrite engineering source data."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('pack_validator', ROOT / 'validate_packs.py')
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)

class FittingDimensionsTest(unittest.TestCase):
    def setUp(self):
        self.refs = {name: json.loads((ROOT / 'reference' / (name + '.ref.json')).read_text())
                     for name in ('fitting-dimensions', 'pipe-sizes')}
        self.pack = self.refs['fitting-dimensions']
        self.row = next(r for r in self.pack['dimensions']
                        if r['qualifier'] == 'LONG_RADIUS_90' and r['nps'] == '8')
        v.problems.clear()
        v.notes.clear()

    def check(self):
        v.check_fitting_dimensions(self.refs)
        return '\n'.join(v.problems)

    def test_original_is_accepted(self):
        self.assertEqual('', self.check())

    def test_oversized_elbow_is_not_a_floor(self):
        self.row['mm'] = 9999
        self.assertIn('GEOMETRY', self.check())

    def test_small_size_exception_is_bounded(self):
        next(r for r in self.pack['dimensions'] if r['qualifier'] == 'LONG_RADIUS_90'
             and r['nps'] == '1/2')['mm'] = 9999
        self.assertIn('GEOMETRY', self.check())

    def test_duplicate_key(self):
        other = copy.deepcopy(self.row)
        other['mm'] += 1
        self.pack['dimensions'].append(other)
        self.assertIn('DUPLICATE', self.check())

    def test_wrong_dn(self):
        self.row['dn'] = 999
        self.assertIn('DN_MISMATCH', self.check())

    def test_unknown_qualifier(self):
        self.row['qualifier'] = 'TYPO'
        self.assertIn('ROW_KIND', self.check())

    def test_wrong_symbol(self):
        self.row['symbol'] = 'E'
        self.assertIn('ROW_KIND', self.check())

    def test_wrong_dimension(self):
        self.row['dimension'] = 'LENGTH'
        self.assertIn('ROW_KIND', self.check())

    def test_wrong_table(self):
        self.row['sourceTable'] = 'Table 6.1-10'
        self.assertIn('ROW_KIND', self.check())

    def test_table_manifest_mismatch(self):
        self.pack['standard']['sourceTables'].append('Table 6.1-4')
        self.assertIn('SOURCE_TABLES', self.check())

    def test_unknown_nps(self):
        self.row['nps'] = '999'
        self.assertIn('not in pipe-sizes', self.check())

    def test_od_mismatch(self):
        self.row['odMm'] = 999
        self.assertIn('outside diameter', self.check())

    def test_missing_pipe_reference(self):
        del self.refs['pipe-sizes']
        self.assertIn('PIPE_REFERENCE', self.check())

    def test_cap_fields_are_required_and_finite(self):
        cap = next(r for r in self.pack['dimensions'] if r['fitting'] == 'CAP')
        for field in ('limitingWallThicknessMm', 'e1Mm'):
            original = cap[field]
            for invalid in (None, 0, -1, '12.7', True, float('nan'), float('inf')):
                with self.subTest(field=field, invalid=invalid):
                    v.problems.clear()
                    cap[field] = invalid
                    self.assertIn('CAP_APPLICABILITY', self.check())
            del cap[field]
            v.problems.clear()
            self.assertIn('CAP_APPLICABILITY', self.check())
            cap[field] = original

    def test_e1_must_not_be_shorter_than_e(self):
        cap = next(r for r in self.pack['dimensions'] if r['fitting'] == 'CAP')
        cap['e1Mm'] = cap['mm'] - 1
        self.assertIn('CAP_APPLICABILITY', self.check())

    def test_cap_fields_are_not_allowed_on_elbows(self):
        self.row['limitingWallThicknessMm'] = 1
        self.assertIn('CAP_APPLICABILITY', self.check())

    def test_source_backed_cap_values(self):
        # Supplied B16.9-2018, Table 6.1-10, printed p20, notes (1)/(2).
        expected = [
            ('1/2',4.57,25), ('3/4',3.81,25), ('1',4.57,38),
            ('1 1/4',4.83,38), ('1 1/2',5.08,38), ('2',5.59,44),
            ('2 1/2',7.11,51), ('3',7.62,64), ('3 1/2',8.13,76),
            ('4',8.64,76), ('5',9.65,89), ('6',10.92,102),
            ('8',12.70,127), ('10',12.70,152), ('12',12.70,178),
            ('14',12.70,191), ('16',12.70,203), ('18',12.70,229),
            ('20',12.70,254), ('22',12.70,254), ('24',12.70,305),
        ]
        actual = [(r['nps'], r['limitingWallThicknessMm'], r['e1Mm'])
                  for r in self.pack['dimensions'] if r['fitting'] == 'CAP' and r['dn'] <= 600]
        self.assertEqual(expected, actual)

    def test_schema_requires_caps_fields_and_rejects_them_on_elbows(self):
        import jsonschema
        schema = json.loads((ROOT / 'reference/schema/fitting-dimensions.schema.json').read_text())
        validator = jsonschema.Draft202012Validator(schema)
        self.assertEqual([], list(validator.iter_errors(self.pack)))
        cap = next(r for r in self.pack['dimensions'] if r['fitting'] == 'CAP')
        del cap['e1Mm']
        self.assertTrue(list(validator.iter_errors(self.pack)))
        cap['e1Mm'] = 25
        self.row['e1Mm'] = 100
        self.assertTrue(list(validator.iter_errors(self.pack)))

    def test_complete_loaded_size_range_for_three_existing_fitting_families(self):
        sizes = {s['nps'] for s in self.refs['pipe-sizes']['sizes'] if 0.5 <= s['npsDecimal'] <= 48}
        for fitting, qualifier in [('CAP', None), ('ELBOW', 'LONG_RADIUS_90'), ('ELBOW', 'LONG_RADIUS_45')]:
            actual = {r['nps'] for r in self.pack['dimensions'] if (r['fitting'],r['qualifier']) == (fitting,qualifier)}
            self.assertEqual(sizes, actual)

    def test_large_cap_null_cells_are_not_zero_or_invented_thresholds(self):
        cap = next(r for r in self.pack['dimensions'] if r['fitting'] == 'CAP' and r['nps'] == '48')
        self.assertIsNone(cap['e1Mm'])
        self.assertIsNone(cap['limitingWallThicknessMm'])
        for field in ('e1Mm', 'limitingWallThicknessMm'):
            for value in (0, 12.7, 343):
                cap[field] = value
                v.problems.clear()
                self.assertIn('CAP_AGREEMENT_REQUIRED', self.check())
            cap[field] = None
        cap['requiresManufacturerAgreement'] = False
        v.problems.clear()
        self.assertIn('CAP_AGREEMENT_REQUIRED', self.check())

    def test_source_backed_upper_boundary(self):
        rows = [r for r in self.pack['dimensions'] if r['nps'] == '48']
        self.assertEqual({'CAP':343, 'LONG_RADIUS_45':759, 'LONG_RADIUS_90':1829},
                         {r['qualifier'] or r['fitting']:r['mm'] for r in rows})
        self.assertEqual({'minNps':0.5,'maxNps':48,'sourceClause':'1.1 General','outsideRangePolicy':'PROJECT_SPEC_REQUIRED'}, self.pack['sizeRange'])

if __name__ == '__main__':
    unittest.main()
