<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PmsPinnedClassSpecification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PmsPinnedBranchResolutionTest extends TestCase
{
    private function cell(string $id, string $header, string $branch, int $priority, string $created): array
    {
        return [
            'id' => $id, 'header_range' => $header, 'branch_range' => $branch,
            'priority' => $priority, 'created_at' => $created,
            'component_type' => 'P2P', 'p2p_kind' => 'STUB_ON',
            'reinforcement' => 'PAD', 'pad_thickness' => '6.000',
        ];
    }

    public function test_specificity_then_priority_then_creation_time_select_the_issued_cell(): void
    {
        $base = $this->cell('general', '[15,600)', '[15,200)', 0, '2026-10-01T12:00:00+00:00');
        $narrow = $this->cell('narrow', '[100,300)', '[15,100)', 0, '2026-10-01T12:00:00+00:00');
        $priority = $this->cell('priority', '[100,300)', '[15,100)', 2, '2026-10-02T12:00:00+00:00');
        $early = $this->cell('early', '[100,300)', '[15,100)', 2, '2026-10-01T12:00:00+00:00');
        $resolver = new PmsPinnedClassSpecification();
        $spec = ['branch_rules' => [$base, $narrow, $priority, $early]];
        $this->assertSame('early', $resolver->branchRuleFromSpecification($spec, 150, 25)['id']);
        $this->assertSame('general', $resolver->branchRuleFromSpecification($spec, 500, 150)['id']);
    }

    public function test_ambiguous_or_uncovered_issued_cells_fail_closed(): void
    {
        $cell = $this->cell('first', '[100,300)', '[15,100)', 0, '2026-10-01T12:00:00+00:00');
        $other = array_replace($cell, ['id' => 'second']);
        $resolver = new PmsPinnedClassSpecification();
        foreach ([
            [['branch_rules' => [$cell, $other]], 150, 25],
            [['branch_rules' => [$cell]], 400, 25],
            [['branch_rules' => [$cell]], 25, 100],
        ] as [$spec, $header, $branch]) {
            try {
                $resolver->branchRuleFromSpecification($spec, $header, $branch);
                $this->fail('An ambiguous, missing or reversed branch must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('piping_class_id', $e->errors());
            }
        }
    }
}
