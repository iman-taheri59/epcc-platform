<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingLine;
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

    /** Resolve exactly one issued pipe schedule covering DN. Never use the live rule table. */
    public function pipeSchedule(PipingLine $line, int $dn): string
    {
        $found = [];
        foreach ($this->forLine($line)['rules'] as $rule) {
            if (($rule['rule_kind'] ?? null) !== PipingRuleKind::PIPE_SCHEDULE) {
                continue;
            }
            $range = $rule['dn_range'] ?? null;
            if (! is_string($range) || preg_match('/^\\[(\\d+),(\\d+)\\)$/', $range, $m) !== 1) {
                throw ValidationException::withMessages(['piping_class_id' => 'The issued PMS schedule range is invalid.']);
            }
            if ($dn >= (int) $m[1] && $dn < (int) $m[2]) {
                $found[] = $rule['value']['schedule'] ?? null;
            }
        }
        if (count($found) !== 1 || ! is_string($found[0]) || $found[0] === '') {
            throw ValidationException::withMessages([
                'piping_class_id' => "Exactly one issued PMS pipe schedule is required for DN{$dn}.",
            ]);
        }

        return $found[0];
    }
}
