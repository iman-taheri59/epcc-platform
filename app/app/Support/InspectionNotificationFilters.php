<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Read-only register filters, independent of draft/issue payloads and source pickers. */
final class InspectionNotificationFilters
{
    public static function fromQuery(array $query): array
    {
        $input = array_intersect_key($query, array_flip(['notice_q', 'notice_state', 'activity_from', 'activity_to']));
        foreach ($input as &$value) {
            if (is_string($value)) {
                $value = trim($value);
            }
        }
        unset($value);
        $time = ['bail', 'nullable', 'string', 'max:35', 'regex:/^(?!0000)\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', 'date_format:Y-m-d\TH:i:sP'];
        $v = Validator::make($input, [
            'notice_q' => ['bail', 'nullable', 'string', 'max:200', 'regex:/\A[\s\S]*\z/u'],
            'notice_state' => ['bail', 'nullable', 'string', Rule::in(['DRAFT', 'ISSUED', 'CANCELLED'])],
            'activity_from' => $time, 'activity_to' => $time,
        ]);
        $v->after(function ($validator) use ($input): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $parsed = [];
            foreach (['activity_from', 'activity_to'] as $key) {
                if (($input[$key] ?? '') === '' || ($input[$key] ?? null) === null) {
                    continue;
                }
                $date = CarbonImmutable::parse($input[$key])->utc();
                if ($date->year < 1 || $date->year > 9999) {
                    $validator->errors()->add($key, __('inspection_notifications.filter_time_invalid'));
                }
                $parsed[$key] = $date;
            }
            if (isset($parsed['activity_from'], $parsed['activity_to']) && $parsed['activity_to']->lessThan($parsed['activity_from'])) {
                $validator->errors()->add('activity_to', __('inspection_notifications.filter_range_invalid'));
            }
        });
        $d = $v->validate();
        $result = [];
        foreach (['notice_q', 'notice_state', 'activity_from', 'activity_to'] as $key) {
            $result[$key] = $d[$key] ?? '';
        }
        foreach (['activity_from', 'activity_to'] as $key) {
            $result[$key.'_utc'] = $result[$key] !== '' ? CarbonImmutable::parse($result[$key])->utc()->format('Y-m-d H:i:sP') : null;
        }

        return $result;
    }
}
