<div class="register-page" data-register-page="{{ $kind }}">
<p>{{ __('procurement_register.page_summary', ['first'=>$num($documents->firstItem() ?? 0), 'last'=>$num($documents->lastItem() ?? 0), 'total'=>$num($documents->total()), 'page'=>$num($documents->currentPage()), 'pages'=>$num($documents->lastPage())]) }}</p>
<nav aria-label="{{ __('procurement_register.'.$kind.'_pages') }}">
<a href="{{ $documents->url(1) }}">{{ __('procurement_register.first_page') }}</a>
@if($documents->previousPageUrl()) · <a rel="prev" href="{{ $documents->previousPageUrl() }}">{{ __('procurement_register.previous_page') }}</a>@endif
@if($documents->nextPageUrl()) · <a rel="next" href="{{ $documents->nextPageUrl() }}">{{ __('procurement_register.next_page') }}</a>@endif
</nav>
</div>
