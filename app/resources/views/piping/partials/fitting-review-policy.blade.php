<section class="spec-card" id="review-policy">
<h2>{{ $r('workflow') }} · {{ $m($family) }}</h2>
<p class="spec-help">{{ $m('policy_scope') }}</p>
<p class="spec-help">{{ $r('policy_note') }}</p>
@if($reviewPolicy)
<p>{{ $r('version') }}: {{ $num($reviewPolicy->version) }}</p>
<ol>@foreach($reviewSteps as $step)<li>{{ $step['label'] }}</li>@endforeach</ol>
@else<p class="spec-error">{{ $r('not_configured') }}</p>@endif
@if($canConfigure && $canCreate)
<details><summary>{{ $r('configure') }}</summary>
<p class="spec-help">{{ $r('config_note') }}</p>
<form method="post" action="{{ $action('/review-policy') }}" class="spec-grid" id="review-policy-form">
@csrf<input type="hidden" name="lang" value="{{ $lang }}">
@for($i=0;$i<5;$i++)
<label>{{ $r('stage') }} {{ $num($i+1) }}
<select name="stages[{{ $i }}]" @required($i===0)>
<option value="">{{ $r('none') }}</option>
@foreach($reviewOptions as $value=>$label)<option value="{{ $value }}" @selected(old('stages.'.$i,$reviewSteps[$i]['selector']??'')===$value)>{{ $label }}</option>@endforeach
</select></label>
@endfor
<div class="spec-wide"><button class="spec-button">{{ $r('configure') }}</button></div>
</form></details>
@endif
</section>
