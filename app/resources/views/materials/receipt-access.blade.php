<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('receipt_access.title') }} — {{ $project->code }}</title>
@include('partials.shell')
<style>.receipt-card{max-width:1000px;border:1px solid var(--line);border-radius:12px;padding:20px;margin:18px 0;background:var(--card);overflow-wrap:anywhere}.receipt-card label{display:block;margin:16px 0}.receipt-card select,.receipt-card textarea{display:block;box-sizing:border-box;width:100%;font:inherit;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--line)}.receipt-card details{border-top:1px solid var(--line);padding:12px 0}.receipt-card textarea,.receipt-note{white-space:pre-wrap;unicode-bidi:plaintext}.receipt-error{border:1px solid #dc6262;padding:12px}</style>
</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')
@php
$actions=\App\Services\PipingReceiptAccessService::ACTIONS;
$currentActions=$current ? json_decode($current->actions,true,512,JSON_THROW_ON_ERROR) : [];
$restore=old('receipt_access_project')===$project->id;
$safeOld=fn($key,$default='')=>$restore && is_scalar(old('receipt_access.'.$key)) ? (string)old('receipt_access.'.$key) : $default;
$selectedCompany=$safeOld('custodian_company_id',$current->custodian_company_id ?? '');
$recordedCustodian=$current?->custodian_snapshot ? json_decode($current->custodian_snapshot,true,512,JSON_THROW_ON_ERROR) : null;
@endphp
<section class="receipt-card"><h1>{{ __('receipt_access.title') }}</h1>
<p><a href="{{ route('materials.receipt-access',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa']) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('materials',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('receipt_access.back') }}</a></p>
<p>{{ __('receipt_access.scope') }}</p><p>{{ __('receipt_access.sod') }}</p>
<p id="piping-receipt-access-status">{{ __('receipt_access.'.(!$current?'unconfigured':($current->suspended?'suspended':'configured'))) }}</p>
@if($current)<p>{{ __('receipt_access.version') }} {{ $current->version }}</p>@endif
@if($recordedCustodian)<p>{{ __('receipt_access.recorded_custodian') }}: {{ $recordedCustodian['code'] }} — {{ $recordedCustodian['name_'.$lang] }}</p>@endif
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul class="receipt-error" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@foreach($actions as $action)<h2>{{ __('receipt_access.'.$action) }}</h2><ul>@forelse($currentActions[$action] ?? [] as $selection)<li>{{ $selection['label'] }}</li>@empty<li>—</li>@endforelse</ul>@endforeach
@if($writable)
<form id="piping-receipt-access-form" method="post" action="{{ route('materials.receipt-access.store',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="receipt_access_project" value="{{ $project->id }}">
<input type="hidden" name="receipt_access[version]" value="{{ $safeOld('version',$current->version ?? 0) }}">
<label>{{ __('receipt_access.custodian') }}<select name="receipt_access[custodian_company_id]">
<option value="">{{ __('receipt_access.choose') }}</option>
@if($selectedCompany!=='' && !isset($companies[$selectedCompany]))<option value="{{ $selectedCompany }}" selected>{{ __('receipt_access.unavailable') }}</option>@endif
@foreach($companies as $company)<option value="{{ $company->id }}" @selected($selectedCompany===$company->id)>{{ $company->code }} — {{ $company->{'name_'.$lang} }}</option>@endforeach
</select></label>
<p>{{ __('receipt_access.selector_help') }}</p>
@foreach($actions as $action)
@php
$oldSelections=$restore ? old('receipt_access.actions.'.$action) : null;
$selected=$restore ? (is_array($oldSelections) ? array_filter($oldSelections,'is_string') : []) : array_column($currentActions[$action] ?? [],'selector');
@endphp
<label>{{ __('receipt_access.'.$action) }}<select multiple size="6" name="receipt_access[actions][{{ $action }}][]">
@foreach($options as $key=>$selection)<option value="{{ $key }}" @selected(in_array($key,$selected,true))>{{ $selection['label'] }}</option>@endforeach
</select></label>
@endforeach
@if($options===[])<p>{{ __('receipt_access.no_options') }}</p>@endif
<label>{{ __('receipt_access.reason') }}<textarea name="receipt_access[reason]" rows="3" maxlength="4000" required>{{ $safeOld('reason') }}</textarea></label>
<input type="hidden" name="receipt_access[suspend]" value="0">
<label><input type="checkbox" name="receipt_access[suspend]" value="1" @checked($safeOld('suspend')==='1')> {{ __('receipt_access.suspend') }}</label>
<button class="btn" type="submit">{{ __('receipt_access.save') }}</button>
</form>
@else<p>{{ __('receipt_access.readonly') }}</p>@endif
</section>
<section class="receipt-card"><h2>{{ __('receipt_access.history') }}</h2><p>{{ __('receipt_access.history_limit') }}</p>
@foreach($history as $policy)
@php $snapshot=$policy->custodian_snapshot ? json_decode($policy->custodian_snapshot,true,512,JSON_THROW_ON_ERROR) : null; $storedActions=json_decode($policy->actions,true,512,JSON_THROW_ON_ERROR); @endphp
<details><summary>{{ __('receipt_access.version') }} {{ $policy->version }} — {{ $policy->actor_name }} · <bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($policy->created_at)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></summary>
<p class="receipt-note">{{ $policy->reason }}</p>
@if($policy->suspended)<p>{{ __('receipt_access.suspended') }}</p>@endif
@if($snapshot)<p>{{ $snapshot['code'] }} — {{ $snapshot['name_'.$lang] }}</p>@endif
@foreach($actions as $action)<h3>{{ __('receipt_access.'.$action) }}</h3><ul>@foreach($storedActions[$action] as $selection)<li>{{ $selection['label'] }} · <bdi dir="ltr">{{ $selection['company_id'] }}</bdi></li>@endforeach</ul>@endforeach
</details>
@endforeach</section>
</div></div></body></html>
