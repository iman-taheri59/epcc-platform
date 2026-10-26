@php
 $R=fn($key)=>__('requisition.'.$key);
 $num=fn($value)=>\App\Support\JalaliDate::digits((string)$value,$lang);
 $date=fn($value)=>$value ? \App\Support\JalaliDate::format($value,$lang) : $R('unknown');
@endphp
<!DOCTYPE html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $R('title') }} — {{ $requisition->number }}</title>
@include('partials.shell')@include('partials.tabletools')
<style>.mr-note{padding:14px;border:1px solid var(--line);border-radius:10px;line-height:1.8}.mr-scroll{overflow:auto}.mr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.mr-grid div{padding:12px;border:1px solid var(--line);border-radius:10px}.mr-grid dd{margin:5px 0}.mr-id{font-size:11px;overflow-wrap:anywhere}td{vertical-align:top}#po-from-mr-{{ $requisition->id }}{padding:14px;margin:16px 0;border:1px solid var(--line);border-radius:10px}#po-from-mr-{{ $requisition->id }} form{display:flex;flex-wrap:wrap;gap:12px;align-items:end}a{color:var(--accent)}h2{margin-top:26px}</style></head>
<body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')
<a href="{{ route('procurement',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ $R('back') }}</a>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<h1>{{ $R('title') }} · <bdi>{{ $requisition->number }}</bdi></h1><p>{{ $requisition->title($lang) }}</p><p class="mr-note">{{ $R('scope') }}</p>
@if($disciplineLimited)<p class="mr-note">{{ $R('discipline_note') }}</p>@endif
<dl class="mr-grid"><div><dt>{{ $R('company') }}</dt><dd>{{ $requisition->company?->name($lang) ?? $R('unknown') }}</dd></div><div><dt>{{ $R('discipline') }}</dt><dd>{{ $requisition->discipline ?? $R('unknown') }}</dd></div><div><dt>{{ $R('category') }}</dt><dd>{{ $requisition->material_category ? __('procurement_category.'.$requisition->material_category) : __('procurement_category.legacy') }}</dd></div><div><dt>{{ $R('state') }}</dt><dd>{{ \App\Support\ProcurementState::mrLabel($requisition->state)[$lang] ?? $requisition->state }}</dd></div></dl>
@include('materials.po-from-mr', ['mr' => $requisition])
<h2>{{ $R('lines') }}</h2><div class="mr-scroll"><table data-tt id="mr-lines"><thead><tr>@foreach(['line','code','description','quantity','unit','subsystem','specification'] as $key)<th>{{ $R($key) }}</th>@endforeach</tr></thead><tbody>
@forelse($requisition->items as $line)<tr id="mr-line-{{ $line->id }}"><td>{{ $num($line->line_no) }}</td><td><bdi>{{ $line->item_code }}</bdi><p class="mr-id">{{ $R('id') }}: <bdi>{{ $line->id }}</bdi></p></td><td>{{ $line->description($lang) }}</td><td><bdi>{{ $num($line->quantity) }}</bdi>@if($line->source_quantity !== null)<p><small>{{ $R('spelling') }}: <bdi>{{ $num($line->source_quantity) }}</bdi></small></p>@endif</td><td>{{ $line->unit }}</td><td>{{ $line->subsystem?->code ?? $R('unknown') }}</td><td>{{ $line->specification ?? $R('unknown') }}</td></tr>@empty<tr><td colspan="7">{{ $R('empty') }}</td></tr>@endforelse
</tbody></table></div>
<h2>{{ $R('approvals') }}</h2><p class="mr-note">{{ $R('approval_note') }}</p><table id="mr-workflow"><thead><tr><th>{{ $R('state') }}</th><th>{{ $R('actor') }}</th><th>{{ $R('date') }}</th></tr></thead><tbody>
@foreach(['raised','technical_approved','commercial_approved','rejected'] as $field)<tr><td>{{ $R($field) }}</td><td class="mr-id"><bdi>{{ $requisition->{$field.'_by'} ?? $R('unknown') }}</bdi></td><td>{{ $date($requisition->{$field.'_at'}) }}</td></tr>@endforeach
</tbody></table>@if($requisition->rejection_reason !== null)<p>{{ $R('reason') }}: {{ $requisition->rejection_reason }}</p>@endif
<h2>{{ $R('related') }}</h2><p class="mr-note">{{ $R('related_note') }}</p><div class="mr-scroll"><table data-tt id="mr-orders"><thead><tr>@foreach(['number','category','state','relationship'] as $key)<th>{{ $R($key) }}</th>@endforeach</tr></thead><tbody>
@forelse($related as $order)<tr><td><a href="{{ route('procurement.orders.show',['projectCode'=>$project->code,'orderId'=>$order->id,'lang'=>$lang]) }}">{{ $order->number }}</a></td><td>{{ $order->material_category ? __('procurement_category.'.$order->material_category) : __('procurement_category.legacy') }}</td><td>{{ \App\Support\ProcurementState::poLabel($order->state)[$lang] ?? $order->state }}</td><td>{{ $R($order->requisition_id === $requisition->id ? 'header' : 'line_only') }}</td></tr>@empty<tr><td colspan="4">{{ $R('empty_orders') }}</td></tr>@endforelse
</tbody></table></div>{{ $related->appends(['lang'=>$lang])->links() }}
</div></div></body></html>
