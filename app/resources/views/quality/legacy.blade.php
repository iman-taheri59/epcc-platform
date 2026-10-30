<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('quality_legacy.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1100px;padding:20px;overflow-wrap:anywhere">
<h1>{{ __('quality_legacy.title') }}</h1>
<p>{{ __('quality_legacy.boundary') }}</p>
<p><a href="{{ route('quality.legacy',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa','lot'=>$lot?->id]) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('quality.nominations',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_legacy.nominations') }}</a> · <a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_legacy.results') }}</a> · <a href="{{ route('quality.sign-offs',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_legacy.signoffs') }}</a> · <a href="{{ route('quality.mtr',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_legacy.mtr') }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

<h2>{{ __('quality_legacy.your_authority') }}</h2>
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr><th data-sort="text" data-facet>{{ __('quality_legacy.action') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.you') }}</th></tr></thead><tbody>
<tr><td>{{ __('quality_nominations.action_reconcile_legacy') }}</td><td id="nominated-reconcile_legacy" data-facet-value="{{ $nominated ? __('quality_legacy.nominated') : __('quality_legacy.not_nominated') }}">{{ $nominated ? __('quality_legacy.nominated') : __('quality_legacy.not_nominated') }}</td></tr>
</tbody></table>

{{-- --------------------------------------------------------- pending --}}
<h2>{{ __('quality_legacy.pending') }}</h2>
@if($pending->isEmpty())
<p id="no-pending">{{ __('quality_legacy.no_pending') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_legacy.lot') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.item') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.heat') }}</th><th data-sort="text">{{ __('quality_legacy.unit') }}</th><th data-sort="text">{{ __('quality_legacy.purchase_order') }}</th><th>{{ __('quality_legacy.select') }}</th>
</tr></thead><tbody>
@foreach($pending as $l)
<tr><td data-facet-value="{{ $l->lot_code }}">{{ $l->lot_code }}</td><td data-facet-value="{{ $l->item_code }}">{{ $l->item_code }}</td><td data-facet-value="{{ $l->heat_number ?? '' }}">{{ $l->heat_number ?? '—' }}</td><td>{{ $l->unit }}</td><td>{{ $l->purchase_order_id ?? '—' }}</td><td><a href="{{ route('quality.legacy',['projectCode'=>$project->code,'lang'=>$lang,'lot'=>$l->id]) }}">{{ __('quality_legacy.open') }}</a></td></tr>
@endforeach
</tbody></table>
@endif

@if($lot)
<h3 id="lot-heading">{{ __('quality_legacy.lot') }} {{ $lot->lot_code }} — {{ __('quality_legacy.item') }} {{ $lot->item_code }}@if($lot->heat_number) / {{ __('quality_legacy.heat') }} {{ $lot->heat_number }}@endif</h3>

@if($nominated)
<form id="decision-form" method="post" action="{{ route('quality.legacy.store',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="lot_id" value="{{ $lot->id }}"><input type="hidden" name="reconciliation[request_id]" value="{{ $requestId }}">
@if($head !== null)<input type="hidden" name="reconciliation[previous_reconciliation_id]" value="{{ $head }}">@endif
<h4>{{ __('quality_legacy.form_title') }}</h4>
@if($head !== null)<p id="supersedes">{{ __('quality_legacy.supersedes') }}</p>@endif
<p><label>{{ __('quality_legacy.decision') }} <select name="reconciliation[decision]" id="decision">@foreach($decisions as $d)<option value="{{ $d }}">{{ $decisionLabels[$d] ?? $d }}</option>@endforeach</select></label>
<label>{{ __('quality_legacy.take_over_custody') }} <select name="reconciliation[custody_id]" id="take-over-custody"><option value="">—</option>@foreach($custody as $c)<option value="{{ $c->id }}">{{ $c->shipment_ref }} · {{ $c->heat_ref }}@if($c->lot_ref) / {{ $c->lot_ref }}@endif</option>@endforeach</select></label></p>
<label style="display:block">{{ __('quality_legacy.reason') }} — {{ __('quality_legacy.reason_help') }}<textarea required maxlength="4000" name="reconciliation[reason]" id="reason" style="display:block;width:100%"></textarea></label>
<button type="submit" id="record-decision">{{ __('quality_legacy.submit') }}</button></form>
@else<p id="decision-denied">{{ __('quality_legacy.denied') }}</p>@endif

<h4>{{ __('quality_legacy.chain') }}</h4>
@if($chain->isEmpty())<p id="no-chain">{{ __('quality_legacy.no_decided') }}</p>@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="decision-chain"><thead><tr>
<th data-sort="num">{{ __('quality_legacy.sequence') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.decision') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.heat') }}</th><th data-sort="text">{{ __('quality_legacy.custody') }}</th><th data-sort="text">{{ __('quality_legacy.decided_at') }}</th><th>{{ __('quality_legacy.reason') }}</th>
</tr></thead><tbody>
@foreach($chain as $r)
<tr><td>{{ $r->sequence }}</td><td data-facet-value="{{ $r->decision }}">{{ $decisionLabels[$r->decision] ?? $r->decision }}</td><td>{{ $r->heat_ref ?? '—' }}</td><td>{{ $r->custody_id ?? '—' }}</td><td>{{ $r->decided_at }}</td><td>{{ $r->reason }}</td></tr>
@endforeach
</tbody></table>@endif
@endif

{{-- --------------------------------------------------------- decided --}}
<h2>{{ __('quality_legacy.decided') }}</h2>
@if($decided->isEmpty())
<p id="no-decided">{{ __('quality_legacy.no_decided') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_legacy.lot') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.item') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.decision') }}</th><th data-sort="text">{{ __('quality_legacy.custody') }}</th><th data-sort="text">{{ __('quality_legacy.decided_at') }}</th><th>{{ __('quality_legacy.select') }}</th>
</tr></thead><tbody>
@foreach($decided as $l)
<tr><td data-facet-value="{{ $l->lot_code }}">{{ $l->lot_code }}</td><td data-facet-value="{{ $l->item_code }}">{{ $l->item_code }}</td><td data-facet-value="{{ $l->decision->decision }}">{{ $decisionLabels[$l->decision->decision] ?? $l->decision->decision }}</td><td>{{ $l->decision->custody_id ?? '—' }}</td><td>{{ $l->decision->decided_at }}</td><td><a href="{{ route('quality.legacy',['projectCode'=>$project->code,'lang'=>$lang,'lot'=>$l->id]) }}">{{ __('quality_legacy.open') }}</a></td></tr>
@endforeach
</tbody></table>
@endif
{{-- ------------------------------------------------- quantity report --}}
<h2>{{ __('quality_legacy.quantity_report') }}</h2>
<p>{{ __('quality_legacy.quantity_help') }}</p>
@if($quantityReport === [])
<p id="no-quantity-report">{{ __('quality_legacy.no_quantity_report') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="quantity-report"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_legacy.lot') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.item') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.decision') }}</th><th data-sort="num">{{ __('quality_legacy.legacy_quantity') }}</th><th data-sort="num">{{ __('quality_legacy.receipt_quantity') }}</th><th data-sort="num">{{ __('quality_legacy.delta') }}</th><th data-sort="text" data-facet>{{ __('quality_legacy.verdict') }}</th>
</tr></thead><tbody>
@foreach($quantityReport as $row)
<tr><td data-facet-value="{{ $row['lot_code'] }}">{{ $row['lot_code'] }}</td><td data-facet-value="{{ $row['item_code'] }}">{{ $row['item_code'] }}</td><td data-facet-value="{{ $row['decision'] }}">{{ $decisionLabels[$row['decision']] ?? $row['decision'] }}</td><td>{{ $row['legacy_quantity'] }} {{ $row['unit'] }}</td><td>@if($row['receipt_quantity'] === null)—@else{{ $row['receipt_quantity'] }} {{ $row['receipt_unit'] }}@endif</td><td>{{ $row['delta'] ?? '—' }}</td><td data-facet-value="{{ $row['verdict'] }}">{{ $row['verdict_label'] }}</td></tr>
@endforeach
</tbody></table>@endif
</section></div></div></body></html>
