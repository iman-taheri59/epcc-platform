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

        // A.5b slice 2 — signatures, and the HOLD evidence they stand beside.
        // The strap and the warning are NOT constants any more: they follow the
        // document. An unsigned dossier keeps saying, plainly, that it is not a
        // certificate — and a signed one says who stands behind it instead.
        'signed_strap' => ['fa' => 'گردآوری شواهد کیفی یک بستهٔ آزمون — امضاشده به عنوان مدرک، نه گواهی', 'en' => 'Quality-evidence compilation for one test pack — signed as evidence, not a certificate'],
        'warn_unsigned' => ['fa' => 'این سند گواهی نیست؛ گردآوری رکوردهای موجود است و فقرات غایب صریحاً درج شده‌اند. هیچ‌کس آن را امضا نکرده است.', 'en' => 'This document is NOT a certificate; it compiles the records that exist and enumerates the gaps explicitly. Nobody has signed it.'],
        'warn_signed' => ['fa' => 'این سند گواهیِ پذیرش نیست؛ مدرکی است امضاشده از سویِ طرف‌های فهرست‌شده در انتها، و امضا فراتر از نتیجهٔ درج‌شده نظری را حمل نمی‌کند.', 'en' => 'This document is not a certificate of acceptance; it is evidence, signed by the parties listed at the end, and a signature carries no opinion beyond the outcome stated.'],
        'sig_title' => ['fa' => 'امضاها', 'en' => 'Signatures'],
        'sig_none' => ['fa' => 'هیچ امضایی روی این سند ثبت نشده است.', 'en' => 'No signature has been recorded on this document.'],
        'sig_company' => ['fa' => 'شرکت', 'en' => 'Company'],
        'sig_by' => ['fa' => 'امضاکننده', 'en' => 'Signer'],
        'sig_at' => ['fa' => 'زمان امضا', 'en' => 'Signed at'],
        'sig_outcome' => ['fa' => 'نتیجه', 'en' => 'Outcome'],
        'sig_reason' => ['fa' => 'توضیح / علت', 'en' => 'Comments / cause'],
        'sig_digest' => ['fa' => 'digest ی سندِ امضاشده', 'en' => 'Digest of the signed document'],
        'sig_ENDORSED' => ['fa' => 'تأیید', 'en' => 'Endorsed'],
        'sig_ENDORSED_WITH_COMMENTS' => ['fa' => 'تأیید با توضیح', 'en' => 'Endorsed with comments'],
        'sig_REFUSED' => ['fa' => 'خودداری از امضا', 'en' => 'Refused to sign'],
        'notices_title' => ['fa' => 'اعلام‌های بازرسیِ پیوندخورده', 'en' => 'Linked inspection notices'],
        'notices_none' => ['fa' => 'هیچ اعلامِ بازرسی به این بسته پیوند نخورده است.', 'en' => 'No inspection notice is linked to this pack.'],
        'notice' => ['fa' => 'اعلام', 'en' => 'Notice'],
        'point' => ['fa' => 'نقطه', 'en' => 'Point'],
        'holder' => ['fa' => 'وضعیت توقف', 'en' => 'Hold state'],
        'stage' => ['fa' => 'مرحله', 'en' => 'Stage'],
        'outcome' => ['fa' => 'امضای بازرسی', 'en' => 'Inspection sign-off'],
        'hold_NOT_SIGNED' => ['fa' => 'امضانشده', 'en' => 'not signed'],
        'hold_CLEARED' => ['fa' => 'آزاد', 'en' => 'cleared'],
        'hold_REJECTED' => ['fa' => 'مردود', 'en' => 'rejected'],
        'hold_CANCELLED' => ['fa' => 'لغو', 'en' => 'cancelled'],
        'hold_NOT_ISSUED' => ['fa' => 'صادرنشده', 'en' => 'not issued'],
        'hold_NOT_VISIBLE' => ['fa' => 'نامرئی', 'en' => 'not visible'],
        'outcome_ACCEPTED' => ['fa' => 'پذیرفته', 'en' => 'Accepted'],
        'outcome_ACCEPTED_WITH_COMMENTS' => ['fa' => 'پذیرفته با توضیح', 'en' => 'Accepted with comments'],
        'outcome_REJECTED' => ['fa' => 'مردود', 'en' => 'Rejected'],
        'outcome_WAIVED_NOT_ATTENDED' => ['fa' => 'چشم‌پوشی — کسی نیامد', 'en' => 'Waived — nobody attended'],

        // A.5b slice 3a — the document names the scope it was compiled for.
        'scope' => ['fa' => 'دامنهٔ کتابچه', 'en' => 'Scope of the dossier'],
        'scope_TEST_PACK' => ['fa' => 'یک بستهٔ آزمون', 'en' => 'One test pack'],
        'scope_SUBSYSTEM' => ['fa' => 'یک زیرسیستم', 'en' => 'One subsystem'],
        'scope_SYSTEM' => ['fa' => 'یک سیستم', 'en' => 'One system'],
        'spans' => ['fa' => 'زیرسیستم‌های دربرگرفته', 'en' => 'Subsystems gathered'],
        'facilities' => ['fa' => 'واحدها', 'en' => 'Facilities'],
        // A.5b slice 3d - the two physical rungs of the same cross-reference.
        'scope_AREA' => ['fa' => 'یک ناحیه', 'en' => 'One area'],
        'scope_FACILITY' => ['fa' => 'یک واحد', 'en' => 'One facility'],
        'systems' => ['fa' => 'سیستم‌ها', 'en' => 'Systems'],
        'areas' => ['fa' => 'ناحیه‌ها', 'en' => 'Areas'],
        'scope_PROJECT' => ['fa' => 'کلِ پروژه', 'en' => 'The whole project'],
        'pack' => ['fa' => 'بستهٔ آزمون', 'en' => 'Test pack'],
        'mpass' => ['fa' => 'بسته‌هایی بی‌نتیجهٔ آزمونِ قبول', 'en' => 'Packs with no PASS test result'],
        'mnopack' => ['fa' => 'خطوطی که به هیچ بسته‌ای نسبت داده نشده‌اند', 'en' => 'Lines assigned to no test pack'],
        'packs' => ['fa' => 'بسته‌های آزمونِ در دامنه', 'en' => 'Test packs in scope'],
        'pcode' => ['fa' => 'کدِ بسته', 'en' => 'Pack code'],
        'pstate' => ['fa' => 'وضعیت', 'en' => 'State'],
    ];
    $L = fn (string $k): string => $fa ? $t[$k]['fa'] : $t[$k]['en'];
    $pack = $dossier['pack'] ?? [];
    $scope = $dossier['scope'] ?? [];
    $scopeKind = (string) ($dossier['scope_kind'] ?? 'TEST_PACK');
    $scopeLabel = match ($scopeKind) {
        'SUBSYSTEM' => ($scope['code'] ?? '—').' — '.$L('scope_SUBSYSTEM'),
        'SYSTEM' => ($scope['code'] ?? '—').' — '.$L('scope_SYSTEM'),
        'AREA' => ($scope['code'] ?? '—').' — '.$L('scope_AREA'),
        'FACILITY' => ($scope['code'] ?? '—').' — '.$L('scope_FACILITY'),
        'PROJECT' => ($scope['code'] ?? '—').' — '.$L('scope_PROJECT'),
        default => ($pack['code'] ?? '—').' — '.$L('scope_TEST_PACK'),
    };
    $missing = $completeness['missing'] ?? [];
    $counts = $completeness['counts'] ?? [];
    $short = fn ($v) => $v === null || $v === '' ? $L('none') : (string) $v;
    $stamp = fn ($v) => $v === null || $v === '' ? '—' : substr(str_replace(['T', '+03:30', 'Z'], [' ', '', ''], (string) $v), 0, 16);
    $modeLabel = fn ($m) => ($m === 'EXTERNAL_REGISTER' ? $L('external') : $L('internal'))
        . ($m === 'EXTERNAL_REGISTER' && ! empty($pack) ? '' : '');

    // Evidence passed in by the caller (A.5b slice 2). Both are optional on
    // purpose: this view has always been renderable from the snapshot alone.
    $signatures = collect($signatures ?? []);
    $notifications = collect($notifications ?? []);
    $endorsements = $signatures->reject(fn ($s) => ($s->outcome ?? null) === 'REFUSED')->values();
    $isSigned = $endorsements->isNotEmpty();
