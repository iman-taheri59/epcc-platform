<?php

declare(strict_types=1);

namespace App\Support;

/** Untrusted flashed input is a convenience, never evidence or write authority. */
final class PurchaseOrderPricingFormState
{
    public function restore(mixed $input, string $currentHash, array $activeIds): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $currentHash) !== 1 || ! is_array($input) || count($input) !== 4 || array_diff(array_keys($input), ['content_hash', 'currency', 'reason', 'lines']) !== []
            || ! is_string($input['content_hash'] ?? null) || ! hash_equals($currentHash, $input['content_hash'])
            || ! array_key_exists('currency', $input) || ! array_key_exists('reason', $input)
            || ! $this->textOrBlank($input['currency'], 8) || ! $this->textOrBlank($input['reason'], 4000)
            || ! is_array($input['lines'] ?? null) || ! array_is_list($input['lines']) || $activeIds === [] || count($activeIds) > 20
            || count($input['lines']) !== count($activeIds)) {
            return null;
        }
        $expected = array_fill_keys($activeIds, true);
        $prices = [];
        foreach ($input['lines'] as $line) {
            if (! is_array($line) || count($line) !== 2 || ! is_string($line['id'] ?? null) || ! isset($expected[$line['id']])
                || array_key_exists($line['id'], $prices) || ! array_key_exists('unit_price', $line) || ! $this->textOrBlank($line['unit_price'], 64)) {
                return null;
            }
            // Empty browser controls may have been converted to null by middleware; never turn them into zero.
            $prices[$line['id']] = $line['unit_price'] ?? '';
        }
        if (count($prices) !== count($expected)) {
            return null;
        }

        return ['currency' => $input['currency'] ?? '', 'reason' => $input['reason'] ?? '', 'prices' => $prices];
    }

    private function textOrBlank(mixed $value, int $max): bool
    {
        return $value === null || (is_string($value) && mb_strlen($value) <= $max);
    }
}
