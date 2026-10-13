@php($valveExisting=$editing?->valveSpec)
<fieldset id="pms-valve-editor" class="pms-wide" style="border:1px solid var(--line);border-radius:8px;padding:14px">
<legend>{{ $t('valve_title') }}</legend>
<p class="pms-help">{{ $t('valve_help') }}</p>
<div class="pms-grid">
<label>{{ $t('valve_mode') }}<select name="item[valve_mode]">@foreach(['keep','replace','clear'] as $mode)<option value="{{ $mode }}" @selected($i('valve_mode','keep')===$mode)>{{ $t('valve_'.$mode) }}</option>@endforeach</select></label>
<label>{{ $t('valve_trim_key') }}<select name="item[valve_trim_key]" id="pms-valve-trim"><option value="">{{ $t('none') }}</option>
@foreach($valveCatalog['trims'] as $key=>$trim)<option value="{{ $key }}" data-types="{{ implode('|',$trim['valveTypes']) }}" @selected($i('valve_trim_key')===$key)>{{ $trim['standard'] }} · {{ $trim['code'] }} · {{ $trim['name'][$lang]??$trim['name']['en'] }} · {{ implode('/',$trim['valveTypes']) }}{{ $trim['obsolete']?' · '.$t('valve_obsolete'):'' }}</option>@endforeach
</select></label>
@foreach(\App\Services\PmsValveEntryService::TEXT as $field=>$max)
@if($field==='operator')
<label>{{ $t('valve_operator') }}<select name="item[valve_operator]"><option value="">{{ $t('none') }}</option>@foreach($valveCatalog['operators'] as $operator)<option value="{{ $operator }}" @selected($i('valve_operator',$valveExisting?->operator)===$operator)>{{ $operator }}</option>@endforeach</select></label>
@elseif($field==='description_as_written')
<label class="pms-wide">{{ $t('valve_'.$field) }}<textarea name="item[valve_{{ $field }}]" maxlength="{{ $max }}" rows="3">{{ $i('valve_'.$field,$valveExisting?->{$field}) }}</textarea></label>
@else
<label>{{ $t('valve_'.$field) }}<input name="item[valve_{{ $field }}]" maxlength="{{ $max }}" value="{{ $i('valve_'.$field,$valveExisting?->{$field}) }}" @if($field==='construction_standard') list="pms-valve-construction" @endif></label>
@endif
@endforeach
</div>
<p class="pms-help">{{ $t('valve_scope') }}</p>
<datalist id="pms-valve-construction">@foreach($valveCatalog['construction'] as $standard)<option value="{{ $standard['code'] }}" data-types="{{ implode('|',$standard['valveTypes']) }}">{{ implode('/',$standard['valveTypes']) }}</option>@endforeach</datalist>
</fieldset>
<script>
(()=>{const type=document.querySelector('[name="item[qualifier]"]');const filter=()=>{const value=type.value.trim().toUpperCase();document.querySelectorAll('#pms-valve-trim option[data-types],#pms-valve-construction option[data-types]').forEach(o=>{o.hidden=!!value&&!o.dataset.types.split('|').includes(value)&&!o.selected;});};if(type){type.addEventListener('input',filter);filter();}})();
</script>
