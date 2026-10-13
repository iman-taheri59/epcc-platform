@extends('print.layout')

@php
    $fa = $lang === 'fa';
    $t = [
        'org'     => ['fa' => 'سامانهٔ مدیریت پروژه‌های صنعتی', 'en' => 'Industrial Project Management Platform'],
        'title'   => ['fa' => 'گواهی تکمیل مکانیکی', 'en' => 'Mechanical Completion Certificate'],
        'strap'   => ['fa' => 'صادرشده بر اساس رویهٔ راه‌اندازی و تحویل', 'en' => 'Issued under the commissioning and handover procedure'],
        'number'  => ['fa' => 'شمارهٔ گواهی', 'en' => 'Certificate no.'],
        'project' => ['fa' => 'پروژه', 'en' => 'Project'],
        'subsys'  => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'disc'    => ['fa' => 'رشته', 'en' => 'Discipline'],
        'issued'  => ['fa' => 'تاریخ صدور', 'en' => 'Issued'],
        'issuer'  => ['fa' => 'صادرکننده', 'en' => 'Issued by'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'Status'],
        'draft'   => ['fa' => 'پیش‌نویس — فاقد اعتبار', 'en' => 'DRAFT — not valid'],
        'issuedS' => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'decl'    => ['fa' => 'اعلامیه', 'en' => 'Declaration'],
        'declbody'=> ['fa' => 'بدین‌وسیله گواهی می‌شود که زیرسیستم یادشده مطابق مدارک طراحی و مشخصات فنی پروژه، نصب و آزمون شده و از نظر مکانیکی تکمیل است.',
                      'en' => 'This is to certify that the subsystem identified above has been installed and tested in accordance with the project design documents and specifications, and is mechanically complete.'],
        'punch'   => ['fa' => 'وضعیت نواقص', 'en' => 'Punch list status'],
        'ptotal'  => ['fa' => 'کل نواقص', 'en' => 'Total items'],
        'pclosed' => ['fa' => 'بسته‌شده', 'en' => 'Closed'],
        'popenA'  => ['fa' => 'باز — ردهٔ A', 'en' => 'Open — category A'],
        'clean'   => ['fa' => 'در زمان صدور، هیچ نقص باز ردهٔ A وجود نداشت.', 'en' => 'At issue there were no open category-A items.'],
        'excep'   => ['fa' => 'استثنای تأییدشدهٔ کارفرما', 'en' => 'Owner-approved exception'],
        'excbody' => ['fa' => 'این گواهی با وجود نواقص باز ردهٔ A و بر پایهٔ استثنای زیر صادر شده است:',
                      'en' => 'This certificate was issued over open category-A items on the basis of the following exception:'],
        'excby'   => ['fa' => 'تأییدکننده', 'en' => 'Approved by'],
        'excat'   => ['fa' => 'تاریخ تأیید', 'en' => 'Approved on'],
        'excwhy'  => ['fa' => 'دلیل', 'en' => 'Reason'],
        'excitems'=> ['fa' => 'نواقص مشمول استثنا در زمان صدور', 'en' => 'Items waived at issue'],
        'pnum'    => ['fa' => 'شماره', 'en' => 'Number'],
        'pcat'    => ['fa' => 'رده', 'en' => 'Cat.'],
        'pstate'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'pdesc'   => ['fa' => 'شرح', 'en' => 'Description'],
        'sigc'    => ['fa' => 'پیمانکار', 'en' => 'Contractor'],
        'sigm'    => ['fa' => 'مدیریت پیمان', 'en' => 'Management contractor'],
        'sigo'    => ['fa' => 'کارفرما', 'en' => 'Employer'],
        'signame' => ['fa' => 'نام، امضا و تاریخ', 'en' => 'Name, signature and date'],
        'foot'    => ['fa' => 'این سند به‌صورت الکترونیکی تولید شده است.', 'en' => 'This document was generated electronically.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $when = fn ($d) => \App\Support\JalaliDate::formatWithTime($d, $lang);
@endphp

@section('document')
<header class="doc">
  <div class="org">{{ $L('org') }}</div>
  <h1>{{ $L('title') }}</h1>
  <div class="strap">{{ $L('strap') }}</div>
</header>

<table class="meta">
  <tr><td class="k">{{ $L('number') }}</td><td><span class="mono">{{ $certificate->number }}</span></td></tr>
  <tr><td class="k">{{ $L('project') }}</td><td>{{ $project->name($lang) }} (<span class="mono">{{ $project->code }}</span>)</td></tr>
  <tr><td class="k">{{ $L('subsys') }}</td><td>{{ $subsystem->name($lang) }} (<span class="mono">{{ $subsystem->code }}</span>)</td></tr>
  <tr><td class="k">{{ $L('disc') }}</td><td>{{ $subsystem->discipline }}</td></tr>
  <tr><td class="k">{{ $L('state') }}</td><td>{{ $certificate->isIssued() ? $L('issuedS') : $L('draft') }}</td></tr>
  <tr><td class="k">{{ $L('issued') }}</td><td>{{ $when($certificate->issued_at) }}</td></tr>
  @if($issuer)
    <tr><td class="k">{{ $L('issuer') }}</td><td>{{ $issuer->name }}@if($issuerCompany) — {{ $issuerCompany->name($lang) }}@endif</td></tr>
  @endif
</table>

<h2>{{ $L('decl') }}</h2>
<p>{{ $L('declbody') }}</p>

<h2>{{ $L('punch') }}</h2>
{{-- Frozen at issue, live only while DRAFT. These counts justify the handover,
     so on an issued certificate they must describe the moment it was issued —
     not today's register. See McCertificate::punchSummary(). --}}
@php($summary = $certificate->punchSummary($items, $openA))
<table class="meta">
  <tr><td class="k">{{ $L('ptotal') }}</td><td>{{ $num($summary['total']) }}</td></tr>
  <tr><td class="k">{{ $L('pclosed') }}</td><td>{{ $num($summary['closed']) }}</td></tr>
  <tr><td class="k">{{ $L('popenA') }}</td><td>{{ $num($summary['open_blocking']) }}</td></tr>
</table>

@if($certificate->hasApprovedException())
  {{-- The exception is the single most consequential fact on this page, so it
       is boxed and flagged rather than buried in a metadata row. --}}
  <div class="note warn">
    <div class="t">{{ $L('excep') }}</div>
    <p style="margin:2mm 0">{{ $L('excbody') }}</p>
    <table class="meta" style="margin-bottom:0">
      <tr><td class="k">{{ $L('excby') }}</td><td>{{ $exceptionApprover?->name ?? '—' }}</td></tr>
      <tr><td class="k">{{ $L('excat') }}</td><td>{{ $when($certificate->exception_approved_at) }}</td></tr>
      <tr><td class="k">{{ $L('excwhy') }}</td><td>{{ $certificate->exception_reason }}</td></tr>
    </table>
  </div>

  <h2>{{ $L('excitems') }}</h2>
  {{-- The stored jsonb snapshot, NOT a live query: this must show what the
       owner actually waived at the moment of approval. --}}
  <table class="grid">
    <thead><tr>
      <th style="width:32mm">{{ $L('pnum') }}</th>
      <th style="width:14mm">{{ $L('pcat') }}</th>
      <th style="width:26mm">{{ $L('pstate') }}</th>
      <th>{{ $L('pdesc') }}</th>
    </tr></thead>
    <tbody>
    @foreach($certificate->exception_items ?? [] as $w)
      <tr>
        <td><span class="mono">{{ $w['number'] ?? '—' }}</span></td>
        <td>{{ \App\Support\PunchCategory::shortLabel($w['category'] ?? '', $lang) }}</td>
        <td>{{ \App\Support\PunchState::label($w['state'] ?? '')[$lang] ?? ($w['state'] ?? '—') }}</td>
        <td>{{ $fa ? ($w['description_fa'] ?? '') : ($w['description_en'] ?? '') }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
@else
  <div class="note">{{ $L('clean') }}</div>
@endif

<table class="sigs">
  <tr>
    <td><div class="box"></div><div class="lbl">{{ $L('sigc') }}<br>{{ $L('signame') }}</div></td>
    <td><div class="box"></div><div class="lbl">{{ $L('sigm') }}<br>{{ $L('signame') }}</div></td>
    <td><div class="box"></div><div class="lbl">{{ $L('sigo') }}<br>{{ $L('signame') }}</div></td>
  </tr>
</table>

<footer class="doc">
  <span><span class="mono">{{ $certificate->number }}</span> · {{ $project->code }}</span>
  <span>{{ $L('foot') }} {{ $generatedAt }}</span>
</footer>
@endsection
