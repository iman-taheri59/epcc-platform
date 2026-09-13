@php
    $fa = $lang === 'fa';
    $t = [
        'title'      => ['fa' => 'صورت‌وضعیت‌های موقت', 'en' => 'Interim Payment Certificates'],
        'acting'     => ['fa' => 'کاربر', 'en' => 'Signed in as'],
        'logout'     => ['fa' => 'خروج', 'en' => 'Sign out'],
        'project'    => ['fa' => 'پروژه', 'en' => 'Project'],
        'visible'    => ['fa' => 'ردیف قابل مشاهده', 'en' => 'rows visible'],
        'rlsnote'    => ['fa' => 'دامنه روی این پروژه محدود شده است (epcc.project_id). تعداد ردیف‌ها را پستگرس تعیین می‌کند، نه کد برنامه.', 'en' => 'Scoped to this project (epcc.project_id). Row visibility is decided by PostgreSQL, not application code.'],
        'number'     => ['fa' => 'شماره', 'en' => 'Number'],
        'contractor' => ['fa' => 'پیمانکار', 'en' => 'Contractor'],
        'net'        => ['fa' => 'مبلغ خالص (ریال)', 'en' => 'Net (IRR)'],
        'gate'       => ['fa' => 'دروازهٔ ترخیص', 'en' => 'Release gate'],
        'cleared'    => ['fa' => 'مجاز', 'en' => 'Cleared'],
        'blocked'    => ['fa' => 'مسدود', 'en' => 'Blocked'],
        'absolute'   => ['fa' => 'قطعی — بدون امکان چشم‌پوشی', 'en' => 'Hard — no waiver'],
        'empty'      => ['fa' => 'هیچ صورت‌وضعیتی برای این شرکت در این پروژه قابل مشاهده نیست. این خطا نیست — RLS در حال کار است.', 'en' => 'No certificates visible to this company on this project. That is not an error — RLS is working.'],
        'limit'      => ['fa' => 'حد اختیارات', 'en' => 'Authority limit'],
        'back'       => ['fa' => 'سبد پروژه‌ها', 'en' => 'Portfolio'],
        'pack'       => ['fa' => 'پک', 'en' => 'Pack'],
        'ungated'    => ['fa' => 'بدون دروازه', 'en' => 'Not gated'],
        'ungatedwhy' => ['fa' => 'پک استاندارد این پروژه دروازه‌ای برای این نوع سند تعریف نکرده است — هیچ بررسی‌ای انجام نشده.', 'en' => 'This project\'s standards pack defines no gate for this object type — nothing was checked.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits(number_format((float) $n), $lang);
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $project->name($lang) }} — {{ $L('title') }}</title>
@include('partials.shell')
@include('partials.tabletools')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $project->name($lang) }}</h1>
  <div class="sub">
    <span class="mono">{{ $project->code }}</span>
    · <span class="pill pack">{{ $L('pack') }}: {{ $project->standards_pack }}</span>
    · {{ $jalaliDate }}
  </div>

  <div class="bar">
    <label>{{ $L('acting') }}:</label>
    <strong style="font-size:14px;">{{ $user->name }}</strong>
    <span class="pill pack">{{ $acting->code }}</span>
    <span style="font-size:12.5px;color:var(--muted);">{{ $L('limit') }}: {{ $num($limit) }}</span>
    <span class="count"><b>{{ count($rows) }}</b> {{ $L('visible') }}</span>
  </div>

  <div class="note">{{ $L('rlsnote') }}</div>

  {{-- data-tt opts this table into filter/sort (partials/tabletools). --}}
  <table data-tt>
    <thead>
      <tr>
        <th data-sort="text">{{ $L('number') }}</th>
        <th data-sort="text" data-facet>{{ $L('contractor') }}</th>
        <th data-sort="num">{{ $L('net') }}</th>
        <th data-sort="text" data-facet>{{ $L('gate') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $row)
      @php $c = $row['cert']; $v = $row['verdict']; @endphp
      <tr>
        <td><span class="mono">{{ $c->number }}</span></td>
        <td>{{ $c->company->name($lang) }}</td>
        {{-- The displayed amount is grouped and in Persian digits; sort on
             the raw value so ۴۳٬۲۳۴٬۵۰۰٬۰۰۰ orders as a number. --}}
        <td class="amt" data-sortkey="{{ $c->net_amount }}">{{ $num($c->net_amount) }}</td>
        {{-- Sort/facet on the VERDICT, not on the cell text: the cell also
             contains the failure reasons, which would make every blocked row
             a unique facet value. --}}
        @php
          $verdictKey = $v->isUngated() ? 'ungated' : ($v->passed ? 'cleared' : 'blocked');
        @endphp
        {{-- data-facet-value keeps the dropdown to three options; the cell
             itself also lists every failure reason. --}}
        <td data-sortkey="{{ $verdictKey }}" data-facet-value="{{ $L($verdictKey) }}">
          @if($v->isUngated())
            <span class="pill none">{{ $L('ungated') }}</span>
            <div class="whynot">{{ $L('ungatedwhy') }}</div>
          @else
            <span class="pill {{ $v->passed ? 'ok' : 'bad' }}">{{ $v->passed ? $L('cleared') : $L('blocked') }}</span>
          @endif
          @if($v->isAbsolutelyBlocked())<span class="pill abs">{{ $L('absolute') }}</span>@endif
          @if(count($v->failures))
            <ul class="rs">
              @foreach($v->failures as $f)
                <li><code>{{ $f->check }}</code>{{ $fa ? $f->messageFa : $f->messageEn }}</li>
              @endforeach
            </ul>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="4" class="empty">{{ $L('empty') }}</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
</div>
</body>
</html>