@endphp
@section('document')
<header class="doc">
  <div class="org">{{ $L('org') }}</div>
  <h1>{{ $L('title') }}</h1>
  <div class="strap">{{ $isSigned ? $L('signed_strap') : $L('strap') }}</div>
</header>

@if($isSigned)
  <div class="warn signed">{{ $L('warn_signed') }}</div>
@else
  <div class="warn">{{ $L('warn_unsigned') }}</div>
@endif

<table class="meta">
  <tr><td class="k">{{ $L('scope') }}</td><td><span class="mono">{{ $scopeLabel }}</span></td></tr>
  @if($scopeKind === 'TEST_PACK')
    <tr><td class="k">{{ $L('pack') }}</td><td><span class="mono">{{ $short($pack['code'] ?? null) }}</span> — {{ $fa ? $short($pack['title_fa'] ?? null) : $short($pack['title_en'] ?? null) }}</td></tr>
  @endif
  {{--
    The cross-reference, printed on the document. A system says which
    facilities it spans; an area says which SYSTEMS reach into it. A reader
    holding this page is entitled to see both directions rather than infer
    them.
  --}}
  @if(in_array($scopeKind, ['SYSTEM','AREA','FACILITY'], true))
    <tr><td class="k">{{ $L('spans') }}</td><td>
      @foreach($scope['subsystem_codes'] ?? [] as $c)<span class="mono">{{ $c }}</span>@if(! $loop->last) · @endif @endforeach
      @if(($scope['system_codes'] ?? []) !== [])<br>{{ $L('systems') }}: <span class="mono">{{ implode(' · ', $scope['system_codes']) }}</span>@endif
      @if(($scope['area_codes'] ?? []) !== [])<br>{{ $L('areas') }}: <span class="mono">{{ implode(' · ', $scope['area_codes']) }}</span>@endif
      @if(($scope['facility_codes'] ?? []) !== [])<br>{{ $L('facilities') }}: <span class="mono">{{ implode(' · ', $scope['facility_codes']) }}</span>@endif
    </td></tr>
  @endif
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
  {{ $L('ctests') }} <span class="mono">{{ (int) ($counts['test_results'] ?? 0) }}</span> ·
  {{ $L('packs') }} <span class="mono">{{ (int) ($counts['packs'] ?? 0) }}</span>
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
  @if(! empty($missing['lines_without_test_pack']))
    <tr><td class="lbl">{{ $L('mnopack') }}</td><td>
      @foreach($missing['lines_without_test_pack'] as $ln)
        <span class="mono">{{ $ln['line_number'] }}</span>@if(! $loop->last) · @endif
      @endforeach
    </td></tr>
  @endif
  @if(! empty($missing['packs_without_passed_result']))
    <tr><td class="lbl">{{ $L('mpass') }}</td><td>
      @foreach($missing['packs_without_passed_result'] as $pp)
        <span class="mono">{{ $pp['pack_code'] }}</span>@if(! $loop->last) · @endif
      @endforeach
    </td></tr>
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

