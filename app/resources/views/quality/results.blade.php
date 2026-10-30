<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('quality_results.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1100px;padding:20px;overflow-wrap:anywhere">
<h1>{{ __('quality_results.title') }}</h1>
<p>{{ __('quality_results.boundary') }}</p>
<p><a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa','joint'=>$joint?->id,'pack'=>$pack?->id]) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('quality.nominations',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_results.nominations') }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

<h2>{{ __('quality_results.your_authority') }}</h2>
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr><th data-sort="text" data-facet>{{ __('quality_results.action') }}</th><th data-sort="text" data-facet>{{ __('quality_results.you') }}</th></tr></thead><tbody>
@foreach(['record_weld'=>'action_record_weld','record_ndt'=>'action_record_ndt','record_pack_result'=>'action_record_pack_result'] as $action=>$key)
<tr><td>{{ __('quality_nominations.'.$key) }}</td><td id="nominated-{{ $action }}" data-facet-value="{{ $nominations[$action] ? __('quality_results.nominated') : __('quality_results.not_nominated') }}">{{ $nominations[$action] ? __('quality_results.nominated') : __('quality_results.not_nominated') }}</td></tr>
@endforeach
</tbody></table>

{{-- ---------------------------------------------------------- joints --}}
<h2>{{ __('quality_results.joints') }}</h2>
@if($joints->isEmpty())
<p id="no-joints">{{ __('quality_results.no_joints') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr>
<th data-sort="text">{{ __('quality_results.joint') }}</th><th data-sort="text" data-facet>{{ __('quality_results.state') }}</th><th>{{ __('quality_results.line') }}</th><th>{{ __('quality_results.select') }}</th>
</tr></thead><tbody>
@foreach($joints as $j)
<tr><td>{{ $j->displayNumber() ?? $j->id }}</td><td>{{ $j->state }}</td><td>{{ $j->line?->line_number ?? '—' }}</td><td><a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang,'joint'=>$j->id]) }}">{{ __('quality_results.open') }}</a></td></tr>
@endforeach
</tbody></table>
@endif

@if($joint)
<h3 id="joint-heading">{{ __('quality_results.joint') }} {{ $joint->displayNumber() ?? $joint->id }} — {{ $joint->state }}</h3>

@if($nominations['record_weld'])
<form id="weld-form" method="post" action="{{ route('quality.results.weld',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="joint_id" value="{{ $joint->id }}"><input type="hidden" name="weld[request_id]" value="{{ $requestId }}">
<h4>{{ __('quality_results.weld_title') }}</h4>
<p><label>{{ __('quality_results.kind') }} <select name="weld[kind]"><option value="WELD">WELD</option><option value="REPAIR_WELD">REPAIR_WELD</option></select></label>
<label>{{ __('quality_results.stamp') }} <input required maxlength="64" name="weld[welded_by_code]"></label>
<label>{{ __('quality_results.at') }} <input required type="datetime-local" name="weld[welded_at]"></label></p>
<p><label>{{ __('quality_results.process') }} <input maxlength="32" name="weld[weld_process]"></label>
<label>{{ __('quality_results.consumable') }} <input maxlength="128" name="weld[consumable_lot_ref]"></label>
<label>{{ __('quality_results.mode') }} <select name="weld[mode]"><option value="IN_SYSTEM">IN_SYSTEM</option><option value="EXTERNAL_REGISTER">EXTERNAL_REGISTER</option></select></label></p>
<label style="display:block">{{ __('quality_results.reason') }}<textarea maxlength="4000" name="weld[reason]" style="display:block;width:100%"></textarea></label>
<button type="submit" id="record-weld">{{ __('quality_results.record_weld') }}</button></form>
@else<p id="weld-denied">{{ __('quality_results.denied_weld') }}</p>@endif

