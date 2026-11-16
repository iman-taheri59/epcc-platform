<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * Read-only lookup for the transcribed ASME B16.11-2021 two-ended elbows.
 *
 * This is deliberately separate from FittingDimensions, which is the B16.9
 * reference. B16.11 class designation, end configuration, angle, and NPS form
 * one exact key; there is no radius alias, interpolation, or class inference.
 */
final class ForgedFittingElbowDimensions
{
    private const SOURCE_PDF_SHA256 = '5fb9295df91fcb38978ec6a7eddabc53057c5465af63aac647f5a56bb4040664';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    /** @var array<string,array<string,mixed>> */
    private array $index = [];

    /** @var array<string,true> */
    private array $coverage = [];

    public function __construct(
        private readonly string $refDir,
        private readonly PipeReference $pipe,
    ) {}

    /**
     * Exact lookup only. SC is the application's end-type code for threaded.
     *
     * @return array{status:string,reason:?string,row:?array,source:array<string,mixed>}
     */
    public function lookup(?string $endType, ?int $classDesignation, ?string $nps, ?string $angle): array
    {
        $this->load();

        $endType = $endType === null ? null : strtoupper(trim($endType));
        $angle = $angle === null ? null : trim($angle);
        $configuration = match ($endType) {
            'SW' => 'SOCKET_WELD_BOTH_ENDS',
            'SC' => 'THREADED_BOTH_ENDS',
            default => null,
        };

        if ($endType === null || $endType === '') {
            return $this->unavailable('END_TYPE_REQUIRED');
        }
        if ($configuration === null) {
            return $this->unavailable('END_CONFIGURATION_NOT_COVERED');
        }
        if ($classDesignation === null) {
            return $this->unavailable('CLASS_REQUIRED');
        }
        if (! in_array($angle, ['45', '90'], true)) {
            return $this->unavailable($angle === null || $angle === '' ? 'ANGLE_REQUIRED' : 'ANGLE_NOT_COVERED');
        }
        if ($nps === null || trim($nps) === '') {
            return $this->unavailable('MISSING_NPS');
        }

        $size = $this->pipe->resolveLabel($nps);
        if ($size === null) {
            return $this->unavailable('SIZE_NOT_IN_PIPE_REFERENCE');
        }

        $key = self::key($configuration, $classDesignation, $size->nps);
        if (! isset($this->coverage[$key])) {
            return $this->unavailable('SIZE_CLASS_NOT_COVERED');
        }

        $row = $this->index[$key] ?? null;
        if (! is_array($row)) {
            throw new RuntimeException('B16.11 coverage declares a row that is missing from the dimension table.');
        }

        return [
            'status' => 'AVAILABLE',
            'reason' => null,
            'row' => $row,
            'source' => $this->source(),
        ];
    }

    /** Source provenance accompanies both available and unavailable results. */
    public function source(): array
    {
        $this->load();

        return array_intersect_key($this->pack, array_flip([
            'refId', 'version', 'source', 'standard', 'units', 'verified', 'readOn', 'coverage', 'notes',
        ]));
    }

    /** @return array{status:string,reason:string,row:null,source:array<string,mixed>} */
    private function unavailable(string $reason): array
    {
        return [
            'status' => 'UNAVAILABLE',
            'reason' => $reason,
            'row' => null,
            'source' => $this->source(),
        ];
    }

    private static function key(string $configuration, int $classDesignation, string $nps): string
    {
        return $configuration.'|'.$classDesignation.'|'.$nps;
    }

    private function load(): void
    {
        if ($this->pack !== null) {
            return;
        }

        $path = rtrim($this->refDir, '/').'/forged-fitting-elbow-dimensions.ref.json';
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('B16.11 forged-elbow dimension reference is missing or unreadable.');
        }

        $pack = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($pack)
            || ($pack['refId'] ?? null) !== 'forged-fitting-elbow-dimensions'
            || ! is_string($pack['version'] ?? null)
            || ! is_string($pack['source'] ?? null)
            || ($pack['units']['length'] ?? null) !== 'mm'
            || ($pack['units']['system'] ?? null) !== 'SI'
            || ($pack['standard']['code'] ?? null) !== 'B16.11'
            || ($pack['standard']['editionYear'] ?? null) !== 2021
            || ($pack['standard']['sourcePdfSha256'] ?? null) !== self::SOURCE_PDF_SHA256
            || ($pack['verified'] ?? null) !== true
            || ! is_string($pack['readOn'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $pack['readOn']) !== 1
            || ! is_array($pack['coverage'] ?? null)
            || ! array_is_list($pack['coverage'])
            || ! is_array($pack['dimensions'] ?? null)
            || ! array_is_list($pack['dimensions'])
            || count($pack['coverage']) !== 6
            || count($pack['dimensions']) !== 63
            || ($pack['standard']['sourceTables'] ?? null) !== ['Table 1.1.1-1', 'Table 6.1-1', 'Table 6.1-3']
            || ($pack['standard']['sourceFigures'] ?? null) !== ['Figure 6.2.7-1']) {
            throw new RuntimeException('Invalid B16.11 forged-elbow reference metadata.');
        }

