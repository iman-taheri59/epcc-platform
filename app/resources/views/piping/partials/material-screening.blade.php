@if(isset($screenings[$row->id]))
@php $screen=$screenings[$row->id]; $s=fn($key)=>__('material_screening.'.$key,[],$lang); @endphp
<section class="spec-card" data-screening="{{ $row->id }}" aria-label="{{ $s('title') }}">
<h4>{{ $s('current_title') }} · {{ $s($screen['status']) }}</h4>
<p class="spec-help">{{ $s('notice') }} {{ $s('current_note') }}</p>
@include('piping.partials.material-screening-findings', ['isHistorical'=>false])</section>
@php $historical=$reviewStates[$row->id]['screeningSnapshot']; @endphp
@if($historical)
@php $screen=$historical['result']; @endphp
<section class="spec-card" data-screening-snapshot="{{ $historical['record']->run_id }}" data-result-sha256="{{ $historical['record']->result_sha256 }}">
<h4>{{ $s('snapshot_title') }} · {{ $s($screen['status']) }}</h4>
<p class="spec-help">{{ $s('snapshot_note') }}</p>
<p>{{ $s('submitted_at') }}: {{ \App\Support\JalaliDate::formatWithTime(\Carbon\Carbon::parse($historical['record']->created_at)->utc(),$lang) }} UTC</p>
@include('piping.partials.material-screening-findings', ['isHistorical'=>true])
<details><summary>{{ $s('snapshot_identity') }}</summary>
<p class="spec-source">{{ $s('run') }}: <bdi>{{ $historical['record']->run_id }}</bdi><br>
{{ $s('definition') }}: <bdi>{{ $historical['record']->definition_id }}</bdi><br>
{{ $s('submitter') }}: <bdi>{{ $historical['record']->created_by }}</bdi><br>
{{ $s('result_hash') }}: <bdi>{{ $historical['record']->result_sha256 }}</bdi><br>
{{ $s('implementation_hash') }}: <bdi>{{ $historical['record']->implementation_sha256 }}</bdi></p>
<pre class="spec-source" dir="ltr">{{ json_encode($historical['input'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>
</details></section>
@elseif($reviewStates[$row->id]['run'])
<p class="spec-help" data-screening-snapshot-missing>{{ $s('legacy_missing') }}</p>
@else
<p class="spec-help">{{ $s('capture_on_submit') }}</p>
@endif
@endif
