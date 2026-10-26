@extends('print.layout')
@php
    $title = __('inspection_notifications.title').' — '.$notice->number;
@endphp
@section('document')
<style>
.notification .mono{unicode-bidi:isolate;overflow-wrap:anywhere}
.notification .text-block{white-space:pre-wrap;overflow-wrap:anywhere;unicode-bidi:plaintext;margin:2mm 0 5mm;line-height:1.9}
.notification .text-block[dir="rtl"]{font-family:Vazirmatn,Tahoma,sans-serif}
.notification h2{break-after:avoid}
.notification .uuid{font-size:8pt}
</style>
<article class="notification">
@include('inspection-notifications.cancellation')
<header class="doc"><div class="org">{{ $snapshot['sender_name_'.$lang] }} — <span class="mono">{{ $snapshot['sender_code'] }}</span></div>
<h1>{{ __('inspection_notifications.title') }}</h1><div class="strap">{{ __('inspection_notifications.pdf_subtitle') }}</div></header>
<table class="meta">
<tr><td class="k">{{ __('inspection_notifications.pdf_number') }}</td><td><span class="mono">{{ $notice->number }}</span></td></tr>
<tr><td class="k">{{ __('inspection_notifications.pdf_project') }}</td><td>@if($snapshot['has_project_snapshot']){{ $snapshot['project_name_'.$lang] }} (<span class="mono">{{ $snapshot['project_code'] }}</span>)@else<span class="mono uuid">{{ $notice->project_id }}</span>@endif</td></tr>
<tr><td class="k">{{ __('inspection_notifications.state') }}</td><td>{{ ($cancellation ?? null) ? __('inspection_notifications.cancelled') : __('inspection_notifications.pdf_issued_state') }} / {{ __('inspection_notifications.version') }} {{ $notice->version }}</td></tr>
<tr><td class="k">{{ __('inspection_notifications.issued_at') }}</td><td><span class="mono">{{ $notice->issued_at }}</span></td></tr>
<tr><td class="k">{{ __('inspection_notifications.sender') }}</td><td>{{ $snapshot['issuer_name'] }}</td></tr>
<tr><td class="k">{{ __('inspection_notifications.activity_window') }}</td><td>@include('inspection-notifications.schedule', ['notice'=>$notice, 'originalOnly'=>true])</td></tr>
</table>
@if(!$snapshot['has_project_snapshot'])<div class="note">{{ __('inspection_notifications.pdf_project_unknown') }}</div>@endif
<h2>{{ __('inspection_notifications.work') }}</h2><div class="text-block">{{ $notice->work_reference }}</div>
<h2>{{ __('inspection_notifications.location') }}</h2><div class="text-block">{{ $notice->location }}</div>
<h2>{{ __('inspection_notifications.pdf_source') }}</h2>
<table class="meta">
<tr><td class="k">{{ __('inspection_notifications.pdf_document_revision') }}</td><td><span class="mono">{{ $snapshot['document_number'] }} / {{ $snapshot['revision'] }}</span></td></tr>
<tr><td class="k">{{ __('inspection_notifications.revision_id') }}</td><td><span class="mono uuid">{{ $snapshot['revision_id'] }}</span></td></tr>
<tr><td class="k">{{ __('inspection_notifications.pdf_cde') }}</td><td><span class="mono">{{ $snapshot['cde_state'] }} / {{ $snapshot['suitability'] ?? '—' }}</span></td></tr>
<tr><td class="k">{{ __('inspection_notifications.point') }}</td><td><span class="mono">{{ $snapshot['stage_sequence'] }} / {{ $snapshot['point'] }}</span></td></tr>
<tr><td class="k">{{ __('inspection_notifications.stage_id') }}</td><td><span class="mono uuid">{{ $notice->stage_id }}</span></td></tr>
</table>
@foreach(['en','fa'] as $textLang)
@if($snapshot['description_'.$textLang] !== null && $snapshot['description_'.$textLang] !== '')
<h2>{{ __('inspection_notifications.'.$textLang) }}</h2><div class="text-block" dir="{{ $textLang==='fa'?'rtl':'ltr' }}">{{ $snapshot['description_'.$textLang] }}</div>
@endif
@endforeach
<h2>{{ __('inspection_notifications.recipients') }}</h2><table class="grid"><thead><tr><th>{{ __('inspection_notifications.recipients') }}</th></tr></thead><tbody>
@foreach($snapshot['recipients'] as $recipient)<tr><td><span class="mono">{{ $recipient['code'] }}</span> — {{ $recipient['name_'.$lang] }}</td></tr>@endforeach
</tbody></table>
@if($notice->purchase_order_id)<h2>{{ __('inspection_notifications.pdf_po_reference') }}</h2><div class="mono uuid">{{ $notice->purchase_order_id }}</div>@endif
@foreach(['en','fa'] as $textLang)
@if($notice->{'notes_'.$textLang} !== null && $notice->{'notes_'.$textLang} !== '')
<h2>{{ __('inspection_notifications.'.$textLang) }}</h2><div class="text-block" dir="{{ $textLang==='fa'?'rtl':'ltr' }}">{{ $notice->{'notes_'.$textLang} }}</div>
@endif
@endforeach
<div class="note">{{ __('inspection_notifications.itp_scope') }}</div>
@include('inspection-notifications.acknowledgments')
@if(isset($prerequisitePrint))
@include('print.inspection-notification-prerequisites')
@endif
<footer class="doc"><span>{{ __('inspection_notifications.pdf_footer') }}<br><span class="mono">{{ $notice->number }}</span></span></footer>
</article>
@endsection
