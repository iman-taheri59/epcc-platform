@php
    use App\Support\Itp\InterventionCode;
    use App\Support\TenantType;

    $fa = $lang === 'fa';
    $t = [
        'back'   => ['fa' => 'بازگشت به فهرست ITP', 'en' => 'Back to the ITP register'],
        'clause' => ['fa' => 'بند', 'en' => 'Clause'],
        'act'    => ['fa' => 'فعالیت بازرسی', 'en' => 'Inspection activity'],
        'accept' => ['fa' => 'معیار پذیرش', 'en' => 'Acceptance criteria'],
        'method' => ['fa' => 'روش', 'en' => 'Method'],
        'freq'   => ['fa' => 'تناوب', 'en' => 'Frequency'],
        'rec'    => ['fa' => 'مدرک', 'en' => 'Record'],
        'notice' => ['fa' => 'اعلام', 'en' => 'Notice'],
        'legend' => ['fa' => 'راهنمای کدها', 'en' => 'Code legend'],
        'hours'  => ['fa' => 'ساعت', 'en' => 'h'],
        'not_registered'  => ['fa' => 'در مدارک ثبت نشده', 'en' => 'not in the register'],
        'register_action' => ['fa' => 'ثبت در مدارک پروژه', 'en' => 'Enter in the document register'],
        'registered_as'   => ['fa' => 'شمارهٔ ثبت', 'en' => 'Register entry'],
        'annexes'         => ['fa' => 'پیوست‌ها', 'en' => 'Annexes'],
        'col_standards'    => ['fa' => 'استانداردها', 'en' => 'Standards'],
        'col_project_refs' => ['fa' => 'مدارک پروژه', 'en' => 'Project references'],
        'col_form'         => ['fa' => 'فرم گزارش', 'en' => 'Report form'],
        'no_reference'     => ['fa' => '—', 'en' => '—'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];

    // The party columns. Fixed order so the grid reads like the printed ITP,
    // and so a stage where a party has no involvement shows a blank cell in a
    // known place rather than shifting the whole row.
    $parties = [TenantType::EPC, TenantType::VEN, TenantType::PMC, TenantType::MC, TenantType::TPI, TenantType::OWN];

    $colour = fn (string $c): string => match ($c) {
        InterventionCode::HOLD => '#f87171',
        InterventionCode::WITNESS => '#fbbf24',
        InterventionCode::MONITOR => '#60a5fa',
        InterventionCode::REVIEW => '#a78bfa',
        default => 'var(--muted)',
    };
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $itp->number }}</title>
@include('partials.shell')
@include('partials.tabletools')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $itp->title($lang) }}</h1>
  <div class="sub">
    <bdi class="mono">{{ $itp->displayNumber() }}</bdi> · {{ $itp->discipline }}
    @if($itp->revision_label) · {{ $itp->revision_label }} @endif
    @if($itp->isRegistered())
      @if($itp->cdeState())
        · <span style="color:var(--muted);">{{ $itp->cdeState() }}</span>
      @endif
    @else
      · <span style="color:#fbbf24;">{{ $L('not_registered') }}</span>
    @endif
  </div>
  @if($fa ? $itp->scope_fa : $itp->scope_en)
    <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;max-width:860px;">
      {{ $fa ? $itp->scope_fa : $itp->scope_en }}
    </p>
  @endif
  <p style="margin:10px 0 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
    <a href="/projects/{{ $project->code }}/itp?{{ http_build_query($q) }}"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; {{ $L('back') }}</a>

    {{-- An ITP is a project deliverable. Until it is in the register it is a
         draft the QA team is working on, not a controlled document. --}}
    @unless($itp->isRegistered())
      <form method="POST" action="{{ route('itp.register', ['projectCode' => $project->code, 'itpId' => $itp->id]) }}"
            style="margin:0;">
        @csrf
        <input type="hidden" name="lang" value="{{ $lang }}">
        <button type="submit" style="font-size:12.5px;padding:5px 12px;cursor:pointer;">
          {{ $L('register_action') }}
        </button>
      </form>
    @else
      <span style="font-size:12px;color:var(--muted);">
        {{ $L('registered_as') }}:
        <a href="{{ route('documents', ['projectCode' => $project->code]) }}#doc-{{ $itp->document_id }}"
           style="color:#93c5fd;text-decoration:none;">
          <bdi class="mono">{{ $itp->document?->number }}</bdi></a>
      </span>
    @endunless
  </p>

  {{-- The annex list, as printed on an ITP cover sheet. The blank report
       forms travel WITH the plan when it is issued. --}}
  @if($itp->annexes->isNotEmpty())
    <div style="margin-top:12px;font-size:12.5px;">
      <b style="color:var(--muted);">{{ $L('annexes') }}:</b>
      @foreach($itp->annexes as $annex)
        <span style="margin-inline-end:14px;">
          {{ $annex->caption($lang) }} —
          <a href="{{ route('documents', ['projectCode' => $project->code]) }}#doc-{{ $annex->document_id }}"
             style="color:#93c5fd;text-decoration:none;">
            <bdi class="mono">{{ $annex->document?->number }}</bdi></a>
          <span style="color:var(--muted);">{{ $fa ? $annex->document?->title_fa : $annex->document?->title_en }}</span>
        </span>
      @endforeach
    </div>
  @endif

  {{-- The legend is not decoration: H versus W is the whole document. --}}
  <div style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;font-size:12px;">
    @foreach(InterventionCode::ALL as $code)
      <span style="display:inline-flex;align-items:center;gap:6px;">
        <b style="color:{{ $colour($code) }};font-family:ui-monospace,monospace;">{{ $code }}</b>
        <span>{{ InterventionCode::label($code)[$lang] }}</span>
        <span style="color:var(--muted);">— {{ InterventionCode::description($code)[$lang] }}</span>
      </span>
    @endforeach
  </div>

  <div style="margin-top:16px;overflow-x:auto;">
  <table data-tt style="min-width:1180px;">
    <thead><tr>
      <th>{{ $L('clause') }}</th>
      <th>{{ $L('act') }}</th>
      <th>{{ $L('accept') }}</th>
      <th>{{ $L('method') }}</th>
      <th>{{ $L('freq') }}</th>
      <th>{{ $L('col_standards') }}</th>
      <th>{{ $L('col_project_refs') }}</th>
      @foreach($parties as $p)
        <th style="text-align:center;" title="{{ TenantType::label($p)[$lang] }}">{{ $p }}</th>
      @endforeach
      <th>{{ $L('rec') }}</th>
      <th>{{ $L('col_form') }}</th>
    </tr></thead>
    <tbody>
    @foreach($itp->stages as $stage)
      @php $grid = $stage->parties->keyBy('tenant_type'); @endphp
      <tr>
        <td class="mono" style="font-size:12px;white-space:nowrap;">
          {{ $num($stage->clause) }}
          @if($stage->isHoldPoint())
            <div style="color:#f87171;font-size:10px;">{{ $fa ? 'نقطهٔ توقف' : 'HOLD' }}</div>
          @endif
        </td>
        <td style="font-size:13px;">{{ $stage->title($lang) }}</td>
        <td style="font-size:12px;color:var(--muted);line-height:1.7;min-width:260px;">{{ $stage->acceptance($lang) }}</td>
        <td style="font-size:12px;"><span class="mono">{{ $stage->method }}</span></td>
        <td style="font-size:12px;">{{ $fa ? $stage->frequency_fa : $stage->frequency_en }}</td>

        {{-- Resolved inside tenant scope: frozen basis for final revisions,
             explicitly unknown for legacy, live source preview for drafts. --}}
        <td style="font-size:11.5px;min-width:130px;line-height:1.7;">
          @if($stage->printed_standard_basis)
            <bdi class="mono">{{ $stage->printed_standard_basis }}</bdi>
          @else
            <span style="color:var(--muted);">{{ $L('no_reference') }}</span>
          @endif
        </td>

        {{-- Project documents: real links into the register, so the reader can
             open the spec the acceptance criterion is quoting. --}}
        <td style="font-size:11.5px;line-height:1.8;white-space:nowrap;">
          @php $refs = $stage->documents->whereIn('usage', ['SPEC','DRAWING','PROCEDURE']); @endphp
          @forelse($refs as $ref)
            <div>
              <a href="{{ route('documents', ['projectCode' => $itp->project->code]) }}#doc-{{ $ref->document_id }}"
                 style="color:#93c5fd;text-decoration:none;">
                <bdi class="mono">{{ $ref->document?->number }}</bdi></a>@if($ref->locator)<bdi
                 style="color:var(--muted);">&nbsp;·&nbsp;{{ $ref->locator }}</bdi>@endif
              @if($ref->pinned_revision_id)
                <div style="color:#fbbf24;font-size:10px;">
                  {{ $fa ? 'تثبیت‌شده روی' : 'pinned at' }}
                  <bdi class="mono">{{ $ref->pinnedRevision?->revision }}</bdi>
                </div>
              @endif
            </div>
          @empty
            <span style="color:var(--muted);">{{ $L('no_reference') }}</span>
          @endforelse
        </td>
        @foreach($parties as $p)
          @php $cell = $grid->get($p); @endphp
          <td style="text-align:center;font-family:ui-monospace,monospace;font-weight:700;color:{{ $cell ? $colour($cell->intervention) : 'var(--line,#374151)' }};"
              title="{{ $cell ? InterventionCode::label($cell->intervention)[$lang] : '' }}">
            {{ $cell?->intervention ?? '·' }}
          </td>
        @endforeach
        <td style="font-size:12px;">
          {{ $fa ? $stage->record_fa : $stage->record_en }}
          @if($stage->notice_hours)
            <div style="color:var(--muted);font-size:11px;">{{ $L('notice') }} {{ $num($stage->notice_hours) }}{{ $L('hours') }}</div>
          @endif
        </td>

        {{-- A registered form wins over the printed number: the register
             carries the revision, a bare number does not. --}}
        <td style="font-size:11.5px;white-space:nowrap;">
          @php $form = $stage->documents->firstWhere('usage', 'FORM'); @endphp
          @if($form?->document)
            <a href="{{ route('documents', ['projectCode' => $itp->project->code]) }}#doc-{{ $form->document_id }}"
               style="color:#93c5fd;text-decoration:none;">
              <bdi class="mono">{{ $form->document->number }}</bdi></a>
          @elseif($stage->form_number)
            <bdi class="mono">{{ $stage->form_number }}</bdi>
          @else
            <span style="color:var(--muted);">{{ $L('no_reference') }}</span>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>

  <div class="foot">
    {{ $fa
        ? 'این جدول همان چیزی است که سر پروژه بر سرش بحث می‌شود: چه کسی کجا حق توقف کار را دارد. به همین دلیل شبکهٔ ارکان یک جدول واقعی است، نه یک فیلد JSON — تا بتوان پرسید «بازرس ثالث در این پروژه کجاها نقطهٔ توقف دارد؟».'
        : 'This grid is what people actually argue about on a project: who may stop the work, and where. That is why the party grid is a real table rather than a JSON blob — so the question "where does the TPI hold in this project?" has an answer.' }}
  </div>
</div>
</div>
</body>
</html>
