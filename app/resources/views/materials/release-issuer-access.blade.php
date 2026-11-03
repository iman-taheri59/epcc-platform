@php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
    $currentSelectors = $releaseIssuerAccess['selectors'];
    $selectedAuthors = old('release_issuers.selectors', $currentSelectors);
    $selectedAuthors = is_array($selectedAuthors) ? $selectedAuthors : [];
@endphp
<style>
.mr-card { border:1px solid var(--line);border-radius:12px;background:var(--card);padding:16px;margin:16px 0; }
.mr-card h2 { font-size:16px;margin:0 0 10px; }.mr-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0; }
.mr-card label { display:flex;flex-direction:column;gap:5px;font-size:12px; }
.mr-card input:not([type=checkbox]),.mr-card select,.mr-card textarea { width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:6px;background:#0b1220;color:#e2e8f0;padding:8px;font:inherit; }
.mr-card details { padding:9px 0;border-top:1px solid var(--line); }.mr-card summary { cursor:pointer; }.mr-card .help { font-size:12px;color:var(--muted);line-height:1.8; }
</style>
<section class="mr-card" aria-labelledby="release-issuer-access-title">
<h2 id="release-issuer-access-title">{{ __('inspection_release_notes.access_title') }}</h2>
<p class="help">{{ __('inspection_release_notes.access_scope') }}</p><p>{{ __('inspection_release_notes.independence') }}</p>
@if(!$releaseIssuerAccess['current'])
<p data-release-issuer-access="unconfigured">{{ __('inspection_release_notes.access_unconfigured') }}</p>
@elseif($releaseIssuerAccess['selectors'] === [])
<p data-release-issuer-access="suspended">{{ __('inspection_release_notes.access_suspended') }}</p>
@else
<p data-release-issuer-access="configured">{{ __('inspection_release_notes.access_version') }} {{ $releaseIssuerAccess['current']->version }}</p>
<ul>@foreach($releaseIssuerAccess['selectors'] as $selection)<li>{{ $releaseIssuerAccess['options'][$selection] ?? $selection }}</li>@endforeach</ul>
@endif
@if($releaseIssuerAccess['canConfigure'])
<details @if(!$releaseIssuerAccess['current'] || old('release_issuers') !== null) open @endif><summary>{{ __('inspection_release_notes.access_configure') }}</summary>
<form method="post" id="release-issuer-access-form" action="{{ route('procurement.release-issuer-access', ['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="release_issuers[version]" value="{{ $safeOld('release_issuers.version', $releaseIssuerAccess['current']->version ?? 0) }}">
<div class="mr-grid">
<label>{{ __('inspection_release_notes.access_selectors') }}<select name="release_issuers[selectors][]" multiple size="8">
@foreach($releaseIssuerAccess['options'] as $value=>$label)<option value="{{ $value }}" @selected(in_array($value,$selectedAuthors,true))>{{ $label }}</option>@endforeach
</select></label>
<label>{{ __('inspection_release_notes.reason') }}<textarea name="release_issuers[reason]" required rows="4" maxlength="4000">{{ $safeOld('release_issuers.reason') }}</textarea></label>
</div>
<p class="help">{{ __('inspection_release_notes.access_selector_help') }}</p>
<button class="btn" type="submit">{{ __('inspection_release_notes.access_save') }}</button>
</form></details>
@endif
@if($releaseIssuerAccess['history']->isNotEmpty())
<details><summary>{{ __('inspection_release_notes.access_history') }}</summary><p class="help">{{ __('inspection_release_notes.access_history_limit') }}</p>
@foreach($releaseIssuerAccess['history'] as $policy)
<details><summary>{{ __('inspection_release_notes.access_version') }} {{ $policy->version }} · {{ $policy->actor_name }} · {{ $policy->created_at }} UTC</summary>
<p>{{ $policy->reason }}</p><ul>@forelse(json_decode($policy->selectors,true) as $selection)<li>{{ $selection }}</li>@empty<li>{{ __('inspection_release_notes.access_suspended') }}</li>@endforelse</ul>
</details>
@endforeach</details>
@endif
</section>
