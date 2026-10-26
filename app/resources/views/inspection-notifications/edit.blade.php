<!doctype html><html lang="{{ $lang }}" dir="{{ $lang==='fa'?'rtl':'ltr' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('inspection_notifications.edit') }}</title><style>body{font:16px/1.9 system-ui,sans-serif;background:#edf2f7;color:#172033}main{max-width:900px;margin:32px auto;background:white;padding:32px;border-radius:12px}label{display:block;margin:14px 0}textarea{display:block;width:95%;min-height:90px}input,select,button{font:inherit;max-width:100%}.errors{background:#fff0ee;padding:16px}code{overflow-wrap:anywhere}</style></head><body><main>
@php
$restore = old('edit_target') === $notice->id && (string) old('notification.version') === (string) $notice->version;
$values = ['work_reference'=>$notice->work_reference,'activity_start_at'=>$notice->activity_start_input,'activity_end_at'=>$notice->activity_end_input,'location'=>$notice->location,'purchase_order_id'=>$notice->purchase_order_id,'notes_en'=>$notice->notes_en,'notes_fa'=>$notice->notes_fa];
$value = static function($key) use($restore,$values) { $v=$restore?old('notification.'.$key):$values[$key]; return is_scalar($v)?(string)$v:''; };
$recipients=$restore?old('notification.recipient_company_ids',[]):json_decode($notice->recipient_company_ids,true);
$recipients=is_array($recipients)?$recipients:[];
@endphp
<h1>{{ __('inspection_notifications.edit') }}</h1><p>{{ __('inspection_notifications.edit_scope') }}</p><p><code>{{ $notice->id }}</code> / {{ __('inspection_notifications.version') }} {{ $notice->version }}</p>
<p>{{ __('inspection_notifications.revision_id') }}: <code>{{ $revision->id }}</code> / {{ $revision->revision }} · {{ $stage->sequence }} / {{ $stage->point }}</p>
@if($errors->any())<ul class="errors">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form id="inspection-notification-edit-form" method="post" action="{{ route('inspection-notifications.update',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">@csrf @method('PUT')
<input type="hidden" name="edit_target" value="{{ $notice->id }}"><input type="hidden" name="notification[version]" value="{{ $notice->version }}">
<label>{{ __('inspection_notifications.work') }}<textarea required maxlength="1000" name="notification[work_reference]">{{ $value('work_reference') }}</textarea></label>
<p>{{ __('inspection_notifications.activity_offset_hint') }}</p>
@if($notice->activity_start_input === null)<aside>@include('inspection-notifications.schedule', ['notice'=>$notice])<p>{{ __('inspection_notifications.activity_window_required') }}</p></aside>@endif
@foreach(['start','end'] as $boundary)
@include('inspection-notifications.time-field', ['boundary'=>$boundary, 'timeValue'=>$value('activity_'.$boundary.'_at')])
@endforeach
<label>{{ __('inspection_notifications.location') }}<textarea required maxlength="2000" name="notification[location]">{{ $value('location') }}</textarea></label>
<label>{{ __('inspection_notifications.recipients') }}<select required multiple size="7" name="notification[recipient_company_ids][]">@foreach($companies as $company)<option value="{{ $company->id }}" @selected(in_array($company->id,$recipients,true))>{{ $company->code }} — {{ $company->{'name_'.$lang} }}</option>@endforeach</select></label>
<label>{{ __('inspection_notifications.po') }} <input name="notification[purchase_order_id]" size="40" value="{{ $value('purchase_order_id') }}"></label>
@foreach(['en','fa'] as $noteLang)<label>{{ __('inspection_notifications.'.$noteLang) }}<textarea maxlength="8000" dir="{{ $noteLang==='fa'?'rtl':'ltr' }}" name="notification[notes_{{ $noteLang }}]">{{ $value('notes_'.$noteLang) }}</textarea></label>@endforeach
<button>{{ __('inspection_notifications.save') }}</button></form>
<a href="{{ route('inspection-notifications.show',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">{{ __('inspection_notifications.back') }}</a>
</main>@include('inspection-notifications.time-controls-script')</body></html>
