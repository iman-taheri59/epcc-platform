<?php

declare(strict_types=1);

namespace App\Support\Units;

/**
 * The quantity kinds the platform converts (ADR-014).
 *
 * A closed list rather than free strings, because the unit a field accepts is
 * a property of the field: design pressure takes pressure units and nothing
 * else. Letting a caller pass "PRESURE" and get an empty unit list back is how
 * a typo becomes a silently unvalidated field.
 */
final class Quantity
{
    public const PRESSURE = 'PRESSURE';
    public const TEMPERATURE = 'TEMPERATURE';
    public const LENGTH = 'LENGTH';
    public const MASS = 'MASS';
    public const FLOW = 'FLOW';
    public const WEIGHT_PER_LENGTH = 'WEIGHT_PER_LENGTH';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PRESSURE,
            self::TEMPERATURE,
            self::LENGTH,
            self::MASS,
            self::FLOW,
            self::WEIGHT_PER_LENGTH,
        ];
    }

    public static function isKnown(string $quantity): bool
    {
        return in_array($quantity, self::all(), true);
    }

    /**
     * Which quantity a given line/equipment field is measured in.
     *
     * Kept here rather than scattered through the forms so that adding a
     * pressure field cannot accidentally offer temperature units.
     *
     * @return array<string, string>  column name => quantity
     */
    public static function fieldMap(): array
    {
        return [
            'design_pressure' => self::PRESSURE,
            'operating_pressure' => self::PRESSURE,
            'test_pressure' => self::PRESSURE,
            'design_temperature' => self::TEMPERATURE,
            'operating_temperature' => self::TEMPERATURE,
            'insulation_thickness_mm' => self::LENGTH,
        ];
    }

    public static function forField(string $field): ?string
    {
        return self::fieldMap()[$field] ?? null;
    }
}
