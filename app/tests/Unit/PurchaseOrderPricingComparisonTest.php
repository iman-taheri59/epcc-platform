<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PurchaseOrderPricingHistoryService;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderPricingComparisonTest extends TestCase
{
    private function payload(): array
    {
        return ['supported' => true,
            'before' => ['currency' => null, 'total' => null, 'lines' => [
                ['id' => '11111111-1111-4111-8111-111111111111', 'code' => 'SAME', 'price' => null],
                ['id' => '22222222-2222-4222-8222-222222222222', 'code' => 'SAME', 'price' => '0.00']]],
            'after' => ['currency' => 'USD', 'total' => '509999999999999.95', 'lines' => [
                ['id' => '22222222-2222-4222-8222-222222222222', 'code' => 'SAME', 'price' => '99999999999999.99'],
                ['id' => '11111111-1111-4111-8111-111111111111', 'code' => 'SAME', 'price' => '0.00']]]];
    }

    public function test_text_money_identity_matching_and_null_vs_zero_are_preserved(): void
    {
        $data = $this->payload();
        $result = (new PurchaseOrderPricingHistoryService)->present(json_encode($data));
        $this->assertSame('509999999999999.95', $result['after']['total']);
        $this->assertSame('99999999999999.99', $result['lines'][0]['after']);
        $this->assertSame('0.00', $result['lines'][0]['before']);
        $this->assertNull($result['lines'][1]['before']);
        $this->assertSame('0.00', $result['lines'][1]['after']);
        $this->assertSame($data['after']['lines'][0]['id'], $result['lines'][0]['id']);
    }

    public function test_unknown_shape_and_invalid_json_return_no_inferred_comparison(): void
    {
        $service = new PurchaseOrderPricingHistoryService;
        foreach (['not-json', 'null', '[]', '{"supported":true}', json_encode(array_replace($this->payload(), ['supported' => false]))] as $input) {
            $this->assertNull($service->present($input));
        }
    }

    public function test_duplicate_missing_and_reassigned_line_identities_are_not_guessed(): void
    {
        foreach (['duplicate', 'missing', 'changed_code', 'invalid_id'] as $case) {
            $data = $this->payload();
            if ($case === 'duplicate') {
                $data['before']['lines'][1]['id'] = $data['before']['lines'][0]['id'];
            } elseif ($case === 'missing') {
                array_pop($data['after']['lines']);
            } elseif ($case === 'changed_code') {
                $data['after']['lines'][0]['code'] = 'DIFFERENT';
            } else {
                $data['after']['lines'][0]['id'] = 'not-a-uuid';
            }
            $this->assertNull((new PurchaseOrderPricingHistoryService)->present(json_encode($data)), $case);
        }
    }

    public function test_native_float_and_nonnumeric_or_malformed_fields_are_not_silently_coerced(): void
    {
        foreach ([1.23, 'NaN', '-1.00', ['1.00']] as $price) {
            $data = $this->payload();
            $data['after']['lines'][0]['price'] = $price;
            $this->assertNull((new PurchaseOrderPricingHistoryService)->present(json_encode($data)));
        }
    }
}
