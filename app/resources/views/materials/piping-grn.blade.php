<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('piping_grn.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1000px;padding:20px;overflow-wrap:anywhere">
<h1>{{ __('piping_grn.title') }}</h1><p>{{ __('piping_grn.boundary') }}</p>
<p><a href="{{ route('materials.piping-grn',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang==='fa'?'en':'fa']) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('materials.piping-custody.show',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">{{ __('piping_grn.back') }}</a></p>
<p>CUST-{{ $receipt->sequence }} · {{ $receipt->quantity }} {{ $receipt->unit }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@if($grn)<article id="piping-grn-posted"><h2>{{ $grn->number }}</h2><p>QA <code>{{ $grn->qa_id }}</code> · <code>{{ $grn->qa_payload_hash }}</code></p><p>{{ $grn->quantity }} {{ $grn->unit }} · {{ $grn->heat_ref }} · {{ $grn->location_ref }}</p><p>{{ $grn->reason }}</p><p>{{ __('piping_grn.credit') }}: {{ $credit?->quantity ?? '—' }} {{ $credit?->unit }}</p><p>Custodian <code>{{ $grn->custodian_company_id }}</code> · Actor <code>{{ $grn->posted_by }}</code> · {{ $grn->created_at }}</p></article>
@elseif($writable)
<form id="piping-grn-form" method="post" action="{{ route('materials.piping-grn.store',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">@csrf
@foreach(['request_id'=>$requestId,'expected_policy_id'=>$policyId,'qa_id'=>$qa->id,'qa_payload_hash'=>$qa->payload_hash] as $k=>$v)<input type="hidden" name="grn[{{ $k }}]" value="{{ $v }}">@endforeach
<p>QA <code>{{ $qa->id }}</code></p>
<label>{{ __('piping_grn.location') }} <input required maxlength="200" name="grn[location_ref]"></label>
<label style="display:block">{{ __('piping_grn.reason') }}<textarea required maxlength="4000" name="grn[reason]" style="display:block;width:100%"></textarea></label>
<label><input type="checkbox" required name="grn[warehouse_confirmed]" value="1">{{ __('piping_grn.confirm') }}</label>
<button type="submit">{{ __('piping_grn.post') }}</button></form>
@else<p>{{ __('piping_grn.none') }} {{ __('piping_grn.closed') }}</p>@endif
</section></div></div></body></html>
