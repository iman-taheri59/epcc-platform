@php
$t=fn($key)=>__('pms.'.$key,[],$lang);
$q=['lang'=>$lang]+(request()->query('sid')?['sid'=>request()->query('sid')]:[]);
$base='/projects/'.rawurlencode($project->code).'/piping/pms';
$url=fn($path='',$extra=[])=>$base.$path.'?'.http_build_query($extra+$q);
$h=function($key,$fallback=null)use($class){$v=old('header.target')===($class?->id??'new')?old('header.'.$key,$fallback):$fallback;return is_scalar($v)||$v===null?$v:$fallback;};
$i=function($key,$fallback=null)use($editing){$v=old('item.target')===($editing?->id??'new')?old('item.'.$key,$fallback):$fallback;return is_scalar($v)||$v===null?$v:$fallback;};
$n=function($key,$fallback=null)use($class){$v=old('note.target')===$class?->id?old('note.'.$key,$fallback):$fallback;return is_scalar($v)||$v===null?$v:$fallback;};
$specialRaw=$class?->special_requirements;
$specialValid=$specialRaw===null || (is_array($specialRaw) && array_is_list($specialRaw) && count(array_filter($specialRaw,'is_string'))===count($specialRaw));
$specialEntries=$specialValid?($specialRaw??[]):[];
$pretty=fn($v)=>json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
@endphp
<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $t('title') }} · {{ $project->code }}</title>
@include('partials.shell')
<style>
.pms-card{padding:22px;border:1px solid var(--line);border-radius:14px;background:var(--panel);margin:18px 0;min-width:0}.pms-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.pms-grid label{display:flex;flex-direction:column;gap:7px;font-size:13px;min-width:0}.pms-grid input,.pms-grid select,.pms-grid textarea{width:100%;min-width:0;box-sizing:border-box;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text);padding:10px;font:inherit}.pms-wide{grid-column:1/-1}.pms-help{color:var(--muted);line-height:1.9;font-size:14px;max-width:90ch}.pms-nav{display:flex;gap:10px;flex-wrap:wrap}.pms-nav a{border:1px solid var(--line);padding:8px 12px;border-radius:8px}.pms-nav a[aria-current]{border-color:#9074ef;background:#7254e820}.pms-save{background:#7254e8;color:white;border:0;border-radius:8px;padding:11px 20px;font:inherit;cursor:pointer;margin-top:16px}.pms-alert{background:#492331;color:#ffd5db;padding:12px;border-radius:8px}.pms-warning{border-inline-start:3px solid #d9ad58;padding-inline-start:12px;margin:10px 0}.pms-card p,.pms-card li,.pms-card dd{overflow-wrap:anywhere}.pms-source{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit;line-height:1.8}.pms-json{white-space:pre-wrap;overflow-wrap:anywhere;max-height:380px;overflow:auto;background:var(--bg);padding:12px;border-radius:8px;font:12px/1.8 monospace}.pms-pairs{display:grid;grid-template-columns:minmax(100px,1fr) minmax(0,2fr);gap:7px}.pms-pairs dt{color:var(--muted)}.pms-pairs dd{margin:0}summary{cursor:pointer;padding:8px 0}nav[role=navigation] svg{width:20px}@media(max-width:650px){.pms-grid{grid-template-columns:1fr}.pms-card{padding:15px}.pms-pairs{grid-template-columns:1fr}.pms-pairs dd{margin-bottom:7px}}
</style></head><body><div class="app">@include('partials.sidebar')<main class="wrap">@include('partials.topbar')
<h1>{{ $t('title') }}</h1><p class="sub">{{ $project->name($lang) }} · <a href="/projects/{{ $project->code }}/piping?{{ http_build_query($q) }}">{{ $t('back') }}</a></p>
<p class="pms-help">{{ $t('intro') }}</p><p class="pms-help">{{ $t('scope') }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="pms-alert" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><a href="{{ $url($class?'/'.$class->id:'') }}">{{ $t('reload') }}</a></div>@endif
@include('piping.pms-author-access')
<h2>{{ $t('classes') }}</h2><nav class="pms-nav"><a href="{{ $url() }}">+ {{ $t('new_class') }}</a>@foreach($classes as $c)<a href="{{ $url('/'.$c->id) }}" @if($class?->id===$c->id) aria-current="page" @endif><bdi>{{ $c->code }}</bdi></a>@endforeach</nav>
@if($classes->isEmpty())<p class="pms-help">{{ $t('no_classes') }}</p>@endif
<section class="pms-card"><h2>{{ $t('header') }} @if($class)· <bdi>{{ $class->code }}</bdi>@endif</h2>
@if($canWrite)
<form method="post" action="{{ $class?$url('/'.$class->id.'/header'):$url() }}" id="pms-header-form">@csrf
<input type="hidden" name="header[target]" value="{{ $class?->id??'new' }}"><input type="hidden" name="header[version]" value="{{ $h('version',$class?->entry_version??0) }}">
<div class="pms-grid">
@foreach(['code'=>32,'source_document'=>120,'source_revision'=>60,'description_fa'=>255,'description_en'=>255,'pressure_rating'=>16] as $field=>$max)
<label>{{ $t($field) }}<input name="header[{{ $field }}]" maxlength="{{ $max }}" value="{{ $h($field,$class?->{$field}) }}" @required(in_array($field,['code','source_document','source_revision']))></label>
@endforeach
<label class="pms-wide">{{ $t('source_text') }}<textarea name="header[source_text]" rows="3" maxlength="10000" required>{{ $h('source_text',$class?->source_text) }}</textarea></label>
<details class="pms-wide"><summary>{{ $t('details') }}</summary><div class="pms-grid">
@foreach(['service_en'=>255,'service_fa'=>255,'material_as_written'=>128,'corrosion_allowance_as_written'=>16,'material_group_code'=>32,'material_spec_code'=>32,'material_grade_key'=>48] as $field=>$max)
<label>{{ $t($field) }}<input name="header[{{ $field }}]" maxlength="{{ $max }}" value="{{ $h($field,$class?->{$field}) }}"></label>@endforeach
@include('piping.pms-measurement-inputs')
@include('piping.pms-special-input')
<label class="pms-wide">{{ $t('library') }}<select name="header[material_key]"><option value="">{{ $t('none') }}</option>@foreach($catalog['materials'] as $key=>$material)<option value="{{ $key }}" @selected($h('material_key')===$key)>{{ $material['group']['code'] }} · {{ $material['label'] }}</option>@endforeach</select></label>
<p class="pms-help pms-wide">{{ $t('library_help') }}</p>
<label>{{ $t('fluid_service_category') }}<select name="header[fluid_service_category]"><option value="">{{ $t('none') }}</option>@foreach(['NORMAL','CATEGORY_D','CATEGORY_M','HIGH_PRESSURE'] as $v)<option @selected($h('fluid_service_category',$class?->fluid_service_category)===$v)>{{ $v }}</option>@endforeach</select></label>
<label>{{ $t('severe_cyclic_conditions') }}<select name="header[severe_cyclic_conditions]"><option value="0" @selected(!$h('severe_cyclic_conditions',$class?->severe_cyclic_conditions))>{{ $lang==='fa'?'خیر':'No' }}</option><option value="1" @selected($h('severe_cyclic_conditions',$class?->severe_cyclic_conditions))>{{ $lang==='fa'?'بله':'Yes' }}</option></select></label>
<label>{{ $t('default_flange_series') }}<select name="header[default_flange_series]"><option value="">{{ $t('none') }}</option>@foreach(['A','B'] as $v)<option @selected($h('default_flange_series',$class?->default_flange_series)===$v)>{{ $v }}</option>@endforeach</select></label>
</div></details>
<label class="pms-wide">{{ $t('reason') }}<textarea name="header[reason]" required maxlength="4000" rows="2">{{ $h('reason') }}</textarea></label>
</div><button class="pms-save">{{ $t('save') }}</button></form>
@else<p class="pms-help">{{ $t('read_only') }}</p>@if($class)<dl class="pms-pairs">@foreach(\App\Services\PmsEntryService::HEADER_TEXT as $f=>$max)<dt>{{ $t($f) }}</dt><dd class="pms-source">{{ $class->{$f}??'—' }}</dd>@endforeach</dl>@endif
@endif
@if($class)@include('piping.pms-measurement-summary')
@include('piping.pms-special-summary')@endif
@if($headerFindings)<details open><summary>{{ $t('findings') }}</summary>@foreach($headerFindings as $f)@include('piping.partials.pms-finding',['finding'=>$f])@endforeach</details>@endif
@if($class && $class->notes->isNotEmpty())<details><summary>{{ $t('notes') }}</summary>@foreach($class->notes as $note)<p>{{ $note->number }} · {{ $note->text($lang) }}</p>@endforeach</details>@endif
</section>
@if($class)
@include('piping.pms-ndt')
@if($canWrite)<details class="pms-card"><summary>{{ $t('note_edit') }}</summary><p class="pms-help">{{ $t('note_help') }}</p><form method="post" action="{{ $url('/'.$class->id.'/notes') }}" id="pms-note-form">@csrf
<input type="hidden" name="note[target]" value="{{ $class->id }}"><input type="hidden" name="note[version]" value="{{ $n('version',$class->entry_version) }}"><div class="pms-grid">
<label>{{ $t('note_number') }}<input name="note[number]" type="number" min="1" value="{{ $n('number') }}" required></label>
@foreach(['text_fa','text_en','reason'] as $f)<label class="pms-wide">{{ $t($f) }}<textarea name="note[{{ $f }}]" rows="2" maxlength="{{ $f==='reason'?4000:10000 }}" @required($f==='reason')>{{ $n($f) }}</textarea></label>@endforeach
</div><button class="pms-save">{{ $t('save') }}</button></form></details>@endif
@if($canWrite && (!$editing || $editing->company_id===$user->company_id))
<section class="pms-card" id="row-editor"><h2>{{ $t($editing?'edit_item':'new_item') }}</h2><p class="pms-help">{{ $t('library_help') }}</p><p class="pms-help">{{ $t('b169') }}</p>
@if($editing)<a href="{{ $url('/'.$class->id) }}">{{ $t('reload') }}</a>@endif
<form method="post" action="{{ $url('/'.$class->id.'/items'.($editing?'/'.$editing->id:'')) }}" id="pms-item-form">@csrf
<input type="hidden" name="item[target]" value="{{ $editing?->id??'new' }}"><input type="hidden" name="item[version]" value="{{ $i('version',$editing?->entry_version??0) }}">
<div class="pms-grid">
<label class="pms-wide">{{ $t('library') }}<select name="item[material_key]"><option value="">{{ $t('none') }}</option>@foreach($catalog['materials'] as $key=>$material)<option value="{{ $key }}" @selected($i('material_key')===$key)>{{ $material['group']['code'] }} · {{ $material['label'] }}</option>@endforeach</select></label>
<label>{{ $t('size_key') }}<select name="item[size_key]"><option value="">{{ $t('none') }}</option>@foreach($catalog['sizes'] as $size)<option value="{{ $size->nps }}" @selected($i('size_key')===$size->nps)>{{ $size->label() }}</option>@endforeach</select></label>
<label>{{ $t('project_definition_id') }}<select name="item[project_definition_id]"><option value="">{{ $t('none') }}</option>@foreach($definitions as $id=>$d)@if($d['state']['state']==='APPROVED' || $editing?->project_definition_id===$id)<option value="{{ $id }}" @selected($i('project_definition_id',$editing?->project_definition_id)===$id)>{{ $d['row']->component_type }} · {{ $d['row']->spec_number }} / {{ $d['row']->spec_revision }} · {{ $d['row']->size_designation??$d['row']->nps }} · {{ $t($d['state']['state']) }} · {{ substr($id,0,8) }}</option>@endif @endforeach</select></label>
<p class="pms-help pms-wide">{{ $t('definition_help') }}</p>
@foreach(\App\Services\PmsEntryService::ITEM_TEXT as $field=>$max)
@if($field==='description_as_written')<label class="pms-wide">{{ $t($field) }}<textarea name="item[{{ $field }}]" maxlength="{{ $max }}" rows="3" required>{{ $i($field,$editing?->{$field}) }}</textarea></label>
@else<label>{{ $t($field) }}<input name="item[{{ $field }}]" maxlength="{{ $max }}" value="{{ $i($field,$editing?->{$field}) }}" @required(in_array($field,['component_type','source_ref'])) @if(in_array($field,['component_type','end_type','qualifier','product_form'])) list="pms-{{ $field }}" @endif></label>@endif
@endforeach
<label>{{ $t('dn') }}<input type="number" min="1" max="2147483647" name="item[dn]" value="{{ $i('dn',$editing?->dn) }}"></label>
<label>{{ $t('note_numbers_text') }}<input name="item[note_numbers_text]" maxlength="500" value="{{ $i('note_numbers_text',implode(',',$editing?->note_numbers??[])) }}"></label>
@include('piping.pms-valve-input')
<label class="pms-wide">{{ $t('reason') }}<textarea name="item[reason]" rows="2" maxlength="4000" required>{{ $i('reason') }}</textarea></label>
</div>
@if(!$editing)
<fieldset style="margin-top:18px;border:1px solid var(--line);border-radius:8px;padding:16px" id="pms-bulk-sizes">
<legend>{{ $t('bulk_title') }}</legend><p class="pms-help">{{ $t('bulk_help') }}</p>
<div style="display:flex;flex-wrap:wrap;gap:12px;max-height:280px;overflow:auto">
@foreach($catalog['sizes'] as $size)
<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="sizes[]" value="{{ $size->nps }}" @checked(in_array($size->nps, is_array(old('sizes')) ? old('sizes') : [], true))><bdi>{{ $size->label() }}</bdi></label>
@endforeach
</div><button class="pms-save" formaction="{{ route('pms.bulk.preview', ['projectCode'=>$project->code,'classId'=>$class->id]+$q) }}">{{ $t('bulk_preview') }}</button>
</fieldset>
@endif
<button class="pms-save">{{ $t($editing?'save':'save_single') }}</button></form>
<datalist id="pms-component_type">@foreach($catalog['templates'] as $tpl)<option value="{{ $tpl['code'] }}">{{ $tpl['name'][$lang]??$tpl['code'] }}</option>@endforeach</datalist>
<datalist id="pms-qualifier">@foreach($catalog['templates'] as $tpl)@foreach($tpl['qualifiers']??[] as $v)<option value="{{ $v }}">{{ $tpl['code'] }}</option>@endforeach @endforeach</datalist>
<datalist id="pms-product_form">@foreach(collect($catalog['materials'])->flatMap(fn($m)=>$m['product_forms'])->unique('code') as $form)<option value="{{ $form['code'] }}">{{ $form['name'][$lang]??$form['code'] }}</option>@endforeach</datalist>
<datalist id="pms-end_type">@foreach($catalog['end_types'] as $v)<option value="{{ $v['code'] }}"></option>@endforeach</datalist>
</section>@endif
<details class="pms-card" id="definition-sources"><summary>{{ $t('definition_details') }}</summary>
@foreach($definitions as $id=>$d)<details data-pms-definition="{{ $id }}"><summary><bdi>{{ $d['row']->component_type }} · {{ $d['row']->spec_number }} / {{ $d['row']->spec_revision }}</bdi> · {{ $t($d['state']['state']) }}</summary>
<p class="pms-help">{{ $t('definition_help') }}</p><pre class="pms-json" dir="auto">{{ $pretty($d['row']->toArray()) }}</pre><a href="/projects/{{ $project->code }}/piping/project-fittings?{{ http_build_query($q+['family'=>$d['row']->material_family]) }}">{{ $lang==='fa'?'سوابق بررسی و تصمیم‌ها':'Review and disposition history' }}</a></details>@endforeach</details>
<h2>{{ $t('rows') }} · {{ $items->total() }}</h2>
@forelse($items as $row)<article class="pms-card" data-pms-item="{{ $row->id }}"><h3><bdi>{{ $row->component_type }} {{ $row->qualifier }} · NPS {{ $row->nps??'—' }} · DN {{ $row->dn }}</bdi></h3>
<p class="pms-help">{{ $t('revision') }} {{ $row->entry_version }} · {{ $row->source_ref }}</p>
@if($canWrite && $row->company_id===$user->company_id)<a href="{{ $url('/'.$class->id,['item'=>$row->id]) }}#row-editor">{{ $t('edit') }}</a>@endif
<p class="pms-source" dir="auto">{{ $row->description_as_written }}</p>
<details><summary>{{ $t('manual') }}</summary><dl class="pms-pairs">@foreach(\App\Services\PmsEntryService::ITEM_TEXT as $f=>$max)@if($f!=='description_as_written')<dt>{{ $t($f) }}</dt><dd><bdi>{{ $row->{$f}??'—' }}</bdi></dd>@endif @endforeach</dl></details>
@if($row->component_type==='VALVE' || $row->valveSpec)@include('piping.pms-valve-summary',['valveData'=>$row->valveSpec?->toArray()])@endif
@if($findings[$row->id] || $links[$row->id])<details open><summary>{{ $t('findings') }}</summary>@foreach($findings[$row->id] as $f)@include('piping.partials.pms-finding',['finding'=>$f])@endforeach @foreach($links[$row->id] as $f)<p class="pms-warning" data-link-finding="{{ $f }}">{{ $t($f) }}</p>@endforeach</details>@else<p class="pms-help">{{ $t('no_findings') }}</p>@endif
@if(isset($geometry[$row->id]))<details><summary>{{ $t('geometry') }}</summary><pre class="pms-json" dir="ltr">{{ $pretty($geometry[$row->id]) }}</pre></details>@endif
@if($row->entry_provenance)<details><summary>{{ $t('selected_evidence') }}</summary><pre class="pms-json" dir="auto">{{ $pretty($row->entry_provenance) }}</pre></details>@endif
</article>@empty<p class="pms-help">{{ $t('no_rows') }}</p>@endforelse
{{ $items->links() }}
<section class="pms-card"><h2>{{ $t('history') }}</h2>@foreach($history as $event)<details data-pms-event="{{ $event->id }}"><summary>{{ $event->actor_name }} · {{ $event->created_at }} UTC · {{ $t('revision') }} {{ $event->version }} · {{ $event->item_id?substr($event->item_id,0,8):$class->code }}</summary><p class="pms-source">{{ $event->reason }}</p><p>{{ $t('access_history_policy') }}: <bdi>{{ $event->author_policy_id ?? $t('access_legacy_event') }}</bdi></p><h4>{{ $t('before') }}</h4><pre class="pms-json" dir="auto">{{ $pretty(json_decode($event->before_data??'null',true)) }}</pre><h4>{{ $t('after') }}</h4><pre class="pms-json" dir="auto">{{ $pretty(json_decode($event->after_data,true)) }}</pre></details>@endforeach {{ $history->links() }}</section>
@endif
<details class="pms-card"><summary>{{ $t('references') }}</summary><dl class="pms-pairs">@foreach($catalog['provenance'] as $key=>$value)<dt><bdi>{{ $key }}</bdi></dt><dd><bdi>{{ $value }}</bdi></dd>@endforeach</dl></details>
</main></div></body></html>
