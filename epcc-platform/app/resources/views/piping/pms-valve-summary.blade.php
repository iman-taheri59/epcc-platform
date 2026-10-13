<section data-valve-summary><h4>{{ $t('valve_title') }}</h4>
<p class="pms-help">{{ $t('valve_scope') }}</p>
@if($valveData)
<dl class="pms-pairs">@foreach(\App\Services\PmsValveEntryService::TEXT as $field=>$max)<dt>{{ $t('valve_'.$field) }}</dt><dd class="pms-source" dir="auto">{{ $valveData[$field]??'—' }}</dd>@endforeach</dl>
@else<p>{{ $t('valve_empty') }}</p>@endif
</section>
