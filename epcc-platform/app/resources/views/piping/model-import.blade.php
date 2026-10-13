@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'ورود مدل سه‌بعدی', 'en' => '3D model import'],
        'sub'     => ['fa' => 'مدل از فایل PCF خوانده می‌شود — خروجی استاندارد Plant 3D، CADWorx، AVEVA، SmartPlant و Creo. خودِ فایل NWD خواندنی نیست.',
                      'en' => 'The model is read from a PCF file — the standard export of Plant 3D, CADWorx, AVEVA, SmartPlant and Creo. The NWD itself cannot be read.'],
        'back'    => ['fa' => 'بازگشت به فهرست خطوط', 'en' => 'Back to the line register'],
        'upload'  => ['fa' => 'بارگذاری فایل PCF', 'en' => 'Upload a PCF file'],
        'file'    => ['fa' => 'فایل', 'en' => 'File'],
        'quar'    => ['fa' => 'زیرسیستم قرنطینه', 'en' => 'Quarantine subsystem'],
        'quarnote'=> ['fa' => 'اگر شمارهٔ خطِ داخل فایل در لیست خطوط نباشد، خط اینجا ساخته و قرنطینه می‌شود؛ تا وقتی کسی آن را نپذیرد، جزو کار به حساب نمی‌آید.',
                      'en' => 'If the line number in the file is not in the line register, the line is created here and quarantined — it does not count as work until somebody accepts it.'],
        'dry'     => ['fa' => 'فقط گزارش بده، چیزی ننویس', 'en' => 'Report only, write nothing'],
        'go'      => ['fa' => 'بارگذاری', 'en' => 'Import'],
        'hist'    => ['fa' => 'تاریخچهٔ بارگذاری', 'en' => 'Import history'],
        'when'    => ['fa' => 'زمان', 'en' => 'When'],
        'fname'   => ['fa' => 'فایل', 'en' => 'File'],
        'line'    => ['fa' => 'خط', 'en' => 'Line'],
        'rev'     => ['fa' => 'نسخه', 'en' => 'Rev'],
        'result'  => ['fa' => 'نتیجه', 'en' => 'Result'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'created' => ['fa' => 'ساخته', 'en' => 'created'],
        'updated' => ['fa' => 'به‌روز', 'en' => 'updated'],
        'unchg'   => ['fa' => 'بدون تغییر', 'en' => 'unchanged'],
        'prot'    => ['fa' => 'محافظت‌شده', 'en' => 'protected'],
        'jnts'    => ['fa' => 'اتصال', 'en' => 'joints'],
        'unmap'   => ['fa' => 'ناشناخته', 'en' => 'unmapped'],
        'nohist'  => ['fa' => 'هنوز مدلی بارگذاری نشده است.', 'en' => 'No model has been imported yet.'],
        'attn'    => ['fa' => 'نیازمند تصمیم', 'en' => 'Needs a decision'],
        'attnnote'=> ['fa' => 'این‌ها تغییراتی‌اند که مدل می‌خواست اعمال کند و سیستم اعمال نکرد — چون کار در کارگاه انجام شده یا کسی دستی ویرایش کرده. هیچ‌کدام خطا نیستند؛ گفت‌وگوی مهندسی و اجرا هستند.',
                      'en' => 'These are changes the model wanted and the system withheld — because the field has already built it, or somebody edited it by hand. None of them is an error; they are a conversation between engineering and construction.'],
        'noattn'  => ['fa' => 'چیزی برای تصمیم‌گیری نمانده است.', 'en' => 'Nothing is waiting for a decision.'],
        'change'  => ['fa' => 'تغییر', 'en' => 'Change'],
        'what'    => ['fa' => 'چه چیزی', 'en' => 'What'],
        'why'     => ['fa' => 'چرا', 'en' => 'Why'],
        'ack'     => ['fa' => 'دیدم', 'en' => 'Seen'],
        'qlines'  => ['fa' => 'خطوط قرنطینه‌شده', 'en' => 'Quarantined lines'],
        'qnote'   => ['fa' => 'مدل این خطوط را می‌شناسد ولی لیست خطوط نه. تا پذیرفته نشوند، در آمار پیشرفت شمرده نمی‌شوند.',
                      'en' => 'The model knows these lines; the line register does not. Until they are accepted they are not counted in progress.'],
        'accept'  => ['fa' => 'پذیرش در لیست خطوط', 'en' => 'Accept into the register'],
        'noq'     => ['fa' => 'خط قرنطینه‌شده‌ای وجود ندارد.', 'en' => 'No quarantined lines.'],
        'r_fitted'=> ['fa' => 'جوینت در کارگاه نصب شده', 'en' => 'a joint is fitted in the field'],
        'r_edited'=> ['fa' => 'دستی ویرایش شده', 'en' => 'edited by hand'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    $q = ['lang' => $lang];
    $base = "/projects/{$project->code}/piping/model-import?".http_build_query($q);

    // System-generated reasons are stored as machine keys and rendered here,
    // so the reader's language wins rather than the importer's. A reason that
    // is not a known key is a human's own words and is shown verbatim.
    $quarantineReason = function (?string $r) use ($lang): string {
        if (is_string($r) && str_starts_with($r, 'AUTO:FROM_FILE:')) {
            $file = substr($r, strlen('AUTO:FROM_FILE:'));

            return $lang === 'fa'
                ? 'از فایل مدل سه‌بعدی '.$file.' ساخته شد و هنوز در لیست خطوط پذیرفته نشده است.'
                : 'Created from the 3D model file '.$file.', and not yet accepted into the line register.';
        }

        return (string) $r;
    };

    $reasonLabel = function (?string $r) use ($L): string {
        return match ($r) {
            'FITTED_JOINT' => $L('r_fitted'),
            'MANUALLY_EDITED' => $L('r_edited'),
            default => (string) $r,
        };
    };

    $changeColour = fn (string $c): string => match ($c) {
        'PROTECTED' => '#fcd34d',
        'UNMAPPED' => '#fca5a5',
        'REMOVED_IN_MODEL' => '#f9a8d4',
        'CREATED' => '#86efac',
        'UPDATED' => '#93c5fd',
        default => 'var(--muted)',
    };
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $project->code }}</title>
@include('partials.shell')
@include('partials.tabletools')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;">{{ $L('sub') }}</p>
  <p style="margin:10px 0 0;">
    <a href="/projects/{{ $project->code }}/piping?{{ http_build_query($q) }}"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; {{ $L('back') }}</a>
  </p>

  @if($errors->any())
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  {{-- ------------------------------------------------------- upload ----- --}}
  <h2 style="margin-top:26px;font-size:16px;">{{ $L('upload') }}</h2>

  <form method="post" action="{{ $base }}" enctype="multipart/form-data"
        style="margin-top:12px;padding:14px;border:1px solid var(--line,#1f2937);border-radius:10px;display:grid;gap:12px;max-width:640px;">
    @csrf
    <label style="display:grid;gap:5px;font-size:13px;">
      <span style="color:var(--muted);">{{ $L('file') }}</span>
      <input type="file" name="file" accept=".pcf,text/plain" required
             style="font-size:13px;padding:7px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
    </label>

    <label style="display:grid;gap:5px;font-size:13px;">
      <span style="color:var(--muted);">{{ $L('quar') }}</span>
      <select name="subsystem_id"
              style="font-size:13px;padding:7px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
        <option value="">—</option>
        @foreach($subsystems as $s)
          <option value="{{ $s->id }}">{{ $s->code }}</option>
        @endforeach
      </select>
      <span style="color:var(--muted);font-size:12px;line-height:1.7;">{{ $L('quarnote') }}</span>
    </label>

    <label style="display:flex;gap:7px;align-items:center;font-size:13px;">
      <input type="checkbox" name="dry_run" value="1">
      <span>{{ $L('dry') }}</span>
    </label>

    <div><button type="submit" class="btn">{{ $L('go') }}</button></div>
  </form>

  {{-- ------------------------------------------------- needs a decision -- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('attn') }}
    <span style="color:var(--muted);font-weight:400;font-size:13px;">({{ $num(count($attention)) }})</span>
  </h2>
  <p style="color:var(--muted);font-size:12px;line-height:1.8;margin:6px 0 0;max-width:780px;">{{ $L('attnnote') }}</p>

  @if(count($attention) === 0)
    <p style="color:var(--muted);font-size:13px;margin-top:10px;">{{ $L('noattn') }}</p>
  @else
    <table data-tt style="margin-top:12px;">
      <thead><tr>
        <th>{{ $L('change') }}</th><th>{{ $L('what') }}</th><th>{{ $L('why') }}</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($attention as $d)
        <tr>
          <td style="color:{{ $changeColour($d->change) }};font-size:12px;">{{ $d->change }}</td>
          <td style="font-size:13px;">
            <span class="mono">{{ $d->component?->pcf_identifier ?? $d->pcf_type ?? '—' }}</span>
            @if($d->template_code)<span style="color:var(--muted);"> · {{ $d->template_code }}</span>@endif
          </td>
          <td style="font-size:12px;color:var(--muted);line-height:1.7;">
            @if($d->protected_reason){{ $reasonLabel($d->protected_reason) }}@endif
            @if($d->detail)<div>{{ $d->detail }}</div>@endif
            @if($d->before && $d->after)
              <div class="mono" style="font-size:11px;margin-top:3px;">
                {{ json_encode($d->before, JSON_UNESCAPED_UNICODE) }}
                &rarr; {{ json_encode($d->after, JSON_UNESCAPED_UNICODE) }}
              </div>
            @endif
          </td>
          <td>
            <form method="post" action="/projects/{{ $project->code }}/piping/model-import/diff/{{ $d->id }}/ack?{{ http_build_query($q) }}">
              @csrf
              <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;">{{ $L('ack') }}</button>
            </form>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  {{-- ------------------------------------------------- quarantined ------ --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('qlines') }}
    <span style="color:var(--muted);font-weight:400;font-size:13px;">({{ $num(count($quarantined)) }})</span>
  </h2>
  <p style="color:var(--muted);font-size:12px;line-height:1.8;margin:6px 0 0;max-width:780px;">{{ $L('qnote') }}</p>

  @if(count($quarantined) === 0)
    <p style="color:var(--muted);font-size:13px;margin-top:10px;">{{ $L('noq') }}</p>
  @else
    <table data-tt style="margin-top:12px;">
      <thead><tr><th>{{ $L('line') }}</th><th>{{ $L('why') }}</th><th></th></tr></thead>
      <tbody>
      @foreach($quarantined as $l)
        <tr>
          <td class="mono" style="font-size:13px;">{{ $l->line_number }}</td>
          <td style="font-size:12px;color:var(--muted);">{{ $quarantineReason($l->quarantine_reason) }}</td>
          <td>
            <form method="post" action="/projects/{{ $project->code }}/piping/model-import/line/{{ $l->id }}/accept?{{ http_build_query($q) }}">
              @csrf
              <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;">{{ $L('accept') }}</button>
            </form>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  {{-- ------------------------------------------------------ history ----- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('hist') }}</h2>

  @if(count($imports) === 0)
    <p style="color:var(--muted);font-size:13px;margin-top:10px;">{{ $L('nohist') }}</p>
  @else
    <table data-tt style="margin-top:12px;">
      <thead><tr>
        <th>{{ $L('when') }}</th><th>{{ $L('fname') }}</th><th>{{ $L('line') }}</th>
        <th>{{ $L('rev') }}</th><th>{{ $L('result') }}</th><th>{{ $L('state') }}</th>
      </tr></thead>
      <tbody>
      @foreach($imports as $im)
        <tr>
          <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
            {{ \App\Support\JalaliDate::format($im->created_at, $lang) }}
          </td>
          <td class="mono" style="font-size:12px;">{{ $im->filename }}</td>
          <td class="mono" style="font-size:12px;">{{ $im->line?->line_number ?? $im->pipeline_reference }}</td>
          <td style="font-size:12px;">{{ $im->revision ?? '—' }}</td>
          <td style="font-size:12px;line-height:1.8;">
            <span style="color:#86efac;">{{ $num($im->components_created) }} {{ $L('created') }}</span> ·
            <span style="color:#93c5fd;">{{ $num($im->components_updated) }} {{ $L('updated') }}</span> ·
            <span style="color:var(--muted);">{{ $num($im->components_unchanged) }} {{ $L('unchg') }}</span>
            @if($im->components_protected > 0)
              · <span style="color:#fcd34d;">{{ $num($im->components_protected) }} {{ $L('prot') }}</span>
            @endif
            @if($im->unmapped_count > 0)
              · <span style="color:#fca5a5;">{{ $num($im->unmapped_count) }} {{ $L('unmap') }}</span>
            @endif
            · {{ $num($im->joints_created) }} {{ $L('jnts') }}
          </td>
          <td style="font-size:12px;">{{ $im->state }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <div class="foot">
    {{ $fa
        ? 'واحد اندازه از هدر فایل خوانده می‌شود و هرگز فرض نمی‌شود: فایلی با INCH-SIXTEENTH که INCH خوانده شود، خط ۶ اینچ را ۹۶ اینچ می‌کند و هیچ عددی بعد از آن مشکوک به نظر نمی‌رسد. جوینت‌ها هم از روی مختصات ساخته می‌شوند، ولی همان موتور سازگاری قضاوتشان می‌کند که برای مدل‌سازی دستی کار می‌کند.'
        : 'The bore unit is read from the header and never assumed: a file in INCH-SIXTEENTH read as INCH turns a 6" line into 96", and no number after that looks wrong. Joints are built from coordinates, but the same compatibility engine judges them as it does for hand modelling.' }}
  </div>
</div>
</div>
</body>
</html>
