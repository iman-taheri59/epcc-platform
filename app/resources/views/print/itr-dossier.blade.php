@extends('print.layout')

@php
    $fa = ($lang ?? 'fa') === 'fa';
    $t = [
        'org'      => ['fa' => 'سامانهٔ مدیریت پروژه‌های صنعتی', 'en' => 'Industrial Project Management Platform'],
        'title'    => ['fa' => 'کتابچهٔ رکورد بازرسی و آزمون (ITR)', 'en' => 'Inspection & Test Record Dossier (ITR)'],
        'strap'    => ['fa' => 'گردآوری شواهد کیفی یک بستهٔ آزمون — صریح، نه گواهی', 'en' => 'Quality-evidence compilation for one test pack — explicit, not a certificate'],
        'warn'     => ['fa' => 'این سند گواهی نیست؛ گردآوری رکوردهای موجود است و فهرست نقص‌ها صریحاً درج شده است.', 'en' => 'This document is NOT a certificate; it compiles the records that exist and enumerates the gaps explicitly.'],
        'pack'     => ['fa' => 'بستهٔ آزمون', 'en' => 'Test pack'],
        'subsys'   => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'state'    => ['fa' => 'وضعیت بسته', 'en' => 'Pack state'],
        'pressure' => ['fa' => 'فشار آزمون (برنامه)', 'en' => 'Test pressure (plan)'],
        'none'     => ['fa' => 'ندارد', 'en' => 'None'],
        'compiled' => ['fa' => 'زمان گردآوری/انجماد', 'en' => 'Compiled/frozen at'],
        'digest'   => ['fa' => 'اثر انگشت (sha256)', 'en' => 'Digest (sha256)'],
        'complete' => ['fa' => 'وضعیت کامل‌بودن', 'en' => 'Completeness'],
        'cyes'     => ['fa' => 'کامل — هیچ فقرهٔ غایبی نیست', 'en' => 'Complete — no missing items'],
        'cno'      => ['fa' => 'ناقص — فقرات غایب در زیر شمرده شده‌اند', 'en' => 'Incomplete — missing items are enumerated below'],
        'counts'   => ['fa' => 'شمارش رکوردها', 'en' => 'Record counts'],
        'clines'   => ['fa' => 'خطوط', 'en' => 'Lines'],
        'cjoints'  => ['fa' => 'مفاصل', 'en' => 'Joints'],
        'cwelds'   => ['fa' => 'رکوردهای جوش', 'en' => 'Weld records'],
        'cndt'     => ['fa' => 'نتایج NDT', 'en' => 'NDT results'],
        'ctests'   => ['fa' => 'نتایج آزمون بسته', 'en' => 'Pack test results'],
        'missing'  => ['fa' => 'فقرات غایب (صریح)', 'en' => 'Missing items (explicit)'],
        'mlines'   => ['fa' => 'خطوط آزادنشده (RELEASED نیستند)', 'en' => 'Lines not released (not RELEASED)'],
        'munfit'   => ['fa' => 'مفاصل fit-نشده', 'en' => 'Joints not fitted'],
        'mweld'    => ['fa' => 'مفاصل بدون رکورد جوش', 'en' => 'Joints without a weld record'],
        'mndt'     => ['fa' => 'مفاصل بدون نتیجهٔ NDT', 'en' => 'Joints without an NDT result'],
        'mopen'    => ['fa' => 'مفاصل با مردودی باز (آخرین آزمون روش، REJECTED)', 'en' => 'Joints with an open rejection (latest per-method result is REJECTED)'],
        'mpass'    => ['fa' => 'بسته هیچ نتیجهٔ آزمون PASS ندارد', 'en' => 'The pack has no PASS test result'],
        'lines'    => ['fa' => 'خطوط بسته', 'en' => 'Pack lines'],
        'lnum'     => ['fa' => 'شمارهٔ خط', 'en' => 'Line number'],
        'lsize'    => ['fa' => 'سایز', 'en' => 'Size'],
        'lrel'     => ['fa' => 'وضعیت آزادسازی', 'en' => 'Release state'],
        'joints'   => ['fa' => 'مفاصل، جوش و آزمون‌ها', 'en' => 'Joints, welds and examinations'],
        'jseq'     => ['fa' => 'مفصل', 'en' => 'Joint'],
        'jstate'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'jfit'     => ['fa' => 'fit-up', 'en' => 'fit-up'],
        'jweld'    => ['fa' => 'جوش', 'en' => 'Weld'],
        'jndt'     => ['fa' => 'NDT', 'en' => 'NDT'],
        'wkind'    => ['fa' => 'نوع', 'en' => 'Kind'],
        'wby'      => ['fa' => 'کد مجری', 'en' => 'Stamp code'],
        'wat'      => ['fa' => 'زمان', 'en' => 'Time'],
        'wmode'    => ['fa' => 'منشأ', 'en' => 'Provenance'],
        'nmethod'  => ['fa' => 'روش', 'en' => 'Method'],
        'nresult'  => ['fa' => 'نتیجه', 'en' => 'Result'],
        'nby'      => ['fa' => 'کد مجری', 'en' => 'Stamp code'],
        'nat'      => ['fa' => 'زمان', 'en' => 'Time'],
        'nreason'  => ['fa' => 'شرح نقص', 'en' => 'Defect description'],
        'results'  => ['fa' => 'نتایج آزمون بسته', 'en' => 'Pack test results'],
        'rtype'    => ['fa' => 'نوع آزمون', 'en' => 'Test type'],
        'rmed'     => ['fa' => 'سیال', 'en' => 'Medium'],
        'rpress'   => ['fa' => 'فشار', 'en' => 'Pressure'],
        'rhold'    => ['fa' => 'نگهداری (دقیقه)', 'en' => 'Hold (min)'],
        'rwin'     => ['fa' => 'پنجرهٔ زمان', 'en' => 'Time window'],
        'rres'     => ['fa' => 'نتیجه', 'en' => 'Result'],
        'rreason'  => ['fa' => 'شرح علت', 'en' => 'Cause'],
        'rmode'    => ['fa' => 'منشأ', 'en' => 'Provenance'],
        'empty'    => ['fa' => '(هیچ رکوردی وجود ندارد)', 'en' => '(no records exist)'],
        'external' => ['fa' => 'بیرونی', 'en' => 'External'],
        'internal' => ['fa' => 'داخلی', 'en' => 'In-system'],
    ];
    $L = fn (string $k): string => $fa ? $t[$k]['fa'] : $t[$k]['en'];
    $pack = $dossier['pack'] ?? [];
    $missing = $completeness['missing'] ?? [];
    $counts = $completeness['counts'] ?? [];
    $short = fn ($v) => $v === null || $v === '' ? $L('none') : (string) $v;
    $stamp = fn ($v) => $v === null || $v === '' ? '—' : substr(str_replace(['T', '+03:30', 'Z'], [' ', '', ''], (string) $v), 0, 16);
    $modeLabel = fn ($m) => ($m === 'EXTERNAL_REGISTER' ? $L('external') : $L('internal'))
        . ($m === 'EXTERNAL_REGISTER' && ! empty($pack) ? '' : '');
