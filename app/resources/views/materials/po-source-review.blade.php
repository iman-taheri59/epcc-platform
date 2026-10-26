@php
 $R=fn($key)=>__('po_source_review.'.$key);
 $num=fn($v)=>\App\Support\JalaliDate::digits((string)$v,$lang);
@endphp
<!DOCTYPE html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $R('title') }}</title>
@include('partials.shell')@include('partials.tabletools')
<style>.review-note{padding:14px;border:1px solid var(--line);border-radius:10px;margin:14px 0}.review-scroll{overflow:auto}.review-id{overflow-wrap:anywhere;font-size:12px}td{vertical-align:top}a{color:var(--accent)}</style></head>
<body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')
<a href="{{ route('procurement.orders.show',['projectCode'=>$project->code,'orderId'=>$order->id,'lang'=>$lang]) }}">{{ $R('back') }}</a>
<h1>{{ $R('title') }}</h1><h2>{{ $order->number }} · {{ $order->title($lang) }}</h2>
<p class="review-note">{{ $R('scope') }}</p><p>{{ $R('generated') }}: <bdi>{{ $generatedAt }}</bdi></p>
@if(!$evidenceVisible)<p role="status" class="review-note">{{ $R('partial') }}</p>@endif
@if($disciplineLimited)<p class="review-note">{{ $R('discipline') }}</p>@endif
@if($source)
<h2>{{ $R('source') }} · <a href="{{ route('procurement.requisitions.show',['projectCode'=>$project->code,'requisitionId'=>$source->id,'lang'=>$lang]) }}">{{ $source->number }}</a> · {{ $source->state }}</h2>
<h3>{{ $R('lines') }}</h3><div class="review-scroll"><table data-tt><thead><tr>@foreach(['id','code','unit','approved','recorded_qty'] as $key)<th>{{ $R($key) }}</th>@endforeach</tr></thead><tbody>
@foreach($sourceLines as $line)<tr><td class="review-id"><bdi>{{ $line->id }}</bdi></td><td>{{ $line->item_code }}</td><td>{{ $line->unit }}</td><td>{{ $num($line->quantity) }}</td><td>@if(!$evidenceVisible){{ $R('unknown') }}@else @forelse($recorded->get($line->id,collect()) as $sum)<div>{{ $num($sum->quantity) }} {{ $sum->unit }}</div>@empty {{ $num('0.000') }} @endforelse @endif</td></tr>@endforeach
</tbody></table></div>
@else<p role="status">{{ $R('source_hidden') }}</p>@endif
<h2>{{ $R('related') }}</h2><div class="review-scroll"><table data-tt><thead><tr>@foreach(['number','state','category','link','classification'] as $key)<th>{{ $R($key) }}</th>@endforeach<th>{{ $R('history') }}</th></tr></thead><tbody>
@forelse($rows as $row)@php($candidate=$row['order'])
<tr><td><a href="{{ route('procurement.orders.show',['projectCode'=>$project->code,'orderId'=>$candidate->id,'lang'=>$lang]) }}">{{ $candidate->number }}</a></td><td>{{ $candidate->state }}</td><td>{{ $candidate->material_category ? __('procurement_category.'.$candidate->material_category) : __('procurement_category.legacy') }}</td><td>{{ $R($row['line_only']?'line_only':'header') }}</td><td>{{ $R($row['status']) }}</td><td>
@if($row['decision'])<details><summary>{{ $R('history') }}</summary><p class="review-id">{{ $R('decision') }}: <bdi>{{ $row['decision']->id }}</bdi></p><p class="review-id">{{ $R('source_hash') }}: <bdi>{{ $row['decision']->source_hash ?? '—' }}</bdi></p>
@foreach($row['allocations'] as $a)<p class="review-id"><bdi>{{ $a->requisition_item_id }}</bdi> · {{ $R('quantity') }}: {{ $num($a->quantity) }} {{ $a->unit }} · {{ $R('approved_then') }}: {{ $num($a->approved_quantity) }}</p>@endforeach
</details>@else — @endif
</td></tr>@empty<tr><td colspan="6">{{ $R('empty') }}</td></tr>@endforelse
</tbody></table></div>{{ $related->appends(['lang'=>$lang])->links() }}
</div></div></body></html>
