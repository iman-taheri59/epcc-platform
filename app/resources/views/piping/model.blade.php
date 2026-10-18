@php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'مدل خط', 'en' => 'Line model'],
        'sub'      => ['fa' => 'قطعات روی این خط و سرهای بازی که هنوز به چیزی وصل نیستند',
                       'en' => 'The components on this line and the ends not yet connected to anything'],
        'back'     => ['fa' => 'بازگشت به فهرست خطوط', 'en' => 'Back to the line register'],
        'seq'      => ['fa' => 'ردیف', 'en' => 'No.'],
        'type'     => ['fa' => 'نوع', 'en' => 'Type'],
        'size'     => ['fa' => 'سایز', 'en' => 'Size'],
        'ports'    => ['fa' => 'پورت‌ها', 'en' => 'Ports'],
        'length'   => ['fa' => 'طول', 'en' => 'Length'],
        'actions'  => ['fa' => 'اقدام', 'en' => 'Actions'],
        'remove'   => ['fa' => 'حذف', 'en' => 'Remove'],
        'none'     => ['fa' => 'هنوز چیزی روی این خط مدل نشده است. اولین قطعه را اضافه کنید.',
                       'en' => 'Nothing modelled on this line yet. Add the first component.'],
        'openends' => ['fa' => 'سرهای باز', 'en' => 'Open ends'],
        'complete' => ['fa' => 'سر بازی نمانده — این خط از نظر ساختاری کامل است.',
                       'en' => 'No open ends — this line is structurally complete.'],
        'attach'   => ['fa' => 'اتصال قطعه به این سر', 'en' => 'Attach a component to this end'],
        'attachbtn'=> ['fa' => 'اتصال', 'en' => 'Attach'],
        'add'      => ['fa' => 'افزودن قطعهٔ مستقل', 'en' => 'Add a standalone component'],
        'addbtn'   => ['fa' => 'افزودن', 'en' => 'Add'],
        'branch'   => ['fa' => 'سایز انشعاب', 'en' => 'Branch size'],
        'small'    => ['fa' => 'سر کوچک', 'en' => 'Small end'],
        'lenmm'    => ['fa' => 'طول (میلی‌متر)', 'en' => 'Length (mm)'],
        'sched'    => ['fa' => 'رده', 'en' => 'Schedule'],
        'issuedPms'=> ['fa' => 'بازنگری PMS زمان صدور', 'en' => 'Issued PMS revision'],
        'unmapped' => ['fa' => 'در انتظار بررسی کلاس و بازنگری PMS', 'en' => 'Class/PMS revision review required'],
        'scheduleMatches' => ['fa' => 'منطبق با ردهٔ PMS زمان صدور', 'en' => 'Matches issued PMS schedule'],
        'scheduleDiffers' => ['fa' => 'مغایر با ردهٔ PMS زمان صدور', 'en' => 'Differs from issued PMS schedule'],
        'scheduleUnknown' => ['fa' => 'ردهٔ PMS قابل بررسی نیست', 'en' => 'Issued PMS schedule cannot be checked'],
        'expected' => ['fa' => 'ردهٔ مورد انتظار', 'en' => 'Expected'],
        'requestReview' => ['fa' => 'درخواست بررسی مغایرت', 'en' => 'Request discrepancy review'],
        'requestReason' => ['fa' => 'دلیل و شرح مغایرت (تأیید نیست)', 'en' => 'Reason for discrepancy (not approval)'],
        'reviewPending' => ['fa' => 'درخواست بررسی ثبت شده؛ هنوز تأیید نشده است', 'en' => 'Review requested; not approved'],
        'taperProposal' => ['fa' => 'Taper داخلیِ پیشنهادی (صرفاً طرح؛ تأیید Fit-up نیست)', 'en' => 'Proposed internal taper (plan only; not Fit-up acceptance)'],
        'remedyUndetermined' => ['fa' => 'روش رفع هنوز تعیین نشده است', 'en' => 'Remedy not yet determined'],
        'requestHistory' => ['fa' => 'سابقهٔ درخواست‌های مغایرت', 'en' => 'Discrepancy request history'],
        'previousValue' => ['fa' => 'مقدار پیشین؛ برای ردهٔ فعلی معتبر نیست', 'en' => 'Previous value; not applicable to current schedule'],
        'unverifiedPmsClaim' => ['fa' => 'مقدار PMS این درخواست با مدرک حاکم فعلی قابل تطبیق نیست؛ مبنای پیش‌نویس جدید نیست', 'en' => 'Request PMS value does not match the current issued basis; not eligible for a new draft'],
        'reviewRecorded' => ['fa' => 'رویداد بررسی ثبت شده؛ تأیید مهندسی احراز نشده', 'en' => 'Review event recorded; engineering acceptance not established'],
        'internalDraft' => ['fa' => 'پیش‌نویس داخلی؛ صادر نشده', 'en' => 'Internal draft; not issued'],
        'externalMetadata' => ['fa' => 'اطلاعات گزارش بیرونی؛ راستی‌آزمایی نشده', 'en' => 'External report metadata; not verified'],
        'captureDraft' => ['fa' => 'ساخت پیش‌نویس داخلی TQ/NCR (بدون صدور)', 'en' => 'Create internal TQ/NCR draft (not issued)'],
        'captureExternal' => ['fa' => 'ثبت اطلاعات TQ/NCR صادرشده در خارج (بدون تأیید)', 'en' => 'Record externally issued TQ/NCR metadata (not verified)'],
        'reportNumber' => ['fa' => 'شمارهٔ گزارش بیرونی', 'en' => 'External report number'],
        'reportRevision' => ['fa' => 'بازنگری (اختیاری)', 'en' => 'Revision (optional)'],
        'reportIssuer' => ['fa' => 'صادرکنندهٔ بیرونی', 'en' => 'External issuer'],
        'reportSigner' => ['fa' => 'نام امضاکنندهٔ بیرونی', 'en' => 'External signatory'],
        'reportCapacity' => ['fa' => 'سمت امضاکننده', 'en' => 'Signatory capacity'],
        'reportIssuedAt' => ['fa' => 'تاریخ صدور بیرونی', 'en' => 'External issue date'],
        'reportSignedAt' => ['fa' => 'تاریخ امضا', 'en' => 'Signature date'],
        'reportRegister' => ['fa' => 'مرجع رجیستر/بایگانی بیرونی', 'en' => 'External register reference'],
        'reportDetails' => ['fa' => 'جزئیات اطلاعات ثبت‌شده (ادعای بیرونی؛ راستی‌آزمایی نشده)', 'en' => 'Recorded details (external claim; not verified)'],
        'reportRecordedAt' => ['fa' => 'زمان ثبت در سامانه', 'en' => 'Recorded in system at'],
        'reportRecorder' => ['fa' => 'شناسهٔ ثبت‌کننده در سامانه (نه صادرکننده)', 'en' => 'System recorder ID (not issuer)'],
        'inherit'  => ['fa' => 'سایز از پورت والد به ارث می‌رسد و قابل تایپ نیست.',
                       'en' => 'The size is inherited from the parent port and cannot be typed.'],
        'locked'   => ['fa' => 'این خط ابلاغ شده است؛ تغییر مدل آن نیاز به بازگشت به پیش‌نویس دارد.',
                       'en' => 'This line is released; changing its model needs a revert to draft first.'],
        'portsnote'=> ['fa' => 'تعداد پورت‌ها از نوع قطعه می‌آید — سه‌راه همیشه سه پورت دارد و کسی آن را وارد نمی‌کند.',
                       'en' => 'The port count comes from the component type — a tee always has three, and nobody types that.'],
        'joints'   => ['fa' => 'اتصالات', 'en' => 'Joints'],
        'jnone'    => ['fa' => 'هنوز اتصالی روی این خط ثبت نشده است.', 'en' => 'No joints on this line yet.'],
        'jnum'     => ['fa' => 'شماره', 'en' => 'No.'],
        'jtype'    => ['fa' => 'نوع', 'en' => 'Type'],
        'jbetween' => ['fa' => 'بین', 'en' => 'Between'],
        'jstate'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'jdia'     => ['fa' => 'قطر-اینچ', 'en' => 'Dia-inch'],
        'junnum'   => ['fa' => 'بدون شماره', 'en' => 'not numbered'],
        'jwelds'   => ['fa' => 'جوش', 'en' => 'welds'],
        'jfitted'  => ['fa' => 'مونتاژشده', 'en' => 'fitted'],
        'jdianote' => ['fa' => 'قطر-اینچ همیشه از سر کوچک‌تر گرفته می‌شود؛ ذخیره نمی‌شود و هر بار محاسبه می‌گردد.',
                       'en' => 'Dia-inch always takes the smaller end; it is computed, never stored.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    // Inch marks inside an <option>: no markup is allowed there, so <bdi> is
    // out. A trailing LRM pins the quote to its number rather than letting
    // the bidi algorithm float it to the visual start of the line.
    $inch = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang).'"'.($lang === 'fa' ? "\u{200E}" : '');

    $tplName = function (string $code) use ($templates, $lang): string {
        foreach ($templates as $tpl) {
            if ($tpl['code'] === $code) {
                return $tpl['name'][$lang] ?? $tpl['name']['en'] ?? $code;
            }
        }
        return $code;
    };

    $q = ['lang' => $lang] + (request()->has('sid') ? ['sid' => request()->query('sid')] : []);
    $base = "/projects/{$project->code}/piping/{$line->id}/model?".http_build_query($q);
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $line->line_number }} — {{ $L('title') }}</title>
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
    <span class="mono">{{ $line->line_number }}</span>
    · {{ $line->subsystem?->code }}
    @if($line->nominal_size) · <bdi>{{ $num($line->nominal_size) }}"</bdi> @endif
    @if($line->pipingClass) · {{ $line->pipingClass->code }} @endif
  </div>
  @if($line->pms_class_snapshot_id !== null)
    @php $issued = $line->pmsSnapshot?->documentRevision; @endphp
    <div style="font-size:12px;color:var(--muted);margin-top:5px;">
      {{ $L('issuedPms') }}:
      {{ $issued?->document?->number ?? 'PMS' }} · {{ $issued?->revision ?? '—' }}
      @if($issued?->suitability) ({{ $issued->suitability }}) @endif
    </div>
  @else
    <div style="font-size:12px;color:#fca5a5;margin-top:5px;">{{ $L('unmapped') }}</div>
  @endif
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;">{{ $L('sub') }}</p>
  <p style="margin:10px 0 0;">
    <a href="/projects/{{ $project->code }}/piping?{{ http_build_query($q) }}"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; {{ $L('back') }}</a>
  </p>

  @if(! $editable)
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:13px;line-height:1.7;">
      {{ $L('locked') }}
    </div>
  @endif

  @if(session('status'))
    <div role="status" style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #14532d;background:rgba(20,83,45,.25);color:#86efac;font-size:13px;line-height:1.7;">
      {{ session('status') }}
    </div>
  @endif
  @if($errors->any())
    <div role="alert" style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  {{-- ------------------------------------------------ open ends --------- --}}
  <h2 style="margin-top:26px;font-size:16px;">{{ $L('openends') }}
    <span style="color:var(--muted);font-weight:400;font-size:13px;">({{ $num(count($open)) }})</span>
  </h2>

  @if(count($open) === 0)
    <div style="margin-top:10px;padding:10px 12px;border-radius:8px;border:1px solid #14532d;background:rgba(20,83,45,.25);color:#86efac;font-size:13px;">
      {{ $L('complete') }}
    </div>
  @else
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-top:10px;">
      @foreach($open as $port)
        <div style="border:1px solid var(--line);border-radius:10px;background:var(--card);padding:10px 12px;">
          <div class="mono" style="font-size:13px;"><bdi>{{ $port->component->label() }}</bdi></div>
          <div style="color:var(--muted);font-size:12px;margin-top:2px;"><bdi>{{ $port->label() }}</bdi>
            @if($port->end_type) · {{ $port->end_type }} @endif
          </div>

          @if($editable)
          <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/attach?{{ http_build_query($q) }}"
                style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
            @csrf
            <input type="hidden" name="port_id" value="{{ $port->id }}">
            <select name="template_code" required
                    style="flex:1 1 60%;min-width:0;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
              @foreach($templates as $tpl)
                <option value="{{ $tpl['code'] }}">{{ $tpl['name'][$lang] ?? $tpl['name']['en'] }} ({{ $num($tpl['ports']) }})</option>
              @endforeach
            </select>
            <button type="submit"
                    style="padding:5px 12px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
              {{ $L('attachbtn') }}
            </button>
          </form>
          @endif
        </div>
      @endforeach
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:8px;">{{ $L('inherit') }}</p>
  @endif

  {{-- ------------------------------------------------ components -------- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('title') }}
    <span style="font-weight:400;font-size:11.5px;color:var(--muted);margin-inline-start:10px;">
      <span style="color:#86efac;">●</span> {{ $fa ? 'متصل' : 'connected' }}
      &nbsp;<span style="color:#fcd34d;">○</span> {{ $fa ? 'باز' : 'open' }}
    </span>
  </h2>

  <table data-tt style="margin-top:10px;">
    <thead>
      <tr>
        <th data-sort="number">{{ $L('seq') }}</th>
        <th data-sort="text" data-facet>{{ $L('type') }}</th>
        <th data-sort="text">{{ $L('size') }}</th>
        <th data-sort="text">{{ $L('sched') }}</th>
        <th>{{ $L('ports') }}</th>
        <th data-sort="number">{{ $L('length') }}</th>
        <th>{{ $L('actions') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $c)
      <tr>
        <td class="mono">{{ $num($c->sequence) }}</td>
        <td>{{ $tplName($c->template_code) }}
          <div style="color:var(--muted);font-size:11px;" class="mono">{{ $c->template_code }}</div>
        </td>
        <td class="mono">@if($c->nominal_size)<bdi>{{ $num($c->nominal_size) }}"</bdi>@else—@endif</td>
        <td class="mono">
          {{ $c->schedule ?? '—' }}
          @if($c->template_code === 'PIPE' && $line->pms_class_snapshot_id !== null)
            @php $check = $scheduleChecks[$c->id] ?? null; @endphp
            @if($check === null)
              <div style="font-size:11px;color:#fcd34d;">{{ $L('scheduleUnknown') }}</div>
            @elseif($check['matches'])
              <div style="font-size:11px;color:#86efac;">{{ $L('scheduleMatches') }}</div>
            @else
              <div style="font-size:11px;color:#fca5a5;">{{ $L('scheduleDiffers') }} · {{ $L('expected') }}: {{ $check['expected'] }}</div>
              @if(isset($pendingScheduleRequests[$c->id]))
                <div style="font-size:11px;color:#fcd34d;">{{ $L('reviewPending') }}</div>
                @if($pendingScheduleRequests[$c->id]['remedy'] === 'INTERNAL_TAPER_PROPOSED')
                  <div style="font-size:11px;color:#fcd34d;">{{ $L('taperProposal') }}</div>
                @endif
              @elseif($canCapture && $c->company_id === $user->company_id && $line->company_id === $user->company_id)
                <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/{{ $c->id }}/request-review?{{ http_build_query($q) }}" style="margin-top:6px;">
                  @csrf
                  @if($check['taper_eligible'])
                    <select name="proposed_remedy" aria-label="{{ $L('taperProposal') }}"
                            style="width:100%;min-width:170px;background:var(--bg);color:inherit;border:1px solid var(--line);border-radius:6px;">
                      <option value="UNDETERMINED">{{ $L('remedyUndetermined') }}</option>
                      <option value="INTERNAL_TAPER_PROPOSED">{{ $L('taperProposal') }}</option>
                    </select>
                  @endif
                  <textarea name="reason" required minlength="10" maxlength="4000" rows="2"
                            placeholder="{{ $L('requestReason') }}" aria-label="{{ $L('requestReason') }}"
                            style="width:100%;min-width:170px;background:var(--bg);color:inherit;border:1px solid var(--line);border-radius:6px;"></textarea>
                  <button type="submit" style="font:inherit;font-size:11px;cursor:pointer;">{{ $L('requestReview') }}</button>
                </form>
              @endif
            @endif
          @endif
          @if(($requestHistory[$c->id] ?? collect())->isNotEmpty())
            <details style="margin-top:8px;font-size:11px;">
              <summary>{{ $L('requestHistory') }} ({{ $num($requestHistory[$c->id]->count()) }})</summary>
              @foreach($requestHistory[$c->id] as $entry)
                <div style="border-top:1px solid var(--line);padding:5px 0;white-space:normal;">
                  <bdi>{{ $entry->created_at }}</bdi> ·
                  @if($entry->nps_at_request)<bdi>{{ $num($entry->nps_at_request) }}" (DN{{ $num($entry->dn_at_request) }})</bdi> · @endif
                  {{ $entry->actual_schedule }} / {{ $entry->expected_schedule }}
                  @if($entry->actual_schedule !== $c->schedule || $entry->pms_class_snapshot_id !== $line->pms_class_snapshot_id
                      || $entry->nps_at_request !== $c->nominal_size)
                    <div style="color:#fcd34d;">{{ $L('previousValue') }}</div>
                  @elseif(! isset($scheduleChecks[$c->id]) || $entry->expected_schedule !== $scheduleChecks[$c->id]['expected']
                      || (int) $entry->dn_at_request !== $scheduleChecks[$c->id]['dn'])
                    <div style="color:#fcd34d;">{{ $L('unverifiedPmsClaim') }}</div>
                  @endif
                  @if($entry->proposed_remedy === 'INTERNAL_TAPER_PROPOSED')
                    <div>{{ $L('taperProposal') }}</div>
                  @endif
                  <div>{{ $entry->reason }}</div>
                  @if($entry->decision_id !== null)
                    <div style="color:#fcd34d;">{{ $L('reviewRecorded') }}</div>
                  @else
                    <div style="color:#fcd34d;">{{ $L('reviewPending') }}</div>
                  @endif
                  @foreach($reportHistory[$entry->id] ?? [] as $report)
                    <div>{{ $report->kind }} ·
                      @if($report->origin === 'IN_SYSTEM')
                        {{ $L('internalDraft') }}
                      @else
                        {{ $L('externalMetadata') }} · <bdi>{{ $report->report_number }}</bdi>
                        <details style="margin:4px 0 8px 12px;">
                          <summary>{{ $L('reportDetails') }}</summary>
                          <div>{{ $L('reportNumber') }}: <bdi>{{ $report->report_number }}</bdi></div>
                          @if($report->report_revision !== null)
                            <div>{{ $L('reportRevision') }}: <bdi>{{ $report->report_revision }}</bdi></div>
                          @endif
                          <div>{{ $L('reportIssuer') }}: {{ $report->reported_issuer }}</div>
                          <div>{{ $L('reportSigner') }}: {{ $report->reported_signer }}</div>
                          <div>{{ $L('reportCapacity') }}: {{ $report->reported_signer_capacity }}</div>
                          <div>{{ $L('reportIssuedAt') }}: <bdi>{{ substr((string) $report->reported_issued_at, 0, 10) }}</bdi></div>
                          <div>{{ $L('reportSignedAt') }}: <bdi>{{ substr((string) $report->reported_signed_at, 0, 10) }}</bdi></div>
                          <div>{{ $L('reportRegister') }}: <bdi>{{ $report->external_register_ref }}</bdi></div>
                          <div>{{ $L('reportRecordedAt') }}: <bdi>{{ $report->created_at }}</bdi></div>
                          <div>{{ $L('reportRecorder') }}: <bdi>{{ $report->recorded_by }}</bdi></div>
                        </details>
                      @endif
                    </div>
                  @endforeach
                  @if($canCapture && $c->company_id === $user->company_id && $line->company_id === $user->company_id)
                    @if($entry->nps_at_request !== null && $entry->nps_at_request === $c->nominal_size
                        && isset($scheduleChecks[$c->id])
                        && (int) $entry->dn_at_request === $scheduleChecks[$c->id]['dn']
                        && $entry->expected_schedule === $scheduleChecks[$c->id]['expected']
                        && $entry->actual_schedule === $c->schedule && $entry->pms_class_snapshot_id === $line->pms_class_snapshot_id)
                      <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/reports/{{ $entry->id }}/draft?{{ http_build_query($q) }}" style="margin-top:6px;">
                        @csrf
                        <select name="kind" aria-label="TQ/NCR"><option value="TQ">TQ</option><option value="NCR">NCR</option></select>
                        <button type="submit">{{ $L('captureDraft') }}</button>
                      </form>
                    @endif
                    <details style="margin-top:6px;">
                      <summary>{{ $L('captureExternal') }}</summary>
                      <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/reports/{{ $entry->id }}/external?{{ http_build_query($q) }}" style="display:grid;gap:5px;max-width:330px;margin-top:5px;">
                        @csrf
                        <select name="kind" aria-label="TQ/NCR"><option value="TQ">TQ</option><option value="NCR">NCR</option></select>
                        <input name="report_number" required maxlength="160" placeholder="{{ $L('reportNumber') }}" aria-label="{{ $L('reportNumber') }}">
                        <input name="report_revision" maxlength="64" placeholder="{{ $L('reportRevision') }}" aria-label="{{ $L('reportRevision') }}">
                        <input name="reported_issuer" required maxlength="200" placeholder="{{ $L('reportIssuer') }}" aria-label="{{ $L('reportIssuer') }}">
                        <input name="reported_signer" required maxlength="200" placeholder="{{ $L('reportSigner') }}" aria-label="{{ $L('reportSigner') }}">
                        <input name="reported_signer_capacity" required maxlength="200" placeholder="{{ $L('reportCapacity') }}" aria-label="{{ $L('reportCapacity') }}">
                        <label>{{ $L('reportIssuedAt') }} <input type="date" name="reported_issued_at" required></label>
                        <label>{{ $L('reportSignedAt') }} <input type="date" name="reported_signed_at" required></label>
                        <input name="external_register_ref" required maxlength="512" placeholder="{{ $L('reportRegister') }}" aria-label="{{ $L('reportRegister') }}">
                        <button type="submit">{{ $L('captureExternal') }}</button>
                      </form>
                    </details>
                  @endif
                </div>
              @endforeach
            </details>
          @endif
        </td>
        <td style="font-size:12px;">
          @foreach($c->ports as $p)
            {{-- Open vs connected must be legible at a glance, and colour
                 alone does not do it: on a dark background the two pills were
                 near-identical in a screenshot, and a colour-blind reader gets
                 nothing from green-vs-grey either. So the OPEN state also
                 carries a dashed border and a bullet glyph. --}}
            <span style="display:inline-block;margin:1px 3px 1px 0;padding:1px 7px;border-radius:999px;font-size:11px;
                         border:1px {{ $p->is_connected ? 'solid #15803d' : 'dashed #f59e0b' }};
                         background:{{ $p->is_connected ? 'rgba(21,128,61,.28)' : 'rgba(245,158,11,.12)' }};
                         color:{{ $p->is_connected ? '#86efac' : '#fcd34d' }};"
                  title="{{ $p->is_connected ? ($fa ? 'متصل' : 'connected') : ($fa ? 'باز' : 'open') }}">
              {{-- bdi, because this is a latin-and-digits token inside an RTL
                   paragraph: without it the trailing inch mark is reordered to
                   the front and the pill reads  "P2 RUN 6  in Farsi. --}}
              {{ $p->is_connected ? '●' : '○' }}
              <bdi>P{{ $num($p->seq) }} {{ $p->role }}{{ $p->nominal_size ? ' '.$num($p->nominal_size).'"' : '' }}</bdi>
            </span>
          @endforeach
        </td>
        <td class="mono">{{ $c->length_mm ? $num(rtrim(rtrim(number_format((float) $c->length_mm, 1, '.', ''), '0'), '.')) : '—' }}</td>
        <td>
          @if($editable)
          <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/{{ $c->id }}/delete?{{ http_build_query($q) }}"
                onsubmit="return confirm('{{ $L('remove') }}?')">
            @csrf
            <button type="submit"
                    style="padding:4px 10px;border-radius:6px;border:1px solid var(--line);background:transparent;color:#fca5a5;font:inherit;font-size:12px;cursor:pointer;">
              {{ $L('remove') }}
            </button>
          </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="7" style="color:var(--muted);">{{ $L('none') }}</td></tr>
    @endforelse
    </tbody>
  </table>

  {{-- ------------------------------------------------------- joints ----- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('joints') }}
    <span style="font-weight:400;font-size:11.5px;color:var(--muted);margin-inline-start:10px;">
      {{-- Each metric is isolated WHOLE, number and label together. Isolating
           only the number leaves the "·" separator as a neutral character
           between a digit run and a word, and the bidi algorithm attaches it
           to the digits: "6 welds · 2 fitted · 28" rendered in Farsi as
           "۶ جوش ۲۰ مونتاژشده ۲۸۰". The numbers were right; the punctuation
           moved. --}}
      <bdi>{{ $num($metrics['welds']) }} {{ $L('jwelds') }}</bdi>
      <span style="color:var(--line);">|</span>
      <bdi>{{ $num($metrics['fitted']) }} {{ $L('jfitted') }}</bdi>
      <span style="color:var(--line);">|</span>
      <bdi>{{ $num(rtrim(rtrim(number_format($metrics['dia_inch'], 2, '.', ''), '0'), '.')) }} {{ $L('jdia') }}</bdi>
    </span>
  </h2>
  <p style="color:var(--muted);font-size:11.5px;margin:4px 0 0;">{{ $L('jdianote') }}</p>

  <table data-tt style="margin-top:10px;">
    <thead>
      <tr>
        <th data-sort="number">{{ $L('jnum') }}</th>
        <th data-sort="text" data-facet>{{ $L('jtype') }}</th>
        <th>{{ $L('jbetween') }}</th>
        <th data-sort="text" data-facet>{{ $L('jstate') }}</th>
        <th data-sort="number">{{ $L('jdia') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($joints as $j)
      @php
        $dia = $diaInch($j);
        $cA = $j->portA?->component;
        $cB = $j->portB?->component;
      @endphp
      <tr>
        <td class="mono">
          @if($j->displayNumber())
            <bdi>{{ $num($j->displayNumber()) }}</bdi>
          @else
            {{-- A flanged joint is NOT an unnumbered weld awaiting a number:
                 it will never have one. Showing a blank cell would put it on
                 somebody's chase-list forever. --}}
            <span style="color:var(--muted);font-size:11px;">{{ $L('junnum') }}</span>
          @endif
        </td>
        <td style="font-size:12px;"><bdi>{{ $j->joint_type }}</bdi>
          @if($j->compatibility_state !== 'VALID')
            <span style="display:inline-block;margin-inline-start:6px;padding:1px 7px;border-radius:999px;font-size:10.5px;
                         border:1px dashed #f59e0b;background:rgba(245,158,11,.12);color:#fcd34d;"
                  title="{{ implode(' · ', collect($j->compatibility_notes['reasons'] ?? [])->map(fn ($r) => __('epcc.joint.reason.'.$r['code'], $r['params']))->all()) }}">
              ⚠ {{ $j->compatibility_state }}
            </span>
          @endif
        </td>
        <td style="font-size:12px;">
          <bdi>{{ $cA ? $num($cA->sequence).' '.$cA->template_code : '?' }}</bdi>
          <span style="color:var(--muted);">↔</span>
          <bdi>{{ $cB ? $num($cB->sequence).' '.$cB->template_code : '?' }}</bdi>
        </td>
        <td style="font-size:12px;">
          <span style="display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;
                       border:1px {{ $j->isFitted() ? 'solid #15803d' : 'dashed var(--line)' }};
                       background:{{ $j->isFitted() ? 'rgba(21,128,61,.28)' : 'transparent' }};
                       color:{{ $j->isFitted() ? '#86efac' : 'var(--muted)' }};">
            {{ $j->isFitted() ? '●' : '○' }} <bdi>{{ $j->state }}</bdi>
          </span>
        </td>
        <td class="mono">
          @if($dia !== null)
            <bdi>{{ $num(rtrim(rtrim(number_format($dia, 2, '.', ''), '0'), '.')) }}</bdi>
          @else
            <span style="color:var(--muted);">—</span>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="5" style="color:var(--muted);">{{ $L('jnone') }}</td></tr>
    @endforelse
    </tbody>
  </table>

  {{-- ------------------------------------------------ add standalone ---- --}}
  @if($editable)
  <div style="margin-top:22px;border:1px solid var(--line);border-radius:10px;background:var(--card);padding:14px;">
    <h2 style="margin:0 0 4px;font-size:15px;">{{ $L('add') }}</h2>
    <p style="color:var(--muted);font-size:12px;margin:0 0 12px;line-height:1.7;">{{ $L('portsnote') }}</p>

    <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model?{{ http_build_query($q) }}"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;">
      @csrf

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('type') }}
        <select name="template_code" required
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          @foreach($templates as $tpl)
            <option value="{{ $tpl['code'] }}" @selected(old('template_code') === $tpl['code'])>
              {{ $tpl['name'][$lang] ?? $tpl['name']['en'] }} — {{ $num($tpl['ports']) }} {{ $L('ports') }}
            </option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('size') }}
        <select name="nominal_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('nominal_size', $line->nominal_size) === $s)>{{ $inch($s) }}</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('branch') }}
        <select name="branch_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('branch_size') === $s)>{{ $inch($s) }}</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('small') }}
        <select name="small_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('small_size') === $s)>{{ $inch($s) }}</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('lenmm') }}
        <input type="number" step="any" min="0" name="length_mm" value="{{ old('length_mm') }}"
               style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
      </label>

      <button type="submit"
              style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
        {{ $L('addbtn') }}
      </button>
    </form>
  </div>
  @endif

  <div class="foot">
    {{ $fa
        ? 'تعداد و نقش پورت‌ها از تمپلیت قطعه می‌آید، نه از ورودی کاربر؛ سایز هنگام اتصال از پورت والد به ارث می‌رسد. به همین دلیل «لولهٔ ۶ اینچ روی انشعاب ۲ اینچ» اصلاً قابل بیان نیست.'
        : 'Port count and roles come from the component template, not from user input; size is inherited from the parent port on attachment. That is why "6\" pipe onto a 2\" branch" is not expressible at all.' }}
  </div>
</div>
</div>
</body>
</html>
