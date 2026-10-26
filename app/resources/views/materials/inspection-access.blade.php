@php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
    $currentSelectors = array_column($inspectionAuthorAccess['selectors'], 'selector');
    $selectedAuthors = old('inspection_access.selectors', $currentSelectors);
    $selectedAuthors = is_array($selectedAuthors) ? $selectedAuthors : [];
@endphp
<style>
.mr-card { border:1px solid var(--line);border-radius:12px;background:var(--card);padding:16px;margin:16px 0; }
.mr-card h2 { font-size:16px;margin:0 0 10px; }.mr-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0; }
.mr-card label { display:flex;flex-direction:column;gap:5px;font-size:12px; }
.mr-card input:not([type=checkbox]),.mr-card select,.mr-card textarea { width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:6px;background:#0b1220;color:#e2e8f0;padding:8px;font:inherit; }
.mr-card details { padding:9px 0;border-top:1px solid var(--line); }.mr-card summary { cursor:pointer; }.mr-card .help { font-size:12px;color:var(--muted);line-height:1.8; }
</style>
<section class="mr-card" aria-labelledby="inspection-access-title">
<h2 id="inspection-access-title">{{ __('inspection_entry.access_title') }}</h2>
<p class="help">{{ __('inspection_entry.access_scope') }}</p><p>{{ __('inspection_entry.independence') }}</p>
@if(!$inspectionAuthorAccess['current'])
<p data-inspection-access="unconfigured">{{ __('inspection_entry.access_unconfigured') }}</p>
@elseif($inspectionAuthorAccess['selectors'] === [])
<p data-inspection-access="suspended">{{ __('inspection_entry.access_suspended') }}</p>
@else
<p data-inspection-access="configured">{{ __('inspection_entry.access_version') }} {{ $inspectionAuthorAccess['current']->version }}</p>
<ul>@foreach($inspectionAuthorAccess['selectors'] as $selection)<li>{{ $selection['label'] }}</li>@endforeach</ul>
@endif
@if($inspectionAuthorAccess['canConfigure'])
<details @if(!$inspectionAuthorAccess['current'] || old('inspection_access') !== null) open @endif><summary>{{ __('inspection_entry.access_configure') }}</summary>
<form method="post" id="inspection-access-form" action="{{ route('procurement.inspection-author-access', ['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="inspection_access[version]" value="{{ $safeOld('inspection_access.version', $inspectionAuthorAccess['current']->version ?? 0) }}">
<div class="mr-grid">
<label>{{ __('inspection_entry.access_selectors') }}<select name="inspection_access[selectors][]" multiple size="8">
@foreach($inspectionAuthorAccess['options'] as $value=>$label)<option value="{{ $value }}" @selected(in_array($value,$selectedAuthors,true))>{{ $label }}</option>@endforeach
</select></label>
<label>{{ __('inspection_entry.reason') }}<textarea name="inspection_access[reason]" required rows="4" maxlength="4000">{{ $safeOld('inspection_access.reason') }}</textarea></label>
</div>
<p class="help">{{ __('inspection_entry.access_selector_help') }}</p>
<input type="hidden" name="inspection_access[suspend]" value="0">
<label style="display:block;margin:12px 0"><input type="checkbox" name="inspection_access[suspend]" value="1" @checked($safeOld('inspection_access.suspend') === '1')> {{ __('inspection_entry.access_suspend') }}</label>
<button class="btn" type="submit">{{ __('inspection_entry.access_save') }}</button>
</form></details>
@endif
@if($inspectionAuthorAccess['history']->isNotEmpty())
<details><summary>{{ __('inspection_entry.access_history') }}</summary><p class="help">{{ __('inspection_entry.access_history_limit') }}</p>
@foreach($inspectionAuthorAccess['history'] as $policy)
<details><summary>{{ __('inspection_entry.access_version') }} {{ $policy->version }} · {{ $policy->actor_name }} · {{ $policy->created_at }} UTC</summary>
<p>{{ $policy->reason }}</p><ul>@forelse(json_decode($policy->selectors,true) as $selection)<li>{{ $selection['label'] }}</li>@empty<li>{{ __('inspection_entry.access_suspended') }}</li>@endforelse</ul>
</details>
@endforeach</details>
@endif
</section>
