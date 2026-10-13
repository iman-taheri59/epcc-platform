from pathlib import Path
base=Path('/home/user/epcc-platform/app')
en={
 'title':'Project material declarations','intro':'Project-specific source declarations for piping materials. Original wording, Spec revision and clause are retained. Each material family has its own authorized review workflow.',
 'scope':'B16.9 scope (NPS ½–48) applies only to the supported fittings. Other groups do not inherit that range. Record the actual applicable standard and edition, or explicit project specification; no standard coverage is inferred.',
 'notice':'These are source declarations, not engineering conformance certificates. Blank technical attributes mean not declared, not zero or compliant. Review completeness and applicability against the cited source before approval. No automatic PMS/model/MTO use.',
 'policy_scope':'This workflow applies only to the selected material family. Existing fitting reviewers receive no authority over other families automatically.',
 'FITTING':'Fittings','PIPE':'Pipe','FLANGE':'Flange','VALVE':'Valve','GASKET':'Gasket','BOLTING':'Bolting / nuts',
 'qualifier':'Component subtype — as written','size_designation':'Size / thread designation — as written, with units',
 'reference_standard':'Component standard + edition / explicit project Spec','material_spec':'Material specification + edition — as written','material_grade':'Material grade — as written (if declared)',
 'declaration_reason':'Reason for project-specific declaration','properties':'Declared technical attributes (optional; never inferred)',
 'schedule':'Schedule — as written','wall_thickness_mm':'Wall thickness (mm)','outside_diameter_mm':'Outside diameter (mm)','product_form':'Product form / manufacture — as written',
 'pressure_rating':'Pressure rating — include Class / PN / units as written','facing':'Facing — as written','series':'Series — as written (e.g. A / B)','bore_mm':'Bore (mm)','thickness_mm':'Thickness (mm)',
 'construction_standard':'Construction standard + edition','end_connection':'End connection — as written','trim':'Trim — as written','operator':'Operator / actuator / datasheet reference — as written','face_to_face_mm':'Face-to-face (mm)',
 'gasket_type':'Gasket construction — as written','ring_number':'Ring designation — as written','inside_diameter_mm':'Inside diameter (mm)',
 'thread_standard':'Thread standard + edition','length_mm':'Bolt / stud length (mm)','nut_height_mm':'Nut height (mm)','coating':'Coating — as written',
 'family_locked':'A correction cannot change the material family. Create a separate declaration instead.',
 'not_b169':'B16.9 is a fitting standard, not the component standard for this material family.',
}
fa={
 'title':'تعریف‌های اختصاصی متریال پروژه','intro':'ثبت تعریف‌های مستند به Spec پروژه برای اقلام پایپینگ؛ متن اصلی، ویرایش و بند منبع حفظ می‌شود. گردش تأیید هر گروه متریال مستقل است.',
 'scope':'محدوده B16.9 از NPS ½ تا ۴۸ فقط مربوط به فیتینگ‌های تحت پوشش است؛ این محدوده به سایر گروه‌ها تعمیم داده نمی‌شود. استاندارد و ویرایش واقعی یا Spec صریح پروژه را ثبت کنید؛ محدوده هیچ استانداردی حدس زده نمی‌شود.',
 'notice':'این ثبت، اظهار اطلاعات منبع است نه گواهی انطباق مهندسی. ویژگی خالی یعنی اظهار نشده، نه صفر یا منطبق. پیش از تأیید، کامل‌بودن و کاربردپذیری را با منبع بررسی کنید. استفاده خودکار در PMS، مدل یا MTO انجام نمی‌شود.',
 'policy_scope':'این گردش تأیید فقط برای گروه انتخاب‌شده است. اختیار بازبینان فیتینگ به گروه‌های دیگر خودکار منتقل نمی‌شود.',
 'FITTING':'فیتینگ','PIPE':'لوله','FLANGE':'فلنج','VALVE':'شیر','GASKET':'گسکت','BOLTING':'پیچ، استادبولت و مهره',
 'qualifier':'زیرنوع قطعه — عین منبع','size_designation':'نام‌گذاری سایز یا رزوه — عین منبع با واحد',
 'reference_standard':'استاندارد قطعه و ویرایش / Spec صریح پروژه','material_spec':'استاندارد متریال و ویرایش — عین منبع','material_grade':'گرید متریال — عین منبع (در صورت ذکر)',
 'declaration_reason':'دلیل نیاز به تعریف اختصاصی پروژه','properties':'ویژگی‌های فنی اظهارشده (اختیاری؛ بدون استنتاج)',
 'schedule':'اسکجول — عین منبع','wall_thickness_mm':'ضخامت دیواره (میلی‌متر)','outside_diameter_mm':'قطر خارجی (میلی‌متر)','product_form':'شکل محصول / روش ساخت — عین منبع',
 'pressure_rating':'رده فشار — همراه Class، PN یا واحد عین منبع','facing':'فیسینگ — عین منبع','series':'سری — عین منبع (مانند A یا B)','bore_mm':'قطر داخلی (میلی‌متر)','thickness_mm':'ضخامت (میلی‌متر)',
 'construction_standard':'استاندارد ساخت و ویرایش','end_connection':'اتصال انتهایی — عین منبع','trim':'تریم — عین منبع','operator':'عملگر / محرک / ارجاع دیتاشیت — عین منبع','face_to_face_mm':'فیس‌تو‌فیس (میلی‌متر)',
 'gasket_type':'ساختار گسکت — عین منبع','ring_number':'شناسه رینگ — عین منبع','inside_diameter_mm':'قطر داخلی (میلی‌متر)',
 'thread_standard':'استاندارد رزوه و ویرایش','length_mm':'طول پیچ یا استادبولت (میلی‌متر)','nut_height_mm':'ارتفاع مهره (میلی‌متر)','coating':'پوشش — عین منبع',
 'family_locked':'تغییر گروه متریال در اصلاح مجاز نیست؛ یک تعریف مستقل ثبت کنید.',
 'not_b169':'B16.9 استاندارد فیتینگ است، نه استاندارد قطعه برای این گروه متریال.',
}
for lang,data in [('en',en),('fa',fa)]:
 def q(s):return "'"+s.replace('\\','\\\\').replace("'","\\'")+"'"
 (base/f'lang/{lang}/project_materials.php').write_text('<?php\nreturn [\n'+''.join(f'    {q(k)} => {q(v)},\n' for k,v in data.items())+'];\n')
