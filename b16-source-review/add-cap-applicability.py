import json,re
from pathlib import Path
root=Path('/home/user/epcc-platform')
p=root/'standards-packs/reference/fitting-dimensions.ref.json'
pack=json.loads(p.read_text())
# Manually transcribed from supplied B16.9-2018 Table 6.1-10, printed p20.
values=[('1/2',4.57,25),('3/4',3.81,25),('1',4.57,38),('1 1/4',4.83,38),('1 1/2',5.08,38),('2',5.59,44),('2 1/2',7.11,51),('3',7.62,64),('3 1/2',8.13,76),('4',8.64,76),('5',9.65,89),('6',10.92,102),('8',12.70,127),('10',12.70,152),('12',12.70,178),('14',12.70,191),('16',12.70,203),('18',12.70,229),('20',12.70,254),('22',12.70,254),('24',12.70,305)]
# Independently compare new cells against layout-extracted columns, keyed by OD.
page=Path('/home/user/organization-report/b16-source-review/source-layout.txt').read_text().split('\f')[30]
source={}
for line in page.splitlines():
 pairs=re.findall(r'([0-9]+(?: [0-9]{3})*(?:\.[0-9]+)?)\s*\([0-9.]+\)',line)
 if len(pairs)==4:
  nums=[float(v.replace(' ','')) for v in pairs]; source[nums[0]]=nums[1:]
lookup={n:(t,e) for n,t,e in values}
for row in pack['dimensions']:
 if row['fitting']=='CAP':
  t,e=lookup[row['nps']]
  assert source[row['odMm']]==[row['mm'],t,e],row
  row['limitingWallThicknessMm']=t; row['e1Mm']=float(e)
pack['version']='1.1.0'
pack['readOn']='2026-09-28'
pack['conventions']['capLengthApplicability']='Table 6.1-10 notes (1) and (2), printed page 20: for the covered NPS 1/2 through 24, use E when the applicable specified wall thickness is at or below limitingWallThicknessMm; use E1 above it. Thickness must be explicitly supplied with its source by a PMS consumer; no automatic schedule-to-thickness or measured-thickness substitution. NPS 26 and larger remain unimplemented: E1 requires manufacturer/purchaser agreement, not extrapolation.'
pack['conventions']['capSourceReview']='Threshold and E1 cells cross-checked against the user-supplied ASME B16.9-2018 PDF, SHA-256 f254b756cf54dc4115fba10c659f205cee913c816068736ee76f59b608052ab4; printed page 20 / PDF page 31. This is a limited transcription review, not engineering certification.'
p.write_text(json.dumps(pack,ensure_ascii=False,indent=1)+'\n')
p=root/'standards-packs/reference/schema/fitting-dimensions.schema.json'; schema=json.loads(p.read_text());item=schema['properties']['dimensions']['items']
for field in ['limitingWallThicknessMm','e1Mm']: item['properties'][field]={'type':'number','exclusiveMinimum':0}
item['allOf']=[{'if':{'properties':{'fitting':{'const':'CAP'}},'required':['fitting']},'then':{'required':['limitingWallThicknessMm','e1Mm']},'else':{'not':{'anyOf':[{'required':['limitingWallThicknessMm']},{'required':['e1Mm']}]}}}]
p.write_text(json.dumps(schema,indent=1)+'\n')
print('21 cap thresholds and E1 values checked against extracted source and added; original 65 dimensions unchanged.')
