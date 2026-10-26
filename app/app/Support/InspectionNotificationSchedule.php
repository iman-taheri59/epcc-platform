<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Historical schedule validation only; no live metadata or time-zone substitution. */
final class InspectionNotificationSchedule
{
    public static function hasWindow(object $notice): bool
    {
        $keys = ['activity_start_input', 'activity_end_input', 'activity_start_at', 'activity_end_at'];
        $present = array_filter($keys, static fn ($key) => ($notice->$key ?? null) !== null);
        try {
            if ($present === []) {
                self::pair($notice->scheduled_input ?? null, $notice->scheduled_at ?? null);

                return false;
            }
            if (count($present) !== 4 || ($notice->scheduled_input ?? null) !== null || ($notice->scheduled_at ?? null) !== null) {
                self::invalid();
            }
            $start = self::pair($notice->activity_start_input, $notice->activity_start_at);
            $end = self::pair($notice->activity_end_input, $notice->activity_end_at);
            if (! $end->greaterThan($start)) {
                self::invalid();
            }

            return true;
        } catch (\Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            self::invalid();
        }
    }

    private static function pair(mixed $input, mixed $stored): CarbonImmutable
    {
        if (! is_string($input) || ! is_string($stored) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $input)) {
            self::invalid();
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $input);
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:sP') !== $input || ! $parsed->equalTo(CarbonImmutable::parse($stored))) {
            self::invalid();
        }

        return $parsed;
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['notification' => __('inspection_notifications.activity_window_invalid')]);
    }
}
