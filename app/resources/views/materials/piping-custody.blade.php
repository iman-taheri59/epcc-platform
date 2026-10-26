<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('piping_custody.title') }} — {{ $project->code }}</title>
@include('partials.shell')
<style>.custody-card{max-width:1050px;border:1px solid var(--line);border-radius:12px;padding:20px;margin:18px 0;background:var(--card);overflow-wrap:anywhere}.custody-card label{display:block;margin:16px 0}.custody-card input,.custody-card select,.custody-card textarea{display:block;box-sizing:border-box;width:100%;font:inherit;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--line)}.custody-card dt{font-weight:bold;margin-top:12px}.custody-card dd{white-space:pre-wrap;unicode-bidi:plaintext;margin:4px 0 14px}.custody-note{white-space:pre-wrap;unicode-bidi:plaintext}.custody-error{border:1px solid #dc6262;padding:12px}.custody-card table{width:100%;border-collapse:collapse}.custody-card th,.custody-card td{text-align:start;padding:9px;border-bottom:1px solid var(--line)}</style>
</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')
<section class="custody-card"><h1>{{ __('piping_custody.title') }}</h1>
<p><a href="{{ $record ? route('materials.piping-custody.show',['projectCode'=>$project->code,'receiptId'=>$record->id,'lang'=>$lang==='fa'?'en':'fa']) : route('materials.piping-custody',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa']) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('materials',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('piping_custody.back') }}</a> · <a href="{{ route('materials.piping-custody',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('piping_custody.register') }}</a></p>
<p><a id="piping-traceability-link" href="{{ $record ? route('materials.piping-traceability.show',['projectCode'=>$project->code,'receiptId'=>$record->id,'lang'=>$lang]) : route('materials.piping-traceability',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('piping_traceability.title') }}</a></p>
<p>{{ __('piping_custody.boundary') }}</p><p>{{ __('piping_custody.scope') }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul class="custody-error" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@if($record)
<h2 id="piping-custody-record">{{ __('piping_custody.number') }} CUST-{{ str_pad((string)$record->sequence,6,'0',STR_PAD_LEFT) }}</h2>
<p><a id="piping-receiving-qc-link" href="{{ route('materials.piping-receiving-qc',['projectCode'=>$project->code,'receiptId'=>$record->id,'lang'=>$lang]) }}">{{ __('piping_receiving_qc.title') }}</a></p>
<p><a id="piping-grn-link" href="{{ route('materials.piping-grn',['projectCode'=>$project->code,'receiptId'=>$record->id,'lang'=>$lang]) }}">{{ __('piping_grn.title') }}</a></p>
<p><a id="piping-qa-link" href="{{ route('materials.piping-qa',['projectCode'=>$project->code,'receiptId'=>$record->id,'lang'=>$lang]) }}">{{ __('piping_qa.title') }}</a></p>
<p><a id="piping-custody-amendments-link" href="{{ route('materials.piping-custody-amendments',['projectCode'=>$project->code,'receiptId'=>$record->id,'lang'=>$lang]) }}">{{ __('piping_amendments.title') }}</a></p>
<p>{{ __('piping_custody.CUSTODY_ONLY') }}</p><p>{{ __('piping_custody.immutable') }}</p>
@php $cust=json_decode($record->custodian_snapshot,true,512,JSON_THROW_ON_ERROR); $src=$record->source_snapshot ? json_decode($record->source_snapshot,true,512,JSON_THROW_ON_ERROR) : null; @endphp
<dl><dt>{{ __('piping_custody.custodian') }}</dt><dd>{{ $cust['code'] }} — {{ $cust['name_'.$lang] }}</dd>
<dt>{{ __('piping_custody.recorder') }}</dt><dd>{{ $record->actor_name }} · {{ $record->company_id }}</dd>
@foreach(['shipment_ref','package_ref','observed_description','quantity','unit','heat_ref','location_ref','unlinked_reason','notes'] as $field)<dt>{{ __('piping_custody.'.$field) }}</dt><dd>{{ $record->$field ?? __('piping_custody.unknown') }}</dd>@endforeach
@foreach(['received_at','created_at'] as $field)<dt>{{ __('piping_custody.'.$field) }}</dt><dd><bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($record->$field)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></dd>@endforeach
<dt>{{ __('piping_custody.location_kind') }}</dt><dd>{{ __('piping_custody.'.$record->location_kind) }}</dd>
<dt>{{ __('piping_custody.document_status') }}</dt><dd>{{ __('piping_custody.'.$record->document_status) }}</dd></dl>
@if($src)<h3>{{ __('piping_custody.source_snapshot') }}</h3><dl>@foreach($src as $key=>$value)<dt>{{ $key }}</dt><dd>{{ $value ?? '—' }}</dd>@endforeach<dt>{{ __('piping_custody.source_fingerprint') }}</dt><dd><bdi dir="ltr">{{ $record->source_fingerprint }}</bdi></dd></dl>@endif
@else
<h2>{{ __('piping_custody.register') }}</h2>
<table><thead><tr>@foreach(['number','shipment_ref','quantity','unit'] as $field)<th>{{ __('piping_custody.'.$field) }}</th>@endforeach</tr></thead><tbody>
@forelse($records as $row)<tr><td><a href="{{ route('materials.piping-custody.show',['projectCode'=>$project->code,'receiptId'=>$row->id,'lang'=>$lang]) }}">CUST-{{ str_pad((string)$row->sequence,6,'0',STR_PAD_LEFT) }}</a></td><td>{{ $row->shipment_ref }}</td><td>{{ $row->quantity }}</td><td>{{ $row->unit }}</td></tr>@empty<tr><td colspan="4">{{ __('piping_custody.empty') }}</td></tr>@endforelse</tbody></table>
@if($records->previousPageUrl())<a href="{{ $records->previousPageUrl() }}">{{ __('piping_custody.previous') }}</a>@endif @if($records->nextPageUrl())<a href="{{ $records->nextPageUrl() }}">{{ __('piping_custody.next') }}</a>@endif
@if($writable)
@php
$restore=old('custody_project')===$project->id;
$safeOld=fn($key,$default='')=>$restore && is_scalar(old('receipt.'.$key)) ? (string)old('receipt.'.$key) : $default;
$selectedSource=$safeOld('source_line_id');
@endphp
<h2>{{ __('piping_custody.new') }}</h2><p>{{ __('piping_custody.immutable') }}</p>
<form id="piping-custody-form" method="post" action="{{ route('materials.piping-custody.store',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="custody_project" value="{{ $project->id }}">
<input type="hidden" name="receipt[expected_policy_id]" value="{{ $safeOld('expected_policy_id',$policyId) }}">
<input type="hidden" name="receipt[request_id]" value="{{ $safeOld('request_id',$requestId) }}">
<label>{{ __('piping_custody.source_line_id') }}<select name="receipt[source_line_id]"><option value="">{{ __('piping_custody.unlinked') }}</option>
@if($selectedSource!=='' && !$sources->contains('id',$selectedSource))<option value="{{ $selectedSource }}" selected>{{ __('piping_custody.source_unavailable') }}</option>@endif
@foreach($sources as $source)<option value="{{ $source->id }}" @selected($selectedSource===$source->id)>{{ $source->number }} · {{ $source->line_no }} · {{ $source->unit }} · {{ $source->id }}</option>@endforeach</select></label>
<p>{{ __('piping_custody.source_help') }}</p>
@foreach(['unlinked_reason'=>2000,'shipment_ref'=>200,'package_ref'=>200,'received_at'=>25,'observed_description'=>2000,'quantity'=>19,'unit'=>32,'heat_ref'=>200,'location_ref'=>200] as $field=>$max)
<label>{{ __('piping_custody.'.$field) }}<input type="text" name="receipt[{{ $field }}]" maxlength="{{ $max }}" value="{{ $safeOld($field) }}" @required(!in_array($field,['unlinked_reason','package_ref','heat_ref'],true)) @if($field==='received_at') placeholder="2026-10-22T14:30:00+03:30" dir="ltr" @endif @if($field==='quantity') inputmode="decimal" dir="ltr" @endif></label>
@endforeach
<p>{{ __('piping_custody.time_help') }}</p>
@foreach(['location_kind'=>['RECEIVING','QUARANTINE'],'document_status'=>['MISSING','PRESENT_UNVERIFIED']] as $field=>$values)<label>{{ __('piping_custody.'.$field) }}<select required name="receipt[{{ $field }}]"><option value="">—</option>@foreach($values as $value)<option value="{{ $value }}" @selected($safeOld($field)===$value)>{{ __('piping_custody.'.$value) }}</option>@endforeach</select></label>@endforeach
<label>{{ __('piping_custody.notes') }}<textarea name="receipt[notes]" maxlength="4000" rows="3">{{ $safeOld('notes') }}</textarea></label>
<button type="submit" class="btn">{{ __('piping_custody.save') }}</button></form>
@else<p id="piping-custody-readonly">{{ __('piping_custody.readonly') }}</p>@endif
@endif
</section></div></div></body></html>
