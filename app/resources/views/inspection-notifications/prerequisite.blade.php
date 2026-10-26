<!doctype html><html lang="{{ $lang }}" dir="{{ $lang==='fa'?'rtl':'ltr' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('inspection_notifications.prerequisite_title') }}</title><style>body{font:16px/1.9 system-ui,sans-serif;background:#edf2f7;color:#172033;margin:0}main{max-width:900px;margin:24px auto;padding:24px;background:white;overflow-wrap:anywhere}section,form{border:1px solid #b8c4d5;padding:16px;margin:18px 0;border-radius:8px}label{display:block}textarea{font:inherit;display:block;width:100%;box-sizing:border-box;padding:8px}button{font:inherit;background:#145d70;color:white;padding:10px 18px;border:0;border-radius:5px;margin:12px 0}.text{white-space:pre-wrap;unicode-bidi:plaintext}.errors{background:#fff0ee;padding:12px}a{color:#075e7b}nav svg{width:18px;height:18px}@media print{.actions{display:none}}</style></head><body><main>
@php $link=['projectCode'=>$project->code,'notificationId'=>$notice->id,'prerequisiteId'=>$prerequisite->id,'lang'=>$lang]; @endphp
<a href="{{ route('inspection-notifications.prerequisites.show',array_replace($link,['lang'=>$lang==='fa'?'en':'fa'])) }}">{{ $lang==='fa'?'English':'فارسی' }}</a>
<p><a id="inspection-prerequisite-register-link" href="{{ route('inspection-notifications.prerequisites.index',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('inspection_notifications.prerequisite_register_title') }}</a></p>
<h1>{{ __('inspection_notifications.prerequisite_title') }}</h1><p>{{ $notice->number }} — {{ $prerequisite->{'company_name_'.$lang} }}</p>
<p>{{ __('inspection_notifications.prerequisite_scope') }}</p>
@include('inspection-notifications.prerequisite-stage')
@if(session('status'))<p>{{ session('status') }}</p>@endif
@if($errors->any())<ul class="errors">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<section><h2>{{ $prerequisite->item_reference }}</h2><div class="text">{{ $prerequisite->requirement }}</div>
<p>{{ __('inspection_notifications.prerequisite_state_'.$prerequisite->state) }} / {{ __('inspection_notifications.version') }} {{ $prerequisite->version }}</p>
<p>{{ __('inspection_notifications.prerequisite_action_'.$prerequisite->latest_action) }} — {{ $prerequisite->latest_actor_name }} / {{ $prerequisite->{'latest_company_name_'.$lang} }} · <bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($prerequisite->latest_at)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></p>
@if($prerequisite->latest_note!==null)<div class="text">{{ $prerequisite->latest_note }}</div>@endif</section>
@foreach($actions as $action)
<form class="actions" id="inspection-prerequisite-{{ strtolower($action) }}" method="post" action="{{ route('inspection-notifications.prerequisites.update',$link) }}">@csrf
<h2>{{ __('inspection_notifications.prerequisite_action_'.$action) }}</h2><input type="hidden" name="prerequisite_target" value="{{ $prerequisite->id }}"><input type="hidden" name="prerequisite[version]" value="{{ $prerequisite->version }}"><input type="hidden" name="prerequisite[action]" value="{{ $action }}">
@php $restore=fn($key,$default='')=>old('prerequisite_target')===$prerequisite->id && old('prerequisite.action')===$action && is_string(old('prerequisite.'.$key)) ? old('prerequisite.'.$key) : $default; @endphp
@if($action==='EDIT')
<label>{{ __('inspection_notifications.prerequisite_item') }}<textarea name="prerequisite[item_reference]" required maxlength="1000" rows="2">{{ $restore('item_reference',$prerequisite->item_reference) }}</textarea></label>
<label>{{ __('inspection_notifications.prerequisite_requirement') }}<textarea name="prerequisite[requirement]" required maxlength="4000" rows="4">{{ $restore('requirement',$prerequisite->requirement) }}</textarea></label>
@endif
<label>{{ __('inspection_notifications.prerequisite_note') }} @if($action==='CONFIRM')({{ __('inspection_notifications.prerequisite_optional') }})@endif<textarea name="prerequisite[note]" @required($action!=='CONFIRM') maxlength="4000" rows="3">{{ $restore('note') }}</textarea></label>
<p>{{ __('inspection_notifications.prerequisite_disclosure') }}</p><button>{{ __('inspection_notifications.prerequisite_action_'.$action) }}</button></form>
@endforeach
<section id="inspection-prerequisite-history"><h2>{{ __('inspection_notifications.prerequisite_history') }}</h2>
@foreach($prerequisiteHistory as $event)
@php $before=$event->before_snapshot ? json_decode($event->before_snapshot,true,512,JSON_THROW_ON_ERROR) : null; $after=json_decode($event->after_snapshot,true,512,JSON_THROW_ON_ERROR); @endphp
<article style="border-bottom:1px solid #b8c4d5;padding:12px 0"><h3>{{ $event->version }} — {{ __('inspection_notifications.prerequisite_action_'.$event->action) }}</h3>
<p>{{ $after['latest_actor_name'] }} / {{ $after['latest_company_name_'.$lang] }} · <bdi dir="ltr">{{ $after['latest_at'] }}</bdi></p>
@if($before)<details><summary>{{ __('inspection_notifications.prerequisite_before') }} — {{ __('inspection_notifications.prerequisite_state_'.$before['state']) }}</summary><div class="text">{{ $before['item_reference'] }}
{{ $before['requirement'] }}
{{ $before['latest_note'] }}</div></details>@endif
<p>{{ __('inspection_notifications.prerequisite_after') }} — {{ __('inspection_notifications.prerequisite_state_'.$after['state']) }}</p><div class="text">{{ $after['item_reference'] }}
{{ $after['requirement'] }}
{{ $after['latest_note'] }}</div></article>
@endforeach
{{ $prerequisiteHistory->links() }}</section>
<a href="{{ route('inspection-notifications.show',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">{{ __('inspection_notifications.back') }}</a>
</main></body></html>
