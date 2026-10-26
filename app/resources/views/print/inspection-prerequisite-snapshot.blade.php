<p>{{ __('inspection_notifications.prerequisite_state_'.$record['state']) }} / {{ __('inspection_notifications.version') }} {{ $record['version'] }}</p>
<h4>{{ __('inspection_notifications.prerequisite_item') }}</h4><div class="text-block">{{ $record['item_reference'] }}</div>
<h4>{{ __('inspection_notifications.prerequisite_requirement') }}</h4><div class="text-block">{{ $record['requirement'] }}</div>
<p>{{ __('inspection_notifications.prerequisite_action_'.$record['latest_action']) }} — {{ $record['latest_actor_name'] }} / {{ $record['latest_company_code'] }} — {{ $record['latest_company_name_'.$lang] }} · <bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($record['latest_at'])->utc()->format('Y-m-d\TH:i:s.uP') }}</bdi></p>
@if($record['latest_note']!==null)<div class="text-block">{{ $record['latest_note'] }}</div>@endif
