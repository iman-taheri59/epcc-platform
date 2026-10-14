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
        'issuedPms' => ['fa' => 'بازنگری PMS زمان صدور', 'en' => 'Issued PMS revision'],
        'unmapped' => ['fa' => 'در انتظار بررسی کلاس و بازنگری PMS', 'en' => 'Class/PMS revision review required'],
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
        'derived' => ['fa' => 'این صفحه از داده‌های واقعی پروژه ساخته می‌شود؛ تست‌پک‌ها اکنون موجودیت واقعی پروژه هستند.', 'en' => 'This page is derived from project data; test packs are now real project records.'],
        'pack' => ['fa' => 'تست‌پک', 'en' => 'Test pack'],
        'no_pack' => ['fa' => 'بدون تست‌پک', 'en' => 'No test pack'],
        'assign_pack' => ['fa' => 'انتساب', 'en' => 'Assign'],
        'new_pack' => ['fa' => 'تست‌پک جدید', 'en' => 'New test pack'],
        'code' => ['fa' => 'کد', 'en' => 'Code'],
        'title_fa' => ['fa' => 'عنوان فارسی', 'en' => 'Persian title'],
        'title_en' => ['fa' => 'عنوان انگلیسی', 'en' => 'English title'],
        'pressure' => ['fa' => 'فشار تست', 'en' => 'Test pressure'],
        'create' => ['fa' => 'ایجاد', 'en' => 'Create'],
        'unassigned' => ['fa' => 'خط بدون تست‌پک', 'en' => 'Lines without a test pack'],
        'advance' => ['fa' => 'تغییر وضعیت', 'en' => 'Advance'],
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
.workspace-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.metric{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px}.metric b{display:block;font-size:28px;margin-top:4px}.metric span{color:var(--muted);font-size:12px}.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-top:14px}.panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}.progress{height:9px;border-radius:999px;background:rgba(148,163,184,.22);overflow:hidden}.progress i{display:block;height:100%;background:#22c55e;border-radius:inherit}.warn{color:#fcd34d}.bad{color:#fca5a5}.oktxt{color:#6ee7b7}.task-table td{vertical-align:top}.chips{display:flex;flex-wrap:wrap;gap:5px}.chip{border:1px solid var(--line);border-radius:999px;padding:2px 7px;font-size:11px;background:rgba(148,163,184,.12)}.chip.bad{border-color:#7f1d1d;background:rgba(127,29,29,.28)}.chip.warn{border-color:#92400e;background:rgba(146,64,14,.28)}.muted{color:var(--muted)}.mini-form{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.mini-form input,.mini-form select{padding:5px 7px;border-radius:7px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;max-width:160px}.mini-form button{padding:5px 10px;border-radius:7px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:12px;cursor:pointer}@media(max-width:820px){.workspace-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.task-table thead{display:none}.task-table tr{display:block;border:1px solid var(--line);border-radius:14px;margin:10px 0;background:rgba(15,23,42,.24);padding:8px}.task-table td{display:grid;grid-template-columns:120px minmax(0,1fr);border:0!important;padding:6px 4px}.task-table td::before{content:attr(data-label);color:var(--muted);font-size:11px}}@media(max-width:520px){.workspace-grid{grid-template-columns:1fr}.panel-head{display:block}.task-table td{grid-template-columns:1fr}.task-table td::before{margin-bottom:3px}}
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

@if($errors->any())
  <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(127,29,29,.35);border:1px solid #991b1b;color:#fecaca;font-size:13px;line-height:1.8;">
    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
  </div>
@endif

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
    <thead><tr><th data-sort="text">{{ $L('line') }}</th><th data-sort="text" data-facet>{{ $L('subsystem') }}</th><th data-sort="text" data-facet>{{ $L('pack') }}</th><th data-sort="text" data-facet>{{ $L('state') }}</th><th>{{ $L('afc_docs') }}</th><th data-sort="num">{{ $L('fitup') }}</th><th>{{ $L('actions') }}</th></tr></thead>
    <tbody>
    @php $shown = 0; @endphp
    @foreach($rows->sortByDesc('is_blocked') as $row)
      @php
        $line = $row['line'];
        $label = \App\Support\LineReleaseState::label((string) $line->release_state);
        $problems = [];
        if ($line->pms_class_snapshot_id === null) $problems[] = $L('unmapped');
        if ($row['missing'] !== []) $problems[] = $L('missing').': '.implode(', ', $row['missing']);
        if ($row['unapproved'] !== []) $problems[] = $L('unapproved').': '.implode(', ', $row['unapproved']);
        if ($row['joints']['invalid'] > 0) $problems[] = $L('badjoint').': '.$num($row['joints']['invalid']);
        if ($row['joints']['awaiting_number'] > 0) $problems[] = $L('nojointno').': '.$num($row['joints']['awaiting_number']);
        if ($line->sequence === null) $problems[] = $L('external');
        $shown += count($problems) > 0 ? 1 : 0;
      @endphp
      <tr>
        <td data-label="{{ $L('line') }}" class="mono">{{ $line->line_number }}
          @if($line->pms_class_snapshot_id !== null)
            @php $issued = $line->pmsSnapshot?->documentRevision; @endphp
            <div class="muted" style="font-size:11px;">{{ $L('issuedPms') }}:
              {{ $issued?->document?->number ?? 'PMS' }} · {{ $issued?->revision ?? '—' }}
            </div>
          @endif
        </td>
        <td data-label="{{ $L('subsystem') }}">{{ $line->subsystem?->code ?? '—' }}</td>
        <td data-label="{{ $L('pack') }}">{{ $line->testPack?->code ?? $L('no_pack') }}</td>
        <td data-label="{{ $L('state') }}"><span class="pill">{{ $fa ? $label['fa'] : $label['en'] }}</span></td>
        <td data-label="{{ $L('afc_docs') }}">
          @if($row['evidence_ready']) <span class="oktxt">✓ {{ $L('ready') }}</span> @else <span class="bad">{{ $L('blocked') }}</span> @endif
          @if($problems !== [])<div class="chips" style="margin-top:5px;">@foreach($problems as $problem)<span class="chip {{ str_contains($problem, $L('unapproved')) ? 'warn' : 'bad' }}">{{ $problem }}</span>@endforeach</div>@endif
        </td>
        <td data-label="{{ $L('fitup') }}"><div class="progress"><i style="width:{{ $row['fit_percent'] }}%"></i></div><div class="muted" style="font-size:11px;margin-top:4px;">{{ $num($row['joints']['fitted']) }} / {{ $num($row['joints']['total']) }}</div></td>
        <td data-label="{{ $L('actions') }}">
          <div style="display:grid;gap:7px;">
            <a href="/projects/{{ $project->code }}/piping/{{ $line->id }}/model?lang={{ $lang }}">{{ $L('model') }}</a>
            <form class="mini-form" method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/test-pack?lang={{ $lang }}">
              @csrf
              <select name="test_pack_id">
                <option value="">{{ $L('no_pack') }}</option>
                @foreach($allPacks as $p)
                  <option value="{{ $p->id }}" @selected($line->test_pack_id === $p->id)>{{ $p->code }}</option>
                @endforeach
              </select>
              <button type="submit">{{ $L('assign_pack') }}</button>
            </form>
          </div>
        </td>
      </tr>
    @endforeach
    @if($shown === 0)<tr><td colspan="7" class="empty">{{ $L('no_blockers') }}</td></tr>@endif
    </tbody>
  </table>
  @endif
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2 style="margin:0;font-size:18px;">{{ $L('packs') }}</h2><div class="muted" style="font-size:12px;margin-top:4px;">{{ $L('unassigned') }}: {{ $num($unassignedCount) }}</div></div>
  </div>

  <form class="mini-form" method="POST" action="/projects/{{ $project->code }}/piping/workspace/packs?lang={{ $lang }}" style="margin-bottom:14px;">
    @csrf
    <strong>{{ $L('new_pack') }}</strong>
    <input name="code" required maxlength="64" placeholder="{{ $L('code') }}" value="{{ old('code') }}">
    <input name="title_fa" maxlength="255" placeholder="{{ $L('title_fa') }}" value="{{ old('title_fa') }}">
    <input name="title_en" maxlength="255" placeholder="{{ $L('title_en') }}" value="{{ old('title_en') }}">
    <select name="subsystem_id">
      <option value="">{{ $L('subsystem') }}</option>
      @foreach($subsystems as $subsystem)
        <option value="{{ $subsystem->id }}">{{ $subsystem->code }}</option>
      @endforeach
    </select>
    <input name="test_pressure" type="number" step="0.0001" placeholder="{{ $L('pressure') }}">
    <select name="test_pressure_unit"><option value="bar">bar</option><option value="psi">psi</option></select>
    <button type="submit">{{ $L('create') }}</button>
  </form>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;">
    @forelse($packs as $pack)
      @php
        $stateLabel = \App\Models\PipingTestPack::label((string) $pack['pack']->state);
      @endphp
      <div class="metric">
        <span class="mono">{{ $pack['code'] }}</span>
        <b>{{ $num($pack['percent']) }}٪</b>
        <div class="progress"><i style="width:{{ $pack['percent'] }}%"></i></div>
        <div class="muted" style="font-size:12px;margin-top:8px;">{{ $pack['pack']->name($lang) }} · {{ $fa ? $stateLabel['fa'] : $stateLabel['en'] }}</div>
        <div class="muted" style="font-size:12px;margin-top:6px;">{{ $L('released') }} {{ $num($pack['released']) }} / {{ $num($pack['total']) }} · {{ $L('blocked') }} {{ $num($pack['blocked']) }}</div>
        @foreach(\App\Models\PipingTestPack::nextStates((string) $pack['pack']->state) as $next)
          @php $nextLabel = \App\Models\PipingTestPack::label($next); @endphp
          <form class="mini-form" method="POST" action="/projects/{{ $project->code }}/piping/workspace/packs/{{ $pack['pack']->id }}/transition?lang={{ $lang }}" style="margin-top:8px;">
            @csrf
            <input type="hidden" name="to" value="{{ $next }}">
            <button type="submit">{{ $L('advance') }}: {{ $fa ? $nextLabel['fa'] : $nextLabel['en'] }}</button>
          </form>
        @endforeach
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
