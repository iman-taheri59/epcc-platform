<div id="pms-special-summary" class="pms-wide">
<h3>{{ $t('special_title') }}</h3>
<p class="pms-help">{{ $t('special_scope') }}</p>
@if(!$specialValid)
<p class="pms-warning">{{ $t('special_legacy') }}</p>
<pre dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $pretty($specialRaw) }}</pre>
@elseif($specialEntries===[])
<p class="pms-help">{{ $t('special_empty') }}</p>
@else
<ol>@foreach($specialEntries as $entry)<li dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $entry }}</li>@endforeach</ol>
@endif
</div>
