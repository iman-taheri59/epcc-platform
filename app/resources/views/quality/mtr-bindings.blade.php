<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('quality_mtr.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1100px;padding:20px;overflow-wrap:anywhere">
<h1>{{ __('quality_mtr.title') }}</h1>
<p>{{ __('quality_mtr.boundary') }}</p>
<p><a href="{{ route('quality.mtr',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa','certificate'=>$certificate?->id]) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('quality.nominations',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_mtr.nominations') }}</a> · <a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_mtr.results') }}</a> · <a href="{{ route('quality.sign-offs',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_mtr.signoffs') }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

<h2>{{ __('quality_mtr.your_authority') }}</h2>
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr><th data-sort="text" data-facet>{{ __('quality_mtr.action') }}</th><th data-sort="text" data-facet>{{ __('quality_mtr.you') }}</th></tr></thead><tbody>
<tr><td>{{ __('quality_nominations.action_register_certificate') }}</td><td id="nominated-register_certificate" data-facet-value="{{ $registerNominated ? __('quality_mtr.nominated') : __('quality_mtr.not_nominated') }}">{{ $registerNominated ? __('quality_mtr.nominated') : __('quality_mtr.not_nominated') }}</td></tr>
<tr><td>{{ __('quality_nominations.action_bind_certificate') }}</td><td id="nominated-bind_certificate" data-facet-value="{{ $nominated ? __('quality_mtr.nominated') : __('quality_mtr.not_nominated') }}">{{ $nominated ? __('quality_mtr.nominated') : __('quality_mtr.not_nominated') }}</td></tr>
</tbody></table>

{{-- --------------------------------------------------- register --}}
<h2>{{ __('quality_mtr.register_title') }}</h2>
@if($registerNominated)
<form id="certificate-form" method="post" action="{{ route('quality.mtr.certificates',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="certificate[request_id]" value="{{ $requestId }}">
<p><label>{{ __('quality_mtr.certificate_ref') }} <input required maxlength="200" name="certificate[certificate_ref]"></label>
<label>{{ __('quality_mtr.certificate_type') }} <select name="certificate[certificate_type]">@foreach($certTypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></label></p>
<p><label>{{ __('quality_mtr.heat') }} <input required maxlength="200" name="certificate[heat_ref]"></label>
<label>{{ __('quality_mtr.lot') }} <input maxlength="200" name="certificate[lot_ref]"></label>
<label>{{ __('quality_mtr.issuer') }} <input required maxlength="4000" name="certificate[issuer_name]"></label></p>
<button type="submit" id="register-certificate">{{ __('quality_mtr.submit_certificate') }}</button></form>
@else<p id="register-denied">{{ __('quality_mtr.denied_register') }}</p>@endif

{{-- --------------------------------------------------- registry --}}
<h2>{{ __('quality_mtr.registry') }}</h2>
@if($certificates->isEmpty())
<p id="no-certificates">{{ __('quality_mtr.no_certificates') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr>
<th data-sort="text">{{ __('quality_mtr.certificate_ref') }}</th><th data-sort="text" data-facet>{{ __('quality_mtr.certificate_type') }}</th><th data-sort="text" data-facet>{{ __('quality_mtr.heat') }}</th><th data-sort="text">{{ __('quality_mtr.lot') }}</th><th data-sort="text">{{ __('quality_mtr.issuer') }}</th><th data-sort="text" data-facet>{{ __('quality_mtr.binding') }}</th><th>{{ __('quality_mtr.select') }}</th>
</tr></thead><tbody>
@foreach($certificates as $c)
<tr><td>{{ $c->certificate_ref }}</td><td data-facet-value="{{ $c->certificate_type }}">{{ $c->certificate_type }}</td><td data-facet-value="{{ $c->heat_ref }}">{{ $c->heat_ref }}</td><td>{{ $c->lot_ref ?? '—' }}</td><td>{{ $c->issuer_name }}</td><td data-facet-value="{{ $c->binding?->binding ?? 'NONE' }}">{{ $bindingLabels[$c->binding?->binding] ?? '—' }}</td><td><a href="{{ route('quality.mtr',['projectCode'=>$project->code,'lang'=>$lang,'certificate'=>$c->id]) }}">{{ __('quality_mtr.open') }}</a></td></tr>
@endforeach
</tbody></table>
@endif

@if($certificate)
<h3 id="certificate-heading">{{ __('quality_mtr.certificate') }} {{ $certificate->certificate_ref }} — {{ __('quality_mtr.heat') }} {{ $certificate->heat_ref }}@if($certificate->lot_ref) / {{ __('quality_mtr.lot') }} {{ $certificate->lot_ref }}@endif</h3>
<p>{{ $certificate->issuer_name }}</p>

@if($nominated)
<form id="bind-form" method="post" action="{{ route('quality.mtr.bindings',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="certificate_id" value="{{ $certificate->id }}"><input type="hidden" name="binding[request_id]" value="{{ $requestId }}">
<h4>{{ __('quality_mtr.bind_title') }}</h4>
<label style="display:block">{{ __('quality_mtr.unlinked_reason') }}<textarea maxlength="4000" name="binding[unlinked_reason]" id="binding-reason" style="display:block;width:100%"></textarea></label>
<button type="submit" id="bind-certificate">{{ __('quality_mtr.submit_binding') }}</button></form>
@else<p id="bind-denied">{{ __('quality_mtr.denied_bind') }}</p>@endif

<h4>{{ __('quality_mtr.history') }}</h4>
@if($bindings->isEmpty())<p id="no-bindings">{{ __('quality_mtr.none') }}</p>@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="binding-history"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_mtr.binding') }}</th><th data-sort="text" data-facet>{{ __('quality_mtr.heat') }}</th><th data-sort="text">{{ __('quality_mtr.lot') }}</th><th data-sort="text">{{ __('quality_mtr.item_code') }}</th><th data-sort="text">{{ __('quality_mtr.custody') }}</th><th data-sort="text">{{ __('quality_mtr.bound_at') }}</th><th>{{ __('quality_mtr.reason') }}</th>
</tr></thead><tbody>
@foreach($bindings as $b)
<tr><td data-facet-value="{{ $b->binding }}">{{ $bindingLabels[$b->binding] ?? $b->binding }}</td><td>{{ $b->heat_ref }}</td><td>{{ $b->lot_ref ?? '—' }}</td><td>{{ $b->item_code ?? '—' }}</td><td>{{ $b->custody_id ?? '—' }}</td><td>{{ $b->bound_at }}</td><td>{{ $b->unlinked_reason ?? '—' }}</td></tr>
@endforeach
</tbody></table>@endif
@endif

{{-- --------------------------------------------------- coverage --}}
<h2>{{ __('quality_mtr.coverage') }}</h2>
<h3>{{ __('quality_mtr.coverage_attested') }}</h3>
@if($coverage['attested'] === [])
<p id="no-attested">{{ __('quality_mtr.no_attested') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="coverage-table"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_mtr.heat') }}</th><th data-sort="text" data-facet>{{ __('quality_mtr.lot') }}</th><th data-sort="text" data-facet>{{ __('quality_mtr.item_code') }}</th><th data-sort="num">{{ __('quality_mtr.custody_records') }}</th><th data-sort="num">{{ __('quality_mtr.certificates') }}</th>
</tr></thead><tbody>
@foreach($coverage['attested'] as $row)
<tr><td data-facet-value="{{ $row->heat_ref }}">{{ $row->heat_ref }}</td><td data-facet-value="{{ $row->lot_ref ?? '' }}">{{ $row->lot_ref ?? '—' }}</td><td data-facet-value="{{ $row->item_code ?? '' }}">{{ $row->item_code ?? '—' }}</td><td>{{ $row->custody_records }}</td><td>{{ $row->certificates }}</td></tr>
@endforeach
</tbody></table>@endif

<h3>{{ __('quality_mtr.coverage_unresolved') }}</h3>
@if($coverage['unresolved']->isEmpty())
<p id="no-unresolved">{{ __('quality_mtr.no_unresolved') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="unresolved-table"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_mtr.heat') }}</th><th data-sort="text">{{ __('quality_mtr.lot') }}</th><th>{{ __('quality_mtr.reason') }}</th>
</tr></thead><tbody>
@foreach($coverage['unresolved'] as $u)
<tr><td data-facet-value="{{ $u->heat_ref }}">{{ $u->heat_ref }}</td><td>{{ $u->lot_ref ?? '—' }}</td><td>{{ $u->unlinked_reason }}</td></tr>
@endforeach
</tbody></table>@endif
</section></div></div></body></html>
