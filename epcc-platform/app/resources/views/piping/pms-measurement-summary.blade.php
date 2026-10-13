<section aria-label="{{ $t('units_title') }}" data-pms-measurements>
<h3>{{ $t('units_title') }}</h3><p class="pms-help">{{ $t('units_display_help') }}</p>
@foreach($measurements as $field=>$measurement)
<div style="border-top:1px solid var(--line);padding:12px 0" data-measurement-summary="{{ $field }}">
<h4>{{ $t($field) }}</h4>
<dl class="pms-pairs">
<dt>{{ $t('units_entered') }}</dt><dd><bdi>{{ $measurement['value'] !== null ? $measurement['value'].' '.$measurement['unit'] : '—' }}</bdi></dd>
<dt>{{ $t('units_canonical') }}</dt><dd><bdi>{{ $measurement['canonical'] !== null ? $measurement['canonical'].' '.$measurement['canonical_unit'] : '—' }}</bdi></dd>
<dt>{{ $t('units_preferred') }}</dt><dd><bdi>{{ $measurement['preferred_value'] !== null ? $measurement['preferred_value'].' '.$measurement['preferred_unit'] : '—' }}</bdi></dd>
</dl>
@if($measurement['legacy'])<p class="pms-warning">{{ $t('units_legacy') }}</p>@endif
</div>
@endforeach
</section>
