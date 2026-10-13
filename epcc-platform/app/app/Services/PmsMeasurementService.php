<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\Project;
use App\Models\User;
use App\Support\Units\Exceptions\UnknownUnitException;
use App\Support\Units\Quantity;
use App\Support\Units\UnitRegistry;
use Illuminate\Validation\ValidationException;

/** Source pairs, canonical mirrors and explicit legacy retention. ADR-014 §14. */
final class PmsMeasurementService
{
    public const FIELDS = [
        'design_temp_min' => ['quantity' => Quantity::TEMPERATURE, 'canonical' => 'design_temp_min_c', 'unit' => 'C', 'min' => -273.15, 'max' => 9999.9],
        'design_temp_max' => ['quantity' => Quantity::TEMPERATURE, 'canonical' => 'design_temp_max_c', 'unit' => 'C', 'min' => -273.15, 'max' => 9999.9],
        'corrosion_allowance' => ['quantity' => Quantity::LENGTH, 'canonical' => 'corrosion_allowance_mm', 'unit' => 'mm', 'min' => 0, 'max' => 999.99],
    ];

    public function __construct(private UnitRegistry $units, private UnitPreferenceService $preferences) {}

    /** Called only after author, ownership and optimistic-version checks. */
    public function apply(PipingClass $class, array $input): void
    {
        $canonical = [];
        foreach (self::FIELDS as $field => $definition) {
            $valueKey = $field.'_value';
            $unitKey = $field.'_unit';
            $modeKey = $field.'_mode';
            $mirror = $definition['canonical'];
            $modern = array_key_exists($valueKey, $input) || array_key_exists($unitKey, $input) || array_key_exists($modeKey, $input);
            if ($modern && array_key_exists($mirror, $input)) {
                $this->fail($field, 'units_mixed_input');
            }
            if (! $modern && ! array_key_exists($mirror, $input)) {
                $canonical[$field] = $class->{$mirror};

                continue;
            }
            $mode = $modern ? ($input[$modeKey] ?? 'set') : 'set';
            if (! is_string($mode) || ! in_array($mode, ['keep', 'set', 'clear'], true)) {
                $this->fail($field, 'units_bad_mode', 'mode');
            }
            if ($mode === 'keep') {
                $canonical[$field] = $class->{$mirror};

                continue;
            }
            if ($modern && $mode === 'set' && ! array_key_exists($valueKey, $input)) {
                $this->fail($field, 'units_value_required');
            }
            $value = $modern ? ($input[$valueKey] ?? null) : $input[$mirror];
            $unit = $modern ? ($input[$unitKey] ?? null) : $definition['unit'];
            if ($mode === 'clear' || $value === null || $value === '') {
                $class->{$valueKey} = null;
                $class->{$unitKey} = null;
                $class->{$mirror} = null;
                $canonical[$field] = null;

                continue;
            }
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $this->fail($field, 'units_decimal');
            }
            $value = trim((string) $value);
            if (! preg_match('/\A[+-]?(?:[0-9]{1,10}(?:\.[0-9]{1,6})?|\.[0-9]{1,6})\z/D', $value)) {
                $this->fail($field, 'units_decimal');
            }
            if (! is_string($unit) || trim($unit) === '') {
                $this->fail($field, 'units_required', 'unit');
            }
            try {
                $resolved = $this->units->resolve($unit);
            } catch (UnknownUnitException $e) {
                $this->fail($field, 'units_unknown', 'unit');
            }
            if ($resolved['quantity'] !== $definition['quantity']) {
                $this->fail($field, 'units_wrong_quantity', 'unit');
            }
            if (! $this->units->isSelectable($resolved['code'])) {
                $this->fail($field, 'units_not_selectable', 'unit');
            }
            try {
                $measurement = $this->units->measure((float) $value, $resolved['code']);
            } catch (UnknownUnitException $e) {
                $this->fail($field, 'units_bounds');
            }
            $converted = $measurement->canonical;
            if ($definition['quantity'] === Quantity::TEMPERATURE && abs($converted + 273.15) < 1e-9) {
                $converted = -273.15;
            }
            if (! is_finite($converted) || $converted < $definition['min'] || $converted > $definition['max']) {
                $this->fail($field, 'units_bounds');
            }
            $class->{$valueKey} = $value;
            $class->{$unitKey} = $resolved['code'];
            $class->{$mirror} = round($converted, 6);
            $canonical[$field] = $converted;
        }
        if ($canonical['design_temp_min'] !== null && $canonical['design_temp_max'] !== null
            && $canonical['design_temp_min'] > $canonical['design_temp_max']) {
            $this->fail('design_temp_max', 'temperature_order');
        }
    }

    /** Read-only; display preferences never become writes to the source pair. */
    public function presentation(Project $project, User $actor, ?PipingClass $class, string $lang): array
    {
        $fields = [];
        foreach (self::FIELDS as $field => $definition) {
            $options = $this->units->selectableUnitsFor($definition['quantity']);
            $preferred = $this->preferences->displayUnit($definition['quantity'], $project, $actor);
            if (! in_array($preferred, array_column($options, 'code'), true)) {
                $preferred = $this->units->defaultDisplayUnit($definition['quantity']);
            }
            $value = $class?->{$field.'_value'};
            $unit = $class?->{$field.'_unit'};
            $canonical = $class?->{$definition['canonical']};
            $display = null;
            if ($canonical !== null) {
                try {
                    $display = $value !== null && $unit !== null
                        ? $this->units->convert((float) $value, $unit, $preferred)
                        : $this->units->fromCanonical((float) $canonical, $preferred);
                } catch (\RuntimeException $e) {
                    // Retain readable source evidence if an old unit cannot currently be resolved.
                    $display = null;
                }
            }
            $selected = $unit ?? ($canonical !== null ? $definition['unit'] : $preferred);
            $fields[$field] = ['options' => array_map(fn (array $u): array => ['code' => $u['code'], 'label' => $this->units->label($u['code'], $lang)], $options),
                'value' => $value, 'unit' => $unit, 'canonical' => $canonical, 'canonical_unit' => $definition['unit'],
                'input_value' => $value ?? ($canonical !== null ? $this->format((float) $canonical) : ''),
                'input_unit' => $selected, 'mode' => $class ? 'keep' : 'set',
                'preferred_unit' => $preferred, 'preferred_value' => $display !== null ? $this->format($display) : null,
                'legacy' => $canonical !== null && $value === null];
        }

        return $fields;
    }

    private function format(float $number): string
    {
        return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }

    private function fail(string $field, string $message, string $part = 'value'): never
    {
        throw ValidationException::withMessages(['header.'.$field.'_'.$part => __('pms.'.$message)]);
    }
}
