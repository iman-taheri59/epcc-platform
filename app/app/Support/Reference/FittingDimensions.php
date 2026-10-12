<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/** Read-only B16.9 lookup. Missing coverage is not physical invalidity.
 * No interpolation, radius formulas, pressure ratings or end-to-end inference.
 * Pack verification metadata is a source claim, not independent certification.
 */
final class FittingDimensions
{
    private ?array $pack = null;
    private array $index = [];

    public function __construct(private readonly string $refDir, private readonly PipeReference $pipe) {}

    /**
     * Standard scope is distinct from the loaded rows and from the pipe pack.
     * A custom NPS outside the standard requires project-spec definition, not
     * a fabricated universal reference size. This does not validate its OD/DN.
     */
    public function sizeScope(string $nps): string
    {
        $value = $this->npsValue($nps);
        if ($value === null) {
            return 'SIZE_INVALID';
        }

        return $value < 0.5 || $value > 48 ? 'PROJECT_SPEC_REQUIRED' : 'WITHIN_STANDARD_RANGE';
    }

    private function npsValue(string $nps): ?float
    {
        $nps = strtr(trim($nps), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '∕'=>'/', '٫'=>'.',
        ]);
        $nps = trim((string) preg_replace('/["”″]$/u', '', $nps));
        if (strlen($nps) > 64) { return null; }
        if (preg_match('/^\d+(?:\.\d+)?$/D', $nps)) {
            $value = (float) $nps;
        } elseif (preg_match('/^(?:(\d+)[ -]+)?(\d+)\/([1-9]\d*)$/D', $nps, $m)) {
            $value = (float) ($m[1] ?: 0) + (float) $m[2] / (float) $m[3];
        } else {
            return null;
        }

