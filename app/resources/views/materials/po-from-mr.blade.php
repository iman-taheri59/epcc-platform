@php($sourceOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '')
@if($canRaisePo && $mr->company_id === $user->company_id && $mr->isOrderable() && $mr->material_category && $mr->items->count() >= 1 && $mr->items->count() <= 20)
<details id="po-from-mr-{{ $mr->id }}" @if(old('po_source.requisition_id') === $mr->id) open @endif>
<summary>{{ __('po_source.action') }}</summary>
<p class="muted">{{ __('po_source.scope') }}</p>
<form method="post" action="{{ route('procurement.orders.from-requisition', ['projectCode'=>$project->code, 'lang'=>$lang]) }}">
@csrf
<input type="hidden" name="po_source[requisition_id]" value="{{ $mr->id }}">
<label>{{ __('po_source.vendor') }} <select name="po_source[vendor_company_id]" required><option value="">—</option>
@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('po_source.requisition_id') === $mr->id && $sourceOld('po_source.vendor_company_id') === $vendor->id)>{{ $vendor->name($lang) }} · {{ $vendor->code }}</option>@endforeach
</select></label>
<label>{{ __('po_source.date') }} <input type="date" name="po_source[promised_date]" value="{{ old('po_source.requisition_id') === $mr->id ? $sourceOld('po_source.promised_date') : '' }}"></label>
<button class="btn" type="submit">{{ __('po_source.save') }}</button>
</form></details>
@endif
