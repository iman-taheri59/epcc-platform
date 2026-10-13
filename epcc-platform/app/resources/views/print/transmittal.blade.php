@extends('print.layout')

@php
    $fa = $lang === 'fa';
    $t = [
        'org'     => ['fa' => 'سامانهٔ مدیریت پروژه‌های صنعتی', 'en' => 'Industrial Project Management Platform'],
        'title'   => ['fa' => 'نامهٔ ارسال مدارک', 'en' => 'Document Transmittal'],
        'strap'   => ['fa' => 'سند رسمی ارسال و رسید مدارک', 'en' => 'Formal record of issue and receipt'],
        'number'  => ['fa' => 'شمارهٔ نامه', 'en' => 'Transmittal no.'],
        'project' => ['fa' => 'پروژه', 'en' => 'Project'],
        'subject' => ['fa' => 'موضوع', 'en' => 'Subject'],
        'purpose' => ['fa' => 'هدف ارسال', 'en' => 'Purpose'],
        'from'    => ['fa' => 'فرستنده', 'en' => 'From'],
        'vendor'  => ['fa' => 'وندور', 'en' => 'Vendor'],
        'vref'    => ['fa' => 'شمارهٔ نامهٔ وندور', 'en' => "Vendor's reference"],
        'recby'   => ['fa' => 'ثبت‌شده توسط', 'en' => 'Recorded by'],
        'issued'  => ['fa' => 'تاریخ صدور', 'en' => 'Issued'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'Status'],
        'draft'   => ['fa' => 'پیش‌نویس — ارسال نشده', 'en' => 'DRAFT — not issued'],
        'issuedS' => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'notes'   => ['fa' => 'توضیحات', 'en' => 'Notes'],
        'rcp'     => ['fa' => 'گیرندگان', 'en' => 'Recipients'],
        'rcompany'=> ['fa' => 'شرکت', 'en' => 'Company'],
        'rack'    => ['fa' => 'تأیید دریافت', 'en' => 'Acknowledged'],
        'rdue'    => ['fa' => 'مهلت پاسخ', 'en' => 'Response due'],
        'pending' => ['fa' => 'در انتظار', 'en' => 'Pending'],
        'overdue' => ['fa' => 'سررسید گذشته', 'en' => 'Overdue'],
        'none'    => ['fa' => 'ندارد', 'en' => 'None'],
        'docs'    => ['fa' => 'مدارک ارسالی', 'en' => 'Documents transmitted'],
        'dnum'    => ['fa' => 'شمارهٔ مدرک', 'en' => 'Document no.'],
        'drev'    => ['fa' => 'بازنگری', 'en' => 'Rev'],
        'dstate'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'dsuit'   => ['fa' => 'کد مناسبت', 'en' => 'Suitability'],
        'integ'   => ['fa' => 'اصالت محتوا', 'en' => 'Content integrity'],
        'integb'  => ['fa' => 'تمام فایل‌های این بسته به همراه اثر انگشت SHA-256 در فایل MANIFEST.csv فهرست شده‌اند. برای اطمینان از تغییرنکردن محتوا پس از صدور، اثر انگشت‌ها را بررسی کنید.',
                      'en' => 'Every file in this package is listed in MANIFEST.csv with its SHA-256 fingerprint as issued. Verify the fingerprints to confirm the contents have not changed since issue.'],
        'sigs'    => ['fa' => 'فرستنده', 'en' => 'Issued by'],
        'sigr'    => ['fa' => 'گیرنده', 'en' => 'Received by'],
        'signame' => ['fa' => 'نام، امضا و تاریخ', 'en' => 'Name, signature and date'],
        'foot'    => ['fa' => 'این سند به‌صورت الکترونیکی تولید شده است.', 'en' => 'This document was generated electronically.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $when = fn ($d) => \App\Support\JalaliDate::formatWithTime($d, $lang);
@endphp

@section('document')
<header class="doc">
  <div class="org">{{ $L('org') }}</div>
  <h1>{{ $L('title') }}</h1>
  <div class="strap">{{ $L('strap') }}</div>
</header>

<table class="meta">
  <tr><td class="k">{{ $L('number') }}</td><td><span class="mono">{{ $transmittal->number }}</span></td></tr>
  <tr><td class="k">{{ $L('project') }}</td><td>{{ $project->name($lang) }} (<span class="mono">{{ $project->code }}</span>)</td></tr>
  <tr><td class="k">{{ $L('subject') }}</td><td>{{ $fa ? $transmittal->subject_fa : $transmittal->subject_en }}</td></tr>
  <tr><td class="k">{{ $L('purpose') }}</td><td>{{ $transmittal->purposeLabel($lang) }}</td></tr>
  {{-- On a vendor letter the originator and the recorder are different
       companies, and the sheet must not imply the EPC wrote it. --}}
  @if($transmittal->isVendor())
    <tr><td class="k">{{ $L('vendor') }}</td>
        <td>{{ $transmittal->vendor?->name($lang) ?? '—' }}
            (<span class="mono">{{ $transmittal->vendor?->code }}</span>)</td></tr>
    @if($transmittal->vendor_reference)
      <tr><td class="k">{{ $L('vref') }}</td>
          <td><span class="mono">{{ $transmittal->vendor_reference }}</span></td></tr>
    @endif
    <tr><td class="k">{{ $L('recby') }}</td><td>{{ $sender?->name($lang) ?? '—' }}</td></tr>
  @else
    <tr><td class="k">{{ $L('from') }}</td><td>{{ $sender?->name($lang) ?? '—' }}</td></tr>
  @endif
  <tr><td class="k">{{ $L('state') }}</td><td>{{ $transmittal->issued_at ? $L('issuedS') : $L('draft') }}</td></tr>
  <tr><td class="k">{{ $L('issued') }}</td><td>{{ $when($transmittal->issued_at) }}</td></tr>
  @if($transmittal->notes)
    <tr><td class="k">{{ $L('notes') }}</td><td>{{ $transmittal->notes }}</td></tr>
  @endif
</table>

<h2>{{ $L('rcp') }}</h2>
<table class="grid">
  <thead><tr>
    <th>{{ $L('rcompany') }}</th>
    <th style="width:38mm">{{ $L('rack') }}</th>
    <th style="width:34mm">{{ $L('rdue') }}</th>
  </tr></thead>
  <tbody>
  @foreach($transmittal->recipients as $r)
    <tr>
      <td><span class="mono">{{ $r->company?->code }}</span> — {{ $r->company?->name($lang) }}</td>
      <td>{{ $r->acknowledged_at ? $when($r->acknowledged_at) : $L('pending') }}</td>
      <td>{{ $r->response_due_at ? $when($r->response_due_at) : $L('none') }}</td>
    </tr>
  @endforeach
  </tbody>
</table>

<h2>{{ $L('docs') }}</h2>
{{-- Snapshot columns, not live joins: a transmittal must show what was sent,
     even after the document has moved on to a later revision. --}}
<table class="grid">
  <thead><tr>
    <th>{{ $L('dnum') }}</th>
    <th style="width:20mm">{{ $L('drev') }}</th>
    <th style="width:30mm">{{ $L('dstate') }}</th>
    <th style="width:26mm">{{ $L('dsuit') }}</th>
  </tr></thead>
  <tbody>
  @foreach($transmittal->items as $i)
    <tr>
      <td><span class="mono">{{ $i->number_at_issue }}</span></td>
      <td><span class="mono">{{ $i->revision_at_issue }}</span></td>
      <td>{{ $i->state_at_issue }}</td>
      <td><span class="mono">{{ $i->suitability_at_issue ?? '—' }}</span></td>
    </tr>
  @endforeach
  </tbody>
</table>

<div class="note">
  <strong>{{ $L('integ') }}</strong><br>
  {{ $L('integb') }}
</div>

<table class="sigs">
  <tr>
    <td><div class="box"></div><div class="lbl">{{ $L('sigs') }}<br>{{ $L('signame') }}</div></td>
    <td><div class="box"></div><div class="lbl">{{ $L('sigr') }}<br>{{ $L('signame') }}</div></td>
    <td></td>
  </tr>
</table>

<footer class="doc">
  <span><span class="mono">{{ $transmittal->number }}</span> · {{ $project->code }}</span>
  <span>{{ $L('foot') }} {{ $generatedAt }}</span>
</footer>
@endsection
