@php
    $fa = $lang === 'fa';
    $t = [
        'title' => ['fa' => 'فضای کار پایپینگ', 'en' => 'Piping workspace'],
        'sub' => ['fa' => 'نمای کارمحور: اول موانع، بعد آماده‌سازی تست‌پک و تحویل', 'en' => 'Task-first view: blockers first, then test-pack readiness and turnover'],
        'lines' => ['fa' => 'خطوط', 'en' => 'Lines'],
        'ready' => ['fa' => 'آماده AFC', 'en' => 'AFC ready'],
        'blocked' => ['fa' => 'دارای مانع', 'en' => 'Blocked'],
        'released' => ['fa' => 'ابلاغ‌شده', 'en' => 'Released'],
        'joints' => ['fa' => 'فیت‌آپ جوینت', 'en' => 'Joint fit-up'],
        'blockers' => ['fa' => 'موانع امروز', 'en' => 'Today’s blockers'],
        'packs' => ['fa' => 'آمادگی تست‌پک', 'en' => 'Test pack readiness'],
        'register' => ['fa' => 'رفتن به رجیستر', 'en' => 'Open register'],
        'model' => ['fa' => 'مدل خط', 'en' => 'Line model'],
        'line' => ['fa' => 'خط', 'en' => 'Line'],
        'subsystem' => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'class' => ['fa' => 'کلاس', 'en' => 'Class'],
        'state' => ['fa' => 'وضعیت', 'en' => 'State'],
        'afc_docs' => ['fa' => 'مدارک AFC', 'en' => 'AFC docs'],
        'fitup' => ['fa' => 'فیت‌آپ', 'en' => 'Fit-up'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'missing' => ['fa' => 'کسری مدرک', 'en' => 'Missing docs'],
        'unapproved' => ['fa' => 'رویزیون تأییدنشده', 'en' => 'Unapproved revision'],
        'badjoint' => ['fa' => 'ناسازگاری جوینت', 'en' => 'Invalid joint'],
        'nojointno' => ['fa' => 'شماره جوینت ندارد', 'en' => 'Joint number missing'],
        'external' => ['fa' => 'شماره خارج از قالب', 'en' => 'External number'],
        'no_blockers' => ['fa' => 'مانع فعالی در داده‌های فعلی دیده نشد.', 'en' => 'No active blocker in the current data.'],
        'empty' => ['fa' => 'هنوز خط پایپینگ برای این پروژه ثبت نشده است.', 'en' => 'No piping lines have been registered for this project yet.'],
        'derived' => ['fa' => 'این صفحه از داده‌های واقعی پروژه ساخته می‌شود؛ تست‌پک‌ها فعلاً بر پایه زیرسیستم گروه‌بندی شده‌اند.', 'en' => 'This page is derived from project data; test packs are currently grouped by subsystem.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $pct = $stats['joints_total'] === 0 ? 0 : (int) round(($stats['joints_fitted'] / $stats['joints_total']) * 100);
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $project->name($lang) }} — {{ $L('title') }}</title>
@include('partials.shell')
@include('partials.tabletools')
<style>
.workspace-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.metric{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px}.metric b{display:block;font-size:28px;margin-top:4px}.metric span{color:var(--muted);font-size:12px}.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-top:14px}.panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}.progress{height:9px;border-radius:999px;background:rgba(148,163,184,.22);overflow:hidden}.progress i{display:block;height:100%;background:#22c55e;border-radius:inherit}.warn{color:#fcd34d}.bad{color:#fca5a5}.oktxt{color:#6ee7b7}.task-table td{vertical-align:top}.chips{display:flex;flex-wrap:wrap;gap:5px}.chip{border:1px solid var(--line);border-radius:999px;padding:2px 7px;font-size:11px;background:rgba(148,163,184,.12)}.chip.bad{border-color:#7f1d1d;background:rgba(127,29,29,.28)}.chip.warn{border-color:#92400e;background:rgba(146,64,14,.28)}.muted{color:var(--muted)}@media(max-width:820px){.workspace-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.task-table thead{display:none}.task-table tr{display:block;border:1px solid var(--line);border-radius:14px;margin:10px 0;background:rgba(15,23,42,.24);padding:8px}.task-table td{display:grid;grid-template-columns:120px minmax(0,1fr);border:0!important;padding:6px 4px}.task-table td::before{content:attr(data-label);color:var(--muted);font-size:11px}}@media(max-width:520px){.workspace-grid{grid-template-columns:1fr}.panel-head{display:block}.task-table td{grid-template-columns:1fr}.task-table td::before{margin-bottom:3px}}
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

<h1>{{ $L('title') }}</h1>
<div class="sub"><span class="mono">{{ $project->code }}</span> · {{ $project->name($lang) }} · {{ $jalaliDate }}</div>

<div class="bar">
  <span>{{ $L('sub') }}</span>
  <span style="margin-{{ $fa ? 'right' : 'left' }}:auto"><a href="/projects/{{ $project->code }}/piping?lang={{ $lang }}">{{ $L('register') }}</a></span>
</div>

<div class="workspace-grid">
  <div class="metric"><span>{{ $L('lines') }}</span><b>{{ $num($stats['total']) }}</b></div>
  <div class="metric"><span>{{ $L('ready') }}</span><b class="oktxt">{{ $num($stats['ready']) }}</b></div>
  <div class="metric"><span>{{ $L('blocked') }}</span><b class="bad">{{ $num($stats['blocked']) }}</b></div>
  <div class="metric"><span>{{ $L('joints') }}</span><b>{{ $num($pct) }}٪</b><div class="progress"><i style="width:{{ $pct }}%"></i></div></div>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2 style="margin:0;font-size:18px;">{{ $L('blockers') }}</h2><div class="muted" style="font-size:12px;margin-top:4px;">{{ $L('derived') }}</div></div>
    <span class="pill">{{ $L('released') }}: {{ $num($stats['released']) }}</span>
  </div>
  @if($rows->isEmpty())
    <div class="empty">{{ $L('empty') }}</div>
  @else
  <table class="task-table" data-tt>
    <thead><tr><th data-sort="text">{{ $L('line') }}</th><th data-sort="text" data-facet>{{ $L('subsystem') }}</th><th data-sort="text" data-facet>{{ $L('state') }}</th><th>{{ $L('afc_docs') }}</th><th data-sort="num">{{ $L('fitup') }}</th><th>{{ $L('actions') }}</th></tr></thead>
    <tbody>
    @php $shown = 0; @endphp
    @foreach($rows->sortByDesc('is_blocked') as $row)
      @php
        $line = $row['line'];
        $label = \App\Support\LineReleaseState::label((string) $line->release_state);
        $problems = [];
        if ($row['missing'] !== []) $problems[] = $L('missing').': '.implode(', ', $row['missing']);
        if ($row['unapproved'] !== []) $problems[] = $L('unapproved').': '.implode(', ', $row['unapproved']);
        if ($row['joints']['invalid'] > 0) $problems[] = $L('badjoint').': '.$num($row['joints']['invalid']);
        if ($row['joints']['awaiting_number'] > 0) $problems[] = $L('nojointno').': '.$num($row['joints']['awaiting_number']);
        if ($line->sequence === null) $problems[] = $L('external');
        $shown += count($problems) > 0 ? 1 : 0;
      @endphp
      <tr>
        <td data-label="{{ $L('line') }}" class="mono">{{ $line->line_number }}</td>
        <td data-label="{{ $L('subsystem') }}">{{ $line->subsystem?->code ?? '—' }}</td>
        <td data-label="{{ $L('state') }}"><span class="pill">{{ $fa ? $label['fa'] : $label['en'] }}</span></td>
        <td data-label="{{ $L('afc_docs') }}">
          @if($row['evidence_ready']) <span class="oktxt">✓ {{ $L('ready') }}</span> @else <span class="bad">{{ $L('blocked') }}</span> @endif
          @if($problems !== [])<div class="chips" style="margin-top:5px;">@foreach($problems as $problem)<span class="chip {{ str_contains($problem, $L('unapproved')) ? 'warn' : 'bad' }}">{{ $problem }}</span>@endforeach</div>@endif
        </td>
        <td data-label="{{ $L('fitup') }}"><div class="progress"><i style="width:{{ $row['fit_percent'] }}%"></i></div><div class="muted" style="font-size:11px;margin-top:4px;">{{ $num($row['joints']['fitted']) }} / {{ $num($row['joints']['total']) }}</div></td>
        <td data-label="{{ $L('actions') }}"><a href="/projects/{{ $project->code }}/piping/{{ $line->id }}/model?lang={{ $lang }}">{{ $L('model') }}</a></td>
      </tr>
    @endforeach
    @if($shown === 0)<tr><td colspan="6" class="empty">{{ $L('no_blockers') }}</td></tr>@endif
    </tbody>
  </table>
  @endif
</div>

<div class="panel">
  <div class="panel-head"><h2 style="margin:0;font-size:18px;">{{ $L('packs') }}</h2></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;">
    @forelse($packs as $pack)
      <div class="metric">
        <span class="mono">{{ $pack['code'] }}</span>
        <b>{{ $num($pack['percent']) }}٪</b>
        <div class="progress"><i style="width:{{ $pack['percent'] }}%"></i></div>
        <div class="muted" style="font-size:12px;margin-top:8px;">{{ $L('released') }} {{ $num($pack['released']) }} / {{ $num($pack['total']) }} · {{ $L('blocked') }} {{ $num($pack['blocked']) }}</div>
      </div>
    @empty
      <div class="empty">{{ $L('empty') }}</div>
    @endforelse
  </div>
</div>

</div>
</div>
</body>
</html>
