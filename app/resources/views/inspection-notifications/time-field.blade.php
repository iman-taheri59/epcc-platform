<fieldset data-notification-time data-boundary="{{ $boundary }}" @if($timeOptional ?? false) data-time-optional @endif style="min-width:0;margin:16px 0;padding:16px;border:1px solid #b8c4d5;border-radius:8px">
<legend>{{ $timeLabel ?? __('inspection_notifications.activity_'.$boundary) }}</legend>
<div data-time-helper hidden>
<p>{{ __('inspection_notifications.time_helper_hint') }} <bdi dir="ltr">+00:00</bdi></p>
<div style="display:flex;flex-wrap:wrap;gap:14px;align-items:end">
<label for="notification-{{ $boundary }}-date">{{ __('inspection_notifications.time_date') }} <input id="notification-{{ $boundary }}-date" data-time-date type="date" dir="ltr" min="0001-01-01" max="9999-12-31"></label>
<label for="notification-{{ $boundary }}-clock">{{ __('inspection_notifications.time_clock') }} <input id="notification-{{ $boundary }}-clock" data-time-clock type="time" step="1" dir="ltr"></label>
<label for="notification-{{ $boundary }}-offset">{{ __('inspection_notifications.time_offset') }} <input id="notification-{{ $boundary }}-offset" data-time-offset type="text" dir="ltr" placeholder="+03:30" size="8" maxlength="6" spellcheck="false" autocomplete="off"></label>
</div>
</div>
<label data-time-mode hidden><input data-time-manual type="checkbox"> {{ __('inspection_notifications.time_manual') }}</label>
<label for="notification-{{ $boundary }}-exact">{{ __('inspection_notifications.time_exact') }} <input id="notification-{{ $boundary }}-exact" data-time-exact @required(!($timeOptional ?? false)) dir="ltr" name="{{ $timeName ?? 'notification[activity_'.$boundary.'_at]' }}" placeholder="2026-10-25T09:30:00+03:30" size="36" maxlength="35" spellcheck="false" value="{{ $timeValue }}"></label>
<output data-time-preview aria-live="polite" style="display:block;overflow-wrap:anywhere"></output>
<noscript><p>{{ __(($timeOptional ?? false) ? 'inspection_notifications.filter_time_noscript' : 'inspection_notifications.time_noscript') }}</p></noscript>
</fieldset>
