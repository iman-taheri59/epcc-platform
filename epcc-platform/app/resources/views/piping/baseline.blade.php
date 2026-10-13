@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'آشتی‌دادن MTO با مدل', 'en' => 'MTO versus model'],
        'sub'     => ['fa' => 'لیست خطوط، MTO و مدل سه‌بعدی سه ادعا دربارهٔ یک واقعیت‌اند. اینجا کنار هم گذاشته می‌شوند و جایی که با هم نمی‌خوانند، یافته است — نه خطا.',
                      'en' => 'The line list, the MTO and the 3D model are three statements about one reality. Here they sit side by side, and where they do not agree, that is a finding — not an error.'],
        'back'    => ['fa' => 'بازگشت به مدل خط', 'en' => 'Back to the line model'],
        'sources' => ['fa' => 'منابع', 'en' => 'Sources'],
        'upload'  => ['fa' => 'بارگذاری MTO', 'en' => 'Upload an MTO'],
        'file'    => ['fa' => 'فایل (XLSX یا CSV)', 'en' => 'File (XLSX or CSV)'],
        'rev'     => ['fa' => 'نسخه', 'en' => 'Revision'],
        'go'      => ['fa' => 'بارگذاری', 'en' => 'Upload'],
        'takeoff' => ['fa' => 'متره از روی مدل سه‌بعدی', 'en' => 'Take off from the 3D model'],
        'source'  => ['fa' => 'منبع', 'en' => 'Source'],
        'rows'    => ['fa' => 'سطر', 'en' => 'Rows'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'when'    => ['fa' => 'زمان', 'en' => 'When'],
        'accept'  => ['fa' => 'پذیرش', 'en' => 'Accept'],
        'nobatch' => ['fa' => 'هنوز منبعی بارگذاری نشده است.', 'en' => 'No source has been loaded yet.'],
        'rec'     => ['fa' => 'مقایسه کن', 'en' => 'Reconcile'],
        'disc'    => ['fa' => 'مغایرت‌ها', 'en' => 'Discrepancies'],
        'discnote'=> ['fa' => 'سیستم عمداً هیچ‌کدام را برنده اعلام نمی‌کند. تا وقتی کسی تصمیم نگیرد و دلیلش را ننویسد، این قلم عدد مورد توافق ندارد.',
                      'en' => 'The system deliberately declares no winner. Until a person decides and writes down why, this item has no agreed quantity.'],
        'nodisc'  => ['fa' => 'مغایرتی ثبت نشده است.', 'en' => 'No discrepancies recorded.'],
        'item'    => ['fa' => 'قلم', 'en' => 'Item'],
        'kind'    => ['fa' => 'نوع', 'en' => 'Kind'],
        'mtoq'    => ['fa' => 'MTO', 'en' => 'MTO'],
        'modelq'  => ['fa' => 'مدل', 'en' => 'Model'],
        'diff'    => ['fa' => 'اختلاف', 'en' => 'Difference'],
        'decide'  => ['fa' => 'تصمیم', 'en' => 'Decision'],
        'why'     => ['fa' => 'دلیل (اجباری)', 'en' => 'Reason (required)'],
        'takea'   => ['fa' => 'MTO درست است', 'en' => 'The MTO is right'],
        'takeb'   => ['fa' => 'مدل درست است', 'en' => 'The model is right'],
        'takeo'   => ['fa' => 'عدد دیگر', 'en' => 'Another figure'],
        'dismiss' => ['fa' => 'مغایرت واقعی نیست', 'en' => 'Not a real difference'],
        'save'    => ['fa' => 'ثبت', 'en' => 'Save'],
        'agreed'  => ['fa' => 'مبنای مورد توافق', 'en' => 'The agreed baseline'],
        'agrnote' => ['fa' => 'این عددی است که باید بر اساسش برنامه‌ریزی و خرید کرد. قلمِ مورد اختلاف عدد ندارد — و نداشتنش صادقانه‌تر از حدس زدن است.',
                      'en' => 'This is the figure to plan and buy against. A disputed item has no figure — and having none is more honest than guessing one.'],
        'qty'     => ['fa' => 'مقدار', 'en' => 'Quantity'],
        'unit'    => ['fa' => 'واحد', 'en' => 'Unit'],
        'disputed'=> ['fa' => 'مورد اختلاف', 'en' => 'disputed'],
        'short'   => ['fa' => 'کسری متریال', 'en' => 'Material shortage'],
        'shnote'  => ['fa' => 'گزارش انبار «سفارش‌شده در برابر تحویل‌شده» را می‌گوید و کسریِ خودِ سفارش را نمی‌بیند: اگر در برابر مبنای ۱۳۴، مقدار ۱۲۰ سفارش شده باشد، آنجا ۱۰۰٪ تحویل‌شده دیده می‌شود در حالی که خط ۱۴ متر کم دارد. این ستون آن را می‌گیرد.',
                      'en' => 'The warehouse report compares ordered with delivered and cannot see a shortfall in the ORDER itself: 120 ordered against a baseline of 134 reads as 100% delivered while the line is 14 m short. This column catches it.'],
        'req'     => ['fa' => 'مورد نیاز', 'en' => 'Required'],
        'ord'     => ['fa' => 'سفارش‌شده', 'en' => 'Ordered'],
        'recv'    => ['fa' => 'تحویل‌شده', 'en' => 'Received'],
        'toord'   => ['fa' => 'باقی‌ماندهٔ سفارش', 'en' => 'To order'],
        'unknown' => ['fa' => 'نامعلوم', 'en' => 'unknown'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];
    $base = "/projects/{$project->code}/piping/{$line->id}/baseline?".http_build_query($q);

    $sourceLabel = fn (string $s): string => match ($s) {
        'XLSX_MTO' => $fa ? 'MTO' : 'MTO',
        'PCF' => $fa ? 'مدل سه‌بعدی' : '3D model',
        'XLSX_LINELIST' => $fa ? 'لیست خطوط' : 'Line list',
        default => $fa ? 'دستی' : 'Manual',
    };

    $kindLabel = fn (string $k): string => match ($k) {
        'QUANTITY' => $fa ? 'اختلاف مقدار' : 'Quantity differs',
        'MISSING_IN_MODEL' => $fa ? 'در مدل نیست' : 'Missing in the model',
        'MISSING_IN_MTO' => $fa ? 'در MTO نیست' : 'Missing in the MTO',
        default => $k,
    };
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $line->line_number }}</title>
@include('partials.shell')
@include('partials.tabletools')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <div class="sub"><span class="mono">{{ $line->line_number }}</span> · {{ $line->subsystem?->code }}</div>
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;max-width:820px;">{{ $L('sub') }}</p>
  <p style="margin:10px 0 0;">
    <a href="/projects/{{ $project->code }}/piping/{{ $line->id }}/model?{{ http_build_query($q) }}"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; {{ $L('back') }}</a>
  </p>

  @if($errors->any())
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  {{-- ------------------------------------------------------- sources ---- --}}
  <h2 style="margin-top:26px;font-size:16px;">{{ $L('sources') }}</h2>

  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
    <form method="post" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/baseline/mto?{{ http_build_query($q) }}"
          enctype="multipart/form-data"
          style="padding:14px;border:1px solid var(--line,#1f2937);border-radius:10px;display:grid;gap:10px;min-width:320px;">
      @csrf
      <strong style="font-size:13px;">{{ $L('upload') }}</strong>
      <input type="file" name="file" accept=".xlsx,.csv,text/csv" required
             style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
      <input type="text" name="revision_label" placeholder="{{ $L('rev') }}"
             style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
      <div><button type="submit" class="btn">{{ $L('go') }}</button></div>
    </form>

    <form method="post" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/baseline/take-off?{{ http_build_query($q) }}"
          style="padding:14px;border:1px solid var(--line,#1f2937);border-radius:10px;display:grid;gap:10px;align-content:start;min-width:280px;">
      @csrf
      <strong style="font-size:13px;">{{ $L('takeoff') }}</strong>
      <span style="color:var(--muted);font-size:12px;line-height:1.7;">
        {{ $fa ? 'لوله بر حسب طول جمع می‌شود و اتصالات شمرده می‌شوند.' : 'Pipe is summed by length; fittings are counted.' }}
      </span>
      <div><button type="submit" class="btn">{{ $L('takeoff') }}</button></div>
    </form>
  </div>

  @if(count($batches) === 0)
    <p style="color:var(--muted);font-size:13px;margin-top:12px;">{{ $L('nobatch') }}</p>
  @else
    <table data-tt style="margin-top:14px;">
      <thead><tr>
        <th>{{ $L('source') }}</th><th>{{ $L('rev') }}</th><th>{{ $L('rows') }}</th>
        <th>{{ $L('state') }}</th><th>{{ $L('when') }}</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($batches as $b)
        <tr>
          <td style="font-size:13px;">{{ $sourceLabel($b->source_kind) }}
            @if($b->filename)<span class="mono" style="color:var(--muted);font-size:11px;"> {{ $b->filename }}</span>@endif
          </td>
          <td style="font-size:12px;">{{ $b->revision_label ?? '—' }}</td>
          <td style="font-size:12px;">{{ $num($b->row_count) }}
            @if($b->rejected_count > 0)
              <span style="color:#fca5a5;"> (+{{ $num($b->rejected_count) }} ✕)</span>
            @endif
          </td>
          <td style="font-size:12px;color:{{ $b->state === 'ACCEPTED' ? '#86efac' : ($b->state === 'SUPERSEDED' ? 'var(--muted)' : '#fcd34d') }};">
            {{ $b->state }}
          </td>
          <td style="font-size:12px;color:var(--muted);">{{ \App\Support\JalaliDate::format($b->created_at, $lang) }}</td>
          <td>
            @if($b->state === 'DRAFT')
              <form method="post" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/baseline/{{ $b->id }}/accept?{{ http_build_query($q) }}">
                @csrf
                <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;">{{ $L('accept') }}</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>

    <form method="post" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/baseline/reconcile?{{ http_build_query($q) }}"
          style="margin-top:12px;">
      @csrf
      <button type="submit" class="btn">{{ $L('rec') }}</button>
    </form>
  @endif

  {{-- --------------------------------------------------- discrepancies -- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('disc') }}
    <span style="color:var(--muted);font-weight:400;font-size:13px;">({{ $num(count($discrepancies)) }})</span>
  </h2>
  <p style="color:var(--muted);font-size:12px;line-height:1.8;margin:6px 0 0;max-width:820px;">{{ $L('discnote') }}</p>

  @if(count($discrepancies) === 0)
    <p style="color:var(--muted);font-size:13px;margin-top:10px;">{{ $L('nodisc') }}</p>
  @else
    <table data-tt style="margin-top:12px;">
      <thead><tr>
        <th>{{ $L('item') }}</th><th>{{ $L('kind') }}</th><th>{{ $L('mtoq') }}</th>
        <th>{{ $L('modelq') }}</th><th>{{ $L('diff') }}</th><th>{{ $L('decide') }}</th>
      </tr></thead>
      <tbody>
      @foreach($discrepancies as $d)
        <tr>
          <td class="mono" style="font-size:12px;">{{ $d->item_code }}
            <span style="color:var(--muted);"> {{ $d->unit }}</span>
          </td>
          <td style="font-size:12px;color:{{ $d->kind === 'QUANTITY' ? '#fcd34d' : '#f9a8d4' }};">{{ $kindLabel($d->kind) }}</td>
          <td style="font-size:13px;">{{ $d->quantity_a === null ? '—' : $num(rtrim(rtrim(number_format((float) $d->quantity_a, 3, '.', ''), '0'), '.')) }}</td>
          <td style="font-size:13px;">{{ $d->quantity_b === null ? '—' : $num(rtrim(rtrim(number_format((float) $d->quantity_b, 3, '.', ''), '0'), '.')) }}</td>
          <td style="font-size:12px;">
            @if($d->difference !== null)
              <bdi>{{ $num(rtrim(rtrim(number_format((float) $d->difference, 3, '.', ''), '0'), '.')) }}</bdi>
              <span style="color:var(--muted);"> (<bdi>{{ $num(round((float) $d->difference_pct, 1)) }}%</bdi>)</span>
            @else — @endif
          </td>
          <td>
            @if($d->state === 'OPEN')
              <form method="post" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/baseline/{{ $d->id }}/resolve?{{ http_build_query($q) }}"
                    style="display:grid;gap:5px;min-width:260px;">
                @csrf
                <select name="state" style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;">
                  <option value="ACCEPTED_A">{{ $L('takea') }}</option>
                  <option value="ACCEPTED_B">{{ $L('takeb') }}</option>
                  <option value="ACCEPTED_OTHER">{{ $L('takeo') }}</option>
                  <option value="DISMISSED">{{ $L('dismiss') }}</option>
                </select>
                <input type="number" step="any" name="other_quantity" placeholder="{{ $L('takeo') }}"
                       style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;">
                <input type="text" name="resolution_note" required placeholder="{{ $L('why') }}"
                       style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;">
                <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;">{{ $L('save') }}</button>
              </form>
            @else
              <div style="font-size:12px;color:#86efac;">{{ $d->state }}
                @if($d->resolved_quantity !== null)
                  · {{ $num(rtrim(rtrim(number_format((float) $d->resolved_quantity, 3, '.', ''), '0'), '.')) }}
                @endif
              </div>
              <div style="font-size:11px;color:var(--muted);line-height:1.6;">{{ $d->resolution_note }}</div>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  {{-- ------------------------------------------------- agreed baseline -- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('agreed') }}</h2>
  <p style="color:var(--muted);font-size:12px;line-height:1.8;margin:6px 0 0;max-width:820px;">{{ $L('agrnote') }}</p>

  @if(count($agreed) > 0)
    <table data-tt style="margin-top:12px;">
      <thead><tr>
        <th>{{ $L('item') }}</th><th>{{ $L('qty') }}</th><th>{{ $L('unit') }}</th><th>{{ $L('source') }}</th>
      </tr></thead>
      <tbody>
      @foreach($agreed as $row)
        <tr>
          <td class="mono" style="font-size:12px;">{{ $row['item_code'] }}</td>
          <td style="font-size:13px;">
            @if($row['disputed'])
              <span style="color:#fcd34d;">{{ $L('disputed') }}</span>
            @else
              {{ $row['quantity'] === null ? '—' : $num(rtrim(rtrim(number_format((float) $row['quantity'], 3, '.', ''), '0'), '.')) }}
            @endif
          </td>
          <td style="font-size:12px;">{{ $row['unit'] }}</td>
          <td style="font-size:11px;color:var(--muted);">
            @foreach($row['sources'] as $s => $v)
              {{ $sourceLabel((string) $s) }} {{ $num(rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.')) }}@if(! $loop->last) · @endif
            @endforeach
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  {{-- ------------------------------------------------------- shortage --- --}}
  @if(count($shortage) > 0)
    <h2 style="margin-top:28px;font-size:16px;">{{ $L('short') }}</h2>
    <p style="color:var(--muted);font-size:12px;line-height:1.8;margin:6px 0 0;max-width:820px;">{{ $L('shnote') }}</p>

    <table data-tt style="margin-top:12px;">
      <thead><tr>
        <th>{{ $L('item') }}</th><th>{{ $L('req') }}</th><th>{{ $L('ord') }}</th>
        <th>{{ $L('recv') }}</th><th>{{ $L('toord') }}</th>
      </tr></thead>
      <tbody>
      @foreach($shortage as $row)
        <tr>
          <td class="mono" style="font-size:12px;">{{ $row['item_code'] }} <span style="color:var(--muted);">{{ $row['unit'] }}</span></td>
          <td style="font-size:13px;">
            {{ $row['required'] === null ? $L('unknown') : $num(rtrim(rtrim(number_format((float) $row['required'], 3, '.', ''), '0'), '.')) }}
          </td>
          <td style="font-size:13px;">{{ $num(rtrim(rtrim(number_format((float) $row['ordered'], 3, '.', ''), '0'), '.')) }}</td>
          <td style="font-size:13px;">{{ $num(rtrim(rtrim(number_format((float) $row['received'], 3, '.', ''), '0'), '.')) }}</td>
          <td style="font-size:13px;color:{{ ($row['to_order'] ?? 0) > 0 ? '#fca5a5' : 'inherit' }};">
            {{ $row['to_order'] === null ? $L('unknown') : $num(rtrim(rtrim(number_format((float) $row['to_order'], 3, '.', ''), '0'), '.')) }}
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <div class="foot">
    {{ $fa
        ? 'هیچ منبعی خودکار برنده نمی‌شود. اختلاف بین MTO و مدل معمولاً یعنی ایزومتریک بازنگری شده و MTO دوباره صادر نشده — همان خطایی که ۲۵۰ متر لولهٔ اشتباه را سوار کامیون می‌کند. به همین دلیل سیستم عدد را انتخاب نمی‌کند و از آدم می‌خواهد تصمیم بگیرد و دلیلش را بنویسد.'
        : 'No source wins automatically. A gap between the MTO and the model usually means the isometric was revised and the MTO was not re-issued — the error that puts 250 m of the wrong pipe on a truck. So the system does not pick; it asks a person to decide and to write down why.' }}
  </div>
</div>
</div>
</body>
</html>
