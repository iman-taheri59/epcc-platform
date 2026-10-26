@if($cancellation ?? null)
<section id="inspection-notification-cancellation" role="note" style="border:2px solid #a52a2a;background:#fff3f0;padding:16px;margin:16px 0;overflow-wrap:anywhere">
<h2>{{ __('inspection_notifications.cancelled') }} — CANCELLED</h2>
<p>{{ __('inspection_notifications.cancel_record_retained') }}</p>
<p>{{ __('inspection_notifications.cancel_actor') }}: {{ $cancellation->actor_name }} · <bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($cancellation->recorded_at)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></p>
<p>{{ __('inspection_notifications.cancel_reason') }}:</p><div dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $cancellation->reason }}</div>
</section>
@endif
