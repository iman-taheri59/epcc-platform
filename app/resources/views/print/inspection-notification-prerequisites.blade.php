<section id="inspection-prerequisite-pdf" style="break-before:page">
<h1>{{ __('inspection_notifications.pdf_prerequisites_title') }}</h1>
<p>{{ __('inspection_notifications.pdf_prerequisites_hint') }}</p>
<p>{{ __('inspection_notifications.pdf_prerequisites_time') }}: <bdi dir="ltr">{{ $prerequisitePrintedAt }}</bdi></p>
@include('inspection-notifications.prerequisite-stage')
@forelse($prerequisitePrint['records'] as $prerequisite)
<article id="prerequisite-{{ $prerequisite->id }}">
<h2>{{ $prerequisite->company_code }} — {{ $prerequisite->{'company_name_'.$lang} }}</h2>
<p class="uuid mono">{{ $prerequisite->id }}</p>
<h3>{{ __('inspection_notifications.prerequisite_after') }} — {{ __('inspection_notifications.version') }} {{ $prerequisite->version }}</h3>
@include('print.inspection-prerequisite-snapshot', ['record' => (array) $prerequisite])
<h3>{{ __('inspection_notifications.prerequisite_history') }}</h3>
@foreach($prerequisitePrint['history']->get($prerequisite->id, collect()) as $event)
@php
$before=$event->before_snapshot ? json_decode($event->before_snapshot,true,512,JSON_THROW_ON_ERROR) : null;
$after=json_decode($event->after_snapshot,true,512,JSON_THROW_ON_ERROR);
@endphp
<div class="prerequisite-event" style="border-top:1px solid #bbb;margin-top:4mm">
<h3>{{ __('inspection_notifications.version') }} {{ $event->version }} — {{ __('inspection_notifications.prerequisite_action_'.$event->action) }}</h3>
@if($before)
<h4>{{ __('inspection_notifications.prerequisite_before') }}</h4>
@include('print.inspection-prerequisite-snapshot', ['record' => $before])
@endif
<h4>{{ __('inspection_notifications.prerequisite_after') }}</h4>
@include('print.inspection-prerequisite-snapshot', ['record' => $after])
</div>
@endforeach
</article>
@empty
<p>{{ __('inspection_notifications.pdf_prerequisites_empty') }}</p>
@endforelse
</section>
