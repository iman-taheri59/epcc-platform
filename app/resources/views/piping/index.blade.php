@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'خطوط لوله', 'en' => 'Piping lines'],
        'sub'     => ['fa' => 'رجیستر خطوط، شماره‌گذاری پروژه‌ای و دروازهٔ AFC',
                      'en' => 'Line register, project numbering and the AFC gate'],
        'number'  => ['fa' => 'شمارهٔ خط', 'en' => 'Line number'],
        'subsys'  => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'size'    => ['fa' => 'سایز', 'en' => 'Size'],
        'service' => ['fa' => 'سرویس', 'en' => 'Service'],
        'class'   => ['fa' => 'کلاس', 'en' => 'Class'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'evidence'=> ['fa' => 'مدارک AFC', 'en' => 'AFC evidence'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'new'     => ['fa' => 'ثبت خط جدید', 'en' => 'Add a line'],
        'add'     => ['fa' => 'ثبت خط', 'en' => 'Add line'],
        'empty'   => ['fa' => 'هنوز خطی ثبت نشده است.', 'en' => 'No lines yet.'],
        'total'   => ['fa' => 'خط', 'en' => 'lines'],
        'draft'   => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
        'afc'     => ['fa' => 'تأیید AFC', 'en' => 'AFC'],
        'rel'     => ['fa' => 'ابلاغ‌شده', 'en' => 'Released'],
        'ext'     => ['fa' => 'شمارهٔ بیرونی', 'en' => 'External no.'],
        'pattern' => ['fa' => 'قالب شمارهٔ پروژه', 'en' => 'Project number format'],
        'autonum' => ['fa' => 'خالی بگذارید تا سیستم شماره بدهد', 'en' => 'Leave blank to allocate a number'],
        'orsupply'=> ['fa' => 'یا شمارهٔ موجود (از P&ID یا مدل سه‌بعدی)', 'en' => 'or an existing number (from P&ID or the 3D model)'],
        'missing' => ['fa' => 'مدرک ندارد:', 'en' => 'Missing:'],
        'notappr' => ['fa' => 'تأییدنشده:', 'en' => 'Not approved:'],
        'ready'   => ['fa' => 'آمادهٔ AFC', 'en' => 'Ready for AFC'],
        'note'    => ['fa' => 'توضیح', 'en' => 'Note'],
        'reason'  => ['fa' => 'دلیل بازگشت (الزامی)', 'en' => 'Reason (required)'],
        'nonconf' => ['fa' => 'خارج از قالب پروژه', 'en' => 'Outside the project format'],
        'noreg'   => ['fa' => 'این فهرست برای پروژه تعریف نشده است؛ تا تعریف نشود شماره‌گذاری خودکار ممکن نیست.',
                      'en' => 'Not set up for this project yet - automatic numbering needs it first.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
@endphp
<!doctype html>
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

  <h1>{{ $L('title') }}</h1>
  <div class="sub">
    <span class="mono">{{ $project->code }}</span>
    · {{ $project->name($lang) }}
    · {{ $jalaliDate }}
  </div>

  @if($errors->any())
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(127,29,29,.35);border:1px solid #991b1b;color:#fecaca;font-size:13px;line-height:1.8;">
      @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
  @endif

  <div class="bar">
    <span class="count"><b>{{ $num($stats['total']) }}</b> {{ $L('total') }}</span>
    <span class="pill">{{ $L('draft') }}: {{ $num($stats['draft']) }}</span>
    <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;">{{ $L('afc') }}: {{ $num($stats['afc']) }}</span>
    <span class="pill ok">{{ $L('rel') }}: {{ $num($stats['released']) }}</span>
    @if($stats['external'] > 0)
      <span class="pill" style="opacity:.8;">{{ $L('ext') }}: {{ $num($stats['external']) }}</span>
    @endif
    <span style="margin-{{ $fa ? 'right' : 'left' }}:auto;font-size:12px;color:var(--muted);">
      {{ $L('pattern') }}: <code>{{ $pattern }}</code>
    </span>
  </div>

  <table data-tt>
    <thead>
      <tr>
        <th data-sort="text">{{ $L('number') }}</th>
        <th data-sort="text" data-facet>{{ $L('subsys') }}</th>
        <th data-sort="text">{{ $L('size') }}</th>
        <th data-sort="text" data-facet>{{ $L('class') }}</th>
        <th data-sort="text" data-facet>{{ $L('state') }}</th>
        <th>{{ $L('evidence') }}</th>
        <th>{{ $L('actions') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $line)
      @php
        $b = $blockers[$line->id] ?? null;
        $ready = $b !== null && $b['missing'] === [] && $b['unapproved'] === [];
        $label = \App\Support\LineReleaseState::label((string) $line->release_state);
      @endphp
      <tr>
        <td class="mono">
          {{ $line->line_number }}
          @if($line->sequence === null)
            {{-- Numbered outside our scheme: worth seeing, not worth blocking. --}}
            <div style="font-size:11px;color:var(--muted);">{{ $L('ext') }}</div>
          @endif
        </td>
        <td>{{ $line->subsystem?->code }}</td>
        <td>{{ $line->nominal_size ? $num($line->nominal_size).'"' : '—' }}</td>
        <td>{{ $line->pipingClass?->code ?? '—' }}</td>
        <td>
          @if($line->release_state === \App\Support\LineReleaseState::RELEASED)
            <span class="pill ok">{{ $fa ? $label['fa'] : $label['en'] }}</span>
          @elseif($line->release_state === \App\Support\LineReleaseState::AFC_CONFIRMED)
            <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;">{{ $fa ? $label['fa'] : $label['en'] }}</span>
          @else
            <span class="pill">{{ $fa ? $label['fa'] : $label['en'] }}</span>
          @endif
        </td>
        <td style="font-size:11.5px;line-height:1.7;">
          @if($b === null)
            —
          @elseif($ready)
            <span style="color:#6ee7b7;">{{ $L('ready') }}</span>
          @else
            @if($b['missing'] !== [])
              <div style="color:#fca5a5;">{{ $L('missing') }} {{ implode(', ', $b['missing']) }}</div>
            @endif
            @if($b['unapproved'] !== [])
              <div style="color:#fcd34d;">{{ $L('notappr') }} {{ implode(', ', $b['unapproved']) }}</div>
            @endif
          @endif
        </td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
          @foreach(\App\Support\LineReleaseState::nextStates((string) $line->release_state) as $next)
            <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/transition?lang={{ $lang }}"
                  style="display:inline-flex;gap:4px;align-items:center;">
              @csrf
              <input type="hidden" name="to" value="{{ $next }}">
              @if($next === \App\Support\LineReleaseState::DRAFT)
                <input type="text" name="note" required maxlength="500"
                       placeholder="{{ $L('reason') }}"
                       style="width:120px;padding:4px 6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:11px;">
              @endif
              @php $nl = \App\Support\LineReleaseState::label($next); @endphp
              <button type="submit" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:11.5px;cursor:pointer;">
                {{ $fa ? $nl['fa'] : $nl['en'] }}
              </button>
            </form>
          @endforeach
          </div>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="empty">{{ $L('empty') }}</td></tr>
    @endforelse
    </tbody>
  </table>

  <div style="margin-top:22px;padding:14px;border-radius:10px;border:1px solid var(--line);background:var(--card);">
    <div style="font-size:13px;font-weight:600;margin-bottom:10px;">{{ $L('new') }}</div>
    <form method="POST" action="/projects/{{ $project->code }}/piping?lang={{ $lang }}"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;">
      @csrf
      <label style="font-size:12px;color:var(--muted);">
        {{ $L('subsys') }}
        <select name="subsystem_id" required style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          @foreach($subsystems as $s)
            <option value="{{ $s->id }}">{{ $s->code }}</option>
          @endforeach
        </select>
      </label>

      @php
          // A token the pattern uses but the project has not populated cannot
          // be composed - the engine refuses rather than invent a value. Show
          // that as an empty, disabled register instead of a free-text box
          // that can only produce an error on submit.
          $needs = fn (string $tok): bool => in_array($tok, $patternTokens, true);
      @endphp

      @if($needs('size'))
      <label style="font-size:12px;color:var(--muted);">
        {{ $L('size') }}
        <select name="nominal_size" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach($vocab['size'] ?? [] as $v)<option value="{{ $v }}">{{ $v }}"</option>@endforeach
        </select>
      </label>
      @endif

      @if($needs('service'))
      <label style="font-size:12px;color:var(--muted);">
        {{ $L('service') }}
        @if(($vocab['service'] ?? []) === [])
          <div style="margin-top:4px;padding:6px;border-radius:6px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:11px;line-height:1.6;">
            {{ $L('noreg') }}
          </div>
        @else
          <select name="service_code" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <option value="">—</option>
            @foreach($vocab['service'] as $v)<option value="{{ $v }}">{{ $v }}</option>@endforeach
          </select>
        @endif
      </label>
      @endif

      @if($needs('class'))
      <label style="font-size:12px;color:var(--muted);">
        {{ $L('class') }}
        @if($classes->isEmpty())
          <div style="margin-top:4px;padding:6px;border-radius:6px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:11px;line-height:1.6;">
            {{ $L('noreg') }}
          </div>
        @else
          <select name="piping_class_id" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <option value="">—</option>
            @foreach($classes as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach
          </select>
        @endif
      </label>
      @endif

      @if($needs('coating'))
      <label style="font-size:12px;color:var(--muted);">
        {{ $fa ? 'پوشش' : 'Coating' }}
        @if(($vocab['coating'] ?? []) === [])
          <div style="margin-top:4px;padding:6px;border-radius:6px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:11px;line-height:1.6;">
            {{ $L('noreg') }}
          </div>
        @else
          <select name="coating_code" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <option value="">—</option>
            @foreach($vocab['coating'] as $v)<option value="{{ $v }}">{{ $v }}</option>@endforeach
          </select>
        @endif
      </label>
      @endif

      <label style="font-size:12px;color:var(--muted);grid-column:span 2;">
        {{ $L('orsupply') }}
        <input type="text" name="line_number" placeholder="{{ $L('autonum') }}" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
      </label>

      <button type="submit" style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;cursor:pointer;">
        {{ $L('add') }}
      </button>
    </form>
  </div>

  <div class="foot">
    {{ $fa
        ? 'قالب شمارهٔ خط از PMS پروژه می‌آید و قابل پیکربندی است؛ خطوطی که از P&ID یا مدل سه‌بعدی می‌آیند با شمارهٔ خودشان ثبت می‌شوند و سیستم شماره‌ای اختراع نمی‌کند.'
        : 'The line number format comes from the project PMS and is configurable; lines arriving from a P&ID or the 3D model keep their own number and the system never invents one.' }}
  </div>
</div>
</div>
</body>
</html>
