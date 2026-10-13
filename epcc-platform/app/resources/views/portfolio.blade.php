@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'سبد پروژه‌ها', 'en' => 'Project Portfolio'],
        'sub'     => ['fa' => 'چند پروژه — دسترسی هر شرکت را پستگرس تعیین می‌کند', 'en' => 'Multi-project — access decided by PostgreSQL, per company'],
        'acting'  => ['fa' => 'کاربر', 'en' => 'Signed in as'],
        'logout'  => ['fa' => 'خروج', 'en' => 'Sign out'],
        'visible' => ['fa' => 'پروژهٔ قابل مشاهده', 'en' => 'projects visible'],
        'note'    => ['fa' => 'شرکت را عوض کنید: هر شرکت فقط پروژه‌هایی را می‌بیند که در آن مشارکت دارد. پک استاندارد هر پروژه جداگانه انتخاب می‌شود.', 'en' => 'Switch company: each sees only projects it participates in. The standards pack is selected per project.'],
        'certs'   => ['fa' => 'صورت‌وضعیت', 'en' => 'Certificates'],
        'blocked' => ['fa' => 'مسدود', 'en' => 'Blocked'],
        'net'     => ['fa' => 'جمع خالص (ریال)', 'en' => 'Net total (IRR)'],
        'ungated' => ['fa' => 'بدون دروازه', 'en' => 'Not gated'],
        'empty'   => ['fa' => 'این شرکت در هیچ پروژه‌ای مشارکت ندارد. این خطا نیست.', 'en' => 'This company participates in no projects. That is not an error.'],
        'owner'   => ['fa' => 'کارفرما', 'en' => 'Employer'],
        'gate'    => ['fa' => 'آزمایشگاه موتور قواعد', 'en' => 'Rule-engine playground'],
        'new'     => ['fa' => '+ پروژهٔ جدید', 'en' => '+ New project'],
        'team'    => ['fa' => 'تیم', 'en' => 'Team'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits(number_format((float) $n), $lang);
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }}</title>
@include('partials.shell')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <div class="sub">{{ $L('sub') }} · {{ $jalaliDate }}</div>

  <div class="bar">
    <label>{{ $L('acting') }}:</label>
    <strong style="font-size:14px;">{{ $user->name }}</strong>
    <span class="pill pack">{{ $acting->code }}</span>
    <span style="font-size:12.5px;color:var(--muted);">{{ $acting->name($lang) }}</span>
    <span class="count"><b>{{ count($cards) }}</b> {{ $L('visible') }}</span>
    @if($archivedCount > 0)
      {{-- Archived projects are hidden by default but never silently: a count
           the user can click is the difference between "put away" and "lost". --}}
      <a href="?lang={{ $lang }}&amp;archived={{ $showArchived ? 0 : 1 }}"
         class="pill"
         style="background:{{ $showArchived ? '#78350f' : 'transparent' }};color:{{ $showArchived ? '#fde68a' : 'var(--muted)' }};border:1px solid #92400e;text-decoration:none;">
        {{ $showArchived ? __('epcc.project.state.archived') : __('epcc.project.show_archived') }}
        · {{ \App\Support\JalaliDate::digits((string) $archivedCount, $lang) }}
      </a>
    @endif
  </div>

  <div class="note">{{ $L('note') }}</div>

  @if(count($cards) === 0)
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;">{{ $L('empty') }}</div>
  @else
    <div class="grid">
      @foreach($cards as $card)
        @php $p = $card['project']; @endphp
        <a class="pcard" href="/projects/{{ $p->code }}?lang={{ $lang }}" @if($p->isArchived()) style="opacity:.72;" @endif>
          <h3>{{ $p->name($lang) }}</h3>
          <span class="code">{{ $p->code }}</span>
          <span class="pill pack">{{ $p->standards_pack }}</span>
          @if($p->isArchived())
            <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;">{{ __('epcc.project.state.archived') }}</span>
          @endif
          <div class="stats">
            <div class="stat">
              <div class="k">{{ $L('certs') }}</div>
              <div class="v">{{ \App\Support\JalaliDate::digits((string) $card['count'], $lang) }}</div>
            </div>
            <div class="stat">
              <div class="k">{{ $L('blocked') }}</div>
              <div class="v {{ $card['blocked'] > 0 ? 'bad' : 'ok' }}">{{ \App\Support\JalaliDate::digits((string) $card['blocked'], $lang) }}</div>
            </div>
            @if($card['ungated'] > 0)
            <div class="stat">
              <div class="k">{{ $L('ungated') }}</div>
              <div class="v" style="color:#cbd5e1;">{{ \App\Support\JalaliDate::digits((string) $card['ungated'], $lang) }}</div>
            </div>
            @endif
            <div class="stat" style="margin-{{ $fa ? 'right' : 'left' }}:auto;text-align:{{ $fa ? 'left' : 'right' }};">
              <div class="k">{{ $L('net') }}</div>
              <div class="v">{{ $num($card['net']) }}</div>
            </div>
          </div>
        </a>
      @endforeach
    </div>
  @endif

  <div class="foot">{{ $fa ? 'احکام از بستهٔ epcc/standards-engine می‌آیند؛ پک استاندارد از روی پروژه انتخاب می‌شود.' : 'Verdicts come from epcc/standards-engine; the standards pack is selected per project.' }}</div>
</div>
</div>
</body>
</html>
