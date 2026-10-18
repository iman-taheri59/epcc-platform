<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * First revision delta: only pipe-schedule values may differ from the previous
 * approved class snapshot. Fail closed on header, range or branch changes.
 * This is NOT the broader engineering validation for arbitrary class edits.
 */
final class PmsRevisionDraftValidator
{
    /** @param array<string, mixed> $previous @param array<string, mixed> $draft */
    public function assertScheduleOnly(array $previous, array $draft): void
    {
        $expected = $previous;
        if (! isset($previous['rules'], $draft['rules'])
            || ! is_array($previous['rules']) || ! is_array($draft['rules'])
            || count($previous['rules']) !== count($draft['rules'])) {
            $this->invalid();
        }

        foreach ($previous['rules'] as $index => $rule) {
            $changed = $draft['rules'][$index] ?? null;
            if (! is_array($rule) || ! is_array($changed)) {
                $this->invalid();
            }
            if (($rule['rule_kind'] ?? null) !== 'PIPE_SCHEDULE') {
                continue;
            }
            $oldValue = $rule['value'] ?? null;
            $newValue = $changed['value'] ?? null;
            if (! is_array($oldValue) || ! is_array($newValue)
                || ! is_string($oldValue['schedule'] ?? null)
                || ! is_string($newValue['schedule'] ?? null)
                || $newValue['schedule'] !== trim($newValue['schedule'])
                || $newValue['schedule'] === '' || mb_strlen($newValue['schedule']) > 32) {
                $this->invalid();
            }
            // Copy precisely the one allowed leaf into a known-good baseline.
            // Full comparison below catches extra keys, reorders, fake rule IDs,
            // range/branch/header edits and changes to other material rules.
            $expected['rules'][$index]['value']['schedule'] = $newValue['schedule'];
        }

        if ($this->canonical($expected) !== $this->canonical($draft)) {
            $this->invalid();
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            $item = $this->canonical($item);
        }
        unset($item);

        return $value;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'specification' => 'This revision only permits pipe-schedule changes from the previous approved PMS snapshot.',
        ]);
    }
}
