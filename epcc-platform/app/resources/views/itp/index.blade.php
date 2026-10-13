@php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'برنامه‌های بازرسی و آزمون (ITP)', 'en' => 'Inspection & Test Plans (ITP)'],
        'sub'      => ['fa' => 'هر دیسیپلین یک ITP دارد. لوله‌کشی واحد و خط لولهٔ برون‌شهری عمداً جدا هستند: کدهای حاکم، ارکان بازرسی و معیارهای پذیرش‌شان یکی نیست.',
                       'en' => 'One ITP per discipline. Plant piping and cross-country pipeline are deliberately separate: different governing codes, different inspection bodies, different acceptance criteria.'],
        'new'      => ['fa' => 'ساخت از روی الگوی استاندارد', 'en' => 'Create from a standard template'],
        'template' => ['fa' => 'الگو', 'en' => 'Template'],
        'number'   => ['fa' => 'شماره (اختیاری)', 'en' => 'Number (optional)'],
        'not_registered' => ['fa' => 'ثبت‌نشده', 'en' => 'not registered'],
        'create'   => ['fa' => 'بساز', 'en' => 'Create'],
        'disc'     => ['fa' => 'دیسیپلین', 'en' => 'Discipline'],
        'num'      => ['fa' => 'شماره', 'en' => 'Number'],
        'ttl'      => ['fa' => 'عنوان', 'en' => 'Title'],
        'stages'   => ['fa' => 'ردیف', 'en' => 'Stages'],
        'state'    => ['fa' => 'وضعیت', 'en' => 'State'],
        'act'      => ['fa' => 'لازم‌الاجرا کن', 'en' => 'Put in force'],
        'none'     => ['fa' => 'هنوز ITP ای ساخته نشده است.', 'en' => 'No ITP has been created yet.'],
        'open'     => ['fa' => 'مشاهده', 'en' => 'Open'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];

    $stateLabel = fn (string $s): string => match ($s) {
        'ACTIVE' => $fa ? 'لازم‌الاجرا' : 'In force',
        'DRAFT' => $fa ? 'پیش‌نویس' : 'Draft',
        default => $fa ? 'جایگزین‌شده' : 'Superseded',
    };
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }}</title>
@include('partials.shell')
@include('partials.tabletools')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;max-width:820px;">{{ $L('sub') }}</p>

  @if($errors->any())
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  <form method="post" action="/projects/{{ $project->code }}/itp/from-template?{{ http_build_query($q) }}"
        style="margin-top:16px;padding:14px;border:1px solid var(--line,#1f2937);border-radius:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    @csrf
    <div style="display:grid;gap:5px;">
      <label style="font-size:12px;color:var(--muted);">{{ $L('template') }}</label>
      <select name="template_id" required
              style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;min-width:320px;">
        @foreach($templates as $tpl)
          <option value="{{ $tpl->id }}">{{ $tpl->discipline }} — {{ $tpl->title($lang) }}</option>
        @endforeach
      </select>
    </div>
    <div style="display:grid;gap:5px;">
      <label style="font-size:12px;color:var(--muted);">{{ $L('number') }}</label>
      <input type="text" name="number"
             style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
    </div>
    <button type="submit" class="btn">{{ $L('create') }}</button>
  </form>

  @if(count($itps) === 0)
    <p style="color:var(--muted);font-size:13px;margin-top:14px;">{{ $L('none') }}</p>
  @else
    <table data-tt style="margin-top:16px;">
      <thead><tr>
        <th>{{ $L('disc') }}</th><th>{{ $L('num') }}</th><th>{{ $L('ttl') }}</th>
        <th>{{ $L('stages') }}</th><th>{{ $L('state') }}</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($itps as $row)
        <tr>
          <td style="font-size:12px;"><span class="mono">{{ $row->discipline }}</span></td>
          <td style="font-size:12px;white-space:nowrap;">
            <bdi class="mono">{{ $row->displayNumber() }}</bdi>
            @unless($row->isRegistered())
              <div style="color:#fbbf24;font-size:10px;">{{ $L('not_registered') }}</div>
            @endunless
          </td>
          <td style="font-size:13px;">
            <a href="/projects/{{ $project->code }}/itp/{{ $row->id }}?{{ http_build_query($q) }}"
               style="color:var(--accent,#60a5fa);">{{ $row->title($lang) }}</a>
          </td>
          <td style="font-size:12px;">{{ $num($row->stages_count) }}</td>
          <td style="font-size:12px;color:{{ $row->state === 'ACTIVE' ? '#86efac' : ($row->state === 'DRAFT' ? '#fcd34d' : 'var(--muted)') }};">
            {{ $stateLabel($row->state) }}
          </td>
          <td>
            @if($row->state === 'DRAFT')
              <form method="post" action="/projects/{{ $project->code }}/itp/{{ $row->id }}/activate?{{ http_build_query($q) }}">
                @csrf
                <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;">{{ $L('act') }}</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <div class="foot">
    {{ $fa
        ? 'نقطهٔ توقف (H) کار را متوقف می‌کند؛ نقطهٔ شاهد (W) فقط دعوت می‌خواهد. همین تفاوت است که تعیین می‌کند هزینهٔ یک هفته توقف با کیست، و به همین دلیل با یک تیک «بازرسی لازم است» جایگزین نشده است.'
        : 'A hold point (H) stops the work; a witness point (W) only requires an invitation. That difference decides who pays for a week of standby, which is why it is not collapsed into one "inspection required" tick box.' }}
  </div>
</div>
</div>
</body>
</html>
