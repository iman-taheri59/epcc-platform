@php
    $L = fn ($key) => __('purchase_order.'.$key);
    $num = fn ($value) => \App\Support\JalaliDate::digits((string) $value, $lang);
    $date = fn ($value) => $value ? \App\Support\JalaliDate::format($value, $lang) : $L('unknown');
    $replacedIds = $order->items->pluck('supersedes_id')->filter()->all();
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $order->number }}</title>
@include('partials.shell')
@include('partials.tabletools')
<style>
.po-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.po-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:18px 0}.po-grid div{background:var(--card);padding:14px;border:1px solid var(--line);border-radius:10px}.po-grid dt{color:var(--muted);font-size:12px}.po-grid dd{margin:6px 0 0;overflow-wrap:anywhere}.po-note{padding:12px;border:1px solid var(--line);border-radius:9px;color:var(--muted);line-height:1.8}.po-scroll{overflow-x:auto}.po-history{opacity:.65}.po-id{font-size:11px;overflow-wrap:anywhere}h2{margin-top:26px;font-size:18px}td{vertical-align:top}a{color:var(--accent)}
</style>
</head>
<body><div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')
<div class="po-head"><a href="{{ route('procurement', ['projectCode'=>$project->code, 'lang'=>$lang]) }}">{{ $L('back') }}</a><a href="{{ route('materials', ['projectCode'=>$project->code, 'lang'=>$lang]) }}">{{ $L('warehouse') }}</a></div>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<h1>{{ $L('title') }} · <bdi>{{ $order->number }}</bdi></h1>
@if($order->author_policy_id)<p>{{ __('po_entry.policy_basis') }}: <bdi>{{ $order->author_policy_id }}</bdi></p>@endif
@if($order->requisition_id && $order->author_policy_id)<p class="po-note">{{ __('po_source.provenance') }}</p>@endif
<p>{{ $order->title($lang) }} · {{ \App\Support\ProcurementState::poLabel($order->state)[$lang] }}</p>
@if($order->requisition_id)<p><a href="{{ route('procurement.orders.source-review',['projectCode'=>$project->code,'orderId'=>$order->id,'lang'=>$lang]) }}">{{ __('po_source_review.title') }}</a></p>@endif
<dl class="po-grid">
<div><dt>{{ __('procurement_category.label') }}</dt><dd>{{ $order->material_category ? __('procurement_category.'.$order->material_category) : __('procurement_category.legacy') }}</dd></div>
@foreach(['buyer'=>$order->company?->name($lang), 'vendor'=>$order->vendor?->name($lang), 'requisition'=>$order->requisition?->number] as $key=>$value)
<div><dt>{{ $L($key) }}</dt><dd>{{ $value ?? $L('unknown') }}</dd></div>
@endforeach
<div><dt>{{ $L('value') }}</dt><dd><bdi>{{ $order->total_value === null ? $L('unknown') : $num($order->total_value).' '.$order->currency }}</bdi></dd></div>
<div><dt>{{ $L('promised') }}</dt><dd>{{ $date($order->promised_date) }}</dd></div>
<div><dt>{{ $L('forecast') }}</dt><dd>{{ $date($order->forecast_date) }}</dd></div>
<div><dt>{{ $L('slip') }}</dt><dd>{{ $order->slipDays() === null ? $L('unknown') : $num($order->slipDays()) }}</dd></div>
</dl>
@include('materials.po-pricing')
<h2>{{ $L('items') }}</h2><p class="po-note">{{ $L('line_note') }}</p>
<div class="po-scroll"><table data-tt id="po-lines"><thead><tr>
@foreach(['line','code','description','quantity','unit','price','scope','history'] as $key)<th>{{ $L($key) }}</th>@endforeach
</tr></thead><tbody>
@forelse($order->items as $item)
<tr id="line-{{ $item->id }}" @class(['po-history'=>in_array($item->id,$replacedIds,true)])>
<td>{{ $num($item->line_no) }}</td><td><bdi>{{ $item->item_code }}</bdi>
@if($item->requisition_item_id)<br><small>{{ $item->requisitionItem ? __('po_source.source_line').' '.$num($item->requisitionItem->line_no) : __('po_source.hidden_source') }}</small>@endif</td><td>{{ $item->description($lang) }}<br><small>{{ !$order->material_category ? __('procurement_category.legacy') : ($item->material_item_id ? __('procurement_category.catalogue') : __('procurement_category.declared')) }}</small></td>
<td><bdi>{{ $num($item->quantity) }}</bdi></td><td><bdi>{{ $item->unit }}</bdi></td><td>{{ $item->unit_price === null ? '—' : $num($item->unit_price) }}</td>
<td>{{ $item->subsystem?->code ?? $L('unknown') }}</td>
<td>{{ $L(in_array($item->id,$replacedIds,true) ? 'replaced' : 'latest') }}
<div class="po-id">{{ $L('id') }}: <bdi>{{ $item->id }}</bdi></div>
@if($item->supersedes_id)<div class="po-id">{{ $L('replaces') }}: <bdi>{{ $item->supersedes_id }}</bdi></div>@endif
@if($item->requisitionItem)<div>{{ $L('mr_line') }}: {{ $num($item->requisitionItem->line_no) }}</div>@endif
</td></tr>
@empty<tr><td colspan="8">{{ $L('no_items') }}</td></tr>@endforelse
</tbody></table></div>
<h2>{{ $L('lots') }}</h2><p class="po-note">{{ $L('advisory') }}</p>
<div class="po-scroll"><table data-tt><thead><tr>@foreach(['lot','code','line','heat','balance','unit','inspection'] as $key)<th>{{ $L($key) }}</th>@endforeach</tr></thead><tbody>
@forelse($order->lots as $lot)
<tr><td>{{ $lot->lot_code }}</td><td>{{ $lot->item_code }}</td><td>{{ $lot->purchaseOrderItem && $lot->purchaseOrderItem->purchase_order_id === $order->id ? $num($lot->purchaseOrderItem->line_no) : $L('unlinked') }}</td><td>{{ $lot->heat_number ?? '—' }} / {{ $lot->serial_number ?? '—' }}</td>
<td>{{ $num($lot->balance($lot->movements)) }}</td><td>{{ $lot->unit }}</td><td>{{ $lot->inspection_result ? \App\Support\ProcurementState::inspectionLabel($lot->inspection_result)[$lang] : $L('unknown') }}</td></tr>
@empty<tr><td colspan="7">{{ $L('no_lots') }}</td></tr>@endforelse
</tbody></table></div>
@include('materials.inspection-entry')
<h2>{{ $L('inspections') }}</h2>
<div class="po-scroll"><table data-tt><thead><tr>@foreach(['number','stage','result','inspector','date','findings'] as $key)<th>{{ $L($key) }}</th>@endforeach</tr></thead><tbody>
@forelse($order->inspections as $inspection)
<tr><td>{{ $inspection->number }}</td><td>{{ \App\Support\ProcurementState::stageLabel($inspection->stage)[$lang] }}</td><td>{{ \App\Support\ProcurementState::inspectionLabel($inspection->result)[$lang] }}</td><td>{{ $inspection->recorded_actor_name ?? $inspection->inspector?->name ?? $L('unknown') }}@if($inspection->entry_policy_id)<br><small>{{ __('inspection_entry.policy') }}: <bdi>{{ $inspection->entry_policy_id }}</bdi> · {{ __('inspection_entry.recorded') }}: <bdi>{{ $inspection->created_at }}</bdi></small>@endif</td><td>{{ $date($inspection->inspected_at) }}</td><td>{{ $inspection->findings($lang) ?? '—' }}</td></tr>
@empty<tr><td colspan="6">{{ $L('no_inspections') }}</td></tr>@endforelse
</tbody></table></div>
</div></div></body></html>
