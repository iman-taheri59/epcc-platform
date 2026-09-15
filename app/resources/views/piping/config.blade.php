@php
    $fa = $lang === 'fa';
    $t = fn (string $k): string => __('epcc.line.config.'.$k, [], $lang);
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    $kindLabel = [
        'attribute' => ['fa' => 'ویژگی خط', 'en' => 'Line attribute'],
        'parent'    => ['fa' => 'از والد', 'en' => 'From parent'],
        'counter'   => ['fa' => 'شمارنده', 'en' => 'Counter'],
        'literal'   => ['fa' => 'متن ثابت', 'en' => 'Fixed text'],
    ];
    $inp = 'width:100%;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;';
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $project->name($lang) }} — {{ $t('title') }}</title>
@include('partials.shell')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $t('title') }}</h1>
  <div class="sub">
    <span class="mono">{{ $project->code }}</span> · {{ $project->name($lang) }}
    · <a href="/projects/{{ $project->code }}/piping?lang={{ $lang }}">{{ $fa ? 'بازگشت به خطوط' : 'Back to lines' }}</a>
  </div>

  <p style="max-width:70ch;font-size:13px;line-height:1.9;color:var(--muted);margin:14px 0 20px;">
    {{ $t('intro') }}
  </p>

  @if(session('status'))
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(6,78,59,.35);border:1px solid #065f46;color:#6ee7b7;font-size:13px;">
      {{ session('status') }}
    </div>
  @endif

  @if($errors->any())
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(127,29,29,.35);border:1px solid #991b1b;color:#fecaca;font-size:13px;line-height:1.9;">
      @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
  @endif

  @unless($canEdit)
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:13px;line-height:1.8;">
      {{ $t('owner_only') }}
    </div>
  @endunless

  {{-- ------------------------------------------------ the number format --}}
  <div style="padding:16px;border-radius:10px;border:1px solid var(--line);background:var(--card);">
    <div style="font-size:13px;font-weight:600;margin-bottom:4px;">{{ $t('pattern') }}</div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:14px;">
      {{ $t('preview') }}: <code style="font-size:13px;">{{ $pattern->sketch() }}</code>
    </div>

    <form method="POST" action="/projects/{{ $project->code }}/piping/config/pattern?lang={{ $lang }}">
      @csrf

      <label style="display:inline-block;font-size:12px;color:var(--muted);margin-bottom:14px;">
        {{ $t('separator') }}
        <input type="text" name="separator" value="{{ $pattern->separator }}" maxlength="3"
               {{ $canEdit ? '' : 'disabled' }}
               style="{{ $inp }}width:70px;margin-top:4px;text-align:center;">
      </label>

      {{-- data-tt-exempt: this is an editor grid, not a data table. The row
           ORDER is the data - it is the order the tokens appear in the number -
           so sorting would corrupt what is being edited, and filtering would
           hide rows that are about to be submitted together. --}}
      <div style="overflow-x:auto;">
      <table data-tt-exempt style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="text-align:{{ $fa ? 'right' : 'left' }};color:var(--muted);">
            <th style="padding:6px 8px;font-weight:500;">#</th>
            <th style="padding:6px 8px;font-weight:500;">{{ $fa ? 'نام توکن' : 'Token' }}</th>
            <th style="padding:6px 8px;font-weight:500;">{{ $fa ? 'منبع' : 'Source' }}</th>
            <th style="padding:6px 8px;font-weight:500;">{{ $fa ? 'ستون / مسیر' : 'Attribute' }}</th>
            <th style="padding:6px 8px;font-weight:500;">{{ $fa ? 'عرض' : 'Width' }}</th>
            <th style="padding:6px 8px;font-weight:500;">{{ $fa ? 'اختیاری' : 'Optional' }}</th>
            <th style="padding:6px 8px;font-weight:500;">{{ $fa ? 'مقادیر ثابت' : 'Fixed values' }}</th>
          </tr>
        </thead>
        <tbody>
        @foreach($pattern->tokens() as $i => $tok)
          <tr style="border-top:1px solid var(--line);">
            <td style="padding:6px 8px;color:var(--muted);">{{ $num($i + 1) }}</td>
            <td style="padding:6px 8px;">
              <input type="text" name="tokens[{{ $i }}][token]" value="{{ $tok->name }}" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}">
            </td>
            <td style="padding:6px 8px;">
              <select name="tokens[{{ $i }}][source]" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}">
                @foreach($tokenKinds as $k)
                  <option value="{{ $k }}" @selected($tok->kind === $k)>{{ $fa ? $kindLabel[$k]['fa'] : $kindLabel[$k]['en'] }}</option>
                @endforeach
              </select>
            </td>
            <td style="padding:6px 8px;">
              <input type="text" name="tokens[{{ $i }}][attribute]" value="{{ $tok->attribute }}" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}">
            </td>
            <td style="padding:6px 8px;">
              <input type="number" name="tokens[{{ $i }}][width]" value="{{ $tok->width ?: '' }}" min="0" max="9" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}width:64px;">
            </td>
            <td style="padding:6px 8px;text-align:center;">
              <input type="checkbox" name="tokens[{{ $i }}][optional]" value="1" @checked($tok->optional) {{ $canEdit ? '' : 'disabled' }}>
            </td>
            <td style="padding:6px 8px;">
              <input type="text" name="tokens[{{ $i }}][values]" value="{{ implode(', ', $tok->values) }}" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}">
            </td>
          </tr>
        @endforeach

        {{-- Two blank rows, so adding a token needs no JavaScript. --}}
        @for($j = count($pattern->tokens()); $j < count($pattern->tokens()) + 2; $j++)
          <tr style="border-top:1px solid var(--line);opacity:.75;">
            <td style="padding:6px 8px;color:var(--muted);">{{ $num($j + 1) }}</td>
            <td style="padding:6px 8px;"><input type="text" name="tokens[{{ $j }}][token]" placeholder="{{ $fa ? 'افزودن…' : 'add…' }}" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}"></td>
            <td style="padding:6px 8px;">
              <select name="tokens[{{ $j }}][source]" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}">
                @foreach($tokenKinds as $k)
                  <option value="{{ $k }}">{{ $fa ? $kindLabel[$k]['fa'] : $kindLabel[$k]['en'] }}</option>
                @endforeach
              </select>
            </td>
            <td style="padding:6px 8px;"><input type="text" name="tokens[{{ $j }}][attribute]" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}"></td>
            <td style="padding:6px 8px;"><input type="number" name="tokens[{{ $j }}][width]" min="0" max="9" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}width:64px;"></td>
            <td style="padding:6px 8px;text-align:center;"><input type="checkbox" name="tokens[{{ $j }}][optional]" value="1" {{ $canEdit ? '' : 'disabled' }}></td>
            <td style="padding:6px 8px;"><input type="text" name="tokens[{{ $j }}][values]" {{ $canEdit ? '' : 'disabled' }} style="{{ $inp }}"></td>
          </tr>
        @endfor
        </tbody>
      </table>
      </div>

      @if($issued > 0)
        <div style="margin-top:12px;font-size:11.5px;color:var(--muted);line-height:1.8;">
          {{ $fa
              ? 'این پروژه تا شمارهٔ '.$num($issued).' خط صادر کرده است؛ عرض شمارنده نمی‌تواند کمتر از تعداد ارقام آن شود.'
              : 'This project has issued up to line '.$issued.'; the counter cannot be narrower than that many digits.' }}
        </div>
      @endif

      @if($canEdit)
        <button type="submit" style="margin-top:14px;padding:7px 16px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
          {{ $t('save') }}
        </button>
      @endif
    </form>
  </div>

  {{-- ---------------------------------------------- the project registers --}}
  <div style="margin-top:22px;font-size:13px;font-weight:600;">{{ $t('registers') }}</div>
  <div style="font-size:12px;color:var(--muted);margin:4px 0 12px;">{{ $t('in_use_note') }}</div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
    @foreach($registers as $reg)
      @php $values = (array) ($config->{$reg} ?? []); @endphp
      <div style="padding:14px;border-radius:10px;border:1px solid var(--line);background:var(--card);">
        <form method="POST" action="/projects/{{ $project->code }}/piping/config/register?lang={{ $lang }}">
          @csrf
          <input type="hidden" name="register" value="{{ $reg }}">
          <div style="font-size:12.5px;font-weight:600;margin-bottom:2px;">{{ $t($reg) }}</div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:8px;">
            {{ $t('one_per_line') }} · {{ $num(count($values)) }}
          </div>
          <textarea name="values" rows="6" {{ $canEdit ? '' : 'disabled' }}
                    style="{{ $inp }}font-family:ui-monospace,monospace;resize:vertical;">{{ implode("\n", $values) }}</textarea>
          @if($canEdit)
            <button type="submit" style="margin-top:10px;padding:6px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
              {{ $t('save') }}
            </button>
          @endif
        </form>
      </div>
    @endforeach
  </div>

  <div class="foot">
    {{ $fa
        ? 'شماره‌های صادرشده هرگز با تغییر این تنظیمات بازنویسی نمی‌شوند؛ قالب تازه فقط بر خطوط بعدی اعمال می‌شود.'
        : 'Numbers already issued are never rewritten by a change here; a new format applies to subsequent lines only.' }}
  </div>
</div>
</div>
</body>
</html>
