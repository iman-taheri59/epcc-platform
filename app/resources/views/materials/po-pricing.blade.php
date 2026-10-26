@if($canPrice)
@php
 $oldPricing = old('pricing');
 $activePricingIds = $order->items->reject(fn($line)=>in_array($line->id,$replacedIds,true))->pluck('id')->all();
 $restoredPricing = app(\App\Support\PurchaseOrderPricingFormState::class)->restore($oldPricing,$pricingHash,$activePricingIds);
@endphp
<section class="po-note"><h2>{{ __('po_pricing.title') }}</h2><p>{{ __('po_pricing.scope') }}</p>
@if($oldPricing !== null && $restoredPricing === null)<p role="alert">{{ __('po_pricing.input_not_restored') }}</p>@endif
<form method="post" action="{{ route('procurement.orders.pricing',['projectCode'=>$project->code,'orderId'=>$order->id,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="pricing[content_hash]" value="{{ $pricingHash }}">
<label>{{ __('po_pricing.currency') }} <input name="pricing[currency]" required maxlength="8" pattern="[A-Z]{3,8}" value="{{ $restoredPricing !== null ? $restoredPricing['currency'] : $order->currency }}"></label>
<div class="po-scroll"><table><thead><tr><th>{{ $L('code') }}</th><th>{{ $L('quantity') }}</th><th>{{ $L('unit') }}</th><th>{{ $L('price') }}</th></tr></thead><tbody>
@php($pricingIndex=0)
@foreach($order->items as $priceLine)
@if(!in_array($priceLine->id,$replacedIds,true))
<tr><td>{{ $priceLine->item_code }}<input type="hidden" name="pricing[lines][{{ $pricingIndex }}][id]" value="{{ $priceLine->id }}"></td><td>{{ $num($priceLine->quantity) }}</td><td>{{ $priceLine->unit }}</td><td><input name="pricing[lines][{{ $pricingIndex }}][unit_price]" inputmode="decimal" required pattern="[0-9]{1,16}(\.[0-9]{1,2})?" value="{{ $restoredPricing !== null ? $restoredPricing['prices'][$priceLine->id] : $priceLine->unit_price }}" aria-label="{{ __('po_pricing.price') }} {{ $priceLine->line_no }}"></td></tr>
@php($pricingIndex++)
@endif
@endforeach
</tbody></table></div>
<label>{{ __('po_pricing.reason') }}<textarea name="pricing[reason]" required maxlength="4000" style="display:block;width:100%;box-sizing:border-box">{{ $restoredPricing['reason'] ?? '' }}</textarea></label>
<button type="submit" class="btn">{{ __('po_pricing.save') }}</button>
</form></section>
@endif
@if($pricingHistory->isNotEmpty())
<section class="po-note"><h2>{{ __('po_pricing.history') }}</h2>
@foreach($pricingHistory as $revision)<details><summary>{{ $num($revision->sequence) }} · {{ $revision->actor_name }} · {{ $revision->created_at }}</summary><p>{{ $revision->reason }}</p><p class="po-id"><bdi>{{ $revision->before_hash }} → {{ $revision->content_hash }}</bdi></p>@if($revision->comparison)
<h3>{{ __('po_pricing.comparison') }}</h3><p>{{ __('po_pricing.comparison_note') }}</p>
<div class="po-scroll"><table data-pricing-comparison="{{ $revision->id }}"><thead><tr><th></th><th>{{ __('po_pricing.before_value') }}</th><th>{{ __('po_pricing.after_value') }}</th></tr></thead><tbody>
@foreach(['currency','total'] as $field)<tr><th>{{ __('po_pricing.'.$field) }}</th>@foreach(['before','after'] as $side)<td><bdi>{{ $revision->comparison[$side][$field] === null ? __('po_pricing.unknown_value') : $num($revision->comparison[$side][$field]) }}</bdi></td>@endforeach</tr>@endforeach
</tbody></table></div>
<div class="po-scroll"><table><thead><tr><th>{{ __('po_pricing.line_identity') }}</th><th>{{ __('po_pricing.before_value') }}</th><th>{{ __('po_pricing.after_value') }}</th></tr></thead><tbody>
@foreach($revision->comparison['lines'] as $row)<tr><td>{{ $row['code'] }}<br><small class="po-id"><bdi>{{ $row['id'] }}</bdi></small></td>@foreach(['before','after'] as $side)<td><bdi>{{ $row[$side] === null ? __('po_pricing.unknown_value') : $num($row[$side]) }}</bdi></td>@endforeach</tr>@endforeach
</tbody></table></div>
@else<p>{{ __('po_pricing.comparison_unavailable') }}</p>@endif
<details><summary>{{ __('po_pricing.before') }}</summary><pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $revision->before_snapshot }}</pre></details><details><summary>{{ __('po_pricing.after') }}</summary><pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $revision->snapshot }}</pre></details></details>@endforeach
</section>
@endif