p=base/'resources/views/piping/project-fittings.blade.php';s=p.read_text()
s=s.replace("    $r = fn", "    $m = fn ($key) => __('project_materials.'.$key, [], $lang);\n    $r = fn",1)
s=s.replace("    $base =", "    if ($family !== 'FITTING') { $q['family'] = $family; }\n    $base =",1)
s=s.replace("$t('title')", "$m('title')")
s=s.replace("<p class=\"spec-help\">{{ $t('intro') }}</p><p class=\"spec-help\">{{ $t('scope') }}</p>", '''<p class="spec-help">{{ $m('intro') }}</p><p class="spec-help">{{ $m('scope') }}</p>
<nav class="spec-meta" aria-label="{{ $m('title') }}">@foreach(\\App\\Services\\ProjectMaterialSchema::FAMILIES as $group)
<a href="{{ $base.'?'.http_build_query(array_merge($q,['family'=>$group])) }}" @if($family===$group) aria-current="page" style="font-weight:bold;color:var(--text)" @endif>{{ $m($group) }}</a>
@endforeach</nav>
<h2>{{ $m($family) }}</h2>
@if($family==='FITTING')<p class="spec-help">{{ $t('intro') }} {{ $t('scope') }}</p>@else<p class="spec-help">{{ $m('notice') }}</p>@endif''')
s=s.replace('<label>{{ $t(\'component_type\') }}', "@if($family==='FITTING')\n<label>{{ $t('component_type') }}",1)
pos="@foreach(['spec_number'=>120,'spec_revision'=>60,'spec_clause'=>120] as $field=>$max)"
s=s.replace(pos, "@else\n@include('piping.partials.project-material-fields')\n@endif\n"+pos)
s=s.replace("</div><p class=\"spec-help\">{{ $t('precision') }}</p>", "</div>@if($family==='FITTING')<p class=\"spec-help\">{{ $t('precision') }}</p>@endif")
s=s.replace("<h3>{{ $t($row->component_type) }}", "@if($family==='FITTING')\n<h3>{{ $t($row->component_type) }}",1)
s=s.replace("<p><bdi>{{ $row->spec_number }}", '''@else
<h3>{{ $m($family) }} · <bdi>{{ $row->qualifier }}</bdi> · <bdi>{{ $row->size_designation }}</bdi></h3>
@foreach(['reference_standard','material_spec','material_grade','declaration_reason'] as $field)
@if($row->{$field})<p>{{ $m($field) }}: <bdi>{{ $row->{$field} }}</bdi></p>@endif
@endforeach
@foreach(($row->properties ?? []) as $field=>$value)<p>{{ $m($field) }}: <bdi>{{ $value }}</bdi></p>@endforeach
@endif
<p><bdi>{{ $row->spec_number }}''',1)
p.write_text(s)
p=base/'resources/views/piping/partials/fitting-review-policy.blade.php';s=p.read_text().replace("<h2>{{ $r('workflow') }}</h2>", "<h2>{{ $r('workflow') }} · {{ $m($family) }}</h2>\n<p class=\"spec-help\">{{ $m('policy_scope') }}</p>")
p.write_text(s)
# Navigation retains existing stable URL; business-facing label becomes generic.
for lang in ['en','fa']:
 p=base/f'lang/{lang}/project_fittings.php';s=p.read_text();import re
 s=re.sub(r"('nav'\s*=>\s*)'[^']*'",lambda m:m.group(1)+q('Project material declarations' if lang=='en' else 'تعریف‌های اختصاصی متریال'),s)
 p.write_text(s)
