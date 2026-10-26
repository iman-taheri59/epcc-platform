<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/** Compare instants only after both strict inputs are strings and valid dates. */
final class InspectionActivityEndAfterStart implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $start = $this->data['notification']['activity_start_at'] ?? null;
        if (! is_string($start) || ! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)) {
            return;
        }
        try {
            $a = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $start);
            $b = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
            // The date_format rules report malformed dates; don't let comparison
            // parse malformed/nested peer input or normalize it into a valid day.
            if ($a === false || $b === false || $a->format('Y-m-d\TH:i:sP') !== $start || $b->format('Y-m-d\TH:i:sP') !== $value) {
                return;
            }
            if ($b <= $a) {
                $fail('inspection_notifications.activity_end_after')->translate();
            }
        } catch (\Exception) {
            // Existing required/string/date_format rules report this input.
        }
    }
}
