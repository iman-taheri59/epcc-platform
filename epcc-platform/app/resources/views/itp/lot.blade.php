@php
    use App\Support\Itp\InterventionCode;
    use App\Support\Itp\StageResult;
    use App\Support\TenantType;

    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'بازرسی ورود متریال', 'en' => 'Incoming material inspection'],
        'prog'    => ['fa' => 'پیشرفت', 'en' => 'Progress'],
        'blocked' => ['fa' => 'این لات قابل ترخیص نیست', 'en' => 'This lot cannot be released'],
        'blocksub'=> ['fa' => 'کالا در انبار می‌ماند و شمرده می‌شود، اما تا آزادسازی نقطه‌های توقف زیر، اجازهٔ نصب ندارد.',
                      'en' => 'The material stays in the warehouse and is counted, but it may not be installed until the hold points below are released.'],
        'free'    => ['fa' => 'قابل ترخیص برای نصب', 'en' => 'Released for installation'],
        'clause'  => ['fa' => 'بند', 'en' => 'Clause'],
        'act'     => ['fa' => 'فعالیت', 'en' => 'Activity'],
        'who'     => ['fa' => 'ارکان', 'en' => 'Parties'],
        'res'     => ['fa' => 'نتیجه', 'en' => 'Result'],
        'action'  => ['fa' => 'اقدام', 'en' => 'Action'],
        'sign'    => ['fa' => 'ثبت نتیجه', 'en' => 'Record the outcome'],
        'invite'  => ['fa' => 'ثبت دعوت', 'en' => 'Record the invitation'],
        'ref'     => ['fa' => 'شمارهٔ نامه / مرجع', 'en' => 'Letter number / reference'],
        'note'    => ['fa' => 'شرح و دلیل', 'en' => 'Findings and reason'],
        'none'    => ['fa' => 'برای این لات هنوز ITP باز نشده است.', 'en' => 'No ITP has been opened for this lot yet.'],
        'openitp' => ['fa' => 'باز کردن ITP برای این لات', 'en' => 'Open an ITP for this lot'],
        'disc'    => ['fa' => 'دیسیپلین', 'en' => 'Discipline'],
        'go'      => ['fa' => 'باز کن', 'en' => 'Open'],
        'by'      => ['fa' => 'امضا', 'en' => 'Signed'],
        'invited' => ['fa' => 'دعوت‌شده', 'en' => 'Invited'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];
    $base = "/projects/{$project->code}/itp/lots/{$lot->id}";

    $colour = fn (string $c): string => match ($c) {
        InterventionCode::HOLD => '#f87171',
        InterventionCode::WITNESS => '#fbbf24',
        InterventionCode::MONITOR => '#60a5fa',
        InterventionCode::REVIEW => '#a78bfa',
        default => 'var(--muted)',
    };

    $resultColour = fn (string $r): string => match ($r) {
        StageResult::PASSED => '#86efac',
        StageResult::CONDITIONAL => '#fcd34d',
        StageResult::FAILED => '#fca5a5',
        StageResult::WAIVED => '#c4b5fd',
        StageResult::NOTIFIED => '#60a5fa',
        default => 'var(--muted)',
    };
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $lot->lot_code }}</title>
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
    <span class="mono">{{ $lot->lot_code }}</span> · {{ $lot->item_code }} ·
    {{ $fa ? $lot->description_fa : $lot->description_en }}
  </div>

  @if($errors->any())
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  @if(count($records) === 0)
    <p style="color:var(--muted);font-size:13px;margin-top:14px;">{{ $L('none') }}</p>
    <form method="post" action="{{ $base }}/open?{{ http_build_query($q) }}"
          style="margin-top:12px;padding:14px;border:1px solid var(--line,#1f2937);border-radius:10px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      @csrf
      <div style="display:grid;gap:5px;">
        <label style="font-size:12px;color:var(--muted);">{{ $L('disc') }}</label>
        <select name="discipline"
                style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
          <option value="PIPING">PIPING</option>
          <option value="PIPELINE">PIPELINE</option>
        </select>
      </div>
      <button type="submit" class="btn">{{ $L('go') }}</button>
    </form>
  @else
    {{-- The gate, stated first and plainly. --}}
    @if(count($holds) > 0)
      <div style="margin-top:14px;padding:12px 14px;border-radius:9px;border:1px solid #7f1d1d;background:rgba(127,29,29,.22);">
        <strong style="color:#fca5a5;font-size:14px;">{{ $L('blocked') }}</strong>
        <div style="color:var(--muted);font-size:12px;line-height:1.8;margin-top:4px;max-width:780px;">{{ $L('blocksub') }}</div>
        <div style="margin-top:8px;font-size:12px;">
          @foreach($holds as $h)
            <div>
              <span class="mono" style="color:#fca5a5;">{{ $num($h['stage']->clause) }}</span>
              {{ $h['stage']->title($lang) }}
              <span style="color:var(--muted);">— {{ implode('، ', $h['parties']) }}</span>
            </div>
          @endforeach
        </div>
      </div>
    @else
      <div style="margin-top:14px;padding:10px 14px;border-radius:9px;border:1px solid #14532d;background:rgba(20,83,45,.22);color:#86efac;font-size:13px;">
        {{ $L('free') }}
      </div>
    @endif

    <div style="margin-top:12px;font-size:12px;color:var(--muted);">
      {{ $L('prog') }}: {{ $num($progress['settled']) }} / {{ $num($progress['total']) }}
      (<bdi>{{ $num($progress['percent']) }}%</bdi>)
    </div>

    <table data-tt style="margin-top:12px;">
      <thead><tr>
        <th>{{ $L('clause') }}</th><th>{{ $L('act') }}</th><th>{{ $L('who') }}</th>
        <th>{{ $L('res') }}</th><th>{{ $L('action') }}</th>
      </tr></thead>
      <tbody>
      @foreach($records as $record)
        @php $stage = $record->stage; @endphp
        <tr>
          <td class="mono" style="font-size:12px;white-space:nowrap;">
            {{ $num($stage->clause) }}
            @if($stage->isHoldPoint())
              <div style="color:#f87171;font-size:10px;">{{ $fa ? 'توقف' : 'HOLD' }}</div>
            @endif
          </td>
          <td style="font-size:13px;">
            {{ $stage->title($lang) }}
            @if($stage->acceptance($lang))
              <div style="color:var(--muted);font-size:11px;line-height:1.6;max-width:340px;">{{ $stage->acceptance($lang) }}</div>
            @endif
          </td>
          <td style="font-size:12px;white-space:nowrap;">
            @foreach($stage->parties as $p)
              <span style="color:{{ $colour($p->intervention) }};font-family:ui-monospace,monospace;">{{ $p->tenant_type }}:{{ $p->intervention }}</span>@if(! $loop->last) @endif
            @endforeach
          </td>
          <td style="font-size:12px;color:{{ $resultColour($record->result) }};">
            {{ StageResult::label($record->result)[$lang] }}
            @if($record->signed_at)
              <div style="color:var(--muted);font-size:11px;">
                {{ $L('by') }}: {{ $record->signed_tenant_type }} · {{ \App\Support\JalaliDate::format($record->signed_at, $lang) }}
              </div>
            @endif
            @if($record->notified_at && $record->isOpen())
              <div style="color:var(--muted);font-size:11px;">
                {{ $L('invited') }}: <bdi class="mono">{{ $record->notice_reference }}</bdi>
              </div>
            @endif
            @if($record->findings($lang))
              <div style="color:var(--muted);font-size:11px;line-height:1.6;max-width:280px;">{{ $record->findings($lang) }}</div>
            @endif
          </td>
          <td>
            @if($record->isOpen())
              @if($record->notified_at === null)
                <form method="post" action="{{ $base }}/{{ $record->id }}/notify?{{ http_build_query($q) }}"
                      style="display:flex;gap:5px;margin-bottom:6px;">
                  @csrf
                  <input type="text" name="notice_reference" required placeholder="{{ $L('ref') }}"
                         style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;width:150px;">
                  <button type="submit" class="btn" style="font-size:12px;padding:4px 8px;">{{ $L('invite') }}</button>
                </form>
              @endif

              <form method="post" action="{{ $base }}/{{ $record->id }}/sign?{{ http_build_query($q) }}"
                    style="display:grid;gap:5px;min-width:250px;">
                @csrf
                <select name="result"
                        style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;">
                  @foreach([StageResult::PASSED, StageResult::CONDITIONAL, StageResult::FAILED, StageResult::WAIVED, StageResult::NOT_APPLICABLE] as $option)
                    {{-- A hold point is never offered "waived": it waits. --}}
                    @if(! ($option === StageResult::WAIVED && $stage->isHoldPoint()))
                      <option value="{{ $option }}">{{ StageResult::label($option)[$lang] }}</option>
                    @endif
                  @endforeach
                </select>
                <input type="text" name="{{ $fa ? 'findings_fa' : 'findings_en' }}" placeholder="{{ $L('note') }}"
                       style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;">
                <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;">{{ $L('sign') }}</button>
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
        ? 'نقطهٔ توقف، رسید انبار را مسدود نمی‌کند — کامیون ساعت ۲ بامداد می‌رسد و کالا باید شمرده شود. چیزی که مسدود می‌شود، ترخیص برای نصب است: کالا هست، دیده می‌شود، اما تا امضای رکن صاحب توقف داخل خط جوش نمی‌شود.'
        : 'A hold point does not block the goods receipt — the truck arrives at 2am and the material must be counted. What it blocks is release for installation: the material exists and is visible, but it is not welded into the plant until the holding party signs.' }}
  </div>
</div>
</div>
</body>
</html>