@if($nominations['record_ndt'])
<form id="ndt-form" method="post" action="{{ route('quality.results.ndt',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="joint_id" value="{{ $joint->id }}"><input type="hidden" name="ndt[request_id]" value="{{ $requestId }}">
<h4>{{ __('quality_results.ndt_title') }}</h4>
<p><label>{{ __('quality_results.method') }} <select name="ndt[method]">@foreach(['RT','UT','MT','PT','VT'] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach</select></label>
<label>{{ __('quality_results.result') }} <select name="ndt[result]"><option value="ACCEPTED">ACCEPTED</option><option value="REJECTED">REJECTED</option></select></label>
<label>{{ __('quality_results.stamp') }} <input required maxlength="64" name="ndt[performed_by_code]"></label>
<label>{{ __('quality_results.at') }} <input required type="datetime-local" name="ndt[performed_at]"></label></p>
<p><label>{{ __('quality_results.technique') }} <input maxlength="128" name="ndt[technique_ref]"></label>
<label>{{ __('quality_results.criteria') }} <input maxlength="128" name="ndt[acceptance_criteria_ref]"></label>
<label>{{ __('quality_results.mode') }} <select name="ndt[mode]"><option value="IN_SYSTEM">IN_SYSTEM</option><option value="EXTERNAL_REGISTER">EXTERNAL_REGISTER</option></select></label></p>
<label style="display:block">{{ __('quality_results.reject_reason') }}<textarea maxlength="4000" name="ndt[reject_reason]" style="display:block;width:100%"></textarea></label>
<button type="submit" id="record-ndt">{{ __('quality_results.record_ndt') }}</button></form>
@else<p id="ndt-denied">{{ __('quality_results.denied_ndt') }}</p>@endif

@if($history)
<h4>{{ __('quality_results.welds') }}</h4>
@if($history['welds']->isEmpty())<p>{{ __('quality_results.none_welds') }}</p>@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="weld-history"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_results.kind') }}</th><th data-sort="text">{{ __('quality_results.stamp') }}</th><th data-sort="text">{{ __('quality_results.at') }}</th><th data-sort="text" data-facet>{{ __('quality_results.mode') }}</th><th>{{ __('quality_results.reason') }}</th>
</tr></thead><tbody>
@foreach($history['welds'] as $w)
<tr><td>{{ $w->kind }}</td><td>{{ $w->welded_by_code }}</td><td>{{ $w->welded_at }}</td><td>{{ $w->mode }}</td><td>{{ $w->reason ?? '—' }}</td></tr>
@endforeach
</tbody></table>@endif

<h4>{{ __('quality_results.ndt_results') }}</h4>
@if($history['ndt']->isEmpty())<p>{{ __('quality_results.none_ndt') }}</p>@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="ndt-history"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_results.method') }}</th><th data-sort="text" data-facet>{{ __('quality_results.result') }}</th><th data-sort="text">{{ __('quality_results.stamp') }}</th><th data-sort="text">{{ __('quality_results.at') }}</th><th>{{ __('quality_results.reject_reason') }}</th>
</tr></thead><tbody>
@foreach($history['ndt'] as $n)
<tr><td>{{ $n->method }}</td><td>{{ $n->result }}</td><td>{{ $n->performed_by_code }}</td><td>{{ $n->performed_at }}</td><td>{{ $n->reject_reason ?? '—' }}</td></tr>
@endforeach
</tbody></table>@endif
@endif
@endif

{{-- ------------------------------------------------------ test packs --}}
<h2>{{ __('quality_results.packs') }}</h2>
@if($packs->isEmpty())
<p id="no-packs">{{ __('quality_results.no_packs') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr>
<th data-sort="text">{{ __('quality_results.pack') }}</th><th data-sort="text" data-facet>{{ __('quality_results.state') }}</th><th>{{ __('quality_results.select') }}</th>
</tr></thead><tbody>
@foreach($packs as $p)
<tr><td>{{ $p->code }}</td><td>{{ $p->state }}</td><td><a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang,'pack'=>$p->id]) }}">{{ __('quality_results.open') }}</a></td></tr>
@endforeach
</tbody></table>
@endif

@if($pack)
<h3 id="pack-heading">{{ __('quality_results.pack') }} {{ $pack->code }} — {{ $pack->state }}</h3>
@if($nominations['record_pack_result'])
<form id="pack-form" method="post" action="{{ route('quality.results.pack',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="test_pack_id" value="{{ $pack->id }}"><input type="hidden" name="pack[request_id]" value="{{ $requestId }}">
<p><label>{{ __('quality_results.test_type') }} <select name="pack[test_type]"><option value="HYDROSTATIC">HYDROSTATIC</option><option value="PNEUMATIC">PNEUMATIC</option></select></label>
<label>{{ __('quality_results.result') }} <select name="pack[result]"><option value="PASS">PASS</option><option value="FAIL">FAIL</option></select></label>
<label>{{ __('quality_results.medium') }} <input required maxlength="32" name="pack[medium]"></label></p>
<p><label>{{ __('quality_results.pressure') }} <input required name="pack[test_pressure]" inputmode="decimal"></label>
<label>{{ __('quality_results.pressure_unit') }} <select name="pack[pressure_unit]">@foreach($pressureUnits as $u)<option value="{{ $u['code'] }}">{{ $pressureUnitLabels[$u['code']] ?? $u['code'] }}</option>@endforeach</select></label>
<label>{{ __('quality_results.hold') }} <input required type="number" min="0" step="1" name="pack[hold_duration_minutes]"></label></p>
<p><label>{{ __('quality_results.started') }} <input required type="datetime-local" name="pack[started_at]"></label>
<label>{{ __('quality_results.completed') }} <input required type="datetime-local" name="pack[completed_at]"></label>
<label>{{ __('quality_results.mode') }} <select name="pack[mode]"><option value="IN_SYSTEM">IN_SYSTEM</option><option value="EXTERNAL_REGISTER">EXTERNAL_REGISTER</option></select></label></p>
<label style="display:block">{{ __('quality_results.failure_reason') }}<textarea maxlength="4000" name="pack[failure_reason]" style="display:block;width:100%"></textarea></label>
<button type="submit" id="record-pack">{{ __('quality_results.record_pack') }}</button></form>
@else<p id="pack-denied">{{ __('quality_results.denied_pack') }}</p>@endif

@if($packResults)
<h4>{{ __('quality_results.pack_results') }}</h4>
@if($packResults->isEmpty())<p>{{ __('quality_results.none_pack_results') }}</p>@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="pack-results"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_results.test_type') }}</th><th data-sort="text" data-facet>{{ __('quality_results.result') }}</th><th data-sort="num">{{ __('quality_results.pressure') }}</th><th data-sort="text">{{ __('quality_results.pressure_unit') }}</th><th data-sort="num">{{ __('quality_results.hold') }}</th><th data-sort="text">{{ __('quality_results.completed') }}</th>
</tr></thead><tbody>
@foreach($packResults as $r)
<tr><td>{{ $r->test_type }}</td><td>{{ $r->result }}</td><td>{{ $r->test_pressure }}</td><td>{{ $r->pressure_unit }}</td><td>{{ $r->hold_duration_minutes }}</td><td>{{ $r->completed_at }}</td></tr>
@endforeach
</tbody></table>@endif
@endif
@endif
</section></div></div></body></html>