@endphp
@section('document')
<header class="doc">
  <div class="org">{{ $L('org') }}</div>
  <h1>{{ $L('title') }}</h1>
  <div class="strap">{{ $L('strap') }}</div>
</header>

<div class="warn">{{ $L('warn') }}</div>

<table class="meta">
  <tr><td class="k">{{ $L('pack') }}</td><td><span class="mono">{{ $short($pack['code'] ?? null) }}</span> — {{ $fa ? $short($pack['title_fa'] ?? null) : $short($pack['title_en'] ?? null) }}</td></tr>
  <tr><td class="k">{{ $L('subsys') }}</td><td><span class="mono">{{ $short($pack['subsystem_code'] ?? null) }}</span></td></tr>
  <tr><td class="k">{{ $L('state') }}</td><td><span class="mono">{{ $short($pack['state'] ?? null) }}</span></td></tr>
  <tr><td class="k">{{ $L('pressure') }}</td><td><span class="mono">{{ $short($pack['test_pressure'] ?? null) }} {{ $short($pack['test_pressure_unit'] ?? null) }}</span></td></tr>
  @if(! empty($compiledAt))
    <tr><td class="k">{{ $L('compiled') }}</td><td><span class="mono">{{ $stamp($compiledAt) }}</span></td></tr>
  @endif
  @if(! empty($digest))
    <tr><td class="k">{{ $L('digest') }}</td><td><span class="mono" style="font-size:7.5pt">{{ $digest }}</span></td></tr>
  @endif
