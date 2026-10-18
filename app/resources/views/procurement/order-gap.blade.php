@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'شکاف سفارش', 'en' => 'Order gap board'],
        'sub'     => ['fa' => 'مقدار ناخالص تأییدشدهٔ متریال منهای مقدار خالص سفارش‌های معتبر صادرشده؛ بدون تسهیم و بدون حدس',
                      'en' => 'Accepted gross MTO minus valid issued/in-force net PO quantity; no proration, no guessing'],
        'materials' => ['fa' => 'متریال‌ها', 'en' => 'Materials'],
        'shortage'=> ['fa' => 'کسری', 'en' => 'Shortage'],
        'covered' => ['fa' => 'پوشش کامل', 'en' => 'Covered'],
        'overplan'=> ['fa' => 'بیش از برنامه', 'en' => 'Over plan'],
        'unmatched' => ['fa' => 'بدون تطبیق', 'en' => 'Unmatched'],
        'material'=> ['fa' => 'متریال', 'en' => 'Material'],
        'unit'    => ['fa' => 'واحد', 'en' => 'Unit'],
        'gross'   => ['fa' => 'ناخالص تأییدشده', 'en' => 'Accepted gross'],
        'ordered' => ['fa' => 'سفارش مؤثر', 'en' => 'Effective ordered'],
        'gap'     => ['fa' => 'شکاف', 'en' => 'Gap'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'allocated' => ['fa' => 'تخصیص اسکوپ', 'en' => 'Scope allocated'],
        'unalloc' => ['fa' => 'تخصیص‌نیافته', 'en' => 'Unallocated'],
        'empty'   => ['fa' => 'هنوز MTO تأییدشده یا سفارش مؤثری وجود ندارد.', 'en' => 'No accepted MTO or effective orders yet.'],
        'rules'   => ['fa' => 'پیش‌نویس و سفارش لغوشده در شمارش نمی‌آیند؛ اصلاحیه جایگزین خط قبلی می‌شود؛ سفارش بیشتر از برنامه به‌جای کسری منفی گزارش می‌شود.',
                      'en' => 'Draft and cancelled POs are not counted; an amendment replaces the previous line; ordering above plan is reported, never a negative shortage.'],
        'gaprule' => ['fa' => 'Gap = Accepted Gross − Effective Ordered (zero tolerance)', 'en' => 'Gap = Accepted Gross − Effective Ordered (zero tolerance)'],
        'items'   => ['fa' => 'خطوط سفارش خرید', 'en' => 'Purchase order lines'],
        'po'      => ['fa' => 'سفارش', 'en' => 'PO'],
        'line'    => ['fa' => 'خط', 'en' => 'Line'],
        'attrs'   => ['fa' => 'تطبیق هویت', 'en' => 'Attribute'],
        'amend'   => ['fa' => 'اصلاحیه', 'en' => 'Amend'],
        'allocate'=> ['fa' => 'تخصیص', 'en' => 'Allocate'],
        'qty'     => ['fa' => 'مقدار', 'en' => 'Quantity'],
        'subsys'  => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'pickmat' => ['fa' => 'انتخاب متریال…', 'en' => 'Pick a material…'],
        'attr'    => ['fa' => 'تطبیق‌شده', 'en' => 'Attributed'],
        'headof'  => ['fa' => 'سرِ سلسله', 'en' => 'Lineage head'],
        'supby'   => ['fa' => 'جایگزینِ', 'en' => 'Supersedes'],
        'nomat'   => ['fa' => 'بدون هویت متریال', 'en' => 'No material identity'],
        'emptyItems' => ['fa' => 'خط سفارشی ثبت نشده است.', 'en' => 'No purchase order lines yet.'],
        'save'    => ['fa' => 'ثبت', 'en' => 'Save'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $qty = fn ($v) => $v === null ? '—' : $num(number_format((float) $v, 3));
    $desc = fn ($row) => $fa ? ($row['description_fa'] ?: $row['description_en']) : ($row['description_en'] ?: $row['description_fa']);
    $stateLabel = [
        'SHORTAGE'  => ['fa' => 'کسری', 'en' => 'Shortage'],
        'COVERED'   => ['fa' => 'پوشش کامل', 'en' => 'Covered'],
        'OVER_PLAN' => ['fa' => 'بیش از برنامه', 'en' => 'Over plan'],
        'UNMATCHED' => ['fa' => 'بدون تطبیق', 'en' => 'Unmatched'],
    ];
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
  .st.SHORTAGE  { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .st.COVERED   { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.OVER_PLAN { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.UNMATCHED { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.DRAFT              { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.ISSUED             { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.PARTIALLY_RECEIVED { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.RECEIVED           { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.CANCELLED          { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .mono { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; }
  .muted { color:var(--muted); font-size:11.5px; }
  .num { font-variant-numeric:tabular-nums; text-align:end; }
  .gapneg { color:#fca5a5; font-weight:700; }
  .gapzero { color:#6ee7b7; font-weight:700; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; }
  .rowforms { display:grid; grid-template-columns:1.4fr 1.2fr 1.2fr; gap:14px; }
  .rowforms label { display:block; font-size:11.5px; color:var(--muted); margin-bottom:4px; }
  .rowforms select, .rowforms input { width:100%; margin-bottom:8px; }
  @media (max-width: 980px) { .rowforms { grid-template-columns:1fr; } }
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
    <div class="dstat"><div class="k">{{ $L('materials') }}</div><div class="v">{{ $num($totals['materials']) }}</div></div>
    <div class="dstat {{ $totals['shortage'] ? 'warn' : 'good' }}"><div class="k">{{ $L('shortage') }}</div><div class="v">{{ $num($totals['shortage']) }}</div></div>
    <div class="dstat"><div class="k">{{ $L('covered') }}</div><div class="v">{{ $num($totals['covered']) }}</div></div>
    <div class="dstat {{ $totals['over_plan'] ? 'warn' : '' }}"><div class="k">{{ $L('overplan') }}</div><div class="v">{{ $num($totals['over_plan']) }}</div></div>
    <div class="dstat {{ $totals['unmatched'] ? 'warn' : '' }}"><div class="k">{{ $L('unmatched') }}</div><div class="v">{{ $num($totals['unmatched']) }}</div></div>
  </div>

  <div class="note">{{ $L('rules') }} · <span class="mono">{{ $L('gaprule') }}</span></div>

  <fieldset>
    <legend>{{ $L('title') }}</legend>
    @if(count($board) === 0)
      <p class="muted">{{ $L('empty') }}</p>
    @else
    <table data-tt>
        <thead><tr>
          <th>{{ $L('material') }}</th>
          <th>{{ $L('unit') }}</th>
          <th class="num">{{ $L('gross') }}</th>
          <th class="num">{{ $L('ordered') }}</th>
          <th class="num">{{ $L('gap') }}</th>
          <th>{{ $L('state') }}</th>
          <th class="num">{{ $L('allocated') }}</th>
          <th class="num">{{ $L('unalloc') }}</th>
        </tr></thead>
        <tbody>
          @foreach($board as $row)
          <tr>
            <td>
              <span class="mono">{{ $row['commodity_code'] }}</span><br>
              <span class="muted">{{ $desc($row) }}</span>
            </td>
            <td>{{ $row['unit'] }}</td>
            <td class="num">{{ $qty($row['gross']) }}</td>
            <td class="num">{{ $qty($row['ordered']) }}</td>
            <td class="num {{ $row['gap'] === null ? '' : ((float) $row['gap'] > 0 ? 'gapneg' : 'gapzero') }}">
              {{ $qty($row['gap']) }}
            </td>
            <td><span class="st {{ $row['state'] }}">{{ $stateLabel[$row['state']][$lang] }}</span></td>
            <td class="num">{{ $qty($row['allocated']) }}</td>
            <td class="num">{{ $qty($row['unallocated']) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </fieldset>

  <fieldset>
    <legend>{{ $L('items') }}</legend>
    @if($items->isEmpty())
      <p class="muted">{{ $L('emptyItems') }}</p>
    @else
    <table data-tt>
        <thead><tr>
          <th>{{ $L('po') }}</th>
          <th>{{ $L('line') }}</th>
          <th>{{ $L('material') }}</th>
          <th class="num">{{ $L('qty') }}</th>
          <th>{{ $L('unit') }}</th>
          <th>{{ $L('state') }}</th>
          <th style="min-width:540px">{{ $L('attrs') }} / {{ $L('amend') }} / {{ $L('allocate') }}</th>
        </tr></thead>
        <tbody>
          @foreach($items as $item)
          <tr>
            <td class="mono">{{ $item->purchaseOrder->number ?? '—' }}</td>
            <td class="num">{{ $num($item->line_no) }}</td>
            <td>
              @if($item->material_item_id)
                <span class="st COVERED">{{ $L('attr') }}</span>
                <span class="mono">{{ $item->materialItem->commodity_code ?? '—' }}</span>
              @else
                <span class="st UNMATCHED">{{ $L('nomat') }}</span>
                <span class="mono">{{ $item->item_code }}</span>
              @endif
              <br><span class="muted">{{ $fa ? $item->description_fa : $item->description_en }}</span>
              @if($item->supersedes_id)
                <br><span class="muted">{{ $L('supby') }} <span class="mono">{{ $item->supersedes->item_code ?? '' }}</span> / {{ $L('headof') }}</span>
              @endif
            </td>
            <td class="num">{{ $num(number_format((float) $item->quantity, 3)) }}</td>
            <td>{{ $item->unit }}</td>
            <td>
              @php $st = $item->purchaseOrder->state ?? ''; @endphp
              <span class="st {{ $st }}">{{ $st }}</span>
            </td>
            <td>
              <div class="rowforms">
                <form method="post" action="{{ route('procurement.order-gap.attribute', ['projectCode' => $project->code]) }}">
                  @csrf
                  <label>{{ $L('attrs') }}</label>
                  <input type="hidden" name="item_id" value="{{ $item->id }}">
                  <select name="material_item_id" required>
                    <option value="">{{ $L('pickmat') }}</option>
                    @foreach($materials as $m)
                      <option value="{{ $m->id }}" @selected($item->material_item_id === $m->id)>
                        {{ $m->commodity_code }} — {{ $fa ? $m->description_fa : $m->description_en }} ({{ $m->base_unit }})
                      </option>
                    @endforeach
                  </select>
                  <button type="submit">{{ $L('save') }}</button>
                </form>
                <form method="post" action="{{ route('procurement.order-gap.amend', ['projectCode' => $project->code]) }}">
                  @csrf
                  <label>{{ $L('amend') }}</label>
                  <input type="hidden" name="item_id" value="{{ $item->id }}">
                  <input type="number" name="quantity" step="0.001" min="0.001" placeholder="{{ $num(number_format((float) $item->quantity, 3)) }}" required>
                  <button type="submit">{{ $L('save') }}</button>
                </form>
                <form method="post" action="{{ route('procurement.order-gap.allocate', ['projectCode' => $project->code]) }}">
                  @csrf
                  <label>{{ $L('allocate') }}</label>
                  <input type="hidden" name="item_id" value="{{ $item->id }}">
                  <select name="subsystem_id" required>
                    @foreach($subsystems as $s)<option value="{{ $s->id }}">{{ $s->code }}</option>@endforeach
                  </select>
                  <input type="number" name="quantity" step="0.001" min="0.001" placeholder="{{ $L('qty') }}" required>
                  <button type="submit">{{ $L('save') }}</button>
                </form>
              </div>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </fieldset>

</div>
</div>
</body>
</html>
