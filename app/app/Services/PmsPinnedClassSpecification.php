<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingLine;
use DateTimeImmutable;
use Throwable;
use App\Support\Piping\PipingRuleKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read engineering rules from a line's ISSUE-TIME PMS pin, not live class rows. */
final class PmsPinnedClassSpecification
{
    /** @return array<string, mixed> */
    public function forLine(PipingLine $line): array
    {
        if ($line->pms_class_snapshot_id === null || $line->piping_class_id === null) {
            throw ValidationException::withMessages([
                'piping_class_id' => 'This historical line needs explicit approved PMS classification.',
            ]);
        }
        $raw = DB::table('pms_class_revision_snapshots')
            ->where('id', $line->pms_class_snapshot_id)
            ->where('project_id', $line->project_id)
            ->where('piping_class_id', $line->piping_class_id)
            ->value('specification');
        if ($raw === null) {
            throw ValidationException::withMessages([
                'piping_class_id' => 'The issued PMS class snapshot is unavailable for this line.',
            ]);
        }
        $spec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($spec) || ($spec['class']['id'] ?? null) !== $line->piping_class_id
            || ! is_array($spec['rules'] ?? null) || ! is_array($spec['branch_rules'] ?? null)) {
            throw ValidationException::withMessages([
                'piping_class_id' => 'The issued PMS class snapshot has an invalid structure.',
            ]);
        }

        return $spec;
    }

    /** Resolve a typed rule value from the issued specification, never from live rows. */
    public function ruleValue(PipingLine $line, string $kind, int $dn): array
    {
        $found = [];
        foreach ($this->forLine($line)['rules'] as $rule) {
            if (($rule['rule_kind'] ?? null) !== $kind) {
                continue;
            }
            if ($this->inRange($rule['dn_range'] ?? null, $dn)) {
                $found[] = $rule['value'] ?? null;
            }
        }
        if (count($found) !== 1 || ! is_array($found[0])) {
            throw ValidationException::withMessages([
                'piping_class_id' => "Exactly one issued {$kind} rule is required for DN{$dn}.",
            ]);
        }

        return $found[0];
    }

    /** Resolve exactly one issued pipe schedule covering DN. Never use the live rule table. */
    public function pipeSchedule(PipingLine $line, int $dn): string
    {
        $schedule = $this->ruleValue($line, PipingRuleKind::PIPE_SCHEDULE, $dn)['schedule'] ?? null;
        if (! is_string($schedule) || $schedule === '') {
            throw ValidationException::withMessages([
                'piping_class_id' => "The issued PMS pipe schedule is missing for DN{$dn}.",
            ]);
        }

        return $schedule;
    }

    /** @return array<string, mixed> Issue-time branch cell, including P2P kind/pad. */
    public function branchRule(PipingLine $line, int $headerDn, int $branchDn): array
    {
        return $this->branchRuleFromSpecification($this->forLine($line), $headerDn, $branchDn);
    }

    /**
     * Same footprint specificity/priority/created-at precedence as the class
     * authoring resolver, but reads ONLY the immutable issue-time snapshot.
     * Kept separate so the resolution contract can be tested without a DB.
     *
     * @param array<string, mixed> $specification
     * @return array<string, mixed>
     */
    public function branchRuleFromSpecification(array $specification, int $headerDn, int $branchDn): array
    {
        if ($branchDn > $headerDn || $headerDn <= 0 || $branchDn <= 0
            || ! is_array($specification['branch_rules'] ?? null)) {
            throw ValidationException::withMessages(['piping_class_id' => 'Invalid issued PMS branch query.']);
        }
        $matches = [];
        foreach ($specification['branch_rules'] as $rule) {
            if (! is_array($rule) || ! is_int($rule['priority'] ?? null)
                || ! is_string($rule['created_at'] ?? null)) {
                throw ValidationException::withMessages(['piping_class_id' => 'Invalid issued PMS branch rule.']);
            }
            $header = $this->range($rule['header_range'] ?? null);
            $branch = $this->range($rule['branch_range'] ?? null);
            if ($headerDn >= $header[0] && $headerDn < $header[1]
                && $branchDn >= $branch[0] && $branchDn < $branch[1]) {
                try {
                    $at = new DateTimeImmutable($rule['created_at']);
                } catch (Throwable) {
                    throw ValidationException::withMessages(['piping_class_id' => 'Invalid issued PMS branch timestamp.']);
                }
                $matches[] = [
                    'rule' => $rule,
                    'width' => $header[1] - $header[0] + $branch[1] - $branch[0],
                    'priority' => $rule['priority'],
                    'at' => $at,
                ];
            }
        }
        if ($matches === []) {
            throw ValidationException::withMessages([
                'piping_class_id' => "No issued PMS branch rule covers DN{$headerDn} x DN{$branchDn}.",
            ]);
        }
        usort($matches, static fn (array $a, array $b): int =>
            ($a['width'] <=> $b['width']) ?: ($b['priority'] <=> $a['priority'])
                ?: ($a['at'] <=> $b['at']));
        if (isset($matches[1]) && $matches[0]['width'] === $matches[1]['width']
            && $matches[0]['priority'] === $matches[1]['priority']
            && $matches[0]['at'] == $matches[1]['at']) {
            throw ValidationException::withMessages(['piping_class_id' => 'Ambiguous issued PMS branch rule.']);
        }

        return $matches[0]['rule'];
    }

    private function inRange(mixed $range, int $dn): bool
    {
        [$lo, $hi] = $this->range($range);

        return $dn >= $lo && $dn < $hi;
    }

    /** @return array{0: int, 1: int} PostgreSQL int4range canonical [lo,hi). */
    private function range(mixed $value): array
    {
        if (! is_string($value) || preg_match('/^\[(\d+),(\d+)\)$/', $value, $m) !== 1
            || (int) $m[1] >= (int) $m[2]) {
            throw ValidationException::withMessages(['piping_class_id' => 'Invalid issued PMS DN range.']);
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