</table>

<h2>{{ $L('complete') }}</h2>
<div class="box">
  <strong>{{ ($completeness['is_complete'] ?? false) ? $L('cyes') : $L('cno') }}</strong><br>
  {{ $L('counts') }}:
  {{ $L('clines') }} <span class="mono">{{ (int) ($counts['lines'] ?? 0) }}</span> ·
  {{ $L('cjoints') }} <span class="mono">{{ (int) ($counts['joints'] ?? 0) }}</span> ·
  {{ $L('cwelds') }} <span class="mono">{{ (int) ($counts['welds'] ?? 0) }}</span> ·
  {{ $L('cndt') }} <span class="mono">{{ (int) ($counts['ndt'] ?? 0) }}</span> ·
  {{ $L('ctests') }} <span class="mono">{{ (int) ($counts['test_results'] ?? 0) }}</span>
</div>

<h2>{{ $L('missing') }}</h2>
<table class="grid">
  @if(! empty($missing['lines_not_released']))
    <tr><td class="lbl">{{ $L('mlines') }}</td><td>
      @foreach($missing['lines_not_released'] as $m)<span class="mono">{{ $m['line_number'] }}</span> (<span class="mono">{{ $m['release_state'] }}</span>)@if(! $loop->last)، @endif @endforeach
    </td></tr>
  @endif
  @if(! empty($missing['joints_not_fitted']))
    <tr><td class="lbl">{{ $L('munfit') }}</td><td>
      @foreach($missing['joints_not_fitted'] as $m)<span class="mono">{{ $m['line_number'] }}/J{{ $short($m['joint_seq']) }}</span> (<span class="mono">{{ $m['state'] }}</span>)@if(! $loop->last)، @endif @endforeach
    </td></tr>
  @endif
  @if(! empty($missing['joints_without_weld_record']))
    <tr><td class="lbl">{{ $L('mweld') }}</td><td>
      @foreach($missing['joints_without_weld_record'] as $m)<span class="mono">{{ $m['line_number'] }}/J{{ $short($m['joint_seq']) }}</span>@if(! $loop->last)، @endif @endforeach
    </td></tr>
  @endif
  @if(! empty($missing['joints_without_ndt']))
    <tr><td class="lbl">{{ $L('mndt') }}</td><td>
      @foreach($missing['joints_without_ndt'] as $m)<span class="mono">{{ $m['line_number'] }}/J{{ $short($m['joint_seq']) }}</span>@if(! $loop->last)، @endif @endforeach
    </td></tr>
  @endif
  @if(! empty($missing['joints_with_open_rejection']))
    <tr><td class="lbl">{{ $L('mopen') }}</td><td>
      @foreach($missing['joints_with_open_rejection'] as $m)<span class="mono">{{ $m['line_number'] }}/J{{ $short($m['joint_seq']) }}</span>@if(! $loop->last)، @endif @endforeach
    </td></tr>
  @endif
  @if(! empty($missing['pack_without_passed_result']))
    <tr><td class="lbl">{{ $L('mpass') }}</td><td>—</td></tr>
  @endif
  @if(($completeness['is_complete'] ?? false))
    <tr><td colspan="2">{{ $L('cyes') }}</td></tr>
  @endif
</table>

