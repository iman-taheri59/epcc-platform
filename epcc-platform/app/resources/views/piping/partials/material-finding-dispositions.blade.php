@if($note->kind!=='REVIEW_NOTE')
@php
    $fd=fn($key)=>__('finding_dispositions.'.$key,[],$lang);
    $dispositionHistory=$note->dispositions??[];
    $latestDisposition=$dispositionHistory ? $dispositionHistory[array_key_last($dispositionHistory)] : null;
    $oldDisposition=old('finding_disposition.response_id')===$note->id;
    $dv=function($key,$default=null)use($oldDisposition){$v=$oldDisposition?old('finding_disposition.'.$key,$default):$default;return is_scalar($v)||$v===null?$v:$default;};
@endphp
<section class="finding-disposition" data-disposition-response="{{ $note->id }}" style="border-inline-start:3px solid #8d78d8;padding-inline-start:12px;margin-top:16px">
<h5>{{ $fd('title') }}</h5>
<p class="spec-help">{{ $fd('notice') }}</p>
@if($latestDisposition)
<p data-current-disposition="{{ $latestDisposition->kind }}" data-disposition-id="{{ $latestDisposition->id }}"><strong>{{ $fd('current') }}: {{ $fd($latestDisposition->kind) }}</strong> · {{ $fd('sequence') }} {{ $num($latestDisposition->sequence) }}</p>
<details><summary>{{ $fd('history') }}</summary>
@foreach($dispositionHistory as $decision)
<div data-disposition-event="{{ $decision->id }}" style="border-top:1px solid var(--line);padding:10px 0">
<p><strong>{{ $fd('sequence') }} {{ $num($decision->sequence) }} · {{ $fd($decision->kind) }}</strong></p>
<p>{{ $decision->actor_name }} · {{ $fd('stage') }} {{ $num($decision->review_stage) }} · {{ \App\Support\JalaliDate::formatWithTime(\Carbon\Carbon::parse($decision->created_at)->utc(),$lang) }} UTC</p>
<p class="spec-source" dir="auto">{{ $decision->rationale }}</p>
<p>{{ $fd('spec_number') }}: <bdi>{{ $decision->spec_number }}</bdi> · {{ $fd('spec_revision') }}: <bdi>{{ $decision->spec_revision }}</bdi> · {{ $fd('spec_clause') }}: <bdi>{{ $decision->spec_clause }}</bdi></p>
@if($decision->source_quote)<blockquote class="spec-source" dir="auto">{{ $decision->source_quote }}</blockquote>@endif
@if($decision->supersedes_id)<p class="spec-source">{{ $fd('supersedes_id') }}: <bdi>{{ $decision->supersedes_id }}</bdi><br>{{ $fd('change_reason') }}: {{ $decision->change_reason }}</p>@endif
</div>
@endforeach</details>
@else<p>{{ $fd('pending') }}</p>@endif
@if($canCreate && $responseContext['role']==='REVIEWER' && $note->created_by!==$user->id)
<details class="disposition-editor"><summary>{{ $fd($latestDisposition?'replace':'new') }}</summary>
<p class="spec-help">{{ $fd('citation_note') }}</p>
<form method="post" action="{{ $action('/'.$row->id.'/finding-responses/'.$note->id.'/dispositions') }}" class="spec-grid finding-disposition-form">
@csrf<input type="hidden" name="lang" value="{{ $lang }}">
<input type="hidden" name="finding_disposition[response_id]" value="{{ $note->id }}">
<input type="hidden" name="finding_disposition[run_id]" value="{{ $note->run_id }}">
<input type="hidden" name="finding_disposition[finding_key]" value="{{ $note->finding_key }}">
<input type="hidden" name="finding_disposition[request_key]" value="{{ $dv('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
<input type="hidden" name="finding_disposition[supersedes_id]" value="{{ $dv('supersedes_id',$latestDisposition?->id) }}">
<label>{{ $fd('kind') }}<select name="finding_disposition[kind]" required><option value="">{{ $fd('choose') }}</option>
@foreach(\App\Services\Engineering\MaterialFindingDispositions::options($note->kind) as $kind)<option value="{{ $kind }}" @selected($dv('kind')===$kind)>{{ $fd($kind) }}</option>@endforeach</select></label>
<label class="spec-wide">{{ $fd('rationale') }}<textarea name="finding_disposition[rationale]" rows="3" required maxlength="4000">{{ $dv('rationale') }}</textarea></label>
@foreach(['spec_number'=>120,'spec_revision'=>60,'spec_clause'=>120] as $field=>$max)
<label>{{ $fd($field) }}<input name="finding_disposition[{{ $field }}]" maxlength="{{ $max }}" value="{{ $dv($field,$latestDisposition?->{$field}??$note->{$field}) }}" required></label>
@endforeach
<label class="spec-wide">{{ $fd('source_quote') }}<textarea name="finding_disposition[source_quote]" maxlength="4000" rows="3">{{ $dv('source_quote',$latestDisposition?->source_quote??$note->source_quote) }}</textarea></label>
@if($latestDisposition)<label class="spec-wide">{{ $fd('change_reason') }}<textarea name="finding_disposition[change_reason]" required maxlength="4000">{{ $dv('change_reason') }}</textarea></label>@endif
<div class="spec-wide"><button class="spec-button">{{ $fd('save') }}</button></div>
</form></details>
@else<p class="spec-help">{{ $fd('read_only') }}</p>@endif
</section>
@endif
