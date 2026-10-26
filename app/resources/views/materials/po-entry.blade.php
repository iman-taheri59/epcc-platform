@php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
    $currentSelectors = array_column($poAuthorAccess['selectors'], 'selector');
    $selectedAuthors = old('po_access.selectors', $currentSelectors);
    $selectedAuthors = is_array($selectedAuthors) ? $selectedAuthors : [];
@endphp
<style>
.po-card { border:1px solid var(--line);border-radius:12px;background:var(--card);padding:16px;margin:16px 0; }
.po-card h2 { font-size:16px;margin:0 0 10px; }.po-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0; }
.po-card label { display:flex;flex-direction:column;gap:5px;font-size:12px; }
.po-card input:not([type=checkbox]),.po-card select,.po-card textarea { width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:6px;background:#0b1220;color:#e2e8f0;padding:8px;font:inherit; }
.po-card details { padding:9px 0;border-top:1px solid var(--line); }.po-card summary { cursor:pointer; }.po-card .help { font-size:12px;color:var(--muted);line-height:1.8; }
</style>
<section class="po-card" aria-labelledby="po-access-title">
<h2 id="po-access-title">{{ __('po_entry.access_title') }}</h2>
<p class="help">{{ __('po_entry.access_scope') }}</p>
@if(!$poAuthorAccess['current'])
<p data-po-access="unconfigured">{{ __('po_entry.access_unconfigured') }}</p>
@elseif($poAuthorAccess['selectors'] === [])
<p data-po-access="suspended">{{ __('po_entry.access_suspended') }}</p>
@else
<p data-po-access="configured">{{ __('po_entry.access_version') }} {{ $poAuthorAccess['current']->version }}</p>
<ul>@foreach($poAuthorAccess['selectors'] as $selection)<li>{{ $selection['label'] }}</li>@endforeach</ul>
@endif
@if($poAuthorAccess['canConfigure'])
<details @if(!$poAuthorAccess['current'] || old('po_access') !== null) open @endif><summary>{{ __('po_entry.access_configure') }}</summary>
<form method="post" id="po-access-form" action="{{ route('procurement.order-author-access', ['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="po_access[version]" value="{{ $safeOld('po_access.version', $poAuthorAccess['current']->version ?? 0) }}">
<div class="po-grid">
<label>{{ __('po_entry.access_selectors') }}<select name="po_access[selectors][]" multiple size="8">
@foreach($poAuthorAccess['options'] as $value=>$label)<option value="{{ $value }}" @selected(in_array($value,$selectedAuthors,true))>{{ $label }}</option>@endforeach
</select></label>
<label>{{ __('po_entry.reason') }}<textarea name="po_access[reason]" required rows="4" maxlength="4000">{{ $safeOld('po_access.reason') }}</textarea></label>
</div>
<p class="help">{{ __('po_entry.access_selector_help') }}</p>
<input type="hidden" name="po_access[suspend]" value="0">
<label style="display:block;margin:12px 0"><input type="checkbox" name="po_access[suspend]" value="1" @checked($safeOld('po_access.suspend') === '1')> {{ __('po_entry.access_suspend') }}</label>
<button class="btn" type="submit">{{ __('po_entry.access_save') }}</button>
</form></details>
@endif
@if($poAuthorAccess['history']->isNotEmpty())
<details><summary>{{ __('po_entry.access_history') }}</summary><p class="help">{{ __('po_entry.access_history_limit') }}</p>
@foreach($poAuthorAccess['history'] as $policy)
<details><summary>{{ __('po_entry.access_version') }} {{ $policy->version }} · {{ $policy->actor_name }} · {{ $policy->created_at }} UTC</summary>
<p>{{ $policy->reason }}</p><ul>@forelse(json_decode($policy->selectors,true) as $selection)<li>{{ $selection['label'] }}</li>@empty<li>{{ __('po_entry.access_suspended') }}</li>@endforelse</ul>
</details>
@endforeach</details>
@endif
</section>
<section class="po-card" aria-labelledby="po-entry-title">
<h2 id="po-entry-title">{{ __('po_entry.new_draft') }}</h2>
<p class="help">{{ __('po_entry.draft_scope') }}</p>
@if(!$canRaisePo)
<p data-po-entry="blocked">{{ __('po_entry.not_author') }}</p>
@else
<details @if(old('po') !== null) open @endif><summary>{{ __('po_entry.open_form') }}</summary>
<form method="post" id="po-create-form" action="{{ route('procurement.orders.create', ['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<div class="po-grid">
@foreach(['title_fa'=>255, 'title_en'=>255, 'discipline'=>32] as $field=>$limit)
<label>{{ __('po_entry.'.$field) }}<input name="po[{{ $field }}]" required maxlength="{{ $limit }}" value="{{ $safeOld('po.'.$field) }}" @if($field !== 'title_fa') dir="ltr" @endif></label>
@endforeach
@include('materials.procurement-category-field', ['entryRoot'=>'po'])
</div>
<div class="po-grid"><label>{{ __('po_entry.vendor') }}<select name="po[vendor_company_id]" required><option value="">—</option>
@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected($safeOld('po.vendor_company_id') === $vendor->id)>{{ $vendor->name($lang) }} · {{ $vendor->code }}</option>@endforeach
</select></label>
<label>{{ __('po_entry.currency') }}<input name="po[currency]" maxlength="8" value="{{ $safeOld('po.currency') }}" dir="ltr"></label>
<label>{{ __('po_entry.promised_date') }}<input type="date" name="po[promised_date]" value="{{ $safeOld('po.promised_date') }}"></label></div>
<p class="help">{{ __('po_entry.line_help') }}</p>
@for($i=0; $i<20; $i++)
<details @if($i===0 || (is_array(old('po.lines.'.$i)) && count(array_filter(old('po.lines.'.$i), fn($v)=>is_scalar($v) && (string)$v !== '')) > 0)) open @endif>
<summary>{{ __('po_entry.line') }} {{ $num($i+1) }}</summary>
<div class="po-grid">
@foreach(['item_code'=>64, 'description_fa'=>4000, 'description_en'=>4000, 'unit'=>16, 'quantity'=>19, 'unit_price'=>19] as $field=>$limit)
<label>{{ __('po_entry.'.$field) }}<input name="po[lines][{{ $i }}][{{ $field }}]" maxlength="{{ $limit }}" value="{{ $safeOld('po.lines.'.$i.'.'.$field) }}" @if(in_array($field,['item_code','unit','quantity','description_en'])) dir="ltr" @endif @if($field==='quantity') inputmode="decimal" @endif></label>
@endforeach
<label>{{ __('procurement_category.material_id') }}<input name="po[lines][{{ $i }}][material_item_id]" maxlength="36" dir="ltr" value="{{ $safeOld('po.lines.'.$i.'.material_item_id') }}"></label>
<label>{{ __('po_entry.subsystem') }}<select name="po[lines][{{ $i }}][subsystem_id]"><option value="">—</option>
@foreach($mrSubsystems as $subsystem)<option value="{{ $subsystem->id }}" @selected($safeOld('po.lines.'.$i.'.subsystem_id') === $subsystem->id)>{{ $subsystem->code }}</option>@endforeach
</select></label>
</div></details>
@endfor
<p class="help">{{ __('procurement_category.identity_help') }}</p>
<p class="help">{{ __('po_entry.quantity_help') }}</p>
<button type="submit" class="btn">{{ __('po_entry.save_draft') }}</button>
</form></details>
@endif
</section>
