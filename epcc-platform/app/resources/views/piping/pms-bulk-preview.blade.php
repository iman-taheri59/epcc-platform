@php
$t=fn($key)=>__('pms.'.$key,[],$lang);
$q=['lang'=>$lang]+(request()->query('sid')?['sid'=>request()->query('sid')]:[]);
$pretty=fn($v)=>json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
@endphp
<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $t('bulk_preview') }}</title>
@include('partials.shell')
<style>.bulk-card{border:1px solid var(--line);background:var(--panel);border-radius:12px;padding:20px;margin:18px 0;overflow-wrap:anywhere}.bulk-data{display:grid;grid-template-columns:minmax(120px,1fr) minmax(0,2fr);gap:10px}.bulk-data dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.bulk-data dt{color:var(--muted)}.bulk-warning{border-inline-start:3px solid #d9ad58;padding-inline-start:12px}.bulk-save{background:#7254e8;color:white;border:0;border-radius:8px;padding:12px 20px;font:inherit;cursor:pointer}.pms-warning{margin:10px 0}.pms-source{white-space:pre-wrap}@media(max-width:600px){.bulk-data{grid-template-columns:1fr}}</style>
</head><body><div class="app">@include('partials.sidebar')<main class="wrap">@include('partials.topbar')
<h1>{{ $t('bulk_preview') }} · <bdi>{{ $plan['class']->code }}</bdi></h1>
<p>{{ $t('bulk_preview_help') }}</p><p>{{ $t('b169') }}</p>
<a href="{{ route('pms.show',['projectCode'=>$project->code,'classId'=>$plan['class']->id]+$q) }}#row-editor">{{ $t('bulk_back') }}</a>
<section class="bulk-card"><h2>{{ $t('bulk_reason') }}</h2><p class="pms-source">{{ $plan['input']['reason'] }}</p></section>
@if($plan['conflicts'])<p class="bulk-warning" role="alert">{{ $t('bulk_conflicts') }}</p>@endif
@foreach($plan['rows'] as $row)
<article class="bulk-card" data-bulk-dn="{{ $row['data']['dn'] }}">
<h2><bdi>{{ $row['data']['component_type'] }} {{ $row['data']['qualifier']??'' }} · NPS {{ $row['data']['nps'] }} · DN {{ $row['data']['dn'] }}</bdi></h2>
<p @class(['bulk-warning'=>$row['conflict']])>{{ $t($row['conflict']?'bulk_conflict':'bulk_ready') }}</p>
<dl class="bulk-data">@foreach($row['data'] as $key=>$value)<dt>{{ $key==='note_numbers'?$t('note_numbers_text'):$t($key) }}</dt><dd dir="auto">{{ is_array($value)?implode(', ',$value):($value??'—') }}</dd>@endforeach</dl>
@if($row['data']['component_type']==='VALVE')@include('piping.pms-valve-summary',['valveData'=>$row['valve_spec']])@endif
@if($row['findings'] || $row['links'])<details open><summary>{{ $t('findings') }}</summary>@foreach($row['findings'] as $finding)@include('piping.partials.pms-finding',['finding'=>$finding])@endforeach @foreach($row['links'] as $key)<p class="bulk-warning">{{ $t($key) }}</p>@endforeach</details>@endif
</article>
@endforeach
@if($plan['token'])
<form method="post" action="{{ route('pms.bulk.confirm',['projectCode'=>$project->code,'classId'=>$plan['class']->id]+$q) }}" id="pms-bulk-confirm-form">@csrf
<input type="hidden" name="preview_token" value="{{ $plan['token'] }}"><button class="bulk-save">{{ $t('bulk_confirm') }} ({{ count($plan['rows']) }})</button>
</form>
@endif
</main></div></body></html>
