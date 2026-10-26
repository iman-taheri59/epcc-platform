<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ __('demand_readers.title') }}</title>@include('partials.shell')
<style>.demand-card{max-width:1100px;border:1px solid var(--line);background:var(--card);border-radius:12px;padding:20px;margin:18px 0;overflow-wrap:anywhere}.demand-card label{display:block;margin:10px 0}.demand-card input[type=text],.demand-card textarea,.demand-card select{max-width:100%;padding:9px}.demand-card td,.demand-card th{padding:12px;text-align:start;border-bottom:1px solid var(--line)}.demand-scroll{overflow:auto}.demand-warning{border-inline-start:4px solid #b58a41;padding-inline-start:12px}.demand-options{max-height:300px;overflow:auto}</style></head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')
<section class="demand-card"><h1>{{ __('demand_readers.title') }}</h1><p class="demand-warning">{{ __('demand_readers.boundary') }}</p>
<p><a href="{{ route('materials.demand-readers',array_merge(request()->only(['target_company_id','batch_id','document_id','scope_id','hierarchy_revision_id']),['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa'])) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('materials.piping-traceability',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('demand_readers.back') }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<p><a href="{{ route('materials.demand-reader-suspensions',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('demand_readers.suspension_title') }}</a></p>
<p><a href="{{ route('materials.scope-admission-actors',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('scope_admission_authority.title') }}</a></p><p>{{ __('demand_readers.select_help') }}</p>
<form method="get" action="{{ route('materials.demand-readers',['projectCode'=>$project->code]) }}"><input type="hidden" name="lang" value="{{ $lang }}"><label>{{ __('demand_readers.company') }} <select name="target_company_id" required><option value="">—</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected($target===$company->id)>{{ $company->code }} · {{ $company->name($lang) }}</option>@endforeach</select></label><button>{{ __('demand_readers.choose') }}</button></form></section>
<section class="demand-card"><h2>{{ __('demand_readers.picker') }}</h2><div class="demand-scroll"><table><thead><tr><th>MTO</th><th>{{ __('demand_readers.scope') }}</th><th></th></tr></thead><tbody>
@forelse($sources as $source)<tr><td>{{ $source->document_number }} · {{ $source->revision_code }}<br>{{ $source->batch_id }}</td><td>{{ $source->scope_id }}<br>{{ $source->hierarchy_revision_id }}</td><td>@if($target)<a href="{{ route('materials.demand-readers',['projectCode'=>$project->code,'lang'=>$lang,'target_company_id'=>$target,'batch_id'=>$source->batch_id,'document_id'=>$source->document_id,'scope_id'=>$source->scope_id,'hierarchy_revision_id'=>$source->hierarchy_revision_id]) }}">{{ __('demand_readers.open') }}</a>@endif</td></tr>@empty<tr><td colspan="3">{{ __('demand_readers.empty') }}</td></tr>@endforelse</tbody></table></div>{{ $sources->links() }}</section>
@if($selected)
<section class="demand-card"><h2>{{ __('demand_readers.nominees') }}</h2><p>{{ $selected['document_id'] }} · {{ $selected['scope_id'] }} · {{ $selected['hierarchy_revision_id'] }}</p>
@if(!$writable)<p>{{ __('demand_readers.archive') }}</p>@else
@php
$hasOld=session()->hasOldInput('policy');
$checked=$hasOld?old('policy.selectors',[]):($current?json_decode($current->selectors,true):[]);
$checked=is_array($checked)?array_filter($checked,'is_string'):[];
$v=$hasOld?old('policy.version',''):($current->version??0); $v=is_scalar($v)?(string)$v:'';
$state=$hasOld?old('policy.suspended','0'):($current?($current->suspended?'1':'0'):'0');$state=is_scalar($state)?(string)$state:'';
$reason=$hasOld?old('policy.reason',''):'';$reason=is_string($reason)?$reason:'';
@endphp
<form id="demand-reader-policy-form" method="post" action="{{ route('materials.demand-readers.store',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
@foreach(['target_company_id','document_id','scope_id','hierarchy_revision_id'] as $field)<input type="hidden" name="policy[{{ $field }}]" value="{{ $selected[$field] }}">@endforeach
<label>{{ __('demand_readers.version') }} <input type="text" name="policy[version]" value="{{ $v }}" readonly></label>
<label>{{ __('demand_readers.suspended') }} <select name="policy[suspended]" required><option value="0" @selected($state==='0')>{{ __('demand_readers.live') }}</option><option value="1" @selected($state==='1')>{{ __('demand_readers.suspend') }}</option></select></label>
<div class="demand-options">@foreach($options as $selector=>$label)<label><input type="checkbox" name="policy[selectors][]" value="{{ $selector }}" @checked(in_array($selector,$checked,true))> {{ $label }}</label>@endforeach</div>
<label>{{ __('demand_readers.reason') }}<textarea name="policy[reason]" required maxlength="2000" rows="3">{{ $reason }}</textarea></label><button>{{ __('demand_readers.save') }}</button></form>
@endif
<p><a href="{{ route('materials.demand-readiness',['projectCode'=>$project->code,'lang'=>$lang,'batch_id'=>$selected['batch_id'],'document_id'=>$selected['document_id'],'scope_id'=>$selected['scope_id'],'hierarchy_revision_id'=>$selected['hierarchy_revision_id']]) }}">{{ __('demand_readers.read_link') }}</a></p><p>{{ __('demand_readers.owner_warning') }}</p>
<p><a href="{{ route('materials.demand-readers',array_merge($selected,['projectCode'=>$project->code,'lang'=>$lang])) }}">{{ __('demand_readers.reload') }}</a></p>
<h2>{{ __('demand_readers.history') }}</h2>@foreach($history as $h)<article><h3>#{{ $h->version }} · {{ $h->suspended?__('demand_readers.suspend'):__('demand_readers.live') }}</h3><p>{{ $h->reason }}</p><p>{{ $h->created_at }} · {{ $h->created_by }}</p><p>{{ __('demand_readers.policy_ref') }}: {{ $h->id }}</p><ul>@foreach(json_decode($h->selectors,true) as $recordedSelector)<li>{{ $recordedSelector }}</li>@endforeach</ul></article>@endforeach
</section>@endif
</div></div></body></html>
