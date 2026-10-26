<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InspectionNotificationInput;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class InspectionNotificationInputTest extends TestCase
{
    private function data(): array
    {
        return ['notification' => [
            'itp_revision_id' => '11111111-1111-4111-8111-111111111111',
            'itp_stage_id' => '22222222-2222-4222-8222-222222222222',
            'work_reference' => 'Field activity / فعالیت اجرایی',
            'activity_start_at' => '2026-10-25T09:30:00+03:30', 'activity_end_at' => '2026-10-25T11:30:00+03:30',
            'location' => 'Site / سایت',
            'recipient_company_ids' => ['33333333-3333-4333-8333-333333333333'],
        ]];
    }

    private function valid(array $data): bool
    {
        return (new Factory(new Translator(new ArrayLoader, 'en')))->make($data, InspectionNotificationInput::draftRules())->passes();
    }

    public function test_non_po_activity_and_bilingual_text_are_valid_shapes(): void
    {
        $this->assertTrue($this->valid($this->data()));
    }

    public function test_revision_stage_and_recipients_are_required(): void
    {
        foreach (['itp_revision_id', 'itp_stage_id', 'recipient_company_ids'] as $key) {
            $data = $this->data();
            unset($data['notification'][$key]);
            $this->assertFalse($this->valid($data));
        }
    }

    public function test_empty_duplicate_or_malformed_recipients_are_refused(): void
    {
        foreach ([[], ['bad'], array_fill(0, 2, '33333333-3333-4333-8333-333333333333')] as $recipients) {
            $data = $this->data();
            $data['notification']['recipient_company_ids'] = $recipients;
            $this->assertFalse($this->valid($data));
        }
    }

    public function test_actor_company_result_number_and_itp_point_are_not_client_owned(): void
    {
        foreach (['actor_id', 'company_id', 'result', 'number', 'point', 'status', 'issued_at'] as $key) {
            $data = $this->data();
            $data['notification'][$key] = 'forged';
            $this->assertFalse($this->valid($data));
        }
    }

    public function test_time_requires_explicit_offset_and_invalid_dates_are_refused(): void
    {
        foreach (['2026-10-25T09:30:00', '2026-02-30T09:30:00+03:30', '۱۴۰۵/۰۸/۰۳', ['nested']] as $time) {
            $data = $this->data();
            $data['notification']['activity_start_at'] = $time;
            $this->assertFalse($this->valid($data));
        }
    }

    public function test_optional_po_is_typed_but_not_required(): void
    {
        $data = $this->data();
        $data['notification']['purchase_order_id'] = null;
        $this->assertTrue($this->valid($data));
        $data['notification']['purchase_order_id'] = 'not-a-uuid';
        $this->assertFalse($this->valid($data));
    }

    public function test_issue_shape_requires_positive_version_and_refuses_authority_spoof(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        foreach ([['version' => 0], ['version' => 1, 'issued_by' => 'forged'], []] as $payload) {
            $this->assertTrue($factory->make(['issuance' => $payload], InspectionNotificationInput::issueRules())->fails());
        }
        $this->assertTrue($factory->make(['issuance' => ['version' => 1]], InspectionNotificationInput::issueRules())->passes());
    }

    public function test_activity_window_requires_both_boundaries_and_refuses_the_old_single_time_contract(): void
    {
        foreach (['activity_start_at', 'activity_end_at'] as $key) {
            $d = $this->data();
            unset($d['notification'][$key]);
            $this->assertFalse($this->valid($d));
        }
        $d = $this->data();
        $d['notification']['scheduled_at'] = '2026-10-25T09:30:00+03:30';
        $this->assertFalse($this->valid($d));
        unset($d['notification']['activity_start_at'],$d['notification']['activity_end_at']);
        $this->assertFalse($this->valid($d));
    }

    public function test_end_must_be_later_in_absolute_time_even_when_offsets_or_calendar_days_differ(): void
    {
        foreach ([
            ['2026-10-25T23:30:00+03:30', '2026-10-26T00:30:00+03:30', true],
            ['2026-10-25T09:30:00+03:30', '2026-10-25T07:00:00+00:00', true],
            ['2026-10-25T02:30:00+02:00', '2026-10-25T02:15:00+01:00', true],
            ['2026-10-25T09:30:00+03:30', '2026-10-25T09:30:00+03:30', false],
            ['2026-10-25T09:30:00+03:30', '2026-10-25T06:00:00+00:00', false],
            ['2026-10-25T09:30:00+03:30', '2026-10-25T10:00:00+05:00', false],
        ] as [$start,$end,$valid]) {
            $d = $this->data();
            $d['notification']['activity_start_at'] = $start;
            $d['notification']['activity_end_at'] = $end;
            $this->assertSame($valid, $this->valid($d));
        }
    }

    public function test_both_boundaries_reject_invalid_or_nested_peer_data_without_comparison_errors(): void
    {
        foreach (['activity_start_at', 'activity_end_at'] as $key) {
            foreach ([['nested'], '0000-01-01T09:30:00+03:30', '2026-02-30T09:30:00+03:30', '2026-10-25T09:30:00', null, "2026-10-25T09:30:00+03:30\0"] as $bad) {
                $d = $this->data();
                $d['notification'][$key] = $bad;
                $this->assertFalse($this->valid($d));
            }
        }
    }
}
