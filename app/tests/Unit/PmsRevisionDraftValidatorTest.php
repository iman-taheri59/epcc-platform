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
            'branch_rules' => [
                ['id' => 'branch-pad', 'reinforcement' => 'PAD', 'header_range' => '[250,350)',
                    'branch_range' => '[125,175)', 'value' => null],
                ['id' => 'branch-none', 'reinforcement' => 'NONE', 'header_range' => '[50,250)',
                    'branch_range' => '[15,50)', 'value' => null],
            ],
        ];
    }

    public function test_schedule_and_pad_material_leaves_can_change_without_changing_their_rules(): void
    {
        $before = $this->baseline();
        $draft = $before;
        $draft['rules'][0]['value']['schedule'] = '40';
        $draft['branch_rules'][0]['value'] = [
            'pad_material' => ['spec' => 'A106', 'grade' => 'A', 'psl' => null],
        ];

        (new PmsRevisionDraftValidator)->assertAllowedDelta($before, $draft);
        $this->assertSame('80', $before['rules'][0]['value']['schedule']);
        $this->assertSame('40', $draft['rules'][0]['value']['schedule']);
        $this->assertNull($before['branch_rules'][0]['value']);

        // Removing the explicit leaf restores the header-material default.
        $draft['branch_rules'][0]['value'] = null;
        (new PmsRevisionDraftValidator)->assertAllowedDelta($before, $draft);
    }

    public function test_identity_ranges_other_material_and_non_pad_branch_changes_are_rejected(): void
    {
        $before = $this->baseline();
        foreach (['class', 'dn_range', 'pipe_material', 'pad_range', 'branch_identity', 'non_pad_value', 'branch_added'] as $change) {
            $draft = $before;
            switch ($change) {
                case 'class': $draft['class']['id'] = 'another-class';
                    break;
                case 'dn_range': $draft['rules'][0]['dn_range'] = '[15,800)';
                    break;
                case 'pipe_material': $draft['rules'][1]['value']['grade'] = 'A333-6';
                    break;
                case 'pad_range': $draft['branch_rules'][0]['header_range'] = '[200,350)';
                    break;
                case 'branch_identity': $draft['branch_rules'][0]['id'] = 'invented-branch';
                    break;
                case 'non_pad_value': $draft['branch_rules'][1]['value'] = ['comment' => 'changed'];
                    break;
                case 'branch_added': $draft['branch_rules'][] = ['id' => 'invented-branch', 'reinforcement' => 'PAD'];
                    break;
            }
            $this->assertRejected($before, $draft, "{$change} must not be accepted as a narrow revision delta.");
        }
    }

    public function test_pad_material_delta_cannot_hitchhike_other_branch_value_changes(): void
    {
        $before = $this->baseline();
        $draft = $before;
        $draft['branch_rules'][0]['value'] = [
            'pad_material' => ['spec' => 'A106', 'grade' => 'A'],
            'unreviewed' => true,
        ];

        $this->assertRejected($before, $draft, 'Other branch-value data must stay unchanged.');

        $draft['branch_rules'][0]['value'] = ['pad_material' => 'A106 B'];
        $this->assertRejected($before, $draft, 'PAD material must be a material object.');

        $draft['branch_rules'][0]['value'] = ['pad_material' => ['spec' => '', 'grade' => 'B']];
        $this->assertRejected($before, $draft, 'PAD material specification must be non-empty.');

        $draft['branch_rules'][0]['value'] = [
            'pad_material' => ['spec' => 'CUSTOM', 'grade' => 'X', 's_mpa_override' => 20, 'p_no' => '1'],
        ];
        $this->assertRejected($before, $draft, 'A manual S override needs Section IX P-No and Group No.');

        $draft['branch_rules'][0]['value'] = [
            'pad_material' => ['spec' => 'A106', 'grade' => 'B', 'internal_note' => 'not editable'],
        ];
        $this->assertRejected($before, $draft, 'Only the six explicitly supported PAD material fields may change.');

        $draft['branch_rules'][0]['value'] = [
            'pad_material' => ['spec' => 'CUSTOM', 'grade' => 'X', 's_mpa_override' => 20, 'p_no' => '1', 'group_no' => 1.5],
        ];
        $this->assertRejected($before, $draft, 'A manual S override requires an integer Group No.');

        $draft['branch_rules'][0]['value'] = [
            'pad_material' => ['spec' => 'CUSTOM', 'grade' => 'X', 's_mpa_override' => 20, 'p_no' => '1', 'group_no' => 1],
        ];
        $draft['branch_rules'][0]['value']['pad_material']['s_mpa_override'] = 100_000_000;
        $this->assertRejected($before, $draft, 'An unbounded S override must be rejected.');
    }

    public function test_empty_oversized_and_whitespace_schedule_are_rejected(): void
    {
        $before = $this->baseline();
        foreach (['', str_repeat('X', 33), ' 40'] as $value) {
            $draft = $before;
            $draft['rules'][0]['value']['schedule'] = $value;
            $this->assertRejected($before, $draft, 'Invalid schedule must be rejected.');
        }
    }

    public function test_jsonb_equivalent_integer_and_float_metadata_remain_unchanged(): void
    {
        $before = $this->baseline();
        $before['class']['design_pressure_mpa'] = 3.0;
        $draft = $before;
        $draft['class']['design_pressure_mpa'] = 3;

        (new PmsRevisionDraftValidator)->assertAllowedDelta($before, $draft);

        $draft['class']['design_pressure_mpa'] = '3';
        $this->assertRejected($before, $draft, 'A JSON number must not be confused with a numeric string.');
    }

    private function assertRejected(array $before, array $draft, string $message): void
    {
        try {
            (new PmsRevisionDraftValidator)->assertAllowedDelta($before, $draft);
            $this->fail($message);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('specification', $exception->errors());
        }
    }
}
