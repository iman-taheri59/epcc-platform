<input type="hidden" name="component_type" value="{{ $family }}">
@foreach(['qualifier'=>80,'size_designation'=>128,'reference_standard'=>200,'material_spec'=>200,'material_grade'=>120] as $field=>$max)
<label>{{ $m($field) }}<input name="{{ $field }}" maxlength="{{ $max }}" value="{{ old($field,$editing?->{$field}) }}" @required($field!=='material_grade')></label>
@endforeach
<label class="spec-wide">{{ $m('declaration_reason') }}<textarea name="declaration_reason" rows="2" maxlength="4000" required>{{ old('declaration_reason',$editing?->declaration_reason) }}</textarea></label>
<p class="spec-wide spec-help">{{ $m('properties') }}</p>
@foreach(\App\Services\ProjectMaterialSchema::FIELDS[$family] as $field)
<label>{{ $m($field) }}<input name="properties[{{ $field }}]" value="{{ old('properties.'.$field,($editing?->properties ?? [])[$field] ?? '') }}" @if(str_ends_with($field,'_mm')) type="number" dir="ltr" min="0.0001" max="9999999999.9999" step="0.0001" @else maxlength="200" @endif></label>
@endforeach
