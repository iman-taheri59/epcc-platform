@if(isset($acknowledgments) && $notice->state==='ISSUED')
<section id="inspection-notification-acknowledgments" style="margin:20px 0;overflow-wrap:anywhere">
<h2>{{ __('inspection_notifications.ack_title') }}</h2>
<p>{{ __('inspection_notifications.ack_scope') }}</p>
@if($acknowledgments->isEmpty())<p>{{ __('inspection_notifications.ack_none') }}</p>@else
<table class="grid" style="width:100%;border-collapse:collapse"><thead><tr>
@foreach(['event','company','actor','time'] as $column)<th style="text-align:start;border-bottom:1px solid #b8c4d5;padding:8px">{{ __('inspection_notifications.ack_'.$column) }}</th>@endforeach
</tr></thead><tbody>
@foreach($acknowledgments as $receipt)<tr data-ack-kind="{{ $receipt->event_kind }}">
<td style="padding:8px;vertical-align:top">{{ __('inspection_notifications.ack_kind_'.$receipt->event_kind) }}</td>
<td style="padding:8px;vertical-align:top"><bdi dir="ltr">{{ $receipt->company_code }}</bdi> — {{ $receipt->{'company_name_'.$lang} }}</td>
<td style="padding:8px;vertical-align:top"><bdi dir="auto">{{ $receipt->actor_name }}</bdi></td>
<td style="padding:8px;vertical-align:top"><bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($receipt->recorded_at)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></td>
</tr>@endforeach
</tbody></table>
@endif
</section>
@endif
