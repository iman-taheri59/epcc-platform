@if($notice->state==='ISSUED')
<section id="inspection-notification-prerequisites" style="border:1px solid #b8c4d5;padding:16px;margin:20px 0;overflow-wrap:anywhere">
<h2>{{ __('inspection_notifications.prerequisite_title') }}</h2><p>{{ __('inspection_notifications.prerequisite_scope') }}</p>
@include('inspection-notifications.prerequisite-stage')
@forelse($prerequisites as $entry)
<p><a href="{{ route('inspection-notifications.prerequisites.show',['projectCode'=>$project->code,'notificationId'=>$notice->id,'prerequisiteId'=>$entry->id,'lang'=>$lang]) }}">{{ $entry->item_reference }}</a> — {{ $entry->{'company_name_'.$lang} }} — {{ __('inspection_notifications.prerequisite_state_'.$entry->state) }} / {{ $entry->version }}</p>
@empty<p>{{ __('inspection_notifications.prerequisite_empty') }}</p>@endforelse
{{ $prerequisites->links() }}
@if($canCreatePrerequisite)
<form class="actions" id="inspection-prerequisite-create" method="post" action="{{ route('inspection-notifications.prerequisites.store',['projectCode'=>$project->code,'notificationId'=>$notice->id,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="prerequisite_create_target" value="{{ $notice->id }}">
@php $createRestore=fn($key)=>old('prerequisite_create_target')===$notice->id && is_string(old('prerequisite.'.$key)) ? old('prerequisite.'.$key) : ''; @endphp
<label>{{ __('inspection_notifications.prerequisite_item') }}<textarea name="prerequisite[item_reference]" required maxlength="1000" rows="2" style="display:block;width:100%;box-sizing:border-box">{{ $createRestore('item_reference') }}</textarea></label>
<label>{{ __('inspection_notifications.prerequisite_requirement') }}<textarea name="prerequisite[requirement]" required maxlength="4000" rows="4" style="display:block;width:100%;box-sizing:border-box">{{ $createRestore('requirement') }}</textarea></label>
<p>{{ __('inspection_notifications.prerequisite_disclosure') }}</p><button>{{ __('inspection_notifications.prerequisite_create') }}</button>
</form>
@endif
</section>
@endif
