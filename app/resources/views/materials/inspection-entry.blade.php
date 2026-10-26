@if($canRecordInspection)
@php
 $inspectionOldMatches = old('inspection.purchase_order_id') === $order->id;
 $inspectionOld = fn($key,$default='') => $inspectionOldMatches && is_scalar(old('inspection.'.$key)) ? (string) old('inspection.'.$key) : $default;
@endphp
<section class="po-note"><h2>{{ __('inspection_entry.title') }}</h2><p>{{ __('inspection_entry.scope') }}</p><p>{{ __('inspection_entry.independence') }}</p>
<form method="post" id="inspection-create-form" action="{{ route('procurement.orders.inspections.create',['projectCode'=>$project->code,'orderId'=>$order->id,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="inspection[purchase_order_id]" value="{{ $order->id }}">
<label>{{ __('inspection_entry.result') }} <select name="inspection[result]" required><option value="">—</option>@foreach(\App\Support\ProcurementState::INSPECTION_ALL as $result)<option value="{{ $result }}" @selected($inspectionOld('result')===$result)>{{ \App\Support\ProcurementState::inspectionLabel($result)[$lang] }}</option>@endforeach</select></label>
<label>{{ __('inspection_entry.stage') }} <select name="inspection[stage]" required><option value="">—</option>@foreach(\App\Support\ProcurementState::STAGE_ALL as $stage)<option value="{{ $stage }}" @selected($inspectionOld('stage')===$stage)>{{ \App\Support\ProcurementState::stageLabel($stage)[$lang] }}</option>@endforeach</select></label>
<label>{{ __('inspection_entry.date') }} <input type="date" name="inspection[inspection_date]" required value="{{ $inspectionOld('inspection_date') }}"></label>
@foreach(['en','fa'] as $findingLang)<label style="display:block">{{ __('inspection_entry.findings_'.$findingLang) }}<textarea name="inspection[findings_{{ $findingLang }}]" maxlength="8000" dir="{{ $findingLang==='fa'?'rtl':'ltr' }}" style="display:block;width:100%;box-sizing:border-box">{{ $inspectionOld('findings_'.$findingLang) }}</textarea></label>@endforeach
<button class="btn" type="submit">{{ __('inspection_entry.save') }}</button>
</form></section>
@endif
