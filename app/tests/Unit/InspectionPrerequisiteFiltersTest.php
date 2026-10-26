<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InspectionPrerequisiteFilters;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InspectionPrerequisiteFiltersTest extends TestCase
{
    public function test_empty_trimmed_zero_uppercase_company_and_unrelated_params_are_normalized(): void
    {
        $this->assertSame(['prerequisite_state' => '', 'prerequisite_company' => '', 'prerequisite_notice' => '', 'prerequisite_company_q' => '', 'prerequisite_notice_state' => ''], InspectionPrerequisiteFilters::fromQuery([]));
        $f = InspectionPrerequisiteFilters::fromQuery(['prerequisite_state' => ' OPEN ', 'prerequisite_company' => ' AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA ', 'prerequisite_notice' => ' 0 ', 'stage' => ['unrelated'], 'prerequisite_register_page' => 2]);
        $this->assertSame(['prerequisite_state' => 'OPEN', 'prerequisite_company' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'prerequisite_notice' => '0', 'prerequisite_company_q' => '', 'prerequisite_notice_state' => ''], $f);
        $this->assertSame('', InspectionPrerequisiteFilters::fromQuery(['prerequisite_state' => null, 'prerequisite_notice' => ' '])['prerequisite_notice']);
    }

    public function test_arrays_invalid_states_bad_identity_oversize_or_invalid_utf8_cannot_become_unfiltered_success(): void
    {
        foreach ([['prerequisite_state' => ['OPEN']], ['prerequisite_state' => 'CANCELLED'], ['prerequisite_state' => 'open'], ['prerequisite_company' => ['x']], ['prerequisite_company' => 'bad'], ['prerequisite_notice' => ['x']], ['prerequisite_notice' => str_repeat('x', 101)], ['prerequisite_notice' => "\xff"], ['prerequisite_notice' => false], ['prerequisite_notice' => "A\0B"], ['prerequisite_notice' => "\0"], ['prerequisite_notice' => "\0A"], ['prerequisite_notice' => "A\0"]] as $bad) {
            try {
                InspectionPrerequisiteFilters::fromQuery($bad);
                $this->fail('Expected invalid filter');
            } catch (ValidationException$e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_literal_wildcards_quotes_rtl_and_states_remain_data(): void
    {
        foreach (['OPEN', 'REPORTED', 'FULFILLED', 'WITHDRAWN'] as $state) {
            $this->assertSame($state, InspectionPrerequisiteFilters::fromQuery(['prerequisite_state' => $state])['prerequisite_state']);
        }
        $term = "%_! ' OR 1=1 -- متن";
        $this->assertSame($term, InspectionPrerequisiteFilters::fromQuery(['prerequisite_notice' => $term])['prerequisite_notice']);
    }

    public function test_company_option_search_is_optional_trimmed_literal_and_rejects_bad_shapes(): void
    {
        $this->assertSame('0', InspectionPrerequisiteFilters::fromQuery(['prerequisite_company_q' => ' 0 '])['prerequisite_company_q']);
        $term = "%_! ' شرکت";
        $this->assertSame($term, InspectionPrerequisiteFilters::fromQuery(['prerequisite_company_q' => $term])['prerequisite_company_q']);
        foreach ([['x'], str_repeat('x', 101), "\xff", "\0", "A\0", false] as $bad) {
            try {
                InspectionPrerequisiteFilters::fromQuery(['prerequisite_company_q' => $bad]);
                $this->fail('Invalid option search');
            } catch (ValidationException$e) {
                $this->assertArrayHasKey('prerequisite_company_q', $e->errors());
            }
        }
    }

    public function test_original_notice_state_is_optional_trimmed_and_not_a_prerequisite_status_or_write_authority(): void
    {
        foreach (['ACTIVE', 'CANCELLED'] as $state) {
            $this->assertSame($state, InspectionPrerequisiteFilters::fromQuery(['prerequisite_notice_state' => ' '.$state.' '])['prerequisite_notice_state']);
        }
        foreach ([null, '', '  '] as $empty) {
            $this->assertSame('', InspectionPrerequisiteFilters::fromQuery(['prerequisite_notice_state' => $empty])['prerequisite_notice_state']);
        }
        foreach ([['ACTIVE'], 'OPEN', 'ISSUED', 'active', 'ALL', 'FULFILLED', true, "\0", "ACTIVE\0", "\xff"] as $bad) {
            try {
                InspectionPrerequisiteFilters::fromQuery(['prerequisite_notice_state' => $bad]);
                $this->fail('Invalid notice lifecycle');
            } catch (ValidationException$e) {
                $this->assertArrayHasKey('prerequisite_notice_state', $e->errors());
            }
        }
    }
}
