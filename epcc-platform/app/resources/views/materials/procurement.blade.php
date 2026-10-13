@php
    $fa = $lang === 'fa';
    $t = [
        'title'  => ['fa' => 'تهیه و خرید', 'en' => 'Procurement'],
        'sub'    => ['fa' => 'درخواست خرید، تأیید فنی و مالی، سفارش خرید و پیگیری تحویل',
                     'en' => 'Requisition, technical and commercial approval, purchase order and expediting'],
        'mrs'    => ['fa' => 'درخواست‌های خرید', 'en' => 'Material requisitions'],
        'pos'    => ['fa' => 'سفارش‌های خرید', 'en' => 'Purchase orders'],
        'insps'  => ['fa' => 'بازرسی‌ها', 'en' => 'Inspections'],
        'number' => ['fa' => 'شماره', 'en' => 'Number'],
        'titlec' => ['fa' => 'عنوان', 'en' => 'Title'],
        'disc'   => ['fa' => 'رشته', 'en' => 'Discipline'],
        'state'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'lines'  => ['fa' => 'اقلام', 'en' => 'Lines'],
        'vendor' => ['fa' => 'وندور', 'en' => 'Vendor'],
        'value'  => ['fa' => 'مبلغ', 'en' => 'Value'],
        'promise'=> ['fa' => 'تعهد قراردادی', 'en' => 'Promised'],
        'fcast'  => ['fa' => 'پیش‌بینی', 'en' => 'Forecast'],
        'slip'   => ['fa' => 'تأخیر (روز)', 'en' => 'Slip (days)'],
        'acts'   => ['fa' => 'اقدام', 'en' => 'Actions'],
        'submit' => ['fa' => 'ارسال', 'en' => 'Submit'],
        'tech'   => ['fa' => 'تأیید فنی', 'en' => 'Technical'],
        'comm'   => ['fa' => 'تأیید مالی', 'en' => 'Commercial'],
        'reject' => ['fa' => 'رد', 'en' => 'Reject'],
        'reason' => ['fa' => 'دلیل', 'en' => 'Reason'],
        'setfc'  => ['fa' => 'ثبت پیش‌بینی', 'en' => 'Set forecast'],
        'result' => ['fa' => 'نتیجه', 'en' => 'Result'],
        'stage'  => ['fa' => 'مرحله', 'en' => 'Stage'],
        'by'     => ['fa' => 'بازرس', 'en' => 'Inspector'],
        'find'   => ['fa' => 'یافته‌ها', 'en' => 'Findings'],
        'emptyMr'=> ['fa' => 'درخواست خریدی ثبت نشده است.', 'en' => 'No requisitions yet.'],
        'emptyPo'=> ['fa' => 'سفارش خریدی ثبت نشده است.', 'en' => 'No purchase orders yet.'],
        'emptyIn'=> ['fa' => 'بازرسی‌ای ثبت نشده است.', 'en' => 'No inspections recorded.'],
        'sod'    => ['fa' => 'تفکیک وظایف: درخواست‌کننده نمی‌تواند درخواست خود را تأیید کند، و تأیید فنی و مالی باید توسط دو نفر متفاوت امضا شود.',
                     'en' => 'Segregation of duties: the raiser cannot approve their own requisition, and the technical and commercial approvals must be signed by two different people.'],
        'exped'  => ['fa' => 'تاریخ تعهد قراردادی هرگز بازنویسی نمی‌شود؛ پیش‌بینی در ستون جداگانه ثبت می‌شود.',
                     'en' => 'The contractual promise is never overwritten; the forecast is kept in its own column.'],
        'rejected' => ['fa' => 'دلیل رد:', 'en' => 'Rejected because:'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $money = function ($v, $cur) use ($lang) {
        if ($v === null) { return '—'; }
        return \App\Support\JalaliDate::digits(number_format((float) $v), $lang).' '.($cur ?? '');
    };
    $late = $orders->filter(fn ($o) => $o->isLate());
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
  .st.DRAFT              { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.SUBMITTED          { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.TECHNICAL_APPROVED { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.APPROVED           { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.REJECTED           { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .st.ORDERED            { background:rgba(139,92,246,.16); color:#c4b5fd; border:1px solid rgba(139,92,246,.35); }
  .st.ISSUED             { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.PARTIALLY_RECEIVED { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.RECEIVED           { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.CANCELLED          { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.PASS        { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.FAIL        { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .st.CONDITIONAL { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .mono { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; }
  .muted { color:var(--muted); font-size:11.5px; }
  .num { font-variant-numeric:tabular-nums; text-align:end; }
  .late { color:#fca5a5; font-weight:700; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:5px 11px;
    font:inherit; font-size:11.5px; font-weight:600; cursor:pointer; }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .btn.danger { background:rgba(239,68,68,.18); border:1px solid rgba(239,68,68,.45); color:#fca5a5; }
  .acts form { display:flex; gap:4px; align-items:center; flex-wrap:wrap; margin-bottom:4px; }
  .acts input { padding:4px 6px; font-size:11px; border-radius:6px; max-width:110px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .note { background:rgba(139,92,246,.10); border:1px solid rgba(139,92,246,.35); color:#c4b5fd;
    border-radius:9px; padding:9px 13px; font-size:12px; margin-bottom:14px; line-height:1.7; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; }
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

  @include('materials.mr-entry')

  <div class="docstats">
    <div class="dstat"><div class="k">{{ $L('mrs') }}</div><div class="v">{{ $num($requisitions->count()) }}</div></div>
    <div class="dstat"><div class="k">{{ $L('pos') }}</div><div class="v">{{ $num($orders->count()) }}</div></div>
    <div class="dstat {{ $late->count() ? 'warn' : 'good' }}">
      <div class="k">{{ $L('slip') }}</div><div class="v">{{ $num($late->count()) }}</div>
    </div>
    <div class="dstat"><div class="k">{{ $L('insps') }}</div><div class="v">{{ $num($inspections->count()) }}</div></div>
  </div>

  <div class="note">{{ $L('sod') }}</div>

  {{-- Requisitions --}}
  <h2 style="font-size:15px;margin:18px 0 8px">{{ $L('mrs') }}</h2>
  @if($requisitions->isEmpty())
    <p class="muted">{{ $L('emptyMr') }}</p>
  @else
  <table data-tt>
    <thead><tr>
      <th>{{ $L('number') }}</th><th>{{ $L('titlec') }}</th><th>{{ $L('disc') }}</th>
      <th class="num">{{ $L('lines') }}</th><th>{{ $L('state') }}</th><th>{{ $L('acts') }}</th>
    </tr></thead>
    <tbody>
    @foreach($requisitions as $mr)
      <tr>
        <td class="mono">{{ $mr->number }}</td>
        <td>
          {{ $mr->title($lang) }}
          @if($mr->isRejected() && $mr->rejection_reason)
            <div class="muted" style="color:#fca5a5">{{ $L('rejected') }} {{ $mr->rejection_reason }}</div>
          @endif
        </td>
        <td class="mono">{{ $mr->discipline }}</td>
        <td><details><summary>{{ $num($mr->items->count()) }} · {{ __('mr.details') }}</summary>
          @foreach($mr->items->sortBy('line_no') as $line)
            <p><span class="mono">{{ $line->item_code }}</span> · {{ $line->description($lang) }}<br>
            <span dir="ltr">{{ $line->source_quantity ?? $line->quantity }} {{ $line->unit }}</span>
            @if($line->specification)<br>{{ $line->specification }}@endif
            @if($line->subsystem)<br>{{ $line->subsystem->code }}@endif</p>
          @endforeach
          @if($mr->author_policy_id)<p class="muted">{{ __('mr.policy_basis') }}: <span class="mono">{{ $mr->author_policy_id }}</span></p>@endif
        </details></td>
        <td><span class="st {{ $mr->state }}">{{ \App\Support\ProcurementState::mrLabel($mr->state)[$lang] }}</span></td>
        <td class="acts">
          @php $act = ['DRAFT'=>'submit','SUBMITTED'=>'technical','TECHNICAL_APPROVED'=>'commercial'][$mr->state] ?? null; @endphp
          @if($act)
            <form method="POST" action="/projects/{{ $project->code }}/procurement/requisition?lang={{ $lang }}">
              @csrf
              <input type="hidden" name="requisition_id" value="{{ $mr->id }}">
              <input type="hidden" name="action" value="{{ $act }}">
              <button class="btn">{{ $L($act === 'submit' ? 'submit' : ($act === 'technical' ? 'tech' : 'comm')) }}</button>
            </form>
          @endif
          @if(in_array($mr->state, ['SUBMITTED','TECHNICAL_APPROVED'], true))
            <form method="POST" action="/projects/{{ $project->code }}/procurement/requisition?lang={{ $lang }}">
              @csrf
              <input type="hidden" name="requisition_id" value="{{ $mr->id }}">
              <input type="hidden" name="action" value="reject">
              <input type="text" name="reason" placeholder="{{ $L('reason') }}" required>
              <button class="btn danger">{{ $L('reject') }}</button>
            </form>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif

  {{-- Purchase orders --}}
  <h2 style="font-size:15px;margin:18px 0 8px">{{ $L('pos') }}</h2>
  <div class="note">{{ $L('exped') }}</div>
  @if($orders->isEmpty())
    <p class="muted">{{ $L('emptyPo') }}</p>
  @else
  <table data-tt>
    <thead><tr>
      <th>{{ $L('number') }}</th><th>{{ $L('titlec') }}</th><th>{{ $L('vendor') }}</th>
      <th class="num">{{ $L('value') }}</th><th>{{ $L('state') }}</th>
      <th>{{ $L('promise') }}</th><th>{{ $L('fcast') }}</th><th class="num">{{ $L('slip') }}</th>
      <th>{{ $L('acts') }}</th>
    </tr></thead>
    <tbody>
    @foreach($orders as $po)
      <tr>
        <td class="mono">{{ $po->number }}</td>
        <td>{{ $po->title($lang) }}</td>
        <td class="mono">{{ $po->vendor?->code }}</td>
        <td class="num">{{ $money($po->total_value, $po->currency) }}</td>
        <td><span class="st {{ $po->state }}">{{ \App\Support\ProcurementState::poLabel($po->state)[$lang] }}</span></td>
        <td class="mono">{{ $po->promised_date ? \App\Support\JalaliDate::format($po->promised_date, $lang) : '—' }}</td>
        <td class="mono">{{ $po->forecast_date ? \App\Support\JalaliDate::format($po->forecast_date, $lang) : '—' }}</td>
        <td class="num {{ $po->isLate() ? 'late' : '' }}">
          {{ $po->slipDays() === null ? '—' : $num($po->slipDays()) }}
        </td>
        <td class="acts">
          <form method="POST" action="/projects/{{ $project->code }}/procurement/forecast?lang={{ $lang }}">
            @csrf
            <input type="hidden" name="purchase_order_id" value="{{ $po->id }}">
            <input type="date" name="forecast_date" required>
            <button class="btn ghost">{{ $L('setfc') }}</button>
          </form>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif

  {{-- Inspections --}}
  <h2 style="font-size:15px;margin:18px 0 8px">{{ $L('insps') }}</h2>
  @if($inspections->isEmpty())
    <p class="muted">{{ $L('emptyIn') }}</p>
  @else
  <table data-tt>
    <thead><tr>
      <th>{{ $L('number') }}</th><th>{{ $L('result') }}</th><th>{{ $L('stage') }}</th>
      <th>{{ $L('by') }}</th><th>{{ $L('find') }}</th>
    </tr></thead>
    <tbody>
    @foreach($inspections as $i)
      <tr>
        <td class="mono">{{ $i->number }}</td>
        <td><span class="st {{ $i->result }}">{{ \App\Support\ProcurementState::inspectionLabel($i->result)[$lang] }}</span></td>
        <td>{{ \App\Support\ProcurementState::stageLabel($i->stage)[$lang] }}</td>
        <td class="mono">{{ $i->company?->code }}</td>
        <td class="muted" style="max-width:320px">{{ $i->findings($lang) ?? '—' }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif

</div>
</div>
</body>
</html>
