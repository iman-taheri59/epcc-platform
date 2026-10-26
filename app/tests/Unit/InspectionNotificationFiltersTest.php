<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InspectionNotificationFilters;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InspectionNotificationFiltersTest extends TestCase
{
    public function test_empty_and_whitespace_filters_are_normalized_without_using_a_default_time_zone(): void
    {
        $f = InspectionNotificationFilters::fromQuery(['notice_q' => '  0  ', 'notice_state' => null, 'activity_from' => ' ', 'stage' => 'unrelated-source-selector']);
        $this->assertSame('0', $f['notice_q']);
        $this->assertSame('CANCELLED', InspectionNotificationFilters::fromQuery(['notice_state' => 'CANCELLED'])['notice_state']);
        $this->assertSame('', $f['notice_state']);
        $this->assertNull($f['activity_from_utc']);
        $this->assertNull($f['activity_to_utc']);
        $this->assertArrayNotHasKey('stage', $f);
    }

    public function test_time_bounds_are_optional_inclusive_and_compared_as_absolute_instants(): void
    {
        $f = InspectionNotificationFilters::fromQuery(['activity_from' => '2026-10-25T09:30:00+03:30', 'activity_to' => '2026-10-25T06:00:00+00:00']);
        $this->assertSame('2026-10-25 06:00:00+00:00', $f['activity_from_utc']);
        $this->assertSame($f['activity_from_utc'], $f['activity_to_utc']);
        $f = InspectionNotificationFilters::fromQuery(['activity_to' => '2026-10-26T00:30:00+03:30']);
        $this->assertNull($f['activity_from_utc']);
        $this->assertSame('2026-10-25 21:00:00+00:00', $f['activity_to_utc']);
    }

    public function test_nested_malformed_long_and_reversed_filters_fail_without_peer_comparison_type_errors(): void
    {
        foreach ([['notice_q' => ['bad']], ['notice_q' => chr(255)], ['notice_state' => ['bad']], ['notice_q' => str_repeat('x', 201)], ['notice_state' => 'SUPERSEDED'], ['activity_to' => ['bad']], ['activity_from' => '2026-02-30T09:30:00+03:30'], ['activity_from' => '2026-10-25T09:30:00'], ['activity_from' => '0000-01-01T00:00:00+00:00'], ['activity_from' => '0001-01-01T00:00:00+03:30'], ['activity_from' => '2026-10-25T09:30:00+03:30', 'activity_to' => '2026-10-25T05:00:00+00:00']] as $bad) {
            try {
                InspectionNotificationFilters::fromQuery($bad);
                $this->fail('Expected invalid register filters');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}
