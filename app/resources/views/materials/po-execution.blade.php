@php
 $L=fn($key)=>__('po_execution.'.$key);
 $num=fn($v)=>\App\Support\JalaliDate::digits((string)$v,$lang);
@endphp
<!DOCTYPE html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('workspace') }}</title>@include('partials.shell')@include('partials.tabletools')
<style>.exec-card{padding:16px;border:1px solid var(--line);background:var(--card);border-radius:12px;margin:14px 0}.exec-scroll{overflow:auto}td{vertical-align:top}textarea{display:block;width:100%;box-sizing:border-box;min-height:70px;background:var(--card);color:var(--ink)}pre{white-space:pre-wrap;overflow-wrap:anywhere}a{color:var(--accent)}.exec-hash{overflow-wrap:anywhere;font-size:12px}label{display:block;margin:12px 0}</style>
</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')
<a href="{{ route('procurement',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ $L('back') }}</a>
<h1>{{ $L('workspace') }}</h1><p>{{ $project->name($lang) }} · {{ $buyer->name($lang) }} · {{ $buyer->code }}</p>
<p class="exec-card">{{ $L('scope') }}<br>{{ $L('limits') }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<nav aria-label="{{ $L('category') }}">@foreach($categories as $tab)<a style="display:inline-block;padding:8px" @if($tab===$category) aria-current="page" @endif href="{{ route('procurement.execution',['projectCode'=>$project->code,'buyerId'=>$buyer->id,'category'=>$tab,'lang'=>$lang]) }}">{{ __('procurement_category.'.$tab) }}</a>@endforeach</nav>
<h2>{{ __('procurement_category.'.$category) }}</h2>
<div class="exec-scroll"><table data-tt><thead><tr><th>{{ $L('number') }}</th><th>{{ $L('category') }}</th><th>{{ $L('state') }}</th><th>{{ $L('value') }}</th><th></th></tr></thead><tbody>
@forelse($orders as $listed)<tr><td>{{ $listed->number }}</td><td>{{ $listed->material_category ? __('procurement_category.'.$listed->material_category) : __('procurement_category.legacy') }}</td><td>{{ \App\Support\ProcurementState::poLabel($listed->state)[$lang] }}</td><td><bdi>{{ $listed->total_value === null ? '—' : $num($listed->total_value).' '.$listed->currency }}</bdi></td><td><a href="{{ route('procurement.execution.order',['projectCode'=>$project->code,'buyerId'=>$buyer->id,'orderId'=>$listed->id,'lang'=>$lang]) }}">{{ $L('view') }}</a></td></tr>
@empty<tr><td colspan="5">{{ $L('empty') }}</td></tr>@endforelse
</tbody></table></div>{{ $orders->links() }}
@if($order)
<section class="exec-card"><h2>{{ $order->number }} · {{ $order->title($lang) }}</h2><p>{{ $L('vendor') }}: {{ $order->vendor?->name($lang) }} · <bdi>{{ $order->total_value }} {{ $order->currency }}</bdi> · {{ $order->promised_date?->format('Y-m-d') }}</p>
<p class="exec-hash">{{ $L('hash') }}: <bdi>{{ $hash }}</bdi></p>
@if($order->requisition_id)
<p>{{ __('po_mr_allocation.scope') }}</p>
@if($canReviewSource)<p><a href="{{ route('procurement.orders.source-review',['projectCode'=>$project->code,'orderId'=>$order->id,'lang'=>$lang]) }}">{{ __('po_source_review.title') }}</a></p>@endif
<p class="exec-hash">{{ __('po_mr_allocation.source_hash') }}: <bdi>{{ $sourceHash }}</bdi></p>
@if($sourceRefusal || $issueRefusal)<p role="status">{{ __('po_mr_allocation.'.($sourceRefusal ?? $issueRefusal)) }}</p>@endif
@endif
<h3>{{ $L('lines') }}</h3><div class="exec-scroll"><table data-tt><thead><tr>@foreach(['code','description','quantity','unit','price','lineage'] as $key)<th>{{ $L($key) }}</th>@endforeach</tr></thead><tbody>
@foreach($order->items as $item)<tr><td>{{ $item->item_code }}</td><td>{{ $item->description($lang) }}</td><td>{{ $num($item->quantity) }}</td><td>{{ $item->unit }}</td><td>{{ $item->unit_price === null ? '—' : $num($item->unit_price) }}</td><td><bdi>{{ $item->supersedes_id ?? '—' }}</bdi></td></tr>@endforeach
</tbody></table></div>
@if($finance)<p>{{ $L('approval') }}: {{ $finance->actor_name }} · <bdi>{{ $finance->id }}</bdi></p>@endif
@foreach(['finance'=>$canFinance,'issue'=>$canIssue] as $purpose=>$allowed)
@if($allowed)<form method="post" action="{{ route('procurement.execution.act',['projectCode'=>$project->code,'buyerId'=>$buyer->id,'orderId'=>$order->id,'purpose'=>$purpose,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="execution[content_hash]" value="{{ $hash }}">
@if($purpose==='issue')<input type="hidden" name="execution[approval_id]" value="{{ $finance->id }}">@endif
<label>{{ $L('reason') }}<textarea name="execution[reason]" required maxlength="4000">{{ is_string(old('execution.reason')) ? old('execution.reason') : '' }}</textarea></label>
<button class="btn" type="submit">{{ $L($purpose) }}</button></form>@endif
@endforeach
<p>{{ $L('pending') }}</p>
@if($allocations->isNotEmpty())
<h3>{{ __('po_mr_allocation.title') }}</h3><div class="exec-scroll"><table><thead><tr><th>{{ __('po_mr_allocation.po_line') }}</th><th>{{ __('po_mr_allocation.mr_line') }}</th><th>{{ $L('quantity') }}</th><th>{{ __('po_mr_allocation.approved') }}</th></tr></thead><tbody>
@foreach($allocations as $allocation)<tr><td><bdi>{{ $allocation->purchase_order_item_id }}</bdi></td><td><bdi>{{ $allocation->requisition_item_id }}</bdi></td><td>{{ $num($allocation->quantity) }} {{ $allocation->unit }}</td><td>{{ $num($allocation->approved_quantity) }}</td></tr>@endforeach
</tbody></table></div>@endif
<h3>{{ $L('history') }}</h3>
@forelse($history as $decision)<details><summary>{{ $L($decision->purpose) }} · {{ $decision->actor_name }} · {{ $decision->created_at }} UTC</summary>
<p>{{ $decision->reason }}</p>@if($decision->source_hash)<p class="exec-hash">{{ __('po_mr_allocation.source_hash') }}: <bdi>{{ $decision->source_hash }}</bdi></p>@endif<p class="exec-hash"><bdi>{{ $decision->id }} · {{ $decision->content_hash }}</bdi></p>
<details><summary>{{ $L('snapshot') }}</summary><pre dir="ltr">{{ $decision->snapshot }}</pre></details>
</details>@empty<p>{{ $L('none') }}</p>@endforelse
</section>@endif
</div></div></body></html>
