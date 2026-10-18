@php $state=$reviewStates[$row->id]; @endphp
@if($row->revises_id)
<p>{{ $r('parent') }}: <bdi>{{ $row->revises_id }}</bdi></p>
<p class="spec-source">{{ $r('correction_reason') }}: {{ $row->correction_reason }}</p>
@endif
@if($state['successor'])<p>{{ $r('successor') }}: <bdi>{{ $state['successor'] }}</bdi></p>@endif
@if($state['run'])
<p>{{ $r('version') }}: {{ $num($state['policy']->version) }}</p>
<ol>@foreach($state['steps'] as $step)<li>{{ $step['label'] }}</li>@endforeach</ol>
@if($state['state']==='IN_REVIEW')<p>{{ $r('current_step') }}: {{ $num(count($state['decisions'])+1) }}</p>@endif
@endif
@if($state['decisions'])
<h4>{{ $r('history') }}</h4>
@foreach($state['decisions'] as $decision)
<p>{{ $r('stage') }} {{ $num($decision->stage) }} · {{ $decision->actor_name }} · {{ $r($decision->decision) }} · {{ \App\Support\JalaliDate::format(\Carbon\Carbon::parse($decision->created_at),$lang) }}</p>
<p class="spec-source" dir="auto">{{ $decision->comment }}</p>
@endforeach
@endif
@if($canCreate)
<div class="spec-meta" style="margin-top:16px">
@if($row->company_id===$user->company_id && !$state['successor'])
<a href="{{ $base.'?'.http_build_query($q+['correct'=>$row->id]) }}">{{ $r('correct') }}</a>
@if($state['state']==='UNREVIEWED' && $reviewPolicy)
<form method="post" action="{{ $action('/'.$row->id.'/submit') }}" class="review-submit">@csrf<input type="hidden" name="lang" value="{{ $lang }}"><button class="spec-button">{{ $r('submit') }}</button></form>
@endif
@endif
</div>
@if($state['can_decide'])
<form method="post" action="{{ $action('/'.$row->id.'/decide') }}" class="spec-grid review-decision" style="margin-top:16px">
@csrf<input type="hidden" name="lang" value="{{ $lang }}"><input type="hidden" name="stage" value="{{ count($state['decisions'])+1 }}">
<label>{{ $r('current_step') }}<select name="decision"><option value="APPROVE">{{ $r('APPROVE') }}</option><option value="RETURN">{{ $r('RETURN') }}</option></select></label>
<label class="spec-wide">{{ $r('comment') }}<textarea name="comment" required maxlength="4000">{{ old('comment') }}</textarea></label>
<div><button class="spec-button">{{ $r('save_decision') }}</button></div>
</form>
@endif
@endif
