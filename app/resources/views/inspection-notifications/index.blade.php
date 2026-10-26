<!doctype html><html lang="{{ $lang }}" dir="{{ $lang==='fa'?'rtl':'ltr' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('inspection_notifications.title') }}</title>
<style>body{font:15px/1.8 system-ui,sans-serif;background:#f1f5f9;color:#172033;margin:0}main{max-width:1150px;margin:24px auto;padding:24px}section{background:white;border:1px solid #d9e1ec;border-radius:12px;padding:22px;margin:20px 0}h1,h2{line-height:1.4}h2{font-size:20px}label{display:block;margin:12px 0}input,select,textarea{font:inherit;max-width:100%;padding:8px;border:1px solid #b8c4d5;border-radius:5px;box-sizing:border-box}textarea{display:block;width:100%}button,.action{background:#135e71;color:white;border:0;border-radius:5px;padding:9px 15px;cursor:pointer}a{color:#075e7b}table{width:100%;border-collapse:collapse}td,th{text-align:start;padding:9px;border-bottom:1px solid #e5eaf1;vertical-align:top;overflow-wrap:anywhere}small,.muted{color:#586579}code{direction:ltr;unicode-bidi:embed;overflow-wrap:anywhere}nav svg{width:18px;height:18px}.errors{background:#fff0ee;border-color:#e7a49d}.success{background:#e9f7ef}form{margin:15px 0}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}</style></head><body><main>
@php
 $link=['projectCode'=>$project->code,'lang'=>$lang];
 $browseLink=$link+array_filter(array_intersect_key($filters,array_flip(['notice_q','notice_state','activity_from','activity_to'])),static fn($v)=>$v!=='');
 if($selected){ $browseLink['stage']=$selected['s']->id; }
 foreach(['revision_page','stage_page','notice_page'] as $pageKey){ $pageValue=request()->query($pageKey); if(is_scalar($pageValue) && filter_var($pageValue,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false){ $browseLink[$pageKey]=(int)$pageValue; } }
 $filterContext=array_diff_key($browseLink,array_flip(['notice_q','notice_state','activity_from','activity_to','notice_page']));
 $safe=fn($key,$default='')=>is_scalar(old($key,$default))?(string)old($key,$default):'';
 $draftIds=$settings?json_decode($settings->draft_users,true):[];
 $issueIds=$settings?json_decode($settings->issue_users,true):[];
 $oldMatches=$selected && old('notification.itp_stage_id')===$selected['s']->id && old('notification.itp_revision_id')===$selected['rev']->id;
 $restore=fn($key)=>$oldMatches?$safe('notification.'.$key):'';
 $oldRecipients=$oldMatches && is_array(old('notification.recipient_company_ids'))?old('notification.recipient_company_ids'):[];
@endphp
<a href="{{ route('inspection-notifications.index',array_replace($browseLink,['lang'=>$lang==='fa'?'en':'fa'])) }}">{{ $lang==='fa'?'English':'فارسی' }}</a>
<p><a id="inspection-prerequisite-register-link" href="{{ route('inspection-notifications.prerequisites.index',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('inspection_notifications.prerequisite_register_title') }}</a></p>
<h1>{{ __('inspection_notifications.title') }} — {{ $project->code }}</h1><p>{{ __('inspection_notifications.scope') }}</p><p>{{ __('inspection_notifications.itp_scope') }}</p>
@if(session('status'))<section class="success">{{ session('status') }}</section>@endif
@if($errors->any())<section class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>@endif
@if($canManage)
<section><h2>{{ __('inspection_notifications.settings') }}</h2><p>{{ __('inspection_notifications.revoke') }}</p>
<form id="notification-settings-form" method="post" action="{{ route('inspection-notifications.settings',$link) }}">@csrf
<input type="hidden" name="settings[version]" value="{{ $settings->version ?? 0 }}">
<label>{{ __('inspection_notifications.main') }} <select required name="settings[main_company_id]"><option value="">—</option>@foreach($epcs as $epc)<option value="{{ $epc->id }}" @selected(($settings->main_company_id ?? '')===$epc->id)>{{ $epc->code }} — {{ $epc->{'name_'.$lang} }}</option>@endforeach</select></label>
<div class="fields">@foreach(['draft_users'=>$draftIds,'issue_users'=>$issueIds] as $field=>$ids)<label>{{ __('inspection_notifications.'.$field) }}<select multiple size="6" name="settings[{{ $field }}][]">@foreach($epcs as $epc)<optgroup label="{{ $epc->code }}">@foreach($people->where('company_id',$epc->id) as $person)<option value="{{ $person->id }}" @selected(in_array($person->id,$ids,true))>{{ $person->name }}</option>@endforeach</optgroup>@endforeach</select></label>@endforeach</div>
<label>{{ __('inspection_notifications.reason') }}<textarea required maxlength="4000" name="settings[reason]"></textarea></label><button>{{ __('inspection_notifications.save') }}</button></form>
<details><summary>{{ __('inspection_notifications.history') }}</summary>@foreach($history as $entry)<p>{{ $entry->version }} · {{ $entry->main_company_id }} · {{ $entry->created_at }} — {{ $entry->reason }}</p>@endforeach</details></section>
@endif
<section><h2>{{ __('inspection_notifications.itp_revisions') }}</h2><table><tbody>@forelse($revisions as $revision)<tr><td>{{ $revision->number }} / {{ $revision->revision }} / {{ $revision->cde_state }}</td><td><code>{{ $revision->id }}</code></td></tr>@empty<tr><td>{{ __('inspection_notifications.empty') }}</td></tr>@endforelse</tbody></table>{{ $revisions->links() }}
@if($canAddStages)
<details><summary>{{ __('inspection_notifications.stage_add') }}</summary><form id="itp-stage-form" method="post" action="{{ route('inspection-notifications.stages',$link) }}">@csrf
<label>{{ __('inspection_notifications.revision_id') }} <input required name="stage[revision_id]" value="{{ $safe('stage.revision_id') }}" size="40"></label>
<label>{{ __('inspection_notifications.sequence') }} <input required type="number" min="1" max="2147483647" name="stage[sequence]" value="{{ $safe('stage.sequence') }}"></label>
<label>{{ __('inspection_notifications.point') }} <select required name="stage[point]"><option value="">—</option>@foreach(['H','W','R','M'] as $point)<option @selected($safe('stage.point')===$point)>{{ $point }}</option>@endforeach</select></label>
<label>{{ __('inspection_notifications.required') }} <select required name="stage[requires_notification]"><option value="">—</option><option value="1">✓</option><option value="0">—</option></select></label>
@foreach(['en','fa'] as $descriptionLang)<label>{{ __('inspection_notifications.'.$descriptionLang) }}<textarea maxlength="8000" dir="{{ $descriptionLang==='fa'?'rtl':'ltr' }}" name="stage[description_{{ $descriptionLang }}]">{{ $safe('stage.description_'.$descriptionLang) }}</textarea></label>@endforeach
<button>{{ __('inspection_notifications.save') }}</button></form></details>
@endif</section>
<section><h2>{{ __('inspection_notifications.stages') }}</h2><table><tbody>@forelse($stages as $stage)<tr><td>{{ $stage->sequence }} / {{ $stage->point }} / {{ $stage->discipline }}</td><td>{{ $stage->{'description_'.$lang} ?: $stage->description_en ?: $stage->description_fa }}</td><td>@if($stage->requires_notification)<a href="{{ route('inspection-notifications.index',array_replace($browseLink,['stage'=>$stage->id])) }}">{{ __('inspection_notifications.select_stage') }}</a>@else — @endif</td></tr>@empty<tr><td>{{ __('inspection_notifications.empty') }}</td></tr>@endforelse</tbody></table>{{ $stages->links() }}</section>
@if($selected)
<section><h2>{{ __('inspection_notifications.draft') }}</h2><p>{{ $selected['doc']->number }} / {{ $selected['rev']->revision }} · {{ $selected['s']->sequence }} / {{ $selected['s']->point }}</p><p>{{ $selected['s']->{'description_'.$lang} ?: $selected['s']->description_en ?: $selected['s']->description_fa }}</p>
@if($selected['canDraft'])
<form id="inspection-notification-draft" method="post" action="{{ route('inspection-notifications.store',$link) }}">@csrf
<input type="hidden" name="notification[itp_revision_id]" value="{{ $selected['rev']->id }}"><input type="hidden" name="notification[itp_stage_id]" value="{{ $selected['s']->id }}">
<label>{{ __('inspection_notifications.work') }}<textarea required maxlength="1000" name="notification[work_reference]">{{ $restore('work_reference') }}</textarea></label>
<p>{{ __('inspection_notifications.activity_offset_hint') }}</p>
@foreach(['start','end'] as $boundary)
@include('inspection-notifications.time-field', ['boundary'=>$boundary, 'timeValue'=>$restore('activity_'.$boundary.'_at')])
@endforeach
<label>{{ __('inspection_notifications.location') }}<textarea required maxlength="2000" name="notification[location]">{{ $restore('location') }}</textarea></label>
<label>{{ __('inspection_notifications.recipients') }}<select required multiple size="7" name="notification[recipient_company_ids][]">@foreach($recipientCompanies as $company)<option value="{{ $company->id }}" @selected(in_array($company->id,$oldRecipients,true))>{{ $company->code }} — {{ $company->{'name_'.$lang} }}</option>@endforeach</select></label>
<label>{{ __('inspection_notifications.po') }} <input name="notification[purchase_order_id]" size="40" value="{{ $restore('purchase_order_id') }}"></label>
@foreach(['en','fa'] as $noteLang)<label>{{ __('inspection_notifications.'.$noteLang) }}<textarea maxlength="8000" dir="{{ $noteLang==='fa'?'rtl':'ltr' }}" name="notification[notes_{{ $noteLang }}]">{{ $restore('notes_'.$noteLang) }}</textarea></label>@endforeach
<button>{{ __('inspection_notifications.save') }}</button></form>
@else<p>{{ __('inspection_notifications.no_grant') }}</p>@endif</section>
@endif
<section><h2>{{ __('inspection_notifications.register') }}</h2>@include('inspection-notifications.filters')<table><tbody>@forelse($notifications as $notice)<tr><td><a href="{{ route('inspection-notifications.show',$link+['notificationId'=>$notice->id]) }}">{{ $notice->number ?? $notice->id }}</a></td><td>{{ $notice->cancellation ? 'CANCELLED' : $notice->state }} / {{ $notice->discipline }}</td><td>{{ $notice->work_reference }}</td><td>@include('inspection-notifications.schedule', ['notice'=>$notice])</td></tr>@empty<tr><td>{{ __('inspection_notifications.empty') }}</td></tr>@endforelse</tbody></table>{{ $notifications->links() }}</section>
<p>{{ __('inspection_notifications.immutable') }}</p></main>@include('inspection-notifications.time-controls-script')</body></html>