        $coverage = [];
        foreach ($pack['coverage'] as $entry) {
            if (! is_array($entry)
                || ! in_array($entry['endConfiguration'] ?? null, ['SOCKET_WELD_BOTH_ENDS', 'THREADED_BOTH_ENDS'], true)
                || ! is_int($entry['classDesignation'] ?? null)
                || ! in_array($entry['classDesignation'], [2000, 3000, 6000, 9000], true)
                || ! is_array($entry['nps'] ?? null)
                || ! array_is_list($entry['nps'])
                || $entry['nps'] === []) {
                throw new RuntimeException('Invalid B16.11 elbow coverage declaration.');
            }

            $configuration = $entry['endConfiguration'];
            $classDesignation = $entry['classDesignation'];
            $allowedClasses = $configuration === 'SOCKET_WELD_BOTH_ENDS'
                ? [3000, 6000, 9000]
                : [2000, 3000, 6000];
            if (! in_array($classDesignation, $allowedClasses, true)) {
                throw new RuntimeException('B16.11 coverage declares a class outside the table scope for its end configuration.');
            }

            foreach ($entry['nps'] as $nps) {
                if (! is_string($nps) || ($size = $this->pipe->resolveLabel($nps)) === null || $size->nps !== $nps) {
                    throw new RuntimeException('B16.11 coverage contains an unknown or non-canonical NPS label.');
                }
                $key = self::key($configuration, $classDesignation, $nps);
                if (isset($coverage[$key])) {
                    throw new RuntimeException('Duplicate B16.11 elbow coverage key.');
                }
                $coverage[$key] = true;
            }
        }

        $index = [];
        foreach ($pack['dimensions'] as $row) {
            if (! is_array($row)
                || ! in_array($row['endConfiguration'] ?? null, ['SOCKET_WELD_BOTH_ENDS', 'THREADED_BOTH_ENDS'], true)
                || ! is_int($row['classDesignation'] ?? null)
                || ! is_string($row['nps'] ?? null)
                || ($size = $this->pipe->resolveLabel($row['nps'])) === null
                || $size->nps !== $row['nps']
                || ! is_array($row['bendDimensions'] ?? null)
                || ! is_string($row['sourceTable'] ?? null)) {
                throw new RuntimeException('Invalid B16.11 elbow dimension row.');
            }

            $configuration = $row['endConfiguration'];
            $classDesignation = $row['classDesignation'];
            $key = self::key($configuration, $classDesignation, $row['nps']);
            if (! isset($coverage[$key]) || isset($index[$key])) {
                throw new RuntimeException('B16.11 dimension row is outside coverage or duplicates another key.');
            }

            if ($configuration === 'SOCKET_WELD_BOTH_ENDS') {
                if (! in_array($classDesignation, [3000, 6000, 9000], true)
                    || $row['sourceTable'] !== 'Table 6.1-1'
                    || ! self::positiveNumber($row['socketDepthMinMm'] ?? null)
                    || array_key_exists('socketDepthMinMm', $row) !== true
                    || array_diff(array_keys($row['bendDimensions']), ['45', '90']) !== []
                    || array_diff(['45', '90'], array_keys($row['bendDimensions'])) !== []) {
                    throw new RuntimeException('Invalid B16.11 socket-welding elbow dimensions.');
                }
                self::assertDimension($row['bendDimensions']['45'], 'CENTRE_TO_BOTTOM_OF_SOCKET', 'A');
                self::assertDimension($row['bendDimensions']['90'], 'CENTRE_TO_BOTTOM_OF_SOCKET', 'A');
            } else {
                if (! in_array($classDesignation, [2000, 3000, 6000], true)
                    || $row['sourceTable'] !== 'Table 6.1-3'
                    || array_key_exists('socketDepthMinMm', $row)
                    || array_diff(array_keys($row['bendDimensions']), ['45', '90']) !== []
                    || array_diff(['45', '90'], array_keys($row['bendDimensions'])) !== []) {
                    throw new RuntimeException('Invalid B16.11 threaded elbow dimensions.');
                }
                self::assertDimension($row['bendDimensions']['45'], 'CENTRE_TO_END', 'C');
                self::assertDimension($row['bendDimensions']['90'], 'CENTRE_TO_END', 'A');
            }

            $index[$key] = $row;
        }

        if (count($index) !== count($coverage)
            || count($index) !== 63
            || array_diff_key($coverage, $index) !== []) {
            throw new RuntimeException('B16.11 dimension rows do not exactly match the declared coverage.');
        }

        // Publish only after the entire reference has passed all checks.
        $this->coverage = $coverage;
        $this->index = $index;
        $this->pack = $pack;
    }

    private static function assertDimension(mixed $dimension, string $kind, string $symbol): void
    {
        if (! is_array($dimension)
            || ($dimension['dimension'] ?? null) !== $kind
            || ($dimension['symbol'] ?? null) !== $symbol
            || ! self::positiveNumber($dimension['mm'] ?? null)
            || array_diff(array_keys($dimension), ['dimension', 'symbol', 'mm']) !== []) {
            throw new RuntimeException('Invalid B16.11 elbow dimension value or symbol.');
        }
    }

    private static function positiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }
}
