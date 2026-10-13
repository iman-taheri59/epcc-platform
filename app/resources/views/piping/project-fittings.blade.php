@php
    $m = fn ($key) => __('project_materials.'.$key, [], $lang);
    $r = fn ($key) => __('fitting_review.'.$key, [], $lang);
    $t = fn ($key) => __('project_fittings.'.$key, [], $lang);
    $num = fn ($value) => \App\Support\JalaliDate::digits((string) $value, $lang);
    $q = ['lang' => $lang] + (request()->query('sid') ? ['sid' => request()->query('sid')] : []);
    if ($family !== 'FITTING') { $q['family'] = $family; }
    $base = '/projects/'.rawurlencode($project->code).'/piping/project-fittings';
    $action = fn ($suffix) => $base.$suffix.'?'.http_build_query($q);
    $url = '/projects/'.rawurlencode($project->code).'/piping/project-fittings?'.http_build_query($q);
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $m('title') }} — {{ $project->code }}</title>
@include('partials.shell')
<style>
.spec-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.spec-grid label{display:flex;flex-direction:column;gap:7px;font-size:13px}.spec-grid input,.spec-grid select,.spec-grid textarea{box-sizing:border-box;min-width:0;width:100%;padding:10px;background:var(--bg);color:inherit;border:1px solid var(--line);border-radius:8px;font:inherit}.spec-wide{grid-column:1/-1}.spec-card{padding:20px;border:1px solid var(--line);border-radius:12px;margin:18px 0;background:var(--panel)}.spec-card p{overflow-wrap:anywhere}.spec-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:13px;color:var(--muted)}.spec-badge{padding:3px 10px;border-radius:20px;background:#554112;color:#ffe6a3;font-size:12px}.spec-source{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.8}.spec-button{padding:10px 18px;border:0;border-radius:8px;background:#7254e8;color:#fff;cursor:pointer;font:inherit}.spec-error{padding:14px;background:#492331;border-radius:10px;color:#ffd5db}.spec-help{line-height:1.9;color:var(--muted);font-size:14px;max-width:85ch}@media(max-width:650px){.spec-grid{grid-template-columns:1fr}.spec-card{padding:14px}}
</style>
</head>
<body><div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')
<h1>{{ $m('title') }}</h1>

<p class="sub">{{ $project->name($lang) }} · <a href="/projects/{{ $project->code }}/piping?{{ http_build_query($q) }}">{{ $t('back') }}</a></p>
<p class="spec-help">{{ $m('intro') }}</p><p class="spec-help">{{ $m('scope') }}</p>
<nav class="spec-meta" aria-label="{{ $m('title') }}">@foreach(\App\Services\ProjectMaterialSchema::FAMILIES as $group)
<a href="{{ $base.'?'.http_build_query(array_merge($q,['family'=>$group])) }}" @if($family===$group) aria-current="page" style="font-weight:bold;color:var(--text)" @endif>{{ $m($group) }}</a>
@endforeach</nav>
<h2>{{ $m($family) }}</h2>
@if($family==='FITTING')<p class="spec-help">{{ $t('intro') }} {{ $t('scope') }}</p>@else<p class="spec-help">{{ $m('notice') }}</p>@endif
@if(session('status'))<p role="status" style="color:#87e3b6">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="spec-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@include('piping.partials.fitting-review-policy')
@if($canCreate)
<section class="spec-card"><h2>{{ $editing ? $r('correcting') : $t('new') }}</h2>
@if($editing)<p>{{ $r('parent') }}: <bdi>{{ $editing->id }}</bdi> · <a href="{{ $url }}">{{ $r('cancel') }}</a></p>@endif
<form method="post" action="{{ $editing ? $action('/'.$editing->id.'/correct') : $url }}" id="project-fitting-form">
@csrf
<input type="hidden" name="lang" value="{{ $lang }}">
<div class="spec-grid">
@if($family==='FITTING')
<label>{{ $t('component_type') }}<select name="component_type" required>@foreach(['ELBOW','CAP'] as $value)<option value="{{ $value }}" @selected(old('component_type',$editing?->component_type ?? 'ELBOW')===$value)>{{ $t($value) }}</option>@endforeach</select></label>
<label>{{ $t('qualifier') }}<input name="qualifier" maxlength="80" value="{{ old('qualifier',$editing?->qualifier) }}"></label>
@foreach(['nps','dn','od_mm','dimension_mm'] as $field)
<label>{{ $t($field) }}<input name="{{ $field }}" type="number" dir="ltr" min="{{ $field==='dn' ? '1' : '0.0001' }}" step="{{ $field==='dn' ? '1' : '0.0001' }}" value="{{ old($field,$editing?->{$field}) }}" @required($field !== 'dn')></label>
@endforeach
<label>{{ $t('dimension') }}<select name="dimension" required>@foreach(['CENTRE_TO_END','LENGTH'] as $value)<option value="{{ $value }}" @selected(old('dimension',$editing?->dimension ?? 'CENTRE_TO_END')===$value)>{{ $t($value) }}</option>@endforeach</select></label>
@else
@include('piping.partials.project-material-fields')
@endif
@foreach(['spec_number'=>120,'spec_revision'=>60,'spec_clause'=>120] as $field=>$max)
<label>{{ $t($field) }}<input name="{{ $field }}" maxlength="{{ $max }}" value="{{ old($field,$editing?->{$field}) }}" required></label>
@endforeach
<label class="spec-wide">{{ $t('source_text') }}<textarea name="source_text" rows="4" maxlength="10000" required>{{ old('source_text',$editing?->source_text) }}</textarea></label>
@if($editing)<label class="spec-wide">{{ $r('correction_reason') }}<textarea name="correction_reason" required maxlength="4000">{{ old('correction_reason') }}</textarea></label>@endif
</div>@if($family==='FITTING')<p class="spec-help">{{ $t('precision') }}</p>@endif<button class="spec-button" type="submit">{{ $t('submit') }}</button>
</form></section>
@else<p class="spec-error">{{ $t('archived') }}</p>@endif
<h2>{{ $t('register') }} · {{ $num($rows->total()) }}</h2>
<p class="spec-help">{{ $r('no_auto_use') }}</p>
@forelse($rows as $row)
<article class="spec-card" data-definition="{{ $row->id }}">
<div class="spec-meta"><span class="spec-badge">{{ $r($reviewStates[$row->id]['state']) }}</span><span>{{ $t('provenance') }}</span></div>
@if($family==='FITTING')
<h3>{{ $t($row->component_type) }} <bdi>{{ $row->qualifier }}</bdi> · NPS <bdi>{{ $num($row->nps) }}</bdi></h3>
<p>{{ $t('od_mm') }}: <bdi>{{ $num($row->od_mm) }}</bdi> · {{ $t($row->dimension) }}: <bdi>{{ $num($row->dimension_mm) }}</bdi> mm @if($row->dn !== null) · DN <bdi>{{ $num($row->dn) }}</bdi>@endif</p>
@else
<h3>{{ $m($family) }} · <bdi>{{ $row->qualifier }}</bdi> · <bdi>{{ $row->size_designation }}</bdi></h3>
@foreach(['reference_standard','material_spec','material_grade','declaration_reason'] as $field)
@if($row->{$field})<p>{{ $m($field) }}: <bdi>{{ $row->{$field} }}</bdi></p>@endif
@endforeach
@foreach(($row->properties ?? []) as $field=>$value)<p>{{ $m($field) }}: <bdi>{{ $value }}</bdi></p>@endforeach
@endif
<p><bdi>{{ $row->spec_number }}</bdi> · {{ $t('spec_revision') }}: <bdi>{{ $row->spec_revision }}</bdi> · {{ $t('spec_clause') }}: <bdi>{{ $row->spec_clause }}</bdi></p>
<p class="spec-source" dir="auto">{{ $row->source_text }}</p>
<div class="spec-meta"><span>{{ $t('author') }}: <bdi>{{ $row->created_by }}</bdi></span><span>{{ $t('company') }}: <bdi>{{ $row->company_id }}</bdi></span><time>{{ \App\Support\JalaliDate::format($row->created_at,$lang) }}</time></div>
@include('piping.partials.material-screening')
@include('piping.partials.fitting-review-actions')
</article>
@empty<p>{{ $t('empty') }}</p>@endforelse
<nav aria-label="Pagination" class="spec-meta">@if($rows->previousPageUrl())<a href="{{ $rows->previousPageUrl() }}">{{ $t('previous') }}</a>@endif @if($rows->nextPageUrl())<a href="{{ $rows->nextPageUrl() }}">{{ $t('next') }}</a>@endif</nav>
</div></div></body></html>
