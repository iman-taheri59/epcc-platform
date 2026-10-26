<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PurchaseOrderPricingFormState;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderPricingFormStateTest extends TestCase
{
    private function input(): array
    {
        return ['content_hash' => str_repeat('a', 64), 'currency' => 'EUR', 'reason' => 'Review',
            'lines' => [['id' => 'line-b', 'unit_price' => '9999999999999999.99'], ['id' => 'line-a', 'unit_price' => '0.00']]];
    }

    public function test_same_version_complete_lines_restore_exact_strings_by_identity(): void
    {
        $state = (new PurchaseOrderPricingFormState)->restore($this->input(), str_repeat('a', 64), ['line-a', 'line-b']);
        $this->assertSame(['line-b' => '9999999999999999.99', 'line-a' => '0.00'], $state['prices']);
        $this->assertSame('EUR', $state['currency']);
        $this->assertSame('Review', $state['reason']);
    }

    public function test_null_browser_controls_restore_as_blank_not_zero_or_server_defaults(): void
    {
        $input = $this->input();
        $input['currency'] = $input['reason'] = $input['lines'][0]['unit_price'] = null;
        $state = (new PurchaseOrderPricingFormState)->restore($input, str_repeat('a', 64), ['line-a', 'line-b']);
        $this->assertSame('', $state['currency']);
        $this->assertSame('', $state['reason']);
        $this->assertSame('', $state['prices']['line-b']);
        $this->assertSame('0.00', $state['prices']['line-a']);
    }

    public function test_stale_malformed_partial_duplicate_foreign_or_oversized_inputs_are_not_merged(): void
    {
        foreach (['stale', 'partial', 'duplicate', 'foreign', 'extra_line_field', 'extra_header_field', 'array_price', 'float_price', 'array_currency', 'array_reason', 'too_long_price', 'too_long_currency', 'too_long_reason', 'non_list'] as $case) {
            $input = $this->input();
            switch ($case) {
                case 'stale': $input['content_hash'] = str_repeat('b', 64);
                    break;
                case 'partial': array_pop($input['lines']);
                    break;
                case 'duplicate': $input['lines'][1]['id'] = $input['lines'][0]['id'];
                    break;
                case 'foreign': $input['lines'][1]['id'] = 'foreign';
                    break;
                case 'extra_line_field': $input['lines'][1]['quantity'] = '2';
                    break;
                case 'extra_header_field': $input['state'] = 'ISSUED';
                    break;
                case 'array_price': $input['lines'][1]['unit_price'] = ['1'];
                    break;
                case 'float_price': $input['lines'][1]['unit_price'] = 1.23;
                    break;
                case 'array_currency': $input['currency'] = ['USD'];
                    break;
                case 'array_reason': $input['reason'] = ['reason'];
                    break;
                case 'too_long_price': $input['lines'][1]['unit_price'] = str_repeat('1', 65);
                    break;
                case 'too_long_currency': $input['currency'] = 'TOOLONGCC';
                    break;
                case 'too_long_reason': $input['reason'] = str_repeat('ش', 4001);
                    break;
                case 'non_list': $input['lines'] = ['x' => $input['lines'][0], 'y' => $input['lines'][1]];
                    break;
            }
            $this->assertNull((new PurchaseOrderPricingFormState)->restore($input, str_repeat('a', 64), ['line-a', 'line-b']), $case);
        }
        $this->assertNull((new PurchaseOrderPricingFormState)->restore('not-an-array', str_repeat('a', 64), ['line-a', 'line-b']));
        $this->assertNull((new PurchaseOrderPricingFormState)->restore($this->input(), '', ['line-a', 'line-b']));
    }

    public function test_invalid_but_scalar_price_is_retained_for_correction_without_becoming_a_valid_price(): void
    {
        $input = $this->input();
        $input['lines'][0]['unit_price'] = '<img src=x onerror=alert(1)>';
        $this->assertSame($input['lines'][0]['unit_price'], (new PurchaseOrderPricingFormState)->restore($input, str_repeat('a', 64), ['line-a', 'line-b'])['prices']['line-b']);
    }
}
