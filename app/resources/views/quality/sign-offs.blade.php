<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('quality_signoffs.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1100px;padding:20px;overflow-wrap:anywhere">
<h1>{{ __('quality_signoffs.title') }}</h1>
<p>{{ __('quality_signoffs.boundary') }}</p>
<p><a href="{{ route('quality.sign-offs',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa','notice'=>$notice?->id]) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('quality.nominations',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_signoffs.nominations') }}</a> · <a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_signoffs.results') }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

<h2>{{ __('quality_signoffs.your_authority') }}</h2>
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr><th data-sort="text" data-facet>{{ __('quality_signoffs.action') }}</th><th data-sort="text" data-facet>{{ __('quality_signoffs.you') }}</th></tr></thead><tbody>
<tr><td>{{ __('quality_nominations.action_sign_off_stage') }}</td><td id="nominated-sign_off_stage" data-facet-value="{{ $nominated ? __('quality_signoffs.nominated') : __('quality_signoffs.not_nominated') }}">{{ $nominated ? __('quality_signoffs.nominated') : __('quality_signoffs.not_nominated') }}</td></tr>
</tbody></table>

{{-- ------------------------------------------------------- worklist --}}
<h2>{{ __('quality_signoffs.worklist') }}</h2>
@if($notices->isEmpty())
<p id="no-notices">{{ __('quality_signoffs.no_notices') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr>
<th data-sort="text">{{ __('quality_signoffs.number') }}</th><th data-sort="text" data-facet>{{ __('quality_signoffs.point') }}</th><th data-sort="text">{{ __('quality_signoffs.work_reference') }}</th><th>{{ __('quality_signoffs.activity') }}</th><th>{{ __('quality_signoffs.select') }}</th>
</tr></thead><tbody>
@foreach($notices as $n)
<tr><td>{{ $n->number ?? $n->id }}</td><td data-facet-value="{{ $n->point }}">{{ $pointLabels[$n->point] ?? $n->point }}</td><td>{{ $n->work_reference }}</td><td>{{ $n->activity_start_at ?? '—' }} → {{ $n->activity_end_at ?? '—' }}</td><td><a href="{{ route('quality.sign-offs',['projectCode'=>$project->code,'lang'=>$lang,'notice'=>$n->id]) }}">{{ __('quality_signoffs.open') }}</a></td></tr>
@endforeach
</tbody></table>
@endif

@if($notice)
<h3 id="notice-heading">{{ __('quality_signoffs.number') }} {{ $notice->number ?? $notice->id }} — {{ $pointLabels[$notice->point] ?? $notice->point }}</h3>
<p>{{ $notice->work_reference }}</p>

@if($nominated)
@if($notice->point === 'H' && !$isEmployer)<p id="hold-note">{{ __('quality_signoffs.hold_note') }}</p>@endif
<form id="signoff-form" method="post" action="{{ route('quality.sign-offs.store',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="notification_id" value="{{ $notice->id }}"><input type="hidden" name="sign_off[request_id]" value="{{ $requestId }}">
<h4>{{ __('quality_signoffs.form_title') }}</h4>
<p><label>{{ __('quality_signoffs.outcome') }} <select name="sign_off[outcome]" id="signoff-outcome">@foreach($outcomes as $o)<option value="{{ $o }}">{{ $outcomeLabels[$o] ?? $o }}</option>@endforeach</select></label>
<label>{{ __('quality_signoffs.signed_at') }} <input required type="datetime-local" name="sign_off[signed_at]"></label></p>
<label style="display:block">{{ __('quality_signoffs.reason') }}<textarea maxlength="4000" name="sign_off[reason]" id="signoff-reason" style="display:block;width:100%"></textarea></label>
@if($corrections->isNotEmpty())
<p><label>{{ __('quality_signoffs.correction') }} <select name="sign_off[previous_signoff_id]"><option value="">{{ __('quality_signoffs.correction_none') }}</option>@foreach($corrections as $c)<option value="{{ $c->id }}">{{ $outcomeLabels[$c->outcome] ?? $c->outcome }} — {{ $c->signed_at }}</option>@endforeach</select></label></p>
@endif
<button type="submit" id="record-signoff">{{ __('quality_signoffs.submit') }}</button></form>
@else<p id="signoff-denied">{{ __('quality_signoffs.denied') }}</p>@endif

<h4>{{ __('quality_signoffs.latest') }}</h4>
@if($latest->isEmpty())<p id="no-signoffs">{{ __('quality_signoffs.none') }}</p>@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="latest-table"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_signoffs.company') }}</th><th data-sort="text" data-facet>{{ __('quality_signoffs.outcome') }}</th><th data-sort="text">{{ __('quality_signoffs.signed_at') }}</th><th>{{ __('quality_signoffs.reason') }}</th>
</tr></thead><tbody>
@foreach($latest as $row)
<tr><td data-facet-value="{{ $companies[$row->company_id]->code ?? $row->company_id }}">{{ $companies[$row->company_id]?->name($lang) ?? $companies[$row->company_id]?->code ?? $row->company_id }}</td><td data-facet-value="{{ $row->outcome }}">{{ $outcomeLabels[$row->outcome] ?? $row->outcome }}</td><td>{{ $row->signed_at }}</td><td>{{ $row->reason ?? '—' }}</td></tr>
@endforeach
</tbody></table>@endif

<h4>{{ __('quality_signoffs.history') }}</h4>
@if($signOffs->isEmpty())<p>{{ __('quality_signoffs.none') }}</p>@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="signoff-history"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_signoffs.company') }}</th><th data-sort="text" data-facet>{{ __('quality_signoffs.outcome') }}</th><th data-sort="text">{{ __('quality_signoffs.signed_at') }}</th><th data-sort="text">{{ __('quality_signoffs.recorded_at') }}</th><th>{{ __('quality_signoffs.reason') }}</th>
</tr></thead><tbody>
@foreach($signOffs as $s)
<tr><td data-facet-value="{{ $companies[$s->company_id]->code ?? $s->company_id }}">{{ $companies[$s->company_id]?->name($lang) ?? $companies[$s->company_id]?->code ?? $s->company_id }}</td><td data-facet-value="{{ $s->outcome }}">{{ $outcomeLabels[$s->outcome] ?? $s->outcome }}</td><td>{{ $s->signed_at }}</td><td>{{ $s->recorded_at }}</td><td>{{ $s->reason ?? '—' }}</td></tr>
@endforeach
</tbody></table>@endif
@endif
</section></div></div></body></html>
