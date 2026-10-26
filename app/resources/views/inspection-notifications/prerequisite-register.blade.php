<!doctype html><html lang="{{ $lang }}" dir="{{ $lang==='fa'?'rtl':'ltr' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('inspection_notifications.prerequisite_register_title') }}</title><style>body{font:16px/1.8 system-ui,sans-serif;background:#f1f5f9;color:#172033;margin:0}main{max-width:1200px;margin:24px auto;padding:20px}section{background:white;padding:20px;border:1px solid #ccd6e1;border-radius:10px;margin:16px 0}label{display:block}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}input,select,button{font:inherit;padding:8px;max-width:100%;box-sizing:border-box}input,select{width:100%}button{background:#145d70;color:white;border:0;border-radius:5px;margin:14px 0}a{color:#075e7b}table{width:100%;border-collapse:collapse}th,td{text-align:start;vertical-align:top;padding:12px;border-bottom:1px solid #dce3eb;overflow-wrap:anywhere}code{font-size:12px;overflow-wrap:anywhere}.table-scroll{overflow-x:auto}small{display:block;color:#526174}.cancelled{background:#fff1e9;padding:6px}nav svg{width:18px;height:18px}@media print{form,.navigation{display:none}body{background:white}}</style></head><body><main>
@php
$link=['projectCode'=>$project->code,'lang'=>$lang];
$filterLink=$link+array_filter($filters,static fn($v)=>$v!=='');
$languageLink=array_replace($filterLink,['lang'=>$lang==='fa'?'en':'fa']);
if($prerequisites->currentPage()>1){$languageLink['prerequisite_register_page']=$prerequisites->currentPage();}
if($prerequisiteCompanies->currentPage()>1){$languageLink['prerequisite_company_page']=$prerequisiteCompanies->currentPage();}
@endphp
<div class="navigation"><a href="{{ route('inspection-notifications.prerequisites.index',$languageLink) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('inspection-notifications.index',$link) }}">{{ __('inspection_notifications.prerequisite_register_back') }}</a></div>
<h1>{{ __('inspection_notifications.prerequisite_register_title') }} — {{ $project->code }}</h1>
<p>{{ __('inspection_notifications.prerequisite_register_hint') }}</p>
<p>{{ __('inspection_notifications.prerequisite_notice_filter_hint') }}</p>
<section><form id="inspection-prerequisite-filters" method="get" action="{{ route('inspection-notifications.prerequisites.index',['projectCode'=>$project->code]) }}">
<input type="hidden" name="lang" value="{{ $lang }}"><div class="fields">
<label>{{ __('inspection_notifications.prerequisite_notice_filter_label') }}<select id="inspection-prerequisite-notice-state" name="prerequisite_notice_state"><option value="">{{ __('inspection_notifications.prerequisite_notice_filter_all') }}</option>@foreach(['ACTIVE','CANCELLED'] as $noticeState)<option value="{{ $noticeState }}" @selected($filters['prerequisite_notice_state']===$noticeState)>{{ __('inspection_notifications.prerequisite_notice_filter_'.$noticeState) }}</option>@endforeach</select></label>
<label>{{ __('inspection_notifications.prerequisite_register_state') }}<select name="prerequisite_state"><option value="">{{ __('inspection_notifications.prerequisite_register_all') }}</option>@foreach(['OPEN','REPORTED','FULFILLED','WITHDRAWN'] as $state)<option value="{{ $state }}" @selected($filters['prerequisite_state']===$state)>{{ __('inspection_notifications.prerequisite_state_'.$state) }}</option>@endforeach</select></label>
<label>{{ __('inspection_notifications.prerequisite_picker_search') }}<input name="prerequisite_company_q" value="{{ $filters['prerequisite_company_q'] }}" maxlength="100"></label>
<label>{{ __('inspection_notifications.prerequisite_picker_label') }}<select id="inspection-prerequisite-company" name="prerequisite_company">
<option value="">{{ __('inspection_notifications.prerequisite_picker_all') }}</option>
@if($filters['prerequisite_company']!=='' && !$prerequisiteCompanies->contains('company_id',$filters['prerequisite_company']))
<option value="{{ $filters['prerequisite_company'] }}" selected>@if($selectedPrerequisiteCompany){{ $selectedPrerequisiteCompany->company_code }} — {{ $selectedPrerequisiteCompany->{'company_name_'.$lang} }}@else{{ __('inspection_notifications.prerequisite_picker_unavailable') }}@endif</option>
@endif
@foreach($prerequisiteCompanies as $company)
<option value="{{ $company->company_id }}" @selected($filters['prerequisite_company']===$company->company_id)>{{ $company->company_code }} — {{ $company->{'company_name_'.$lang} }}</option>
@endforeach
</select></label>
<label>{{ __('inspection_notifications.prerequisite_register_notice') }}<input name="prerequisite_notice" value="{{ $filters['prerequisite_notice'] }}" maxlength="100"></label>
</div><p>{{ __('inspection_notifications.prerequisite_picker_hint') }}</p><p id="inspection-prerequisite-company-count">{{ __('inspection_notifications.prerequisite_picker_count') }}: {{ $prerequisiteCompanies->total() }}</p>@if($prerequisiteCompanies->total()===0)<p>{{ __('inspection_notifications.prerequisite_picker_empty') }}</p>@endif
{{ $prerequisiteCompanies->links() }}<button>{{ __('inspection_notifications.prerequisite_register_apply') }}</button> · <a href="{{ route('inspection-notifications.prerequisites.index',$link) }}">{{ __('inspection_notifications.prerequisite_register_reset') }}</a></form></section>
<section><p id="inspection-prerequisite-counts">{{ __('inspection_notifications.prerequisite_register_counts') }}: {{ $prerequisites->total() }} / {{ $prerequisiteVisibleTotal }}</p>
<div class="table-scroll"><table><thead><tr><th scope="col">{{ __('inspection_notifications.prerequisite_register_notice_column') }}</th><th scope="col">{{ __('inspection_notifications.prerequisite_register_company_column') }}</th><th scope="col">{{ __('inspection_notifications.prerequisite_register_item_column') }}</th><th scope="col">{{ __('inspection_notifications.prerequisite_register_state') }}</th><th scope="col">{{ __('inspection_notifications.prerequisite_register_latest_column') }}</th></tr></thead><tbody>
@forelse($prerequisites as $entry)
<tr><td><a href="{{ route('inspection-notifications.show',$link+['notificationId'=>$entry->notification_id]) }}">{{ $entry->notice_number }}</a><br><a href="{{ route('inspection-notifications.prerequisites.index',array_replace($filterLink,['prerequisite_notice'=>$entry->notice_number])) }}">{{ __('inspection_notifications.prerequisite_register_notice_link') }}</a>@if($entry->cancellation_id)<p class="cancelled">{{ __('inspection_notifications.prerequisite_register_cancelled') }}</p>@endif</td>
<td>{{ $entry->company_code }} — {{ $entry->{'company_name_'.$lang} }}<br><a href="{{ route('inspection-notifications.prerequisites.index',array_replace($filterLink,['prerequisite_company'=>$entry->company_id])) }}">{{ __('inspection_notifications.prerequisite_register_company_link') }}</a><small><bdi dir="ltr">{{ $entry->company_id }}</bdi></small></td>
<td><a href="{{ route('inspection-notifications.prerequisites.show',$link+['notificationId'=>$entry->notification_id,'prerequisiteId'=>$entry->id]) }}">{{ $entry->item_reference }}</a><small>{{ __('inspection_notifications.prerequisite_register_open') }}</small></td>
<td>{{ __('inspection_notifications.prerequisite_state_'.$entry->state) }} / {{ $entry->version }}</td>
<td>{{ __('inspection_notifications.prerequisite_action_'.$entry->latest_action) }}<small>{{ $entry->latest_actor_name }} / {{ $entry->latest_company_code }} — {{ $entry->{'latest_company_name_'.$lang} }}</small><bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($entry->latest_at)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></td></tr>
@empty<tr><td colspan="5">{{ __('inspection_notifications.prerequisite_register_empty') }}</td></tr>@endforelse
</tbody></table></div>{{ $prerequisites->links() }}</section>
</main></body></html>
