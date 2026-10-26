<!doctype html><html lang="{{ $lang }}" dir="{{ $lang==='fa'?'rtl':'ltr' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $notice->number ?? $notice->id }}</title><style>body{font:16px/1.9 system-ui,sans-serif;background:#edf2f7;color:#172033}main{max-width:900px;margin:32px auto;background:white;padding:32px;border-radius:12px;border:1px solid #dbe2ed}dt{font-weight:bold;margin-top:14px}dd{margin-inline:0;white-space:pre-wrap;overflow-wrap:anywhere}.muted{color:#526174}button{background:#145d70;color:white;border:0;padding:12px 20px;border-radius:6px;font:inherit}.errors{background:#fff0ee;padding:12px}code{overflow-wrap:anywhere}@media print{.actions{display:none}body{background:white}main{border:0;margin:0}}</style></head><body><main>
<p><a id="inspection-prerequisite-register-link" href="{{ route('inspection-notifications.prerequisites.index',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('inspection_notifications.prerequisite_register_title') }}</a></p>
<h1>{{ __('inspection_notifications.title') }}</h1><h2>{{ $notice->number ?? $notice->id }}</h2><p class="muted">{{ __('inspection_notifications.scope') }}</p>
@if(session('status'))<p>{{ session('status') }}</p>@endif
@if($errors->any())<ul class="errors">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<dl><dt>{{ __('inspection_notifications.state') }} / {{ __('inspection_notifications.version') }}</dt><dd>{{ $cancellation ? 'CANCELLED' : $notice->state }} / {{ $notice->version }}</dd>
<dt>{{ __('inspection_notifications.work') }}</dt><dd>{{ $notice->work_reference }}</dd>
<dt>{{ __('inspection_notifications.activity_window') }}</dt><dd>@include('inspection-notifications.schedule', ['notice'=>$notice])</dd>
@if($notice->state==='DRAFT' && $notice->activity_start_input===null)<dd>{{ __('inspection_notifications.activity_window_required') }}</dd>@endif
<dt>{{ __('inspection_notifications.location') }}</dt><dd>{{ $notice->location }}</dd>
<dt>{{ __('inspection_notifications.po') }}</dt><dd>{{ $notice->purchase_order_id ?? '—' }}</dd>
<dt>{{ __('inspection_notifications.created') }}</dt><dd>{{ $notice->created_at }}</dd>
@if($notice->last_edited_at ?? null)<dt>{{ __('inspection_notifications.last_edit') }}</dt><dd><code>{{ $notice->last_edited_by }}</code> / {{ $notice->last_edited_at }}</dd>@endif
<dt>{{ __('inspection_notifications.stage_id') }}</dt><dd><code>{{ $notice->stage_id }}</code></dd>
@if($snapshot)
<dt>{{ __('inspection_notifications.sender') }}</dt><dd>{{ $snapshot['sender_code'] }} — {{ $snapshot['sender_name_'.$lang] }} / {{ $snapshot['issuer_name'] }}</dd>
<dt>{{ __('inspection_notifications.issued_at') }}</dt><dd>{{ $notice->issued_at }}</dd>
<dt>{{ __('inspection_notifications.revision_id') }}</dt><dd>{{ $snapshot['document_number'] }} / {{ $snapshot['revision'] }} / {{ $snapshot['cde_state'] }} / {{ $snapshot['suitability'] }}</dd>
<dt>{{ __('inspection_notifications.point') }}</dt><dd>{{ $snapshot['stage_sequence'] }} / {{ $snapshot['point'] }}</dd><dd>{{ $snapshot['description_'.$lang] ?: $snapshot['description_en'] ?: $snapshot['description_fa'] }}</dd>
<dt>{{ __('inspection_notifications.recipients') }}</dt>@foreach($snapshot['recipients'] as $recipient)<dd>{{ $recipient['code'] }} — {{ $recipient['name_'.$lang] }}</dd>@endforeach
@else
<dt>{{ __('inspection_notifications.recipients') }} (UUID)</dt>@foreach(json_decode($notice->recipient_company_ids,true) as $recipient)<dd>{{ $recipient }}</dd>@endforeach
@endif
@foreach(['en','fa'] as $noteLang)<dt>{{ __('inspection_notifications.'.$noteLang) }}</dt><dd dir="{{ $noteLang==='fa'?'rtl':'ltr' }}">{{ $notice->{'notes_'.$noteLang} }}</dd>@endforeach
</dl><p>{{ __('inspection_notifications.itp_scope') }}</p><p>{{ __('inspection_notifications.immutable') }}</p>
@include('inspection-notifications.cancellation')
@include('inspection-notifications.acknowledgments')
@if($canAcknowledge)<form class="actions" id="inspection-notification-acknowledge" method="post" action="{{ route('inspection-notifications.acknowledge',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="acknowledgment[version]" value="{{ $notice->version }}"><input type="hidden" name="acknowledgment[event_kind]" value="{{ $ackKind }}">
@if($ackKind==='CANCELLATION')<input type="hidden" name="acknowledgment[cancellation_id]" value="{{ $cancellation->id }}">@endif
<p>{{ __('inspection_notifications.ack_action_hint') }}</p><button>{{ __('inspection_notifications.ack_action_'.$ackKind) }}</button></form>@endif
@include('inspection-notifications.prerequisites')
@include('inspection-notifications.draft-history')
<div class="actions">@if($canCancel)<form id="inspection-notification-cancel" method="post" action="{{ route('inspection-notifications.cancel',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="cancel_target" value="{{ $notice->id }}"><input type="hidden" name="cancellation[version]" value="{{ $notice->version }}">
<p>{{ __('inspection_notifications.cancel_hint') }}</p>
<label for="notification-cancel-reason">{{ __('inspection_notifications.cancel_reason') }}</label><textarea id="notification-cancel-reason" name="cancellation[reason]" required maxlength="4000" rows="4" style="display:block;width:100%;box-sizing:border-box">{{ old('cancel_target') === $notice->id && is_string(old('cancellation.reason')) ? old('cancellation.reason') : '' }}</textarea>
<button>{{ __('inspection_notifications.cancel_action') }}</button></form>@endif@if($canEdit)<p><a id="inspection-notification-edit" href="{{ route('inspection-notifications.edit',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">{{ __('inspection_notifications.edit') }}</a></p>@endif@if($notice->state==='ISSUED')<p><a id="inspection-notification-pdf" href="{{ route('inspection-notifications.pdf',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">{{ __('inspection_notifications.pdf_download') }}</a></p>@endif@if($canIssue)<form id="inspection-notification-issue" method="post" action="{{ route('inspection-notifications.issue',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">@csrf<input type="hidden" name="issuance[version]" value="{{ $notice->version }}"><button>{{ __('inspection_notifications.issue') }}</button></form>@endif
<a href="{{ route('inspection-notifications.index',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('inspection_notifications.back') }}</a></div></main></body></html>
