@if($draftHistory !== null)
<section id="inspection-notification-draft-history" style="margin:24px 0;border-top:1px solid #dbe2ed;padding-top:16px">
<h2>{{ __('inspection_notifications.draft_history') }} ({{ $draftHistory->total() }})</h2>
<p>{{ __('inspection_notifications.draft_history_scope') }}</p><p>{{ __('inspection_notifications.draft_history_coverage') }}</p>
@if($historyFromVersion !== null)<p>{{ __('inspection_notifications.draft_history_from') }}: {{ $historyFromVersion }}</p>@endif
@php
$historyFields = [
 'work_reference'=>'work', 'location'=>'location', 'activity_start_input'=>'activity_start', 'activity_end_input'=>'activity_end',
 'activity_start_at'=>'draft_history_start_utc', 'activity_end_at'=>'draft_history_end_utc', 'scheduled_input'=>'legacy_time', 'scheduled_at'=>'draft_history_legacy_utc',
 'recipient_company_ids'=>'recipients', 'purchase_order_id'=>'po', 'notes_en'=>'en', 'notes_fa'=>'fa',
];
$historyFormat = static function($value) {
 if ($value === null) { return __('inspection_notifications.draft_history_null'); }
 if ($value === '') { return __('inspection_notifications.draft_history_blank'); }
 return is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
};
@endphp
@forelse($draftHistory as $entry)
@php
$before = json_decode($entry->before_snapshot, true, 512, JSON_THROW_ON_ERROR);
$after = json_decode($entry->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
$changed = array_filter(array_keys($historyFields), static fn($field)=>($before[$field]??null)!==($after[$field]??null));
@endphp
<details class="draft-history-entry" style="border:1px solid #dbe2ed;border-radius:6px;padding:12px;margin:12px 0">
<summary><span dir="ltr">v{{ $entry->before_version }} → v{{ $entry->after_version }} · {{ $entry->recorded_at }}</span> · {{ $entry->actor_name }}</summary>
<p>{{ __('inspection_notifications.draft_history_actor') }}: {{ $entry->actor_name }} · <code>{{ $entry->actor_id }}</code></p>
<p>{{ __('inspection_notifications.draft_history_setting') }}: <code>{{ $entry->edit_setting_id }}</code></p>
@if($changed === [])<p>{{ __('inspection_notifications.draft_history_unchanged') }}</p>@else
<div style="overflow-x:auto"><table style="width:100%;table-layout:fixed;border-collapse:collapse"><thead><tr><th>{{ __('inspection_notifications.draft_history_field') }}</th><th>{{ __('inspection_notifications.draft_history_before') }}</th><th>{{ __('inspection_notifications.draft_history_after') }}</th></tr></thead><tbody>
@foreach($changed as $field)<tr><th style="text-align:start;overflow-wrap:anywhere">{{ __('inspection_notifications.'.$historyFields[$field]) }}</th><td style="white-space:pre-wrap;overflow-wrap:anywhere;vertical-align:top">{{ $historyFormat($before[$field]??null) }}</td><td style="white-space:pre-wrap;overflow-wrap:anywhere;vertical-align:top">{{ $historyFormat($after[$field]??null) }}</td></tr>@endforeach
</tbody></table></div>
@endif
<details><summary>{{ __('inspection_notifications.draft_history_raw') }}</summary>
<h3>{{ __('inspection_notifications.draft_history_before') }}</h3><pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($before, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) }}</pre>
<h3>{{ __('inspection_notifications.draft_history_after') }}</h3><pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($after, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) }}</pre>
</details></details>
@empty<p>{{ __('inspection_notifications.draft_history_empty') }}</p>@endforelse
{{ $draftHistory->links() }}
</section>
@endif
