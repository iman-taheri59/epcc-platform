@php
    $fa = $lang === 'fa';
    $t = [
        'title'  => ['fa' => 'انبار و متریال', 'en' => 'Warehouse & materials'],
        'sub'    => ['fa' => 'ورود به انبار، خروج از انبار، ورود به سایت و ردیابی تا شمارهٔ ذوب',
                     'en' => 'Goods receipt, issue, site delivery and traceability to heat number'],
        'lots'   => ['fa' => 'اقلام انبار', 'en' => 'Stock lots'],
        'lot'    => ['fa' => 'شمارهٔ پارت', 'en' => 'Lot'],
        'item'   => ['fa' => 'کالا', 'en' => 'Item'],
        'heat'   => ['fa' => 'ذوب / سریال', 'en' => 'Heat / serial'],
        'bal'    => ['fa' => 'موجودی انبار', 'en' => 'In store'],
        'onsite' => ['fa' => 'تحویل سایت', 'en' => 'On site'],
        'transit'=> ['fa' => 'در راه', 'en' => 'In transit'],
        'insp'   => ['fa' => 'بازرسی', 'en' => 'Inspection'],
        'mtc'    => ['fa' => 'گواهی مواد', 'en' => 'MTC'],
        'acts'   => ['fa' => 'اقدام', 'en' => 'Actions'],
        'moves'  => ['fa' => 'دفتر گردش متریال', 'en' => 'Movement ledger'],
        'number' => ['fa' => 'شماره', 'en' => 'Number'],
        'type'   => ['fa' => 'نوع', 'en' => 'Type'],
        'qty'    => ['fa' => 'مقدار', 'en' => 'Qty'],
        'when'   => ['fa' => 'تاریخ', 'en' => 'Date'],
        'who'    => ['fa' => 'ثبت‌کننده', 'en' => 'By'],
        'subsys' => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'mcgate' => ['fa' => 'کسری متریال در دروازهٔ تکمیل مکانیکی', 'en' => 'Material shortage at the MC gate'],
        'ordered'=> ['fa' => 'سفارش', 'en' => 'Ordered'],
        'deliv'  => ['fa' => 'تحویل سایت', 'en' => 'Delivered'],
        'short'  => ['fa' => 'کسری', 'en' => 'Short'],
        'ok'     => ['fa' => 'کامل', 'en' => 'Complete'],
        'nomat'  => ['fa' => 'بدون متریال', 'en' => 'No material'],
        'attn'   => ['fa' => 'اقلام نیازمند توجه بازرسی', 'en' => 'Lots needing inspection attention'],
        'noattn' => ['fa' => 'همهٔ اقلام بازرسی و قبول شده‌اند.', 'en' => 'Every lot has passed inspection.'],
        'issue'  => ['fa' => 'خروج از انبار', 'en' => 'Issue'],
        'deliver'=> ['fa' => 'ورود به سایت', 'en' => 'Deliver to site'],
        'empty'  => ['fa' => 'هنوز کالایی وارد انبار نشده است.', 'en' => 'Nothing has been received yet.'],
        'noMove' => ['fa' => 'گردشی ثبت نشده است.', 'en' => 'No movements recorded.'],
        'reverse'=> ['fa' => 'ابطال', 'en' => 'Reverse'],
        'reason' => ['fa' => 'دلیل', 'en' => 'Reason'],
        'ledger' => ['fa' => 'این دفتر فقط افزودنی است: اشتباه با سند ابطال اصلاح می‌شود، نه با ویرایش.',
                     'en' => 'This ledger is append-only: a mistake is corrected with a reversing entry, never an edit.'],
        'advisory' => ['fa' => 'بازرسی در این سامانه بازدارنده نیست؛ نتیجه ثبت و نمایش داده می‌شود ولی جلوی ورود کالا را نمی‌گیرد.',
                       'en' => 'Inspection is advisory here: the result is recorded and shown, but it does not block a receipt.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $qty = fn ($n) => \App\Support\JalaliDate::digits(rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0', $lang);

    $totalOnSite = $lots->sum(fn ($l) => $l->deliveredToSite($l->movements));
    $shortSubs = $subsystems->filter(fn ($s) => ! ($materialStatus[$s->id]['complete'] ?? true));
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $project->code }}</title>
@include('partials.shell')
@include('partials.tabletools')
<style>
  .docstats { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
  .dstat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:118px; }
  .dstat .k { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .dstat .v { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:2px; }
  .dstat.warn .v { color:#fca5a5; }
  .dstat.good .v { color:#6ee7b7; }
  .st { display:inline-block; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
  .st.PASS        { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.FAIL        { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .st.CONDITIONAL { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.NONE        { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.RECEIPT { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.ISSUE   { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.SITE    { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.RETURN  { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .mono { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; }
  .muted { color:var(--muted); font-size:11.5px; }
  .num { font-variant-numeric:tabular-nums; text-align:end; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:5px 11px;
    font:inherit; font-size:11.5px; font-weight:600; cursor:pointer; }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .acts form { display:flex; gap:4px; align-items:center; flex-wrap:wrap; margin-bottom:4px; }
  .acts input, .acts select { padding:4px 6px; font-size:11px; border-radius:6px; max-width:92px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .note { background:rgba(139,92,246,.10); border:1px solid rgba(139,92,246,.35); color:#c4b5fd;
    border-radius:9px; padding:9px 13px; font-size:12px; margin-bottom:14px; line-height:1.7; }
  .gate { border-radius:9px; padding:9px 12px; font-size:12.5px; margin-bottom:8px; line-height:1.8; }
  .gate.short { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.35); color:#fca5a5; }
  .gate.fine  { background:rgba(16,185,129,.10); border:1px solid rgba(16,185,129,.35); color:#6ee7b7; }
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <div class="sub">
    {{ $project->name($lang) }} · <span class="mono">{{ $project->code }}</span> · {{ $L('sub') }} · {{ $jalaliDate }}
  </div>

  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if($errors->any())
    <div class="err"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <div class="docstats">
    <div class="dstat"><div class="k">{{ $L('lots') }}</div><div class="v">{{ $num($lots->count()) }}</div></div>
    <div class="dstat good"><div class="k">{{ $L('onsite') }}</div><div class="v">{{ $qty($totalOnSite) }}</div></div>
    <div class="dstat {{ $flagged->count() ? 'warn' : 'good' }}">
      <div class="k">{{ $L('insp') }}</div><div class="v">{{ $num($flagged->count()) }}</div>
    </div>
    <div class="dstat {{ $shortSubs->count() ? 'warn' : 'good' }}">
      <div class="k">{{ $L('short') }}</div><div class="v">{{ $num($shortSubs->count()) }}</div>
    </div>
  </div>

  <div class="note">{{ $L('advisory') }}</div>

  {{-- The MC link the user asked for --}}
  <fieldset>
    <legend>{{ $L('mcgate') }}</legend>
    @foreach($subsystems as $s)
      @php $st = $materialStatus[$s->id] ?? null; @endphp
      @if($st && $st['ordered'] > 0)
        <div class="gate {{ $st['complete'] ? 'fine' : 'short' }}">
          <span class="mono">{{ $s->code }}</span> — {{ $s->name($lang) }}<br>
          {{ $L('ordered') }}: {{ $qty($st['ordered']) }} ·
          {{ $L('deliv') }}: {{ $qty($st['delivered']) }} ·
          {{ $L('short') }}: <strong>{{ $qty($st['shortfall']) }}</strong>
        </div>
      @endif
    @endforeach
    @if($subsystems->every(fn($s) => ($materialStatus[$s->id]['ordered'] ?? 0) <= 0))
      <div class="muted">{{ $L('nomat') }}</div>
    @endif
  </fieldset>

  {{-- Lots --}}
  <h2 style="font-size:15px;margin:18px 0 8px">{{ $L('lots') }}</h2>
  @if($lots->isEmpty())
    <p class="muted">{{ $L('empty') }}</p>
  @else
  <table data-tt>
    <thead><tr>
      <th>{{ $L('lot') }}</th><th>{{ $L('item') }}</th><th>{{ $L('heat') }}</th>
      <th class="num">{{ $L('bal') }}</th><th class="num">{{ $L('onsite') }}</th>
      <th>{{ $L('insp') }}</th><th>{{ $L('mtc') }}</th><th>{{ $L('acts') }}</th>
    </tr></thead>
    <tbody>
    @foreach($lots as $lot)
      @php
        $bal = $lot->balance($lot->movements);
        $site = $lot->deliveredToSite($lot->movements);
        $res = $lot->inspection_result ?? 'NONE';
      @endphp
      <tr>
        <td class="mono">{{ $lot->lot_code }}</td>
        <td>
          <div class="mono">{{ $lot->item_code }}</div>
          <div class="muted">{{ $lot->description($lang) }}</div>
        </td>
        <td class="mono">
          {{ $lot->heat_number ?? $lot->serial_number ?? '—' }}
        </td>
        <td class="num">{{ $qty($bal) }} <span class="muted">{{ $lot->unit }}</span></td>
        <td class="num">{{ $qty($site) }}</td>
        <td><span class="st {{ $res }}">{{ \App\Support\ProcurementState::inspectionLabel($lot->inspection_result)[$lang] }}</span></td>
        <td>
          @if($lot->mtc_document_id)
            <span class="mono" style="color:#6ee7b7">{{ $lot->mtcDocument?->number ?? '✓' }}</span>
          @else
            <span class="muted">—</span>
          @endif
        </td>
        <td class="acts">
          @if($bal > 0)
          <form method="POST" action="/projects/{{ $project->code }}/materials/issue?lang={{ $lang }}">
            @csrf
            <input type="hidden" name="lot_id" value="{{ $lot->id }}">
            <input type="number" step="0.001" min="0.001" name="quantity" placeholder="{{ $L('qty') }}" required>
            <select name="subsystem_id">
              <option value="">—</option>
              @foreach($subsystems as $s)<option value="{{ $s->id }}">{{ $s->code }}</option>@endforeach
            </select>
            <button class="btn">{{ $L('issue') }}</button>
          </form>
          @endif
          <form method="POST" action="/projects/{{ $project->code }}/materials/deliver?lang={{ $lang }}">
            @csrf
            <input type="hidden" name="lot_id" value="{{ $lot->id }}">
            <input type="number" step="0.001" min="0.001" name="quantity" placeholder="{{ $L('qty') }}" required>
            <select name="subsystem_id" required>
              @foreach($subsystems as $s)<option value="{{ $s->id }}">{{ $s->code }}</option>@endforeach
            </select>
            <button class="btn ghost">{{ $L('deliver') }}</button>
          </form>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif

  {{-- Lots needing attention: what pays for the advisory rule --}}
  <fieldset style="margin-top:18px">
    <legend>{{ $L('attn') }}</legend>
    @forelse($flagged as $l)
      <div style="font-size:12.5px;margin-bottom:5px">
        <span class="mono">{{ $l->lot_code }}</span>
        @if($l->heat_number)<span class="muted"> · {{ $L('heat') }} {{ $l->heat_number }}</span>@endif
        — <span class="st {{ $l->inspection_result ?? 'NONE' }}">{{ \App\Support\ProcurementState::inspectionLabel($l->inspection_result)[$lang] }}</span>
        @unless($l->mtc_document_id)
          <span class="muted"> · {{ $L('mtc') }} —</span>
        @endunless
      </div>
    @empty
      <div class="muted">{{ $L('noattn') }}</div>
    @endforelse
  </fieldset>

  {{-- Ledger --}}
  <h2 style="font-size:15px;margin:18px 0 8px">{{ $L('moves') }}</h2>
  <div class="note">{{ $L('ledger') }}</div>
  @if($movements->isEmpty())
    <p class="muted">{{ $L('noMove') }}</p>
  @else
  <table data-tt>
    <thead><tr>
      <th>{{ $L('number') }}</th><th>{{ $L('type') }}</th><th>{{ $L('lot') }}</th>
      <th class="num">{{ $L('qty') }}</th><th>{{ $L('subsys') }}</th>
      <th>{{ $L('when') }}</th><th>{{ $L('who') }}</th><th>{{ $L('acts') }}</th>
    </tr></thead>
    <tbody>
    @foreach($movements as $m)
      <tr>
        <td class="mono">{{ $m->number }}</td>
        <td><span class="st {{ $m->type }}">{{ $m->typeName($lang) }}</span></td>
        <td class="mono">{{ $m->lot?->lot_code }}</td>
        <td class="num">{{ $qty($m->quantity) }}</td>
        <td class="mono">{{ $m->subsystem?->code ?? '—' }}</td>
        <td class="mono">{{ \App\Support\JalaliDate::format($m->performed_at, $lang) }}</td>
        <td class="muted">{{ $m->performer?->name }}</td>
        <td class="acts">
          @unless($m->isReversal())
          <form method="POST" action="/projects/{{ $project->code }}/materials/reverse?lang={{ $lang }}">
            @csrf
            <input type="hidden" name="movement_id" value="{{ $m->id }}">
            <input type="text" name="reason" placeholder="{{ $L('reason') }}" required style="max-width:110px">
            <button class="btn ghost">{{ $L('reverse') }}</button>
          </form>
          @endunless
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif

</div>
</div>
</body>
</html>
