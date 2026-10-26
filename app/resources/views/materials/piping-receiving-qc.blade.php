<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('piping_receiving_qc.title') }} — {{ $project->code }}</title>
@include('partials.shell')
<style>.qc-card{max-width:1000px;border:1px solid var(--line);border-radius:12px;padding:20px;margin:18px 0;background:var(--card);overflow-wrap:anywhere}.qc-card label{display:block;margin:16px 0}.qc-card input,.qc-card select,.qc-card textarea{display:block;box-sizing:border-box;width:100%;font:inherit;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--line)}.qc-card details{border-top:1px solid var(--line);padding:12px 0}.qc-findings{white-space:pre-wrap;unicode-bidi:plaintext}.qc-error{border:1px solid #dc6262;padding:12px}</style>
</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')
<section class="qc-card"><h1>{{ __('piping_receiving_qc.title') }}</h1>
<p>CUST-{{ str_pad((string)$receipt->sequence,6,'0',STR_PAD_LEFT) }} · {{ $receipt->shipment_ref }} · {{ $receipt->quantity }} {{ $receipt->unit }} · {{ $receipt->heat_ref ?? __('piping_custody.unknown') }}</p>
<p><a href="{{ route('materials.piping-receiving-qc',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang==='fa'?'en':'fa']) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('materials.piping-custody.show',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">{{ __('piping_receiving_qc.back') }}</a></p>
<p>{{ __('piping_receiving_qc.boundary') }}</p><p>{{ __('piping_receiving_qc.checklist_scope') }}</p><p>{{ __('piping_receiving_qc.sod') }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul class="qc-error" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@include('materials.partials.piping-effective-basis')
<h2>{{ __('piping_receiving_qc.latest') }}</h2>
@if($latest)<p id="receiving-qc-latest">{{ __('piping_receiving_qc.sequence') }} {{ $latest->sequence }} — {{ __('piping_receiving_qc.'.$latest->observed_result) }}</p>@else<p>{{ __('piping_receiving_qc.empty') }}</p>@endif
@if($writable)
@php
$restore=old('receiving_qc_project')===$project->id && old('receiving_qc_custody')===$receipt->id;
$safeOld=fn($key,$default='')=>$restore ? (is_scalar(old('review.'.$key)) ? (string)old('review.'.$key) : '') : $default;
// A deliberately empty previous head must stay empty on recovery, never silently rebased to a new head.
$previous=$restore ? $safeOld('previous_review_id') : ($latest->id ?? '');
@endphp
<h2>{{ __('piping_receiving_qc.new') }}</h2><p>{{ __('piping_receiving_qc.immutable') }}</p>
<form id="piping-receiving-qc-form" method="post" action="{{ route('materials.piping-receiving-qc.store',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="review[amendment_id]" value="{{ $safeOld('amendment_id',$amendmentId??'') }}">
<input type="hidden" name="receiving_qc_project" value="{{ $project->id }}"><input type="hidden" name="receiving_qc_custody" value="{{ $receipt->id }}">
<input type="hidden" name="review[expected_policy_id]" value="{{ $safeOld('expected_policy_id',$policyId) }}">
<input type="hidden" name="review[previous_review_id]" value="{{ $previous }}">
<input type="hidden" name="review[request_id]" value="{{ $safeOld('request_id',$requestId) }}">
<label>{{ __('piping_receiving_qc.reviewed_at') }}<input required type="text" maxlength="25" dir="ltr" name="review[reviewed_at]" placeholder="2026-10-22T14:30:00+03:30" value="{{ $safeOld('reviewed_at') }}"></label><p>{{ __('piping_custody.time_help') }}</p>
<label>{{ __('piping_receiving_qc.observed_result') }}<select required name="review[observed_result]"><option value="">—</option>@foreach(\App\Services\PipingReceivingReviewService::RESULTS as $result)<option value="{{ $result }}" @selected($safeOld('observed_result')===$result)>{{ __('piping_receiving_qc.'.$result) }}</option>@endforeach</select></label>
<label>{{ __('piping_receiving_qc.findings') }}<textarea required name="review[findings]" maxlength="4000" rows="6">{{ $safeOld('findings') }}</textarea></label><button class="btn" type="submit">{{ __('piping_receiving_qc.save') }}</button>
</form>
@elseif($receipt->created_by===$user->id)<p id="receiving-qc-self-review-blocked">{{ __('piping_receiving_qc.self_review') }}</p>
@else<p>{{ __('piping_receiving_qc.readonly') }}</p>@endif
<h2>{{ __('piping_receiving_qc.history') }}</h2>
@foreach($reviews as $review)<details id="receiving-qc-{{ $review->id }}"><summary>{{ __('piping_receiving_qc.sequence') }} {{ $review->sequence }} — {{ __('piping_receiving_qc.'.$review->observed_result) }} — {{ $review->actor_name }}</summary><p>{{ __('piping_amendments.bound') }}: <code>{{ $review->amendment_id ?? __('piping_amendments.original') }}</code></p>
<p>{{ __('piping_receiving_qc.reviewer') }}: {{ $review->actor_name }} · {{ $review->company_id }}</p><p class="qc-findings">{{ $review->findings }}</p>
@foreach(['reviewed_at','created_at'] as $field)<p>{{ __('piping_receiving_qc.'.$field) }}: <bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($review->$field)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></p>@endforeach
<p>{{ __('piping_receiving_qc.custody_fingerprint') }}: <bdi dir="ltr">{{ $review->custody_fingerprint }}</bdi></p><p>{{ __('piping_receiving_qc.policy') }}: <bdi dir="ltr">{{ $review->policy_id }}</bdi></p>
</details>@endforeach
@if($reviews->previousPageUrl())<a href="{{ $reviews->previousPageUrl() }}">{{ __('piping_custody.previous') }}</a>@endif @if($reviews->nextPageUrl())<a href="{{ $reviews->nextPageUrl() }}">{{ __('piping_custody.next') }}</a>@endif
</section></div></div></body></html>
