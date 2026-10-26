<form id="inspection-notification-filters" method="get" action="{{ route('inspection-notifications.index',['projectCode'=>$project->code]) }}">
<h3>{{ __('inspection_notifications.filter_title') }}</h3>
@foreach($filterContext as $key=>$contextValue)@if($key!=='projectCode')<input type="hidden" name="{{ $key }}" value="{{ $contextValue }}">@endif@endforeach
<div class="fields">
<label>{{ __('inspection_notifications.filter_q') }} <input type="search" name="notice_q" maxlength="200" value="{{ $filters['notice_q'] }}"></label>
<label>{{ __('inspection_notifications.filter_state') }} <select name="notice_state"><option value="">{{ __('inspection_notifications.filter_all') }}</option>@foreach(['DRAFT','ISSUED','CANCELLED'] as $filterState)<option value="{{ $filterState }}" @selected($filters['notice_state']===$filterState)>{{ $filterState }}</option>@endforeach</select></label>
</div>
@foreach(['from','to'] as $filterBoundary)
@include('inspection-notifications.time-field', ['boundary'=>'filter-'.$filterBoundary, 'timeName'=>'activity_'.$filterBoundary, 'timeLabel'=>__('inspection_notifications.filter_'.$filterBoundary), 'timeOptional'=>true, 'timeValue'=>$filters['activity_'.$filterBoundary]])
@endforeach
<div>
</div><p>{{ __('inspection_notifications.filter_hint') }}</p>
<button>{{ __('inspection_notifications.filter_apply') }}</button> <a id="inspection-notification-clear-filters" href="{{ route('inspection-notifications.index',$filterContext) }}">{{ __('inspection_notifications.filter_clear') }}</a>
</form>
<p id="inspection-notification-counts">{{ __('inspection_notifications.filter_counts') }}: <bdi dir="ltr">{{ $notifications->total() }} / {{ $noticeVisibleTotal }}</bdi></p>
