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
        'pickdoc' => ['fa' => 'مدرک…', 'en' => 'Document…'],
        'link'    => ['fa' => 'پیوست', 'en' => 'Link'],
        'unlink'  => ['fa' => 'حذف پیوست', 'en' => 'Remove link'],
        'config'  => ['fa' => 'تنظیم شماره‌گذاری', 'en' => 'Numbering setup'],
        'model'   => ['fa' => 'مدل', 'en' => 'Model'],
        'issuedPms'=> ['fa' => 'بازنگری PMS زمان صدور', 'en' => 'Issued PMS revision'],
        'unmapped'=> ['fa' => 'در انتظار بررسی کلاس و بازنگری PMS', 'en' => 'Class/PMS revision review required'],
        'nopms'  => ['fa' => 'هیچ کلاس منتشرشده‌ای در بازنگری تأییدشدهٔ جاری مدرک PMS موجود نیست؛ ثبت خط جدید تا تأیید آن ممکن نیست.',
                      'en' => 'No published class in a currently approved PMS revision. New lines require an approved PMS first.'],
        'noreg'   => ['fa' => 'این فهرست برای پروژه تعریف نشده است؛ تا تعریف نشود شماره‌گذاری خودکار ممکن نیست.',
                      'en' => 'Not set up for this project yet - automatic numbering needs it first.'],
        'dp'      => ['fa' => 'فشار طراحی', 'en' => 'Design pressure'],
        'dt'      => ['fa' => 'دمای طراحی', 'en' => 'Design temperature'],
        'tp'      => ['fa' => 'فشار تست', 'en' => 'Test pressure'],
        'cond'    => ['fa' => 'شرایط طراحی', 'en' => 'Design conditions'],
        'units'   => ['fa' => 'واحدها', 'en' => 'Units'],
        'unitsave'=> ['fa' => 'ذخیرهٔ واحدها', 'en' => 'Save units'],
        'mine'    => ['fa' => 'فقط برای من', 'en' => 'Just for me'],
        'forproj' => ['fa' => 'پیش‌فرض پروژه', 'en' => 'Project default'],
        'asent'   => ['fa' => 'ثبت‌شده', 'en' => 'as entered'],
        'unitsnote'=> ['fa' => 'مقدار همان‌طور که وارد شده ذخیره می‌شود؛ این انتخاب فقط نحوهٔ نمایش را عوض می‌کند.',
                       'en' => 'The value is stored exactly as entered; this choice only changes how it is displayed.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    // Render a stored value in the reader's chosen unit, and say what was
    // actually typed when the two differ. Showing only the converted number
    // would quietly replace the figure that appears on the drawing.
    $reg = app(\App\Support\Units\UnitRegistry::class);
    $show = function ($value, ?string $unit, string $quantity) use ($reg, $displayUnits, $num, $t, $lang): string {
        if ($value === null || $unit === null || $unit === '') {
            return '—';
        }
        $want = $displayUnits[$quantity] ?? $unit;
        $entered = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        if ($want === $unit || ! $reg->knows($unit)) {
            return $num($entered).' '.e($unit);
        }

        try {
            $converted = $reg->convert((float) $value, $unit, $want);
        } catch (\Throwable $e) {
            // Gauge/absolute, most likely: refusing is correct, so show the
            // original rather than a number nobody can defend.
            return $num($entered).' '.$unit;
        }

        $shown = rtrim(rtrim(number_format($converted, 2, '.', ''), '0'), '.');
        $note = $t['asent'][$lang] ?? 'as entered';

        // e(): the unit strings are database values rendered inside raw HTML
        // (the span below is deliberate markup, hence {!! !!} at the call site).
        return $num($shown).' '.e($want)
            .' <span style="color:var(--muted);font-size:11px;" title="'.e($note).'">('.$num($entered).' '.e($unit).')</span>';
    };
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
  <a href="{{ route('piping.project-fittings', ['projectCode' => $project->code, 'lang' => $lang]) }}">{{ $lang === 'fa' ? 'مشخصات فیتینگ و متریال پروژه' : 'Project fitting and material specifications' }}</a>
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
    @if($stats['unmapped'] > 0)
      <span class="pill" style="background:#7f1d1d;color:#fecaca;">{{ $L('unmapped') }}: {{ $num($stats['unmapped']) }}</span>
    @endif
    @if($stats['external'] > 0)
      <span class="pill" style="opacity:.8;">{{ $L('ext') }}: {{ $num($stats['external']) }}</span>
    @endif
    <span style="margin-{{ $fa ? 'right' : 'left' }}:auto;font-size:12px;color:var(--muted);">
      {{ $L('pattern') }}: <code>{{ $pattern }}</code>
      · <a href="/projects/{{ $project->code }}/piping/config?lang={{ $lang }}">{{ $L('config') }}</a>
    </span>
  </div>

  <table data-tt>
    <thead>
      <tr>
        <th data-sort="text">{{ $L('number') }}</th>
        <th data-sort="text" data-facet>{{ $L('subsys') }}</th>
        <th data-sort="text">{{ $L('size') }}</th>
        <th data-sort="text" data-facet>{{ $L('class') }}</th>
        <th data-sort="text">{{ $L('dp') }}</th>
        <th data-sort="text">{{ $L('dt') }}</th>
        <th data-sort="text" data-facet>{{ $L('state') }}</th>
        <th>{{ $L('evidence') }}</th>
        <th>{{ $L('actions') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $line)
      @php
        $b = $blockers[$line->id] ?? null;
        $ready = $b !== null && $line->pms_class_snapshot_id !== null
            && $b['missing'] === [] && $b['unapproved'] === [];
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
        <td>{{ $line->pipingClass?->code ?? '—' }}
          @if($line->pms_class_snapshot_id === null)
            <div style="font-size:11px;color:#fca5a5;">{{ $L('unmapped') }}</div>
          @else
            @php $issued = $line->pmsSnapshot?->documentRevision; @endphp
            <div style="font-size:11px;color:var(--muted);">
              {{ $L('issuedPms') }}:
              {{ $issued?->document?->number ?? 'PMS' }} · {{ $issued?->revision ?? '—' }}
              @if($issued?->suitability) ({{ $issued->suitability }}) @endif
            </div>
          @endif
        </td>
        <td class="mono" style="white-space:nowrap;">
          {!! $show($line->design_pressure, $line->design_pressure_unit, 'PRESSURE') !!}
        </td>
        <td class="mono" style="white-space:nowrap;">
          {!! $show($line->design_temperature, $line->design_temperature_unit, 'TEMPERATURE') !!}
        </td>
        <td>
          @if($line->release_state === \App\Support\LineReleaseState::RELEASED)
            <span class="pill ok">{{ $fa ? $label['fa'] : $label['en'] }}</span>
          @elseif($line->release_state === \App\Support\LineReleaseState::AFC_CONFIRMED)
            <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;">{{ $fa ? $label['fa'] : $label['en'] }}</span>
          @else
            <span class="pill">{{ $fa ? $label['fa'] : $label['en'] }}</span>
          @endif
        </td>
        <td style="font-size:11.5px;line-height:1.7;min-width:230px;">
          @php $links = $attached[$line->id] ?? collect(); @endphp

          @foreach($links as $link)
            @php
              $rev = $link->document?->currentRevision;
              $ok = $rev !== null && \App\Support\CdeState::isApproved((string) $rev->cde_state, $rev->suitability);
            @endphp
            <div style="display:flex;gap:5px;align-items:baseline;">
              <span style="color:{{ $ok ? '#6ee7b7' : '#fcd34d' }};">{{ $ok ? '✓' : '•' }}</span>
              <span class="mono" style="font-size:11px;">{{ $link->document?->number }}</span>
              <span style="color:var(--muted);font-size:10.5px;">
                {{ $link->evidence_kind }}@if($rev) · {{ $rev->cde_state }}/{{ $rev->suitability ?: '—' }}@endif
              </span>
              @if(\App\Support\LineReleaseState::isEditable((string) $line->release_state))
                <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/evidence/{{ $link->id }}/detach?lang={{ $lang }}" style="display:inline;">
                  @csrf
                  <button type="submit" title="{{ $L('unlink') }}"
                          style="border:0;background:none;color:#fca5a5;cursor:pointer;font:inherit;font-size:11px;padding:0 2px;">×</button>
                </form>
              @endif
            </div>
          @endforeach

          @if($b !== null)
            @if($ready)
              <div style="color:#6ee7b7;margin-top:3px;">{{ $L('ready') }}</div>
            @else
              @if($b['missing'] !== [])
                <div style="color:#fca5a5;">{{ $L('missing') }} {{ implode(', ', $b['missing']) }}</div>
              @endif
              @if($b['unapproved'] !== [])
                <div style="color:#fcd34d;">{{ $L('notappr') }} {{ implode(', ', $b['unapproved']) }}</div>
              @endif
            @endif
          @endif

          @if(\App\Support\LineReleaseState::isEditable((string) $line->release_state))
            <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/evidence?lang={{ $lang }}"
                  style="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;">
              @csrf
              <select name="document_id" required style="max-width:118px;padding:3px 4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:10.5px;">
                <option value="">{{ $L('pickdoc') }}</option>
                @foreach($documents as $doc)
                  <option value="{{ $doc->id }}">{{ $doc->number }}</option>
                @endforeach
              </select>
              <select name="evidence_kind" required style="max-width:96px;padding:3px 4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:10.5px;">
                @foreach($evidenceKinds as $kind)
                  <option value="{{ $kind }}" @selected(in_array($kind, $requiredKinds, true) && in_array($kind, $b['missing'] ?? [], true))>{{ $kind }}</option>
                @endforeach
              </select>
              <button type="submit" style="padding:3px 8px;border-radius:5px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:10.5px;cursor:pointer;">
                {{ $L('link') }}
              </button>
            </form>
          @endif
        </td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
          {{-- The way into ADR-006 phase 2: what this line is made of. --}}
          <a href="/projects/{{ $project->code }}/piping/{{ $line->id }}/model?lang={{ $lang }}"
             style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font-size:11.5px;text-decoration:none;">
            {{ $L('model') }}
          </a>
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

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('class') }}
        @if($classes->isEmpty())
          <div style="margin-top:4px;padding:6px;border-radius:6px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:11px;line-height:1.6;">
            {{ $L('nopms') }}
          </div>
        @else
          <select name="piping_class_id" required style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <option value="">—</option>
            @foreach($classes as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach
          </select>
        @endif
      </label>

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

      {{-- Design conditions. Value and unit are one control, because a
           pressure without its unit is not a pressure - the service will
           refuse to guess one rather than assume the project default. --}}
      @foreach([['design_pressure','dp','PRESSURE'],['design_temperature','dt','TEMPERATURE'],['test_pressure','tp','PRESSURE']] as [$field, $key, $qty])
      <label style="font-size:12px;color:var(--muted);">
        {{ $L($key) }}
        <span style="display:flex;gap:4px;margin-top:4px;">
          <input type="number" step="any" name="{{ $field }}" value="{{ old($field) }}"
                 style="flex:1 1 60%;min-width:0;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <select name="{{ $field }}_unit"
                  style="flex:0 0 38%;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            @foreach($unitMenu[$qty]['units'] as $u)
              <option value="{{ $u['code'] }}"
                @selected(old($field.'_unit', $displayUnits[$qty] ?? '') === $u['code'])>{{ $u['code'] }}</option>
            @endforeach
          </select>
        </span>
        @error($field.'_unit')
          <span style="display:block;margin-top:3px;color:#fca5a5;font-size:11px;">{{ $message }}</span>
        @enderror
      </label>
      @endforeach

      <label style="font-size:12px;color:var(--muted);grid-column:span 2;">
        {{ $L('orsupply') }}
        <input type="text" name="line_number" placeholder="{{ $L('autonum') }}" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
      </label>

      <button type="submit" @disabled($classes->isEmpty()) style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;cursor:pointer;">
        {{ $L('add') }}
      </button>
    </form>
  </div>

  {{-- Unit preferences. Two scopes on purpose: the employer sets what the
       project speaks in, and any reader may override it for themselves
       without touching a single stored value. --}}
  <details style="margin-top:18px;border:1px solid var(--line);border-radius:10px;background:var(--card);">
    <summary style="padding:10px 14px;cursor:pointer;font-weight:600;">{{ $L('units') }}</summary>
    <form method="POST" action="{{ route('piping.units', ['projectCode' => $project->code, 'lang' => $lang]) }}"
          style="padding:0 14px 14px;">
      @csrf
      <p style="font-size:12px;color:var(--muted);line-height:1.7;margin:4px 0 12px;">{{ $L('unitsnote') }}</p>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
        @foreach($unitMenu as $qty => $meta)
        <label style="font-size:12px;color:var(--muted);">
          {{ $meta['label'] }}
          <select name="units[{{ $qty }}]"
                  style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            @foreach($meta['units'] as $u)
              <option value="{{ $u['code'] }}" @selected(($displayUnits[$qty] ?? '') === $u['code'])>
                {{ $u['label'] }} ({{ $u['code'] }})
              </option>
            @endforeach
          </select>
        </label>
        @endforeach
      </div>

      @error('units')
        <p style="margin:10px 0 0;color:#fca5a5;font-size:12px;">{{ $message }}</p>
      @enderror

      <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <button type="submit" name="scope" value="user"
                style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;cursor:pointer;">
          {{ $L('unitsave') }} — {{ $L('mine') }}
        </button>
        @unless($unitsLocked)
          <button type="submit" name="scope" value="project"
                  style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
            {{ $L('unitsave') }} — {{ $L('forproj') }}
          </button>
        @endunless
      </div>
    </form>
  </details>

  <div class="foot">
    {{ $fa
        ? 'قالب شمارهٔ خط از PMS پروژه می‌آید و قابل پیکربندی است؛ خطوطی که از P&ID یا مدل سه‌بعدی می‌آیند با شمارهٔ خودشان ثبت می‌شوند و سیستم شماره‌ای اختراع نمی‌کند.'
        : 'The line number format comes from the project PMS and is configurable; lines arriving from a P&ID or the 3D model keep their own number and the system never invents one.' }}
  </div>
</div>
</div>
</body>
</html>
