@php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'مدل خط', 'en' => 'Line model'],
        'sub'      => ['fa' => 'قطعات روی این خط و سرهای بازی که هنوز به چیزی وصل نیستند',
                       'en' => 'The components on this line and the ends not yet connected to anything'],
        'back'     => ['fa' => 'بازگشت به فهرست خطوط', 'en' => 'Back to the line register'],
        'seq'      => ['fa' => 'ردیف', 'en' => 'No.'],
        'type'     => ['fa' => 'نوع', 'en' => 'Type'],
        'size'     => ['fa' => 'سایز', 'en' => 'Size'],
        'ports'    => ['fa' => 'پورت‌ها', 'en' => 'Ports'],
        'length'   => ['fa' => 'طول', 'en' => 'Length'],
        'actions'  => ['fa' => 'اقدام', 'en' => 'Actions'],
        'remove'   => ['fa' => 'حذف', 'en' => 'Remove'],
        'none'     => ['fa' => 'هنوز چیزی روی این خط مدل نشده است. اولین قطعه را اضافه کنید.',
                       'en' => 'Nothing modelled on this line yet. Add the first component.'],
        'openends' => ['fa' => 'سرهای باز', 'en' => 'Open ends'],
        'complete' => ['fa' => 'سر بازی نمانده — این خط از نظر ساختاری کامل است.',
                       'en' => 'No open ends — this line is structurally complete.'],
        'attach'   => ['fa' => 'اتصال قطعه به این سر', 'en' => 'Attach a component to this end'],
        'attachbtn'=> ['fa' => 'اتصال', 'en' => 'Attach'],
        'add'      => ['fa' => 'افزودن قطعهٔ مستقل', 'en' => 'Add a standalone component'],
        'addbtn'   => ['fa' => 'افزودن', 'en' => 'Add'],
        'branch'   => ['fa' => 'سایز انشعاب', 'en' => 'Branch size'],
        'small'    => ['fa' => 'سر کوچک', 'en' => 'Small end'],
        'lenmm'    => ['fa' => 'طول (میلی‌متر)', 'en' => 'Length (mm)'],
        'sched'    => ['fa' => 'رده', 'en' => 'Schedule'],
        'inherit'  => ['fa' => 'سایز از پورت والد به ارث می‌رسد و قابل تایپ نیست.',
                       'en' => 'The size is inherited from the parent port and cannot be typed.'],
        'locked'   => ['fa' => 'این خط ابلاغ شده است؛ تغییر مدل آن نیاز به بازگشت به پیش‌نویس دارد.',
                       'en' => 'This line is released; changing its model needs a revert to draft first.'],
        'portsnote'=> ['fa' => 'تعداد پورت‌ها از نوع قطعه می‌آید — سه‌راه همیشه سه پورت دارد و کسی آن را وارد نمی‌کند.',
                       'en' => 'The port count comes from the component type — a tee always has three, and nobody types that.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    $tplName = function (string $code) use ($templates, $lang): string {
        foreach ($templates as $tpl) {
            if ($tpl['code'] === $code) {
                return $tpl['name'][$lang] ?? $tpl['name']['en'] ?? $code;
            }
        }
        return $code;
    };

    $q = ['lang' => $lang] + (request()->has('sid') ? ['sid' => request()->query('sid')] : []);
    $base = "/projects/{$project->code}/piping/{$line->id}/model?".http_build_query($q);
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $line->line_number }} — {{ $L('title') }}</title>
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
    <span class="mono">{{ $line->line_number }}</span>
    · {{ $line->subsystem?->code }}
    @if($line->nominal_size) · {{ $num($line->nominal_size) }}" @endif
    @if($line->pipingClass) · {{ $line->pipingClass->code }} @endif
  </div>
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;">{{ $L('sub') }}</p>
  <p style="margin:10px 0 0;">
    <a href="/projects/{{ $project->code }}/piping?{{ http_build_query($q) }}"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; {{ $L('back') }}</a>
  </p>

  @if(! $editable)
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:13px;line-height:1.7;">
      {{ $L('locked') }}
    </div>
  @endif

  @if($errors->any())
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  {{-- ------------------------------------------------ open ends --------- --}}
  <h2 style="margin-top:26px;font-size:16px;">{{ $L('openends') }}
    <span style="color:var(--muted);font-weight:400;font-size:13px;">({{ $num(count($open)) }})</span>
  </h2>

  @if(count($open) === 0)
    <div style="margin-top:10px;padding:10px 12px;border-radius:8px;border:1px solid #14532d;background:rgba(20,83,45,.25);color:#86efac;font-size:13px;">
      {{ $L('complete') }}
    </div>
  @else
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-top:10px;">
      @foreach($open as $port)
        <div style="border:1px solid var(--line);border-radius:10px;background:var(--card);padding:10px 12px;">
          <div class="mono" style="font-size:13px;">{{ $port->component->label() }}</div>
          <div style="color:var(--muted);font-size:12px;margin-top:2px;">{{ $port->label() }}
            @if($port->end_type) · {{ $port->end_type }} @endif
          </div>

          @if($editable)
          <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/attach?{{ http_build_query($q) }}"
                style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
            @csrf
            <input type="hidden" name="port_id" value="{{ $port->id }}">
            <select name="template_code" required
                    style="flex:1 1 60%;min-width:0;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
              @foreach($templates as $tpl)
                <option value="{{ $tpl['code'] }}">{{ $tpl['name'][$lang] ?? $tpl['name']['en'] }} ({{ $num($tpl['ports']) }})</option>
              @endforeach
            </select>
            <button type="submit"
                    style="padding:5px 12px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
              {{ $L('attachbtn') }}
            </button>
          </form>
          @endif
        </div>
      @endforeach
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:8px;">{{ $L('inherit') }}</p>
  @endif

  {{-- ------------------------------------------------ components -------- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('title') }}</h2>

  <table data-tt style="margin-top:10px;">
    <thead>
      <tr>
        <th data-sort="number">{{ $L('seq') }}</th>
        <th data-sort="text" data-facet>{{ $L('type') }}</th>
        <th data-sort="text">{{ $L('size') }}</th>
        <th>{{ $L('ports') }}</th>
        <th data-sort="number">{{ $L('length') }}</th>
        <th>{{ $L('actions') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $c)
      <tr>
        <td class="mono">{{ $num($c->sequence) }}</td>
        <td>{{ $tplName($c->template_code) }}
          <div style="color:var(--muted);font-size:11px;" class="mono">{{ $c->template_code }}</div>
        </td>
        <td class="mono">{{ $c->nominal_size ? $num($c->nominal_size).'"' : '—' }}</td>
        <td style="font-size:12px;">
          @foreach($c->ports as $p)
            <span style="display:inline-block;margin:1px 3px 1px 0;padding:1px 6px;border-radius:999px;font-size:11px;
                         border:1px solid {{ $p->is_connected ? '#14532d' : 'var(--line)' }};
                         background:{{ $p->is_connected ? 'rgba(20,83,45,.3)' : 'transparent' }};
                         color:{{ $p->is_connected ? '#86efac' : 'inherit' }};"
                  title="{{ $p->is_connected ? 'connected' : 'open' }}">
              P{{ $num($p->seq) }} {{ $p->role }}{{ $p->nominal_size ? ' '.$num($p->nominal_size).'"' : '' }}
            </span>
          @endforeach
        </td>
        <td class="mono">{{ $c->length_mm ? $num(rtrim(rtrim(number_format((float) $c->length_mm, 1, '.', ''), '0'), '.')) : '—' }}</td>
        <td>
          @if($editable)
          <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/{{ $c->id }}/delete?{{ http_build_query($q) }}"
                onsubmit="return confirm('{{ $L('remove') }}?')">
            @csrf
            <button type="submit"
                    style="padding:4px 10px;border-radius:6px;border:1px solid var(--line);background:transparent;color:#fca5a5;font:inherit;font-size:12px;cursor:pointer;">
              {{ $L('remove') }}
            </button>
          </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="6" style="color:var(--muted);">{{ $L('none') }}</td></tr>
    @endforelse
    </tbody>
  </table>

  {{-- ------------------------------------------------ add standalone ---- --}}
  @if($editable)
  <div style="margin-top:22px;border:1px solid var(--line);border-radius:10px;background:var(--card);padding:14px;">
    <h2 style="margin:0 0 4px;font-size:15px;">{{ $L('add') }}</h2>
    <p style="color:var(--muted);font-size:12px;margin:0 0 12px;line-height:1.7;">{{ $L('portsnote') }}</p>

    <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model?{{ http_build_query($q) }}"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;">
      @csrf

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('type') }}
        <select name="template_code" required
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          @foreach($templates as $tpl)
            <option value="{{ $tpl['code'] }}" @selected(old('template_code') === $tpl['code'])>
              {{ $tpl['name'][$lang] ?? $tpl['name']['en'] }} — {{ $num($tpl['ports']) }} {{ $L('ports') }}
            </option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('size') }}
        <select name="nominal_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('nominal_size', $line->nominal_size) === $s)>{{ $num($s) }}"</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('branch') }}
        <select name="branch_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('branch_size') === $s)>{{ $num($s) }}"</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('small') }}
        <select name="small_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('small_size') === $s)>{{ $num($s) }}"</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('lenmm') }}
        <input type="number" step="any" min="0" name="length_mm" value="{{ old('length_mm') }}"
               style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
      </label>

      <button type="submit"
              style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
        {{ $L('addbtn') }}
      </button>
    </form>
  </div>
  @endif

  <div class="foot">
    {{ $fa
        ? 'تعداد و نقش پورت‌ها از تمپلیت قطعه می‌آید، نه از ورودی کاربر؛ سایز هنگام اتصال از پورت والد به ارث می‌رسد. به همین دلیل «لولهٔ ۶ اینچ روی انشعاب ۲ اینچ» اصلاً قابل بیان نیست.'
        : 'Port count and roles come from the component template, not from user input; size is inherited from the parent port on attachment. That is why "6\" pipe onto a 2\" branch" is not expressible at all.' }}
  </div>
</div>
</div>
</body>
</html>
