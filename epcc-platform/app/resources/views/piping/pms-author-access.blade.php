<section class="pms-card" aria-labelledby="pms-access-title">
<h2 id="pms-access-title">{{ $t('access_title') }}</h2>
<p class="pms-help">{{ $t('access_scope') }}</p>
@if(!$authorAccess['current'])
<p class="pms-warning" data-pms-access="unconfigured">{{ $t('access_unconfigured') }}</p>
@elseif($authorAccess['selectors'] === [])
<p class="pms-warning" data-pms-access="suspended">{{ $t('access_suspended') }}</p>
@else
<p data-pms-access="configured">{{ $t('access_configured') }} · {{ $t('access_version') }} {{ $authorAccess['current']->version }}</p>
<ul>@foreach($authorAccess['selectors'] as $selection)<li>{{ $selection['label'] }}</li>@endforeach</ul>
@endif
@if($authorAccess['canConfigure'])
<details @if(!$authorAccess['current'] || $errors->has('pms_access')) open @endif><summary>{{ $t('access_configure') }}</summary>
<form method="post" action="{{ $url('/access') }}" id="pms-access-form">@csrf
<input type="hidden" name="access[version]" value="{{ $authorAccess['current']->version ?? 0 }}">
<div class="pms-grid"><label class="pms-wide">{{ $t('access_selectors') }}
<select name="access[selectors][]" multiple size="8" aria-describedby="pms-access-selector-help">
@foreach($authorAccess['options'] as $value=>$label)<option value="{{ $value }}" @selected(in_array($value,array_column($authorAccess['selectors'],'selector'),true))>{{ $label }}</option>@endforeach
</select></label>
<p class="pms-help pms-wide" id="pms-access-selector-help">{{ $t('access_selector_help') }}</p>
<label class="pms-wide">{{ $t('reason') }}<textarea name="access[reason]" rows="2" required maxlength="4000"></textarea></label>
<div class="pms-wide"><input type="hidden" name="access[suspend]" value="0"><label><span><input type="checkbox" name="access[suspend]" value="1" style="width:auto"> {{ $t('access_suspend') }}</span></label></div>
</div><button class="pms-save" type="submit">{{ $t('access_save') }}</button>
</form></details>
@endif
@if($authorAccess['history']->isNotEmpty())
<details><summary>{{ $t('access_history') }}</summary><p class="pms-help">{{ $t('access_history_limit') }}</p>
@foreach($authorAccess['history'] as $policy)
<details><summary>{{ $t('access_version') }} {{ $policy->version }} · {{ $policy->actor_name }} · {{ $policy->created_at }} UTC</summary>
<p class="pms-source">{{ $policy->reason }}</p>
<ul>@forelse(json_decode($policy->selectors,true) as $selection)<li>{{ $selection['label'] }}</li>@empty<li>{{ $t('access_suspended') }}</li>@endforelse</ul>
</details>
@endforeach</details>
@endif
</section>