        return is_finite($value) && $value > 0 ? $value : null;
    }

    /**
     * A result always has status/reason/row/source; unavailable rows are null.
     * For caps, the caller supplies the applicable specified wall thickness in
     * mm, not a schedule label, corrosion allowance or an inferred measurement.
     * Available cap results include selection inputs and the applicable note.
     */
    public function lookup(string $fitting, ?string $qualifier, string $nps, ?float $capWallThicknessMm = null): array
    {
        $this->load();
        $scope = $this->sizeScope($nps);
        if ($scope !== 'WITHIN_STANDARD_RANGE') {
            return $this->unavailable($scope);
        }
        $fitting = strtoupper(trim($fitting));
        $qualifier = $qualifier === null ? null : strtoupper(trim($qualifier));
        $qualifier = match ($qualifier) {
            '90LR' => 'LONG_RADIUS_90',
            '45LR' => 'LONG_RADIUS_45',
            '' => null,
            default => $qualifier,
        };
        if (! in_array($fitting, ['ELBOW', 'CAP'], true)) {
            return $this->unavailable('FITTING_NOT_COVERED');
        }
        if ($fitting === 'ELBOW' && $qualifier === null) {
            return $this->unavailable('QUALIFIER_REQUIRED');
        }
        // The template's "45" says angle, not radius. Never silently assume LR.
        if ($fitting === 'ELBOW' && $qualifier === '45') {
            return $this->unavailable('RADIUS_UNSPECIFIED');
        }
        if (($fitting === 'CAP' && $qualifier !== null)
            || ($fitting === 'ELBOW' && ! in_array($qualifier, ['LONG_RADIUS_90', 'LONG_RADIUS_45'], true))) {
            return $this->unavailable('QUALIFIER_NOT_COVERED');
        }
        $size = $this->pipe->resolveLabel((string) $this->npsValue($nps));
        if ($size === null) {
            return $this->unavailable('REFERENCE_SIZE_NOT_LOADED');
        }
        $row = $this->index[$this->key($fitting, $qualifier, $size->dn)] ?? null;
        if ($row === null) {
            return $this->unavailable('REFERENCE_DATA_NOT_LOADED');
        }

        $selection = null;
        if ($fitting === 'CAP') {
            if ($row['requiresManufacturerAgreement']) {
                return $this->unavailable('CAP_MANUFACTURER_AGREEMENT_REQUIRED');
            }
            if ($capWallThicknessMm === null) {
                return $this->unavailable('CAP_THICKNESS_REQUIRED');
            }
            if (! $this->positiveNumber($capWallThicknessMm)) {
                return $this->unavailable('CAP_THICKNESS_INVALID');
            }
            // Do not round the input across the boundary. Equality belongs to E.
            // Work on a copy; selecting E1 must not mutate the memoised E row.
            $useE1 = $capWallThicknessMm > $row['limitingWallThicknessMm'];
            $selection = [
                'wallThicknessMm' => $capWallThicknessMm,
                'limitingWallThicknessMm' => $row['limitingWallThicknessMm'],
                'tabulatedEMm' => $row['mm'],
                'sourceNote' => $useE1 ? '(2)' : '(1)',
            ];
            if ($useE1) {
                $row['symbol'] = 'E1';
                $row['mm'] = $row['e1Mm'];
            }
        }

        return ['status' => 'AVAILABLE', 'reason' => null, 'row' => $row,
            'source' => $this->source(), 'selection' => $selection];
    }

    public function unavailable(string $reason): array
    {
        return ['status' => 'UNAVAILABLE', 'reason' => $reason, 'row' => null, 'source' => $this->source()];
    }

    /** Provenance accompanies both positive and negative lookup results. */
    public function source(): array
    {
        $this->load();

        return array_intersect_key($this->pack, array_flip([
            'refId', 'version', 'source', 'standard', 'units', 'verified', 'readOn', 'sizeRange',
        ]));
    }

    private function key(string $fitting, ?string $qualifier, int $dn): string
    {
        return $fitting.'|'.($qualifier ?? '').'|'.$dn;
    }

    private function load(): void
    {
        if ($this->pack !== null) {
            return;
        }
        $path = $this->refDir.'/fitting-dimensions.ref.json';
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Fitting dimension reference pack is missing or unreadable.');
        }
        $pack = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($pack)
            || ($pack['refId'] ?? null) !== 'fitting-dimensions'
            || ($pack['units']['length'] ?? null) !== 'mm'
            || ($pack['standard']['code'] ?? null) !== 'B16.9'
            || ($pack['sizeRange']['minNps'] ?? null) !== 0.5
            || ($pack['sizeRange']['maxNps'] ?? null) !== 48
            || ($pack['sizeRange']['outsideRangePolicy'] ?? null) !== 'PROJECT_SPEC_REQUIRED'
            || ! is_int($pack['standard']['editionYear'] ?? null)
            || ! is_array($pack['standard']['sourceTables'] ?? null)
            || ! is_bool($pack['verified'] ?? null)
            || ! is_string($pack['source'] ?? null)
            || ! is_string($pack['version'] ?? null)
            || ! is_string($pack['readOn'] ?? null)
            || ! is_array($pack['dimensions'] ?? null)
            || ! array_is_list($pack['dimensions']) || $pack['dimensions'] === []) {
            throw new RuntimeException('Invalid fitting dimension reference metadata.');
        }
        $index = [];
        foreach ($pack['dimensions'] as $row) {
            if (! is_array($row) || ! array_key_exists('qualifier', $row)
                || ! is_string($row['nps'] ?? null) || ! is_int($row['dn'] ?? null)) {
                throw new RuntimeException('Invalid fitting dimension row.');
            }
            $definition = match ([$row['fitting'] ?? null, $row['qualifier']]) {
                ['ELBOW', 'LONG_RADIUS_90'] => ['CENTRE_TO_END', 'A', 'Table 6.1-1'],
                ['ELBOW', 'LONG_RADIUS_45'] => ['CENTRE_TO_END', 'B', 'Table 6.1-1'],
                ['CAP', null] => ['LENGTH', 'E', 'Table 6.1-10'],
                default => null,
            };
            $size = $this->pipe->resolveLabel($row['nps']);
            if ($definition === null
                || [$row['dimension'] ?? null, $row['symbol'] ?? null, $row['sourceTable'] ?? null] !== $definition
                || ! in_array($row['sourceTable'], $pack['standard']['sourceTables'], true)
                || $size === null || $size->dn !== $row['dn']
                || $this->sizeScope($row['nps']) !== 'WITHIN_STANDARD_RANGE'
                || ! $this->positiveNumber($row['odMm'] ?? null)
                || abs($size->odMm - $row['odMm']) > 0.11
                || ! $this->positiveNumber($row['mm'] ?? null)) {
                throw new RuntimeException('Inconsistent fitting dimension row.');
            }
            if ($row['fitting'] === 'CAP') {
                if ($size->npsDecimal <= 24) {
                    if (($row['requiresManufacturerAgreement'] ?? null) !== false
                        || ! $this->positiveNumber($row['limitingWallThicknessMm'] ?? null)
                        || ! $this->positiveNumber($row['e1Mm'] ?? null)
                        || $row['e1Mm'] < $row['mm']) {
                        throw new RuntimeException('Incomplete or inconsistent cap applicability.');
                    }
                } elseif (($row['requiresManufacturerAgreement'] ?? null) !== true
                    || ! array_key_exists('limitingWallThicknessMm', $row)
                    || ! array_key_exists('e1Mm', $row)
                    || $row['limitingWallThicknessMm'] !== null || $row['e1Mm'] !== null) {
                    throw new RuntimeException('Large cap requires agreement, not invented threshold/E1.');
                }
            } elseif (array_key_exists('limitingWallThicknessMm', $row) || array_key_exists('e1Mm', $row)
                || array_key_exists('requiresManufacturerAgreement', $row)) {
                throw new RuntimeException('Cap applicability fields on a non-cap row.');
            }
            $key = $this->key($row['fitting'], $row['qualifier'], $row['dn']);
            if (isset($index[$key])) {
                throw new RuntimeException('Duplicate fitting dimension key.');
            }
            $index[$key] = $row;
        }
        // Publish only after the whole pack validates; a failure cannot cache partial data.
        $this->index = $index;
        $this->pack = $pack;
    }

    private function positiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }
}
