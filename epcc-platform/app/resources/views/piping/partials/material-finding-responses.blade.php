@php
    $fr=fn($key)=>__('finding_responses.'.$key,[],$lang);
    $responseContext=$findingResponses[$row->id]??['role'=>null,'threads'=>[]];
    $thread=$responseContext['threads'][$findingIndex]??[];
    $runId=$historical['record']->run_id;
    $findingKey=\App\Services\Engineering\MaterialFindingResponses::findingKey($historical['record']->result_sha256,$findingIndex);
    $oldIndex=old('finding_response.finding_index');
    $isOld=old('finding_response.run_id')===$runId && is_scalar($oldIndex) && (string)$oldIndex===(string)$findingIndex;
    $value=function($key,$default=null)use($isOld){
        $v=$isOld?old('finding_response.'.$key,$default):$default;
        return is_scalar($v)||$v===null?$v:$default;
    };
@endphp
<details class="finding-thread" data-finding-thread="{{ $findingIndex }}" style="margin:12px 0;padding:10px;border:1px solid var(--line);border-radius:8px">
<summary>{{ $fr('title') }} · {{ $num(count($thread)) }}</summary>
<p class="spec-help">{{ $fr('notice') }}</p>
@forelse($thread as $note)
<div class="response-entry" data-response="{{ $note->id }}" style="padding:12px 0;border-bottom:1px solid var(--line)">
<p><strong>{{ $fr('sequence') }} {{ $num($note->sequence) }} · {{ $fr($note->kind) }}</strong></p>
<p>{{ $note->actor_name }} · {{ $fr($note->actor_kind) }} @if($note->review_stage) · {{ $fr('stage') }} {{ $num($note->review_stage) }} @endif</p>
<p><bdi>{{ $note->company_id }}</bdi> · {{ \App\Support\JalaliDate::formatWithTime(\Carbon\Carbon::parse($note->created_at)->utc(),$lang) }} UTC</p>
<p class="spec-source" dir="auto">{{ $note->message }}</p>
<p>{{ $fr('spec_number') }}: <bdi>{{ $note->spec_number }}</bdi> · {{ $fr('spec_revision') }}: <bdi>{{ $note->spec_revision }}</bdi> · {{ $fr('spec_clause') }}: <bdi>{{ $note->spec_clause }}</bdi></p>
@if($note->source_quote)<blockquote class="spec-source" dir="auto">{{ $note->source_quote }}</blockquote>@endif
@include('piping.partials.material-finding-dispositions')
</div>
@empty<p class="spec-help">{{ $fr('empty') }}</p>@endforelse
@if($canCreate && $responseContext['role'])
<form method="post" action="{{ $action('/'.$row->id.'/finding-responses') }}" class="spec-grid finding-response-form" style="margin-top:16px">
@csrf
<input type="hidden" name="lang" value="{{ $lang }}">
<input type="hidden" name="finding_response[run_id]" value="{{ $runId }}">
<input type="hidden" name="finding_response[finding_index]" value="{{ $findingIndex }}">
<input type="hidden" name="finding_response[finding_key]" value="{{ $findingKey }}">
<input type="hidden" name="finding_response[request_key]" value="{{ $value('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
<label>{{ $fr('kind') }}<select name="finding_response[kind]" required>
@foreach(($responseContext['role']==='REVIEWER'?['REVIEW_NOTE']:['EXPLANATION','NA_REQUEST']) as $kind)
<option value="{{ $kind }}" @selected($value('kind')===$kind)>{{ $fr($kind) }}</option>
@endforeach</select></label>
<label class="spec-wide">{{ $fr('message') }}<textarea name="finding_response[message]" required maxlength="4000" rows="3">{{ $value('message') }}</textarea></label>
@foreach(['spec_number'=>120,'spec_revision'=>60,'spec_clause'=>120] as $field=>$max)
<label>{{ $fr($field) }}<input name="finding_response[{{ $field }}]" maxlength="{{ $max }}" value="{{ $value($field,$row->{$field}) }}" required></label>
@endforeach
<label class="spec-wide">{{ $fr('source_quote') }}<textarea name="finding_response[source_quote]" maxlength="4000" rows="3">{{ $value('source_quote') }}</textarea></label>
<div class="spec-wide"><button class="spec-button" type="submit">{{ $fr('save') }}</button></div>
</form>
@else<p class="spec-help">{{ $fr('read_only') }}</p>@endif
</details>
