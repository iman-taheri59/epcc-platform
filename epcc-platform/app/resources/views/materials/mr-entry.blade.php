@php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
    $currentSelectors = array_column($mrAuthorAccess['selectors'], 'selector');
    $selectedAuthors = old('access.selectors', $currentSelectors);
    $selectedAuthors = is_array($selectedAuthors) ? $selectedAuthors : [];
@endphp
<style>
.mr-card { border:1px solid var(--line);border-radius:12px;background:var(--card);padding:16px;margin:16px 0; }
.mr-card h2 { font-size:16px;margin:0 0 10px; }.mr-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0; }
.mr-card label { display:flex;flex-direction:column;gap:5px;font-size:12px; }
.mr-card input:not([type=checkbox]),.mr-card select,.mr-card textarea { width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:6px;background:#0b1220;color:#e2e8f0;padding:8px;font:inherit; }
.mr-card details { padding:9px 0;border-top:1px solid var(--line); }.mr-card summary { cursor:pointer; }.mr-card .help { font-size:12px;color:var(--muted);line-height:1.8; }
</style>
<section class="mr-card" aria-labelledby="mr-access-title">
<h2 id="mr-access-title">{{ __('mr.access_title') }}</h2>
<p class="help">{{ __('mr.access_scope') }}</p>
@if(!$mrAuthorAccess['current'])
<p data-mr-access="unconfigured">{{ __('mr.access_unconfigured') }}</p>
@elseif($mrAuthorAccess['selectors'] === [])
<p data-mr-access="suspended">{{ __('mr.access_suspended') }}</p>
@else
<p data-mr-access="configured">{{ __('mr.access_version') }} {{ $mrAuthorAccess['current']->version }}</p>
<ul>@foreach($mrAuthorAccess['selectors'] as $selection)<li>{{ $selection['label'] }}</li>@endforeach</ul>
@endif
@if($mrAuthorAccess['canConfigure'])
<details @if(!$mrAuthorAccess['current'] || old('access') !== null) open @endif><summary>{{ __('mr.access_configure') }}</summary>
<form method="post" id="mr-access-form" action="{{ route('procurement.author-access', ['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="access[version]" value="{{ $safeOld('access.version', $mrAuthorAccess['current']->version ?? 0) }}">
<div class="mr-grid">
<label>{{ __('mr.access_selectors') }}<select name="access[selectors][]" multiple size="8">
@foreach($mrAuthorAccess['options'] as $value=>$label)<option value="{{ $value }}" @selected(in_array($value,$selectedAuthors,true))>{{ $label }}</option>@endforeach
</select></label>
<label>{{ __('mr.reason') }}<textarea name="access[reason]" required rows="4" maxlength="4000">{{ $safeOld('access.reason') }}</textarea></label>
</div>
<p class="help">{{ __('mr.access_selector_help') }}</p>
<input type="hidden" name="access[suspend]" value="0">
<label style="display:block;margin:12px 0"><input type="checkbox" name="access[suspend]" value="1" @checked($safeOld('access.suspend') === '1')> {{ __('mr.access_suspend') }}</label>
<button class="btn" type="submit">{{ __('mr.access_save') }}</button>
</form></details>
@endif
@if($mrAuthorAccess['history']->isNotEmpty())
<details><summary>{{ __('mr.access_history') }}</summary><p class="help">{{ __('mr.access_history_limit') }}</p>
@foreach($mrAuthorAccess['history'] as $policy)
<details><summary>{{ __('mr.access_version') }} {{ $policy->version }} · {{ $policy->actor_name }} · {{ $policy->created_at }} UTC</summary>
<p>{{ $policy->reason }}</p><ul>@forelse(json_decode($policy->selectors,true) as $selection)<li>{{ $selection['label'] }}</li>@empty<li>{{ __('mr.access_suspended') }}</li>@endforelse</ul>
</details>
@endforeach</details>
@endif
</section>
<section class="mr-card" aria-labelledby="mr-entry-title">
<h2 id="mr-entry-title">{{ __('mr.new_draft') }}</h2>
<p class="help">{{ __('mr.draft_scope') }}</p>
@if(!$canRaiseMr)
<p data-mr-entry="blocked">{{ __('mr.not_author') }}</p>
@else
<details @if(old('mr') !== null) open @endif><summary>{{ __('mr.open_form') }}</summary>
<form method="post" id="mr-create-form" action="{{ route('procurement.requisitions.create', ['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<div class="mr-grid">
@foreach(['title_fa'=>255, 'title_en'=>255, 'discipline'=>32] as $field=>$limit)
<label>{{ __('mr.'.$field) }}<input name="mr[{{ $field }}]" required maxlength="{{ $limit }}" value="{{ $safeOld('mr.'.$field) }}" @if($field !== 'title_fa') dir="ltr" @endif></label>
@endforeach
</div>
<p class="help">{{ __('mr.line_help') }}</p>
@for($i=0; $i<20; $i++)
<details @if($i===0 || (is_array(old('mr.lines.'.$i)) && count(array_filter(old('mr.lines.'.$i), fn($v)=>is_scalar($v) && (string)$v !== '')) > 0)) open @endif>
<summary>{{ __('mr.line') }} {{ $num($i+1) }}</summary>
<div class="mr-grid">
@foreach(['item_code'=>64, 'description_fa'=>4000, 'description_en'=>4000, 'unit'=>16, 'quantity'=>19, 'specification'=>255] as $field=>$limit)
<label>{{ __('mr.'.$field) }}<input name="mr[lines][{{ $i }}][{{ $field }}]" maxlength="{{ $limit }}" value="{{ $safeOld('mr.lines.'.$i.'.'.$field) }}" @if(in_array($field,['item_code','unit','quantity','description_en'])) dir="ltr" @endif @if($field==='quantity') inputmode="decimal" @endif></label>
@endforeach
<label>{{ __('mr.subsystem') }}<select name="mr[lines][{{ $i }}][subsystem_id]"><option value="">—</option>
@foreach($mrSubsystems as $subsystem)<option value="{{ $subsystem->id }}" @selected($safeOld('mr.lines.'.$i.'.subsystem_id') === $subsystem->id)>{{ $subsystem->code }}</option>@endforeach
</select></label>
</div></details>
@endfor
<p class="help">{{ __('mr.quantity_help') }}</p>
<button type="submit" class="btn">{{ __('mr.save_draft') }}</button>
</form></details>
@endif
</section>
