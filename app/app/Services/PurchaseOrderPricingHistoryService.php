<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Original caller RLS only; display retained pricing evidence, never certify or mutate it. */
final class PurchaseOrderPricingHistoryService
{
    public function forOrder(string $projectId, string $orderId): Collection
    {
        $before = $this->snapshotSQL('before_snapshot');
        $after = $this->snapshotSQL('snapshot');
        $supported = "r.before_snapshot->>'schema'='1' AND r.snapshot->>'schema'='1'
            AND jsonb_typeof(r.before_snapshot->'header')='object' AND jsonb_typeof(r.snapshot->'header')='object'
            AND jsonb_typeof(r.before_snapshot->'lines')='array' AND jsonb_typeof(r.snapshot->'lines')='array'";

        return DB::table('po_pricing_revisions as r')->where('r.project_id', $projectId)->where('r.purchase_order_id', $orderId)
            ->select('r.*')->selectRaw("jsonb_build_object('supported',COALESCE(($supported),false),'before',($before),'after',($after))::text AS comparison_payload")
            ->orderByDesc('r.sequence')->limit(20)->get()->map(function ($revision) {
                $revision->comparison = $this->present($revision->comparison_payload);
                unset($revision->comparison_payload);

                return $revision;
            });
    }

    /** PostgreSQL ->> extracts monetary values as exact TEXT, before PHP ever decodes JSON. */
    private function snapshotSQL(string $column): string
    {
        return "jsonb_build_object('currency',r.$column #>> '{header,currency}','total',r.$column #>> '{header,total_value}',
            'lines',CASE WHEN jsonb_typeof(r.$column->'lines')='array' THEN
                (SELECT COALESCE(jsonb_agg(jsonb_build_object('id',line->>'id','code',line->>'item_code','price',line->>'unit_price') ORDER BY n),'[]'::jsonb)
                 FROM jsonb_array_elements(r.$column->'lines') WITH ORDINALITY AS l(line,n)) ELSE NULL END)";
    }

    /** Only normalized text payloads from the extraction above; not arbitrary snapshot-number decoding. */
    public function present(string $payload): ?array
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data) || ($data['supported'] ?? false) !== true) {
            return null;
        }
        $maps = [];
        foreach (['before', 'after'] as $side) {
            $value = $data[$side] ?? null;
            if (! is_array($value) || ! array_key_exists('currency', $value) || ! array_key_exists('total', $value)
                || ! ($value['currency'] === null || (is_string($value['currency']) && preg_match('/\A[A-Z]{3,8}\z/', $value['currency'])))
                || ! $this->decimalOrNull($value['total']) || ! isset($value['lines']) || ! is_array($value['lines']) || ! array_is_list($value['lines'])) {
                return null;
            }
            $maps[$side] = [];
            foreach ($value['lines'] as $line) {
                if (! is_array($line) || ! is_string($line['id'] ?? null) || ! Str::isUuid($line['id']) || isset($maps[$side][$line['id']])
                    || ! array_key_exists('price', $line) || ! $this->decimalOrNull($line['price']) || ! is_string($line['code'] ?? null)) {
                    return null;
                }
                $maps[$side][$line['id']] = $line;
            }
        }
        if ($maps['before'] === [] || count($maps['before']) !== count($maps['after']) || array_diff_key($maps['before'], $maps['after']) !== []) {
            return null;
        }
        $rows = [];
        foreach ($maps['after'] as $id => $line) {
            if ($maps['before'][$id]['code'] !== $line['code']) {
                return null;
            }
            $rows[] = ['id' => $id, 'code' => $line['code'], 'before' => $maps['before'][$id]['price'], 'after' => $line['price']];
        }

        return ['before' => ['currency' => $data['before']['currency'], 'total' => $data['before']['total']],
            'after' => ['currency' => $data['after']['currency'], 'total' => $data['after']['total']], 'lines' => $rows];
    }

    private function decimalOrNull(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value) === 1);
    }
}