<h2>{{ $L('lines') }}</h2>
<table class="grid">
  <thead><tr><th>{{ $L('lnum') }}</th><th>{{ $L('lsize') }}</th><th>{{ $L('lrel') }}</th></tr></thead>
  <tbody>
  @forelse($dossier['lines'] ?? [] as $l)
    <tr><td><span class="mono">{{ $l['line_number'] }}</span></td><td><span class="mono">{{ $short($l['nominal_size'] ?? null) }}</span></td><td><span class="mono">{{ $l['release_state'] }}</span></td></tr>
  @empty
    <tr><td colspan="3">{{ $L('empty') }}</td></tr>
  @endforelse
  </tbody>
</table>

<h2>{{ $L('joints') }}</h2>
<table class="grid">
  <thead><tr>
    <th>{{ $L('lnum') }}</th><th>{{ $L('jseq') }}</th><th>{{ $L('jstate') }}</th>
    <th>{{ $L('jfit') }}</th><th>{{ $L('jweld') }}</th><th>{{ $L('jndt') }}</th>
  </tr></thead>
  <tbody>
  @forelse($dossier['joints'] ?? [] as $j)
    <tr>
      <td><span class="mono">{{ $j['line_number'] }}</span></td>
      <td><span class="mono">J{{ $short($j['joint_seq'] ?? null) }}</span></td>
      <td><span class="mono">{{ $j['state'] }}</span></td>
      <td><span class="mono">{{ $short($j['fitted_by_code'] ?? null) }}</span><br>{{ $stamp($j['fitted_at'] ?? null) }}</td>
      <td>
        @forelse($j['welds'] ?? [] as $w)
          <span class="mono">{{ $w['kind'] }}</span> · {{ $short($w['welded_by_code'] ?? null) }} · {{ $stamp($w['welded_at'] ?? null) }} · {{ $modeLabel($w['mode'] ?? 'IN_SYSTEM') }}@if(! $loop->last)<br>@endif
        @empty—@endforelse
      </td>
      <td>
        @forelse($j['ndt'] ?? [] as $n)
          <span class="mono">{{ $n['method'] }}</span>: <strong>{{ $n['result'] }}</strong> · {{ $short($n['performed_by_code'] ?? null) }} · {{ $stamp($n['performed_at'] ?? null) }}@if($n['result'] === 'REJECTED' && ! empty($n['reject_reason']))<br><span class="note">{{ $L('nreason') }}: {{ $n['reject_reason'] }}</span>@endif@if(! $loop->last)<br>@endif
        @empty—@endforelse
      </td>
    </tr>
  @empty
    <tr><td colspan="6">{{ $L('empty') }}</td></tr>
  @endforelse
  </tbody>
</table>

<h2>{{ $L('results') }}</h2>
<table class="grid">
  <thead><tr>
    <th>{{ $L('rtype') }}</th><th>{{ $L('rmed') }}</th><th>{{ $L('rpress') }}</th>
    <th>{{ $L('rhold') }}</th><th>{{ $L('rwin') }}</th><th>{{ $L('rres') }}</th><th>{{ $L('rmode') }}</th>
  </tr></thead>
  <tbody>
  @forelse($dossier['test_results'] ?? [] as $r)
    <tr>
      <td><span class="mono">{{ $r['test_type'] }}</span></td>
      <td>{{ $r['medium'] }}</td>
      <td><span class="mono">{{ $r['test_pressure'] }} {{ $r['pressure_unit'] }}</span></td>
      <td><span class="mono">{{ (int) $r['hold_duration_minutes'] }}</span></td>
      <td><span class="mono">{{ $stamp($r['started_at'] ?? null) }} → {{ $stamp($r['completed_at'] ?? null) }}</span></td>
      <td><strong>{{ $r['result'] }}</strong>@if($r['result'] === 'FAIL' && ! empty($r['failure_reason']))<br><span class="note">{{ $L('rreason') }}: {{ $r['failure_reason'] }}</span>@endif</td>
      <td>{{ $modeLabel($r['mode'] ?? 'IN_SYSTEM') }}</td>
    </tr>
  @empty
    <tr><td colspan="7">{{ $L('empty') }}</td></tr>
  @endforelse
  </tbody>
</table>
@endsection
