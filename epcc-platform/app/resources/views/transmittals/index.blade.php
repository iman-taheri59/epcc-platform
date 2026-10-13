@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'نامه‌های ارسال مدارک', 'en' => 'Transmittals'],
        'sub'     => ['fa' => 'ثبت رسمی ارسال مدارک و پیگیری تأیید دریافت', 'en' => 'Formal record of issue, with acknowledgement tracking'],
        'number'  => ['fa' => 'شماره', 'en' => 'Number'],
        'subject' => ['fa' => 'موضوع', 'en' => 'Subject'],
        'purpose' => ['fa' => 'هدف', 'en' => 'Purpose'],
        'from'    => ['fa' => 'فرستنده', 'en' => 'From'],
        'to'      => ['fa' => 'گیرندگان', 'en' => 'Recipients'],
        'docs'    => ['fa' => 'مدارک', 'en' => 'Documents'],
        'status'  => ['fa' => 'وضعیت', 'en' => 'Status'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'new'     => ['fa' => 'نامهٔ جدید', 'en' => 'New transmittal'],
        'sen'     => ['fa' => 'موضوع (انگلیسی)', 'en' => 'Subject (English)'],
        'sfa'     => ['fa' => 'موضوع (فارسی)', 'en' => 'Subject (Farsi)'],
        'notes'   => ['fa' => 'توضیحات', 'en' => 'Notes'],
        'pick'    => ['fa' => 'مدارک (چند مورد قابل انتخاب)', 'en' => 'Documents (select several)'],
        'pickto'  => ['fa' => 'گیرندگان (چند مورد قابل انتخاب)', 'en' => 'Recipients (select several)'],
        'issue'   => ['fa' => 'صدور', 'en' => 'Issue'],
        'draftit' => ['fa' => 'ذخیره به‌صورت پیش‌نویس', 'en' => 'Save as draft'],
        'issuenow'=> ['fa' => 'صدور فوری', 'en' => 'Issue immediately'],
        'ack'     => ['fa' => 'تأیید دریافت', 'en' => 'Acknowledge'],
        'zip'     => ['fa' => 'دریافت بستهٔ ZIP', 'en' => 'Download ZIP'],
        'sheet'   => ['fa' => 'برگهٔ ارسال (PDF)', 'en' => 'Transmittal sheet (PDF)'],
        'vtitle'  => ['fa' => 'ترانسمیتال وندورها', 'en' => 'Vendor transmittals'],
        'vsub'    => ['fa' => 'مدارک دریافتی از وندورها، ثبت‌شده توسط پیمانکار',
                      'en' => 'Documents received from vendors, recorded by the contractor'],
        'vnew'    => ['fa' => 'ثبت نامهٔ وندور', 'en' => 'Record a vendor transmittal'],
        'vendor'  => ['fa' => 'وندور', 'en' => 'Vendor'],
        'vref'    => ['fa' => 'شمارهٔ نامهٔ وندور', 'en' => "Vendor's own reference"],
        'vnone'   => ['fa' => 'هنوز نامه‌ای از وندورها ثبت نشده است.', 'en' => 'No vendor transmittals recorded yet.'],
        'novend'  => ['fa' => 'وندوری در این پروژه تعریف نشده است؛ ابتدا از صفحهٔ تیم پروژه اضافه کنید.',
                      'en' => 'No vendor is defined on this project; add one from the project team page first.'],
        'rollup'  => ['fa' => 'انتقال به نامهٔ پیمانکار', 'en' => 'Roll up into a contractor letter'],
        'rollupgo'=> ['fa' => 'انتقال', 'en' => 'Roll up'],
        'nodraft' => ['fa' => 'پیش‌نویس پیمانکاری باز ندارید', 'en' => 'no open contractor draft'],
        'goven'   => ['fa' => 'ترانسمیتال وندورها ←', 'en' => 'Vendor transmittals →'],
        'gocon'   => ['fa' => '← ترانسمیتال پیمانکار', 'en' => '← Contractor transmittals'],
        'srcfrom' => ['fa' => 'برگرفته از', 'en' => 'from'],
        'empty'   => ['fa' => 'هنوز نامه‌ای ثبت نشده است.', 'en' => 'No transmittals yet.'],
        'nodocs'  => ['fa' => 'مدرک قابل ارسالی ندارید؛ ابتدا مدرکی را برای بررسی ارسال کنید.',
                      'en' => 'You have no transmittable documents; share one for review first.'],
        'draft'   => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
        'issued'  => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'overdue' => ['fa' => 'سررسید گذشته', 'en' => 'Overdue'],
        'acked'   => ['fa' => 'تأیید شد', 'en' => 'Acknowledged'],
        'waiting' => ['fa' => 'در انتظار', 'en' => 'Awaiting'],
        'due'     => ['fa' => 'مهلت', 'en' => 'Due'],
        'total'   => ['fa' => 'نامه', 'en' => 'transmittals'],
        'openack' => ['fa' => 'در انتظار تأیید', 'en' => 'Awaiting acknowledgement'],
        'od'      => ['fa' => 'سررسید گذشته', 'en' => 'Overdue'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $jdate = fn ($d) => \App\Support\JalaliDate::format($d, $lang);

    $issued   = $transmittals->where('status', \App\Models\Transmittal::ISSUED);
    $overdue  = $issued->filter(fn ($x) => $x->isOverdue());
    $awaiting = $issued->filter(fn ($x) => $x->recipients->contains(fn ($r) => $r->acknowledged_at === null));
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
  .dstat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:120px; }
  .dstat .k { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .dstat .v { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:2px; }
  .dstat.warn .v { color:#fca5a5; }
  .dstat.good .v { color:#6ee7b7; }
  .st { display:inline-block; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
  .st.DRAFT   { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.ISSUED  { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.OVERDUE { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .rcp { display:flex; flex-direction:column; gap:3px; }
  .rcp .r { display:flex; align-items:center; gap:6px; font-size:11.5px; white-space:nowrap; }
  .rcp .dot { width:7px; height:7px; border-radius:50%; flex:none; }
  .rcp .dot.yes { background:#6ee7b7; }
  .rcp .dot.no  { background:#64748b; }
  .rcp .when { color:var(--muted); font-size:10.5px; }
  .docl { font-size:11.5px; color:var(--muted); line-height:1.7; max-width:230px; }
  .docl .mono { color:#cbd5e1; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .acts { display:flex; flex-direction:column; gap:6px; align-items:flex-start; }
  .acts a.btn { text-decoration:none; display:inline-block; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select, .frow textarea { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .frow select[multiple] { min-height:118px; padding:6px; }
  .frow select[multiple] option { padding:4px 6px; border-radius:4px; }
  .hint { font-size:11px; color:#64748b; margin-top:4px; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $isVendorRegister ? $L('vtitle') : $L('title') }}</h1>
  <div class="sub">
    {{ $project->name($lang) }} · <span class="mono">{{ $project->code }}</span> ·
    {{ $isVendorRegister ? $L('vsub') : $L('sub') }} · {{ $jalaliDate }}
  </div>

  {{-- The two registers are separate places; each links to the other. --}}
  <div class="bar" style="margin-bottom:14px">
    @if($isVendorRegister)
      <a class="btn ghost" href="/projects/{{ $project->code }}/transmittals?lang={{ $lang }}">{{ $L('gocon') }}</a>
    @else
      <a class="btn ghost" href="/projects/{{ $project->code }}/vendor-transmittals?lang={{ $lang }}">{{ $L('goven') }}</a>
    @endif
  </div>

  @if(session('status'))
    <div class="ok">{{ session('status') }}</div>
  @endif
  @if($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <div class="docstats">
    <div class="dstat">
      <div class="k">{{ $L('total') }}</div>
      <div class="v">{{ $num(count($transmittals)) }}</div>
    </div>
    <div class="dstat">
      <div class="k">{{ $L('issued') }}</div>
      <div class="v">{{ $num($issued->count()) }}</div>
    </div>
    <div class="dstat {{ $awaiting->count() > 0 ? 'warn' : 'good' }}">
      <div class="k">{{ $L('openack') }}</div>
      <div class="v">{{ $num($awaiting->count()) }}</div>
    </div>
    <div class="dstat {{ $overdue->count() > 0 ? 'warn' : 'good' }}">
      <div class="k">{{ $L('od') }}</div>
      <div class="v">{{ $num($overdue->count()) }}</div>
    </div>
  </div>

  @if($canIssue)
    @if($documents->isEmpty())
      <div class="err" style="background:rgba(148,163,184,.09);border-color:var(--line);color:var(--muted);">
        {{ $L('nodocs') }}
      </div>
    @else
      <fieldset>
        <legend>{{ $isVendorRegister ? $L('vnew') : $L('new') }}</legend>
        @if($isVendorRegister && $vendors->isEmpty())
          <div class="err" style="background:rgba(148,163,184,.09);border-color:var(--line);color:var(--muted);">
            {{ $L('novend') }}
          </div>
        @else
        <form method="post" action="/projects/{{ $project->code }}/transmittals?lang={{ $lang }}">
          @csrf
          <input type="hidden" name="kind" value="{{ $kind }}">
          @if($isVendorRegister)
            <div class="frow">
              <div>
                <label for="vendor_company_id">{{ $L('vendor') }}</label>
                <select id="vendor_company_id" name="vendor_company_id" required>
                  @foreach($vendors as $v)
                    <option value="{{ $v->id }}">{{ $v->code }} — {{ $v->name($lang) }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="vendor_reference">{{ $L('vref') }}</label>
                <input id="vendor_reference" name="vendor_reference" maxlength="128"
                       placeholder="AZ-2026-114">
              </div>
            </div>
          @endif
          <div class="frow">
            <div>
              <label for="subject_en">{{ $L('sen') }}</label>
              <input id="subject_en" name="subject_en" required maxlength="512">
            </div>
            <div>
              <label for="subject_fa">{{ $L('sfa') }}</label>
              <input id="subject_fa" name="subject_fa" required maxlength="512">
            </div>
            <div>
              <label for="purpose">{{ $L('purpose') }}</label>
              <select id="purpose" name="purpose" required>
                @foreach($purposes as $p)
                  <option value="{{ $p }}">{{ \App\Support\TransmittalPurpose::label($p, $lang) }}</option>
                @endforeach
              </select>
              <div class="hint">
                {{ $fa
                  ? 'مهلت تأیید دریافت از روی هدف تعیین می‌شود؛ «جهت اطلاع» نیازی به تأیید ندارد.'
                  : 'The acknowledgement deadline follows the purpose; "for information" needs none.' }}
              </div>
            </div>
          </div>
          <div class="frow">
            <div>
              <label for="documents">{{ $L('pick') }}</label>
              <select id="documents" name="documents[]" multiple required>
                @foreach($documents as $d)
                  <option value="{{ $d->id }}">
                    {{ $d->number }} · {{ $d->currentRevision->revision }} · {{ $d->title($lang) }}
                  </option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="recipients">{{ $L('pickto') }}</label>
              <select id="recipients" name="recipients[]" multiple required>
                @foreach($companies as $c)
                  <option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name($lang) }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="frow" style="grid-template-columns:1fr;">
            <div>
              <label for="notes">{{ $L('notes') }}</label>
              <textarea id="notes" name="notes" maxlength="4000" rows="2"></textarea>
            </div>
          </div>
          <div class="btnrow">
            <button type="submit" name="issue_now" value="1" class="btn">{{ $L('issuenow') }}</button>
            <button type="submit" class="btn ghost">{{ $L('draftit') }}</button>
          </div>
        </form>
        @endif
      </fieldset>
    @endif
  @endif

  @if($transmittals->isEmpty())
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;">
      {{ $isVendorRegister ? $L('vnone') : $L('empty') }}
    </div>
  @else
    {{-- data-tt opts this table into filter/sort (partials/tabletools). --}}
    <table data-tt>
      <thead>
        <tr>
          <th data-sort="text">{{ $L('number') }}</th>
          <th data-sort="text">{{ $L('subject') }}</th>
          <th data-sort="text" data-facet>{{ $L('purpose') }}</th>
          @if($isVendorRegister)
            <th data-sort="text" data-facet>{{ $L('vendor') }}</th>
          @endif
          <th data-sort="text" data-facet>{{ $L('from') }}</th>
          <th data-sort="text">{{ $L('to') }}</th>
          <th data-sort="num">{{ $L('docs') }}</th>
          <th data-sort="text" data-facet>{{ $L('status') }}</th>
          <th>{{ $L('actions') }}</th>
        </tr>
      </thead>
      <tbody>
      @foreach($transmittals as $tr)
        @php
          $isSender = $tr->company_id === $acting->id;
          $mine = $tr->recipients->firstWhere('recipient_company_id', $acting->id);
          $od = $tr->isOverdue();
          $statusKey = $tr->isDraft() ? 'draft' : ($od ? 'overdue' : 'issued');
        @endphp
        <tr>
          <td><span class="mono">{{ $tr->number }}</span></td>
          <td>
            {{ $tr->subject($lang) }}
            @if($tr->issued_at)
              <div class="hint">{{ $jdate($tr->issued_at) }}@if($tr->due_at) · {{ $L('due') }} {{ $jdate($tr->due_at) }}@endif</div>
            @endif
          </td>
          <td>{{ $tr->purposeLabel($lang) }}</td>
          @if($isVendorRegister)
            <td>
              <span class="pill pack">{{ $tr->vendor?->code ?? '—' }}</span>
              @if($tr->vendor_reference)
                <div class="hint mono">{{ $tr->vendor_reference }}</div>
              @endif
            </td>
          @endif
          <td><span class="pill pack">{{ $tr->sender->code }}</span></td>
          <td>
            {{-- Per-recipient acknowledgement: "who has not replied" is the
                 question the sender actually asks. --}}
            <div class="rcp">
              @foreach($tr->recipients as $r)
                <div class="r">
                  <span class="dot {{ $r->hasAcknowledged() ? 'yes' : 'no' }}"
                        title="{{ $r->hasAcknowledged() ? $L('acked') : $L('waiting') }}"></span>
                  <span class="mono">{{ $r->company->code }}</span>
                  @if($r->hasAcknowledged())
                    <span class="when">{{ $jdate($r->acknowledged_at) }}</span>
                  @endif
                </div>
              @endforeach
            </div>
          </td>
          <td data-sortkey="{{ $tr->items->count() }}">
            <div class="docl">
              @foreach($tr->items as $it)
                <div>
                  <span class="mono">{{ $it->number_at_issue }}</span> · {{ $it->revision_at_issue }}
                  {{-- Traceability: this document reached the client via a
                       vendor letter, and the chain is visible on the row. --}}
                  @if($it->source_item_id && $it->source?->transmittal)
                    <span class="hint">({{ $L('srcfrom') }}
                      <span class="mono">{{ $it->source->transmittal->number }}</span>)</span>
                  @endif
                </div>
              @endforeach
            </div>
          </td>
          {{-- Sort by lifecycle, and give the facet a clean single value. --}}
          <td data-sortkey="{{ $tr->isDraft() ? 0 : ($od ? 1 : 2) }}" data-facet-value="{{ $L($statusKey) }}">
            <span class="st {{ $od ? 'OVERDUE' : $tr->status }}">{{ $L($statusKey) }}</span>
          </td>
          <td>
            <div class="acts">
              @if($isVendorRegister && $tr->isIssued() && $isSender && $canIssue)
                @if($rollUpTargets->isEmpty())
                  <span class="hint">{{ $L('nodraft') }}</span>
                @else
                  <form method="post"
                        action="/projects/{{ $project->code }}/vendor-transmittals/{{ $tr->id }}/rollup?lang={{ $lang }}">
                    @csrf
                    <label class="hint" for="rt_{{ $tr->id }}">{{ $L('rollup') }}</label>
                    <select id="rt_{{ $tr->id }}" name="target_id" required>
                      @foreach($rollUpTargets as $t)
                        <option value="{{ $t->id }}">{{ $t->number }}</option>
                      @endforeach
                    </select>
                    <button type="submit" class="btn ghost">{{ $L('rollupgo') }}</button>
                  </form>
                @endif
              @endif

              @if($tr->isDraft() && $isSender && $canIssue)
                <form method="post" action="/projects/{{ $project->code }}/transmittals/{{ $tr->id }}?lang={{ $lang }}">
                  @csrf
                  <input type="hidden" name="action" value="issue">
                  <button type="submit" class="btn">{{ $L('issue') }}</button>
                </form>
              @endif

              @if($tr->isIssued())
                <a class="btn ghost" href="/projects/{{ $project->code }}/transmittals/{{ $tr->id }}/download?lang={{ $lang }}">
                  {{ $L('zip') }}
                </a>
                <a class="btn ghost" href="/projects/{{ $project->code }}/transmittals/{{ $tr->id }}/sheet.pdf?lang={{ $lang }}">
                  {{ $L('sheet') }}
                </a>
              @endif

              {{-- Only a named recipient that has not yet replied. --}}
              @if($tr->isIssued() && $mine && ! $mine->hasAcknowledged())
                <form method="post" action="/projects/{{ $project->code }}/transmittals/{{ $tr->id }}?lang={{ $lang }}">
                  @csrf
                  <input type="hidden" name="action" value="acknowledge">
                  <button type="submit" class="btn">{{ $L('ack') }}</button>
                </form>
              @endif
            </div>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <div class="foot">
    {{ $fa
      ? 'نامهٔ صادرشده قابل تغییر نیست و آنچه را در لحظهٔ صدور ارسال شده ثبت می‌کند. هر گیرنده جداگانه دریافت را تأیید می‌کند و بستهٔ ZIP شامل فایل MANIFEST با اثر انگشت SHA-256 هر فایل است.'
      : 'An issued transmittal cannot be changed and records what was sent at the moment of issue. Each recipient acknowledges separately, and the ZIP carries a MANIFEST with the SHA-256 of every file.' }}
  </div>
</div>
</div>
</body>
</html>
