import json,re
from pathlib import Path
root=Path('/home/user/epcc-platform')
p=root/'standards-packs/reference/fitting-dimensions.ref.json'; pack=json.loads(p.read_text())
sizes=json.loads((root/'standards-packs/reference/pipe-sizes.ref.json').read_text())['sizes']
# Explicit transcription: NPS, A, B, E. B16.9-2018 pp7/20, SI columns.
new=[(26,991,406,267),(28,1067,438,267),(30,1143,470,267),(32,1219,502,267),(34,1295,533,267),(36,1372,565,267),(38,1448,600,305),(40,1524,632,305),(42,1600,660,305),(44,1676,695,343),(46,1753,727,343),(48,1829,759,343)]
pages=Path('/home/user/organization-report/b16-source-review/source-layout.txt').read_text().split('\f')
source={}
for name,page in [('ELBOW',pages[17]),('CAP',pages[30])]:
 source[name]={}
 for line in page.splitlines():
  pairs=re.findall(r'([0-9]+(?: [0-9]{3})*(?:\.[0-9]+)?)\s*\([0-9.]+\)',line)
  if len(pairs)>=2:
   nums=[float(v.replace(' ','')) for v in pairs];source[name][nums[0]]=nums[1:]
for n,a,b,e in new:
 size=next(s for s in sizes if s['nps']==str(n));od=size['odMm']
 assert source['ELBOW'][od]==[a,b]
 assert source['CAP'][od]==[e], 'Large cap has no tabulated threshold or E1'
 for fitting,q,mm,symbol in [('CAP',None,e,'E'),('ELBOW','LONG_RADIUS_90',a,'A'),('ELBOW','LONG_RADIUS_45',b,'B')]:
  if any(r['fitting']==fitting and r['qualifier']==q and r['nps']==str(n) for r in pack['dimensions']):continue
  row={'fitting':fitting,'qualifier':q,'nps':str(n),'dn':size['dn'],'odMm':od,'dimension':'LENGTH' if fitting=='CAP' else 'CENTRE_TO_END','symbol':symbol,'mm':float(mm),'sourceTable':'Table 6.1-10' if fitting=='CAP' else 'Table 6.1-1'}
  if fitting=='CAP':row.update(limitingWallThicknessMm=None,e1Mm=None)
  pack['dimensions'].append(row)
for r in pack['dimensions']:
 if r['fitting']=='CAP':r['requiresManufacturerAgreement']=r['dn']>=650
order={s['nps']:s['dn'] for s in sizes}
pack['dimensions'].sort(key=lambda r:(r['fitting'],r['qualifier'] or '',order[r['nps']]))
pack['version']='1.2.0'
pack['sizeRange']={'minNps':0.5,'maxNps':48,'sourceClause':'1.1 General','outsideRangePolicy':'PROJECT_SPEC_REQUIRED'}
pack['conventions']['capLengthApplicability']='Table 6.1-10 notes (1)/(2): for NPS 1/2 through 24 select E/E1 from explicitly sourced specified thickness. NPS 26 through 48 are WITHIN B16.9 coverage: E is tabulated but its thickness threshold and E1 are not. E1 requires manufacturer/purchaser agreement; do not choose E by assuming a missing threshold means infinity, or infer E1.'
pack['conventions']['sizeScope']='B16.9-2018 clause 1.1: NPS 1/2 through 48. Reference ingestion gaps do not narrow this range. Outside this range, the user must define dimensions according to the project specification, with separate project provenance, not B16.9 extrapolation.'
p.write_text(json.dumps(pack,ensure_ascii=False,indent=1)+'\n')
p=root/'standards-packs/reference/schema/fitting-dimensions.schema.json';s=json.loads(p.read_text());s['required'].append('sizeRange');s['properties']['sizeRange']={'type':'object','required':['minNps','maxNps','sourceClause','outsideRangePolicy'],'additionalProperties':False,'properties':{'minNps':{'const':0.5},'maxNps':{'const':48},'sourceClause':{'const':'1.1 General'},'outsideRangePolicy':{'const':'PROJECT_SPEC_REQUIRED'}}}
item=s['properties']['dimensions']['items']
for field in ['limitingWallThicknessMm','e1Mm']:item['properties'][field]={'type':['number','null'],'exclusiveMinimum':0}
item['properties']['requiresManufacturerAgreement']={'type':'boolean'}
fields=['limitingWallThicknessMm','e1Mm','requiresManufacturerAgreement']
item['allOf']=[{'if':{'properties':{'fitting':{'const':'CAP'}},'required':['fitting']},'then':{'required':fields,'allOf':[{'if':{'properties':{'dn':{'maximum':600}}},'then':{'properties':{'limitingWallThicknessMm':{'type':'number'},'e1Mm':{'type':'number'},'requiresManufacturerAgreement':{'const':False}}},'else':{'properties':{'limitingWallThicknessMm':{'type':'null'},'e1Mm':{'type':'null'},'requiresManufacturerAgreement':{'const':True}}}}]},'else':{'not':{'anyOf':[{'required':[f]} for f in fields]}}}]
p.write_text(json.dumps(s,indent=1)+'\n')
print('Pack 1.2.0:',len(pack['dimensions']),'rows; source cross-check passed')