@if($scopeKind !== 'TEST_PACK')
<h2>{{ $L('packs') }}</h2>
<table class="grid">
  <thead><tr><th>{{ $L('pcode') }}</th><th>{{ $L('pstate') }}</th><th>{{ $L('subsys') }}</th><th>{{ $L('pressure') }}</th></tr></thead>
  <tbody>
  @forelse($dossier['test_packs'] ?? [] as $tp)
    <tr>
      <td><span class="mono">{{ $tp['code'] ?? '—' }}</span></td>
      <td><span class="mono">{{ $tp['state'] ?? '—' }}</span></td>
      <td><span class="mono">{{ $short($tp['subsystem_code'] ?? null) }}</span></td>
      <td><span class="mono">{{ $short($tp['test_pressure'] ?? null) }} {{ $short($tp['test_pressure_unit'] ?? null) }}</span></td>
    </tr>
  @empty
    <tr><td colspan="4">{{ $L('empty') }}</td></tr>
  @endforelse
  </tbody>
</table>
@endif

<h2>{{ $L('results') }}</h2>
<table class="grid">
  <thead><tr>
    @if($scopeKind !== 'TEST_PACK')<th>{{ $L('pcode') }}</th>@endif
    <th>{{ $L('rtype') }}</th><th>{{ $L('rmed') }}</th><th>{{ $L('rpress') }}</th>
    <th>{{ $L('rhold') }}</th><th>{{ $L('rwin') }}</th><th>{{ $L('rres') }}</th><th>{{ $L('rmode') }}</th>
  </tr></thead>
  <tbody>
  @forelse($dossier['test_results'] ?? [] as $r)
    <tr>
      @if($scopeKind !== 'TEST_PACK')<td><span class="mono">{{ $r['test_pack_code'] ?? '—' }}</span></td>@endif
      <td><span class="mono">{{ $r['test_type'] }}</span></td>
      <td>{{ $r['medium'] }}</td>
      <td><span class="mono">{{ $r['test_pressure'] }} {{ $r['pressure_unit'] }}</span></td>
      <td><span class="mono">{{ (int) $r['hold_duration_minutes'] }}</span></td>
      <td><span class="mono">{{ $stamp($r['started_at'] ?? null) }} → {{ $stamp($r['completed_at'] ?? null) }}</span></td>
      <td><strong>{{ $r['result'] }}</strong>@if($r['result'] === 'FAIL' && ! empty($r['failure_reason']))<br><span class="note">{{ $L('rreason') }}: {{ $r['failure_reason'] }}</span>@endif</td>
      <td>{{ $modeLabel($r['mode'] ?? 'IN_SYSTEM') }}</td>
    </tr>
  @empty
    <tr><td colspan="{{ $scopeKind === 'TEST_PACK' ? 7 : 8 }}">{{ $L('empty') }}</td></tr>
  @endforelse
  </tbody>
