<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PmsRevisionDraftValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PmsRevisionDraftValidatorTest extends TestCase
{
    private function baseline(): array
    {
        return [
            'class' => ['id' => 'stable-class', 'dn_span' => '[15,600)'],
            'rules' => [
                ['id' => 'rule-1', 'rule_kind' => 'PIPE_SCHEDULE', 'dn_range' => '[15,600)',
                    'value' => ['schedule' => '80', 'source' => 'class']],
                ['id' => 'rule-2', 'rule_kind' => 'PIPE_MATERIAL', 'dn_range' => '[15,600)',
                    'value' => ['grade' => 'A106-B']],
            ],
            'branch_rules' => [['id' => 'branch-1', 'reinforcement' => 'NONE']],
        ];
    }

    public function test_only_schedule_leaves_can_change(): void
    {
        $before = $this->baseline();
        $draft = $before;
        $draft['rules'][0]['value']['schedule'] = '40';
        (new PmsRevisionDraftValidator())->assertScheduleOnly($before, $draft);
        (new PmsRevisionDraftValidator())->assertScheduleOnly($before, $before);
        $this->assertSame('80', $before['rules'][0]['value']['schedule']);
        $this->assertSame('40', $draft['rules'][0]['value']['schedule']);
    }

    public function test_identity_range_material_and_branch_changes_are_rejected(): void
    {
        $before = $this->baseline();
        foreach (['class', 'dn_range', 'material', 'branch', 'extra_value', 'type'] as $change) {
            $draft = $before;
            switch ($change) {
                case 'class': $draft['class']['id'] = 'another-class'; break;
                case 'dn_range': $draft['rules'][0]['dn_range'] = '[15,800)'; break;
                case 'material': $draft['rules'][1]['value']['grade'] = 'A333'; break;
                case 'branch': $draft['branch_rules'][0]['reinforcement'] = 'PAD'; break;
                case 'extra_value': $draft['rules'][0]['value']['unreviewed'] = true; break;
                case 'type': $draft['rules'][1]['value']['grade'] = 106; break;
            }
            try {
                (new PmsRevisionDraftValidator())->assertScheduleOnly($before, $draft);
                $this->fail("{$change} must not pass schedule-only validation.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('specification', $e->errors());
            }
        }
    }

    public function test_empty_oversized_and_whitespace_schedule_are_rejected(): void
    {
        $before = $this->baseline();
        foreach (['', str_repeat('X', 33), ' 40'] as $value) {
            $draft = $before;
            $draft['rules'][0]['value']['schedule'] = $value;
            try {
                (new PmsRevisionDraftValidator())->assertScheduleOnly($before, $draft);
                $this->fail('Invalid schedule must be rejected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('specification', $e->errors());
            }
        }
    }
}
