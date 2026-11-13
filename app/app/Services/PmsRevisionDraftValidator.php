<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Narrow PMS revision delta: schedule leaves and PAD-material values only.
 * This is NOT the broader engineering validation for arbitrary class edits.
 */
final class PmsRevisionDraftValidator
{
    /**
     * Only PIPE_SCHEDULE values and `branch_rules[*].value.pad_material` on an
     * existing PAD rule may differ from the last approved snapshot. All class,
     * rule, range, identity and other value fields remain byte-for-byte
     * equivalent after canonical JSON object-key ordering.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $draft
     */
    public function assertAllowedDelta(array $previous, array $draft): void
    {
        if (! is_array($previous['rules'] ?? null)
            || ! is_array($draft['rules'] ?? null)
            || ! array_is_list($previous['rules'])
            || ! array_is_list($draft['rules'])
            || count($previous['rules']) !== count($draft['rules'])
            || ! is_array($previous['branch_rules'] ?? null)
            || ! is_array($draft['branch_rules'] ?? null)
            || ! array_is_list($previous['branch_rules'])
            || ! array_is_list($draft['branch_rules'])
            || count($previous['branch_rules']) !== count($draft['branch_rules'])) {
            $this->invalid();
        }

        $expected = $previous;

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
            $expected['rules'][$index]['value']['schedule'] = $newValue['schedule'];
        }

        foreach ($previous['branch_rules'] as $index => $rule) {
            $changed = $draft['branch_rules'][$index] ?? null;
            if (! is_array($rule) || ! is_array($changed)) {
                $this->invalid();
            }
            if (($rule['reinforcement'] ?? null) !== 'PAD') {
                continue;
            }

            $oldValue = $rule['value'] ?? null;
            $newValue = $changed['value'] ?? null;
            if (($oldValue !== null && ! is_array($oldValue))
                || ($newValue !== null && ! is_array($newValue))) {
                $this->invalid();
            }
            $oldMaterial = is_array($oldValue) ? ($oldValue['pad_material'] ?? null) : null;
            $newMaterial = is_array($newValue) ? ($newValue['pad_material'] ?? null) : null;
            if (($oldMaterial !== null && ! is_array($oldMaterial))
                || ($newMaterial !== null && ! is_array($newMaterial))) {
                $this->invalid();
            }

            // No material change: leave the entire branch value untouched.
            // This also rejects null-vs-object representation changes that do
            // not carry a material meaning, and avoids revalidating legacy
            // values already present in the approved baseline.
            if ($oldMaterial === $newMaterial) {
                continue;
            }
            if ($newMaterial !== null) {
                $this->assertPadMaterialShape($newMaterial);
            }

            $expectedValue = is_array($oldValue) ? $oldValue : [];
            if ($newMaterial === null) {
                unset($expectedValue['pad_material']);
            } else {
                $expectedValue['pad_material'] = $newMaterial;
            }
            // The persisted representation of the inherited/default material
            // is SQL NULL when no other branch-value metadata remains.
            $expected['branch_rules'][$index]['value'] = $expectedValue === [] ? null : $expectedValue;
        }

        // JSONB considers 3 and 3.0 equal. A PHP round-trip through json_encode
        // can normalize a float to an integer, so compare JSON numbers
        // semantically while keeping strings, booleans and null type-strict.
        if (! $this->sameJson($this->canonical($expected), $this->canonical($draft))) {
            $this->invalid();
        }
    }

    /** @param array<string, mixed> $material */
    private function assertPadMaterialShape(array $material): void
    {
        $allowed = ['spec', 'grade', 'psl', 's_mpa_override', 'p_no', 'group_no'];
        if (array_diff(array_keys($material), $allowed) !== []) {
            $this->invalid();
        }

        foreach (['spec', 'grade'] as $field) {
            if (! is_string($material[$field] ?? null)
                || trim($material[$field]) === ''
                || mb_strlen($material[$field]) > 80) {
                $this->invalid();
            }
        }

        $psl = $material['psl'] ?? null;
        if ($psl !== null && (! is_string($psl) || trim($psl) === '' || mb_strlen($psl) > 16)) {
            $this->invalid();
        }

        $pNo = $material['p_no'] ?? null;
        if ($pNo !== null && (! is_string($pNo) || mb_strlen($pNo) > 8
            || preg_match('/^[1-9]\\d*(?:[A-Z]{1,2})?$/', $pNo) !== 1)) {
            $this->invalid();
        }

        $groupNo = $material['group_no'] ?? null;
        if ($groupNo !== null && ! $this->validGroupNumber($groupNo)) {
            $this->invalid();
        }

        $stress = $material['s_mpa_override'] ?? null;
        if ($stress !== null
            && ((! is_int($stress) && ! is_float($stress))
                || ! is_finite((float) $stress)
                || (float) $stress <= 0.0
                || (float) $stress >= 100_000_000
                || $pNo === null
                || $groupNo === null)) {
            $this->invalid();
        }
    }

    private function validGroupNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 10_000;
        }

        return is_string($value)
            && preg_match('/^[1-9]\\d{0,4}$/', $value) === 1
            && (int) $value <= 10_000;
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

    private function sameJson(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float) $left === (float) $right;
        }

        if (! is_array($left) || ! is_array($right)) {
            return get_debug_type($left) === get_debug_type($right) && $left === $right;
        }
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! $this->sameJson($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'specification' => 'This revision only permits pipe-schedule and PAD-material changes from the previous approved PMS snapshot.',
        ]);
    }
}
