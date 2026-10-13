@php
$ndtTarget=$class->id.':'.($ndtEditing?->id??'new');
$ndtValue=function($field,$fallback=null)use($ndtTarget){$value=old('ndt.target')===$ndtTarget?old('ndt.'.$field,$fallback):$fallback;return is_scalar($value)||$value===null?$value:$fallback;};
@endphp
<section class="pms-card" id="pms-ndt">
<h2>{{ $t('ndt_title') }}</h2>
<p class="pms-help">{{ $t('ndt_scope') }}</p>
@if($canWrite && (!$ndtEditing || $ndtEditing->company_id===$user->company_id))
<details @if($ndtEditing || $errors->has('ndt.*') || $errors->has('ndt')) open @endif><summary>{{ $t($ndtEditing?'ndt_edit':'ndt_add') }}</summary>
<p class="pms-help">{{ $t('ndt_help') }}</p>
<form method="post" action="{{ $url('/'.$class->id.'/ndt') }}" id="pms-ndt-form">@csrf
<input type="hidden" name="ndt[target]" value="{{ $ndtTarget }}">
<input type="hidden" name="ndt[id]" value="{{ $ndtEditing?->id }}">
<input type="hidden" name="ndt[version]" value="{{ $ndtValue('version',$class->entry_version) }}">
<div class="pms-grid">
@foreach(\App\Models\PipingClassNdtRequirement::FIELDS as $field=>$limit)
<label @class(['pms-wide'=>$field!=='method'])>{{ $t('ndt_'.$field) }}@if(in_array($field,\App\Models\PipingClassNdtRequirement::REQUIRED,true)) * @endif
<textarea name="ndt[{{ $field }}]" rows="{{ $field==='method'?1:2 }}" maxlength="{{ $limit }}" dir="auto" @required(in_array($field,\App\Models\PipingClassNdtRequirement::REQUIRED,true))>{{ $ndtValue($field,$ndtEditing?->{$field}) }}</textarea></label>
@endforeach
<label class="pms-wide">{{ $t('reason') }} *<textarea name="ndt[reason]" maxlength="4000" rows="2" required>{{ $ndtValue('reason') }}</textarea></label>
</div><button class="pms-save">{{ $t('save') }}</button>
@if($ndtEditing)<a href="{{ $url('/'.$class->id) }}#pms-ndt">{{ $t('ndt_cancel') }}</a>@endif
</form></details>
@endif
@forelse($class->ndtRequirements as $requirement)
<article data-ndt-requirement="{{ $requirement->id }}" style="border-top:1px solid var(--line);margin-top:18px;padding-top:12px">
<h3><bdi>{{ $requirement->method }}</bdi></h3>
<p class="pms-help"><bdi>{{ $requirement->id }}</bdi></p>
@if($canWrite && $requirement->company_id===$user->company_id)<a href="{{ $url('/'.$class->id,['ndt'=>$requirement->id]) }}#pms-ndt">{{ $t('ndt_edit') }}</a>@endif
@if($requirement->missingDetails())<p class="pms-warning" data-ndt-incomplete>{{ $t('ndt_missing') }}: {{ implode('، ',array_map(fn($field)=>$t('ndt_'.$field),$requirement->missingDetails())) }}</p>@endif
<dl class="pms-pairs">@foreach(\App\Models\PipingClassNdtRequirement::FIELDS as $field=>$limit)<dt>{{ $t('ndt_'.$field) }}</dt><dd class="pms-source" dir="auto">{{ $requirement->{$field}??$t('ndt_not_recorded') }}</dd>@endforeach</dl>
</article>
@empty<p class="pms-help">{{ $t('ndt_empty') }}</p>@endforelse
</section>
