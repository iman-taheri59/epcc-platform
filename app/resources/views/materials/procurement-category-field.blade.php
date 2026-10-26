<label>{{ __('procurement_category.label') }}
<select name="{{ $entryRoot }}[material_category]" required>
<option value="">{{ __('procurement_category.choose') }}</option>
@foreach(\App\Models\MaterialItem::categories() as $category)
<option value="{{ $category }}" @selected($safeOld($entryRoot.'.material_category') === $category)>{{ __('procurement_category.'.$category) }}</option>
@endforeach
</select></label>
<p class="help">{{ __('procurement_category.help') }}</p>
