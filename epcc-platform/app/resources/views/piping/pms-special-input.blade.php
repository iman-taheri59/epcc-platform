<fieldset id="pms-special-editor" class="pms-wide" style="border:1px solid var(--line);border-radius:8px;padding:14px"><legend>{{ $t('special_title') }}</legend>
<label>{{ $t('special_mode') }}<select name="header[special_requirements_mode]">
@foreach(['keep','replace','clear'] as $mode)<option value="{{ $mode }}" @selected($h('special_requirements_mode','keep')===$mode)>{{ $t('special_'.$mode) }}</option>@endforeach
</select></label>
<label>{{ $t('special_text') }}<textarea name="header[special_requirements_text]" rows="5" maxlength="50100">{{ $h('special_requirements_text',implode("\n",$specialEntries)) }}</textarea></label>
<p class="pms-help">{{ $t('special_help') }}</p>
<p class="pms-help">{{ $t('special_scope') }}</p>
@if(!$specialValid)<p class="pms-warning">{{ $t('special_legacy') }}</p>@endif
</fieldset>
