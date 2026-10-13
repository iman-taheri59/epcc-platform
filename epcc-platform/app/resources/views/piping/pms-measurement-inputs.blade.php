<div class="pms-wide"><h3>{{ $t('units_title') }}</h3><p class="pms-help">{{ $t('units_input_help') }}</p></div>
@foreach($measurements as $field=>$measurement)
<fieldset class="pms-wide" style="border:1px solid var(--line);border-radius:8px;padding:14px" data-measurement-input="{{ $field }}">
<legend>{{ $t($field) }}</legend>
<div class="pms-grid">
<label>{{ $t('units_action') }}<select name="header[{{ $field }}_mode]">
@foreach(['keep','set','clear'] as $mode)<option value="{{ $mode }}" @selected($h($field.'_mode',$measurement['mode'])===$mode)>{{ $t('units_'.$mode) }}</option>@endforeach
</select></label>
<label>{{ $t('units_entered_value') }}<input type="text" inputmode="decimal" dir="ltr" maxlength="32" name="header[{{ $field }}_value]" value="{{ $h($field.'_value',$measurement['input_value']) }}" aria-describedby="{{ $field }}-unit-help"></label>
<label>{{ $t('units_entered_unit') }}<select name="header[{{ $field }}_unit]">
@php($selectedUnit=$h($field.'_unit',$measurement['input_unit']))
@if(!in_array($selectedUnit,array_column($measurement['options'],'code'),true))<option value="{{ $selectedUnit }}" selected>{{ $selectedUnit }} · {{ $t('units_stored_only') }}</option>@endif
@foreach($measurement['options'] as $option)<option value="{{ $option['code'] }}" @selected($selectedUnit===$option['code'])>{{ $option['label'] }}</option>@endforeach
</select></label>
<p class="pms-help" id="{{ $field }}-unit-help">{{ $t('units_decimal_help') }}</p>
@if($measurement['legacy'])<p class="pms-warning pms-wide">{{ $t('units_legacy') }}</p>@endif
</div>
</fieldset>
@endforeach
