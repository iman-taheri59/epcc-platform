@if(($notice->activity_start_input ?? null) !== null)
@foreach(['start','end'] as $boundary)<div><strong>{{ __('inspection_notifications.activity_'.$boundary) }}:</strong> <span class="mono" style="display:inline-block;direction:ltr;unicode-bidi:isolate;overflow-wrap:anywhere">{{ $notice->{'activity_'.$boundary.'_input'} }}</span>
@if(!($originalOnly ?? false) && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})([+-]\d{2}:\d{2})$/D', $notice->{'activity_'.$boundary.'_input'}, $displayParts))<small style="display:block;direction:ltr;unicode-bidi:isolate">{{ $displayParts[1] }} · {{ $displayParts[2] }} · UTC{{ $displayParts[3] }}</small>@endif
</div>@endforeach
@else
<div><strong>{{ __('inspection_notifications.legacy_time') }}:</strong> <span class="mono" style="display:inline-block;direction:ltr;unicode-bidi:isolate;overflow-wrap:anywhere">{{ $notice->scheduled_input }}</span></div><small>{{ __('inspection_notifications.legacy_time_warning') }}</small>
@endif
