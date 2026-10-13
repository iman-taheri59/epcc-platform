@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'کنترل کیفیت و فهرست نواقص', 'en' => 'QA/QC & punch list'],
        'sub'     => ['fa' => 'بازرسی زیرسیستم‌ها، رفع نواقص و صدور گواهی تکمیل مکانیکی',
                      'en' => 'Subsystem walkdown, defect closure and Mechanical Completion'],
        'number'  => ['fa' => 'شماره', 'en' => 'Number'],
        'subsys'  => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'cat'     => ['fa' => 'رده', 'en' => 'Cat.'],
        'desc'    => ['fa' => 'شرح نقص', 'en' => 'Description'],
        'loc'     => ['fa' => 'موقعیت', 'en' => 'Location'],
        'raisedby'=> ['fa' => 'ثبت‌کننده', 'en' => 'Raised by'],
        'resp'    => ['fa' => 'مسئول رفع', 'en' => 'Responsible'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'new'     => ['fa' => 'ثبت نقص جدید', 'en' => 'Raise a punch item'],
        'den'     => ['fa' => 'شرح (انگلیسی)', 'en' => 'Description (English)'],
        'dfa'     => ['fa' => 'شرح (فارسی)', 'en' => 'Description (Farsi)'],
        'raise'   => ['fa' => 'ثبت نقص', 'en' => 'Raise item'],
        'assign'  => ['fa' => 'ارجاع', 'en' => 'Assign'],
        'fix'     => ['fa' => 'اعلام رفع', 'en' => 'Report fixed'],
        'verify'  => ['fa' => 'تأیید', 'en' => 'Verify'],
        'reject'  => ['fa' => 'رد', 'en' => 'Reject'],
        'close'   => ['fa' => 'بستن', 'en' => 'Close'],
        'note'    => ['fa' => 'توضیح', 'en' => 'Note'],
        'empty'   => ['fa' => 'نقصی ثبت نشده است.', 'en' => 'No punch items yet.'],
        'total'   => ['fa' => 'نقص', 'en' => 'items'],
        'opena'   => ['fa' => 'نواقص باز ردهٔ A', 'en' => 'Open A-items'],
        'closure' => ['fa' => 'درصد رفع', 'en' => 'Closure'],
        'mcc'     => ['fa' => 'گواهی تکمیل مکانیکی', 'en' => 'Mechanical Completion'],
        'blocked' => ['fa' => 'مسدود', 'en' => 'Blocked'],
        'ready'   => ['fa' => 'آمادهٔ صدور', 'en' => 'Ready to issue'],
        'issued'  => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'draftc'  => ['fa' => 'ایجاد پیش‌نویس گواهی', 'en' => 'Start certificate'],
        'issuec'  => ['fa' => 'صدور گواهی', 'en' => 'Issue certificate'],
        'excep'   => ['fa' => 'تأیید استثنا (کارفرما)', 'en' => 'Approve exception (employer)'],
        'excr'    => ['fa' => 'دلیل تأیید استثنا', 'en' => 'Reason for the exception'],
        'exon'    => ['fa' => 'استثنای تأییدشده', 'en' => 'Exception approved'],
        'blockedby' => ['fa' => 'مسدود به دلیل نواقص باز ردهٔ A:', 'en' => 'Blocked by open A-items:'],
        'waived'  => ['fa' => 'نواقص مشمول استثنا:', 'en' => 'Waived items:'],
        'nosub'   => ['fa' => 'زیرسیستمی تعریف نشده است.', 'en' => 'No subsystems defined.'],
        'pdf'     => ['fa' => 'دریافت گواهی (PDF)', 'en' => 'Download certificate (PDF)'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);

    $openA = $items->filter(fn ($i) => $i->blocksMc());
    $closed = $items->filter(fn ($i) => ! $i->isOpen());
    $closurePct = count($items) > 0 ? (int) round($closed->count() / count($items) * 100) : 100;
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
  .st.RAISED   { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.ASSIGNED { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.FIXED    { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.VERIFIED { background:rgba(139,92,246,.16); color:#c4b5fd; border:1px solid rgba(139,92,246,.35); }
  .st.CLOSED   { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.REJECTED { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .cat { display:inline-block; font-size:12px; font-weight:800; width:26px; height:26px; line-height:26px;
    text-align:center; border-radius:7px; }
  .cat.A { background:rgba(239,68,68,.18); color:#fca5a5; border:1px solid rgba(239,68,68,.45); }
  .cat.B { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.4); }
  .cat.C { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .desc { max-width:340px; font-size:12.5px; line-height:1.7; }
  .desc .loc { display:block; color:var(--muted); font-size:11px; margin-top:2px; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .btn.danger { background:rgba(239,68,68,.18); border:1px solid rgba(239,68,68,.45); color:#fca5a5; }
  .acts { display:flex; flex-direction:column; gap:6px; align-items:flex-start; }
  .acts form { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
  .acts input[type=text], .acts select { padding:4px 7px; font-size:11.5px; border-radius:6px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; max-width:150px; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select, .frow textarea { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .hint { font-size:11px; color:#64748b; margin-top:4px; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

  .sscard { background:var(--card); border:1px solid var(--line); border-radius:12px;
    padding:14px 16px; margin-bottom:12px; }
  .sscard .sshead { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; }
  .sscard .ssname { font-size:14px; font-weight:700; color:#e2e8f0; }
  .sscard .ssmeta { font-size:11.5px; color:var(--muted); margin-top:2px; }
  .gate { margin-top:10px; padding:10px 12px; border-radius:9px; font-size:12.5px; line-height:1.8; }
  .gate.blocked { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.35); color:#fca5a5; }
  .gate.ready   { background:rgba(16,185,129,.10); border:1px solid rgba(16,185,129,.35); color:#6ee7b7; }
  .gate.issued  { background:rgba(59,130,246,.10); border:1px solid rgba(59,130,246,.35); color:#93c5fd; }
  .gate .mono { color:#e2e8f0; }
  .gate form { margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
  .gate input[type=text] { padding:5px 9px; font-size:12px; border-radius:6px; min-width:240px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
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

  @if(session('status'))
    <div class="ok">{{ session('status') }}</div>
  @endif
  @if($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <div class="docstats">
    <div class="dstat">
      <div class="k">{{ $L('total') }}</div>
      <div class="v">{{ $num(count($items)) }}</div>
    </div>
    <div class="dstat {{ $openA->count() > 0 ? 'warn' : 'good' }}">
      <div class="k">{{ $L('opena') }}</div>
      <div class="v">{{ $num($openA->count()) }}</div>
    </div>
    <div class="dstat {{ $closurePct === 100 ? 'good' : '' }}">
      <div class="k">{{ $L('closure') }}</div>
      <div class="v">{{ $num($closurePct) }}٪</div>
    </div>
  </div>

  {{-- ---- Subsystems and the MC gate ---- --}}
  @forelse($subsystems as $s)
    @php
      $cert = $s->certificate;
      $block = $blocking[$s->id] ?? collect();
      $state = $cert && $cert->isIssued() ? 'issued' : ($block->isNotEmpty() && ! ($cert && $cert->hasApprovedException()) ? 'blocked' : 'ready');
    @endphp
    <div class="sscard">
      <div class="sshead">
        <div>
          <div class="ssname">{{ $s->name($lang) }}</div>
          <div class="ssmeta"><span class="mono">{{ $s->code }}</span> · {{ $s->discipline }} ·
            {{ $L('closure') }} {{ $num($s->closurePercent()) }}٪</div>
        </div>
        <div>
          @if($cert)
            <span class="st {{ $cert->isIssued() ? 'CLOSED' : 'ASSIGNED' }}">
              {{ $cert->number }} · {{ $cert->isIssued() ? $L('issued') : $L('mcc') }}
            </span>
          @endif
        </div>
      </div>

      <div class="gate {{ $state }}">
        @if($state === 'issued')
          ✓ {{ $L('mcc') }} — <span class="mono">{{ $cert->number }}</span> · {{ $L('issued') }}
          @if($cert->hasApprovedException())
            <br>⚠ {{ $L('exon') }}: {{ $cert->exception_reason }}
            <br>{{ $L('waived') }}
            <span class="mono">{{ collect($cert->exception_items)->pluck('number')->implode('، ') }}</span>
          @endif
        @elseif($state === 'blocked')
          {{ $L('blockedby') }}
          <span class="mono">{{ $block->pluck('number')->implode('، ') }}</span>
          @if($isEmployer && $cert)
            {{-- Only the employer sees the override, because only the employer
                 may grant it. Showing it to everyone invites the question. --}}
            <form method="post" action="/projects/{{ $project->code }}/subsystems/{{ $s->id }}/certificate?lang={{ $lang }}">
              @csrf
              <input type="hidden" name="action" value="except">
              <input type="text" name="reason" required placeholder="{{ $L('excr') }}">
              <button type="submit" class="btn danger">{{ $L('excep') }}</button>
            </form>
          @endif
        @else
          @if($cert && $cert->hasApprovedException())
            ⚠ {{ $L('exon') }}: {{ $cert->exception_reason }}
          @else
            ✓ {{ $L('ready') }}
          @endif
          @if($cert)
            <form method="post" action="/projects/{{ $project->code }}/subsystems/{{ $s->id }}/certificate?lang={{ $lang }}">
              @csrf
              <input type="hidden" name="action" value="issue">
              <button type="submit" class="btn">{{ $L('issuec') }}</button>
            </form>
          @else
            <form method="post" action="/projects/{{ $project->code }}/subsystems/{{ $s->id }}/certificate?lang={{ $lang }}">
              @csrf
              <input type="hidden" name="action" value="draft">
              <button type="submit" class="btn ghost">{{ $L('draftc') }}</button>
            </form>
          @endif
        @endif
      </div>

      {{-- Available for a DRAFT too: the sheet is watermarked "not valid" and
           people genuinely circulate drafts for wet-ink review before issue. --}}
      @if($cert)
        <div style="margin-top:10px">
          <a class="btn ghost" style="text-decoration:none;display:inline-block"
             href="/projects/{{ $project->code }}/subsystems/{{ $s->id }}/certificate.pdf?lang={{ $lang }}">
            {{ $L('pdf') }}
          </a>
        </div>
      @endif
    </div>
  @empty
    <p class="sub">{{ $L('nosub') }}</p>
  @endforelse

  {{-- ---- Raise a new item ---- --}}
  @if($canRaise && $subsystems->isNotEmpty())
  <fieldset>
    <legend>{{ $L('new') }}</legend>
    <form method="post" action="/projects/{{ $project->code }}/punch?lang={{ $lang }}">
      @csrf
      <div class="frow">
        <div>
          <label for="pi_sub">{{ $L('subsys') }}</label>
          <select id="pi_sub" name="subsystem_id" required>
            @foreach($subsystems as $s)
              <option value="{{ $s->id }}">{{ $s->code }} — {{ $s->name($lang) }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="pi_cat">{{ $L('cat') }}</label>
          <select id="pi_cat" name="category" required>
            @foreach($categories as $c)
              <option value="{{ $c }}">{{ \App\Support\PunchCategory::label($c)[$lang] }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="pi_resp">{{ $L('resp') }}</label>
          <select id="pi_resp" name="responsible_company_id">
            <option value="">—</option>
            @foreach($companies as $c)
              <option value="{{ $c->id }}">{{ $c->code }} — {{ $c->name($lang) }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="pi_loc">{{ $L('loc') }}</label>
          <input id="pi_loc" type="text" name="location" maxlength="255">
        </div>
      </div>
      <div class="frow">
        <div>
          <label for="pi_den">{{ $L('den') }}</label>
          <textarea id="pi_den" name="description_en" rows="2" required></textarea>
        </div>
        <div>
          <label for="pi_dfa">{{ $L('dfa') }}</label>
          <textarea id="pi_dfa" name="description_fa" rows="2" required></textarea>
        </div>
      </div>
      <div class="btnrow">
        <button type="submit" class="btn">{{ $L('raise') }}</button>
      </div>
    </form>
  </fieldset>
  @endif

  {{-- ---- The register ---- --}}
  @if(count($items) === 0)
    <p class="sub">{{ $L('empty') }}</p>
  @else
  {{-- data-tt turns on the shared filter/sort toolbar (partials/tabletools). --}}
  <table data-tt>
    <thead>
      <tr>
        <th data-sort="text">{{ $L('number') }}</th>
        <th data-sort="text" data-facet>{{ $L('subsys') }}</th>
        <th data-sort="text" data-facet>{{ $L('cat') }}</th>
        <th data-sort="text">{{ $L('desc') }}</th>
        <th data-sort="text" data-facet>{{ $L('resp') }}</th>
        <th data-sort="num" data-facet>{{ $L('state') }}</th>
        <th data-sort="none">{{ $L('actions') }}</th>
      </tr>
    </thead>
    <tbody>
    @foreach($items as $i)
      <tr>
        <td class="mono">{{ $i->number }}</td>
        <td class="mono">{{ $i->subsystem->code }}</td>
        <td data-facet-value="{{ $i->category }}">
          <span class="cat {{ $i->category }}">{{ $i->categoryLabel($lang) }}</span>
        </td>
        <td class="desc">
          {{ $i->description($lang) }}
          @if($i->location)<span class="loc">{{ $i->location }}</span>@endif
        </td>
        <td>{{ $i->responsibleCompany?->code ?? '—' }}</td>
        {{-- Sort by the lifecycle, not the label: sorting Persian state names
             alphabetically is meaningless in either language. --}}
        <td data-sortkey="{{ \App\Support\PunchState::ordinalOf($i->state) }}"
            data-facet-value="{{ $i->stateLabel($lang) }}">
          <span class="st {{ $i->state }}">{{ $i->stateLabel($lang) }}</span>
        </td>
        <td>
          <div class="acts">
            @if($i->state === \App\Support\PunchState::RAISED || $i->state === \App\Support\PunchState::REJECTED)
              <form method="post" action="/projects/{{ $project->code }}/punch/{{ $i->id }}?lang={{ $lang }}">
                @csrf
                <input type="hidden" name="action" value="assign">
                <select name="responsible_company_id" required>
                  <option value="">—</option>
                  @foreach($companies as $c)
                    <option value="{{ $c->id }}">{{ $c->code }}</option>
                  @endforeach
                </select>
                <button type="submit" class="btn ghost">{{ $L('assign') }}</button>
              </form>
            @endif

            @if($i->state === \App\Support\PunchState::ASSIGNED && $i->responsible_company_id === $acting->id)
              <form method="post" action="/projects/{{ $project->code }}/punch/{{ $i->id }}?lang={{ $lang }}">
                @csrf
                <input type="hidden" name="action" value="fix">
                <input type="text" name="note" required placeholder="{{ $L('note') }}">
                <button type="submit" class="btn">{{ $L('fix') }}</button>
              </form>
            @endif

            @if($i->state === \App\Support\PunchState::FIXED && $i->responsible_company_id !== $acting->id)
              <form method="post" action="/projects/{{ $project->code }}/punch/{{ $i->id }}?lang={{ $lang }}">
                @csrf
                <input type="hidden" name="action" value="verify">
                <input type="text" name="note" placeholder="{{ $L('note') }}">
                <button type="submit" class="btn">{{ $L('verify') }}</button>
              </form>
              <form method="post" action="/projects/{{ $project->code }}/punch/{{ $i->id }}?lang={{ $lang }}">
                @csrf
                <input type="hidden" name="action" value="reject">
                <input type="text" name="note" required placeholder="{{ $L('note') }}">
                <button type="submit" class="btn danger">{{ $L('reject') }}</button>
              </form>
            @endif

            @if($i->state === \App\Support\PunchState::VERIFIED && $isEmployer)
              <form method="post" action="/projects/{{ $project->code }}/punch/{{ $i->id }}?lang={{ $lang }}">
                @csrf
                <input type="hidden" name="action" value="close">
                <button type="submit" class="btn">{{ $L('close') }}</button>
              </form>
            @endif
          </div>
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