</table>

@if($notifications->isNotEmpty())
<h2>{{ $L('notices_title') }}</h2>
<table class="grid">
  <thead><tr>
    <th>{{ $L('notice') }}</th><th>{{ $L('point') }}</th><th>{{ $L('holder') }}</th>
    <th>{{ $L('stage') }}</th><th>{{ $L('outcome') }}</th>
  </tr></thead>
  <tbody>
  @foreach($notifications as $n)
    <tr>
      <td><span class="mono">{{ $n['number'] ?? '—' }}</span></td>
      <td><span class="mono">{{ $n['point'] ?? '—' }}</span></td>
      <td>{{ $L('hold_'.($n['hold_state'] ?? 'NOT_VISIBLE')) }}</td>
      <td>{{ $n['stage_description'] ?? '—' }}</td>
      <td>
        @forelse($n['signoffs'] ?? [] as $so)
          {{ $L('outcome_'.($so['outcome'] ?? '')) }}@if(! empty($so['is_employer'])) <span class="note">({{ $L('org') }})</span>@endif<br><span class="note">{{ $stamp($so['signed_at'] ?? null) }}</span>@if(! $loop->last)<br>@endif
        @empty—@endforelse
      </td>
    </tr>
  @endforeach
  </tbody>
</table>
@endif

<h2>{{ $L('sig_title') }}</h2>
@if($signatures->isEmpty())
  <div class="box">{{ $L('sig_none') }}</div>
@else
<table class="grid">
  <thead><tr>
    <th>{{ $L('sig_company') }}</th><th>{{ $L('sig_by') }}</th><th>{{ $L('sig_at') }}</th>
    <th>{{ $L('sig_outcome') }}</th><th>{{ $L('sig_reason') }}</th><th>{{ $L('sig_digest') }}</th>
  </tr></thead>
  <tbody>
  @foreach($signatures as $s)
    <tr>
      <td><span class="mono">{{ $s->company_code ?? '—' }}</span></td>
      <td>{{ $s->signer_name ?? '—' }}</td>
      <td><span class="mono">{{ $stamp($s->signed_at ?? null) }}</span></td>
      <td><strong>{{ $L('sig_'.($s->outcome ?? '')) }}</strong></td>
      <td>{{ $s->reason ?? '—' }}</td>
      <td><span class="mono" style="font-size:7pt">{{ substr((string) ($s->digest ?? ''), 0, 16) }}…</span></td>
    </tr>
  @endforeach
  </tbody>
</table>
@endif
@endsection
