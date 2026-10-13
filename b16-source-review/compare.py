"""Compare existing SI dimensions to the supplied PDF's layout text by OD.
Not a full NPS/DN mapping or engineering applicability certification.
Run after pdftotext -layout; no project data writes.
"""
import hashlib
import json
import re
from pathlib import Path

base = Path('/home/user')
folder = base / 'organization-report/b16-source-review'
pages = (folder / 'source-layout.txt').read_text().split('\f')
pack = json.loads((base / 'epcc-platform/standards-packs/reference/fitting-dimensions.ref.json').read_text())
tables = {}
for kind, page in [('ELBOW', pages[17]), ('CAP', pages[30])]:
    rows = {}
    for line in page.splitlines():
        pairs = re.findall(r'([0-9]+(?: [0-9]{3})*(?:\.[0-9]+)?)\s*\([0-9.]+\)', line)
        if len(pairs) >= 2:
            nums = [float(v.replace(' ', '')) for v in pairs]
            assert nums[0] not in rows, 'Ambiguous OD extraction'
            rows[nums[0]] = nums[1:]
    tables[kind] = rows
results = []
for row in pack['dimensions']:
    vals = tables[row['fitting']].get(row['odMm'])
    value = None if vals is None else vals[1 if row['qualifier'] == 'LONG_RADIUS_45' else 0]
    results.append({
        'fitting': row['fitting'], 'qualifier': row['qualifier'],
        'nps': row['nps'], 'odMm': row['odMm'], 'storedMm': row['mm'],
        'sourceMm': value, 'match': value == row['mm'],
    })
cap_cells = []
for row in pack['dimensions']:
    if row['fitting'] != 'CAP':
        continue
    vals = tables['CAP'][row['odMm']]
    for field, index in [('limitingWallThicknessMm', 1), ('e1Mm', 2)]:
        cap_cells.append({'nps': row['nps'], 'field': field,
                          'stored': row.get(field), 'source': vals[index] if index < len(vals) else None,
                          'match': row.get(field) == (vals[index] if index < len(vals) else None)})
out = {
    'pdfSha256': hashlib.sha256((base / 'uploads/ASME B16.9-2018 - Factory-Made Wrought Buttwelding Fittings.pdf').read_bytes()).hexdigest(),
    'method': 'SI cells matched by OD; NPS/DN linkage not independently rechecked. E-column equality does not prove cap thickness applicability.',
    'pdfPages': {'elbows': 18, 'caps': 31},
    'rowsChecked': len(results), 'numericMatches': sum(r['match'] for r in results),
    'results': results,
    'capApplicabilityCells': cap_cells,
    'capApplicabilityMatches': sum(r['match'] for r in cap_cells),
}
(folder / 'comparison.json').write_text(json.dumps(out, indent=2))
print(f"{out['numericMatches']}/{out['rowsChecked']} SI dimensions match the selected source columns.")
for row in results:
    if not row['match']:
        print(row)

print(f"{out['capApplicabilityMatches']}/{len(cap_cells)} cap applicability cells match.")
assert all(r['match'] for r in results + cap_cells), 'Source comparison failed'
