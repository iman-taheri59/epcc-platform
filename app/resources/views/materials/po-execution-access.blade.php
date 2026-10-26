<section class="po-card" aria-labelledby="po-execution-access-title">
<h2 id="po-execution-access-title">{{ __('po_execution_access.title') }}</h2>
<p class="help">{{ __('po_execution_access.scope') }}</p>
<p class="help" data-po-execution-stage="nomination-only">{{ __('po_execution_access.stage') }}</p>
@foreach($poExecutionAccess['buyers'] as $access)
@php
    $buyer = $access['buyer'];
    $sameOld = old('execution_access.buyer_company_id') === $buyer->id;
    $safeExecutionOld = fn($key,$default='') => $sameOld && is_scalar(old('execution_access.'.$key,$default)) ? (string)old('execution_access.'.$key,$default) : (string)$default;
@endphp
<details @if($sameOld) open @endif><summary>{{ __('po_execution_access.buyer') }}: {{ $buyer->name($lang) }} · {{ $buyer->code }}</summary>
@if($access['workspace'])<p><a href="{{ route('procurement.execution',['projectCode'=>$project->code,'buyerId'=>$buyer->id,'lang'=>$lang]) }}">{{ __('po_execution.workspace') }}</a></p>@endif
@if(!$access['current'])<p>{{ __('po_execution_access.unconfigured') }}</p>
@else<p>{{ __('po_execution_access.version') }} {{ $num($access['current']->version) }}</p>@endif
@foreach(['finance','issue'] as $purpose)
<strong>{{ __('po_execution_access.'.$purpose) }}</strong>
<ul>@forelse($access[$purpose] as $selection)<li>{{ $selection['label'] }}</li>@empty<li>{{ __('po_execution_access.none') }}</li>@endforelse</ul>
@endforeach
@if($poExecutionAccess['canConfigure'])
<details><summary>{{ __('po_execution_access.configure') }}</summary>
<form method="post" action="{{ route('procurement.order-execution-access', ['projectCode'=>$project->code,'lang'=>$lang]) }}">
@csrf
<input type="hidden" name="execution_access[buyer_company_id]" value="{{ $buyer->id }}">
<input type="hidden" name="execution_access[version]" value="{{ $safeExecutionOld('version', $access['current']->version ?? 0) }}">
<div class="po-grid">
@foreach(['finance','issue'] as $purpose)
@php
 $selected = $sameOld ? old('execution_access.'.$purpose.'_selectors',[]) : array_column($access[$purpose],'selector');
 $selected = is_array($selected) ? $selected : [];
@endphp
<label>{{ __('po_execution_access.'.$purpose) }}<select name="execution_access[{{ $purpose }}_selectors][]" multiple size="7">
@foreach($poExecutionAccess['options'] as $value=>$label)<option value="{{ $value }}" @selected(in_array($value,$selected,true))>{{ $label }}</option>@endforeach
</select></label>
@endforeach
<label>{{ __('po_execution_access.reason') }}<textarea name="execution_access[reason]" maxlength="4000" required rows="3">{{ $safeExecutionOld('reason') }}</textarea></label>
</div>
<p class="help">{{ __('po_execution_access.help') }}</p>
<input type="hidden" name="execution_access[suspend]" value="0">
<label style="display:block"><input type="checkbox" name="execution_access[suspend]" value="1" @checked($safeExecutionOld('suspend') === '1')>{{ __('po_execution_access.suspend') }}</label>
<button class="btn" type="submit">{{ __('po_execution_access.save') }}</button>
</form></details>
@endif
@if($access['history']->isNotEmpty())
<details><summary>{{ __('po_execution_access.history') }}</summary>
@foreach($access['history'] as $policy)
<details><summary>{{ __('po_execution_access.version') }} {{ $num($policy->version) }} · {{ $policy->actor_name }} · {{ $policy->created_at }} UTC</summary>
<p>{{ $policy->reason }}</p>
@foreach(['finance','issue'] as $purpose)
<p>{{ __('po_execution_access.'.$purpose) }}</p><ul>@forelse(json_decode($policy->{$purpose.'_selectors'},true) as $selection)<li>{{ $selection['label'] }}</li>@empty<li>{{ __('po_execution_access.none') }}</li>@endforelse</ul>
@endforeach
</details>
@endforeach</details>
@endif
</details>
@endforeach
</section>
