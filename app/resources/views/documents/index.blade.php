@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'دفتر مدارک', 'en' => 'Document register'],
        'sub'     => ['fa' => 'حالت‌های ایزو ۱۹۶۵۰ و کنترل بازنگری', 'en' => 'ISO 19650 CDE states and revision control'],
        'number'  => ['fa' => 'شمارهٔ مدرک', 'en' => 'Document no.'],
        'dtitle'  => ['fa' => 'عنوان', 'en' => 'Title'],
        'disc'    => ['fa' => 'رشته', 'en' => 'Discipline'],
        'rev'     => ['fa' => 'بازنگری', 'en' => 'Rev'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'CDE state'],
        'suit'    => ['fa' => 'کد تناسب', 'en' => 'Suitability'],
        'owner'   => ['fa' => 'تهیه‌کننده', 'en' => 'Originator'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'empty'   => ['fa' => 'هنوز مدرکی ثبت نشده است.', 'en' => 'No documents registered yet.'],
        'new'     => ['fa' => 'ثبت مدرک جدید', 'en' => 'Register a document'],
        'type'    => ['fa' => 'نوع', 'en' => 'Type'],
        'ten'     => ['fa' => 'عنوان (انگلیسی)', 'en' => 'Title (English)'],
        'tfa'     => ['fa' => 'عنوان (فارسی)', 'en' => 'Title (Farsi)'],
        'save'    => ['fa' => 'ثبت', 'en' => 'Register'],
        'move'    => ['fa' => 'انتقال به', 'en' => 'Move to'],
        'apply'   => ['fa' => 'اعمال', 'en' => 'Apply'],
        'revise'  => ['fa' => 'بازنگری جدید', 'en' => 'New revision'],
        'total'   => ['fa' => 'مدرک', 'en' => 'documents'],
        'noperm'  => ['fa' => 'شما اجازهٔ ثبت مدرک در این پروژه را ندارید.', 'en' => 'You may not register documents on this project.'],
        'files'   => ['fa' => 'فایل‌ها', 'en' => 'Files'],
        'attach'  => ['fa' => 'پیوست فایل', 'en' => 'Attach file'],
        'nofiles' => ['fa' => 'بدون فایل', 'en' => 'No files'],
        'view'    => ['fa' => 'نمایش', 'en' => 'View'],
        'dl'      => ['fa' => 'دریافت', 'en' => 'Download'],
        'rm'      => ['fa' => 'حذف', 'en' => 'Remove'],
        'locked'  => ['fa' => 'بازنگری بسته است', 'en' => 'Revision closed'],
        'maxsz'   => ['fa' => 'حداکثر', 'en' => 'Max'],
        'confirm' => ['fa' => 'این فایل حذف شود؟', 'en' => 'Remove this file?'],
        'review'  => ['fa' => 'نظرات', 'en' => 'Comments'],
        'blocking'=> ['fa' => 'نظر بازدارنده', 'en' => 'blocking'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
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
  .st { display:inline-block; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
  .st.WIP       { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.SHARED    { background:rgba(245,158,11,.16); color:#fcd34d; border:1px solid rgba(245,158,11,.35); }
  .st.PUBLISHED { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.ARCHIVED  { background:rgba(100,116,139,.16); color:#94a3b8; border:1px solid rgba(100,116,139,.35); }
  .inline-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
  .inline-form select, .inline-form input { padding:5px 8px; font-size:12px; border-radius:6px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  /* --- attachments (FR-DOC-01/02) --- */
  .files { display:flex; flex-direction:column; gap:5px; }
  .f { display:flex; align-items:center; gap:7px; font-size:12px; min-width:0; }
  .f .ext { flex:none; font-size:9.5px; font-weight:800; letter-spacing:.05em; padding:2px 5px;
    border-radius:4px; background:rgba(56,132,255,.16); color:#93c5fd;
    border:1px solid rgba(56,132,255,.35); text-transform:uppercase; }
  .f .nm { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:210px;
    color:#cbd5e1; text-decoration:none; border-bottom:1px dotted rgba(203,213,225,.4); }
  .f .nm:hover { color:#fff; border-bottom-color:#fff; }
  .f .sz { flex:none; color:var(--muted); font-variant-numeric:tabular-nums; font-size:11px; }
  /* The hash is the integrity record (FR-AUD-02); shown so it can be quoted
     in a transmittal or a claim without opening the file. */
  .f .hash { flex:none; font-family:var(--mono,monospace); font-size:10px; color:#64748b; }
  .f .x { flex:none; background:none; border:0; color:#64748b; cursor:pointer; font-size:14px;
    line-height:1; padding:0 3px; font-family:inherit; }
  .f .x:hover { color:#fca5a5; }
  .nofiles { font-size:11.5px; color:var(--muted); }
  .up { display:flex; align-items:center; gap:6px; margin-top:7px; flex-wrap:wrap; }
  .up input[type=file] { font:inherit; font-size:11px; color:var(--muted); max-width:190px; }
  .up input[type=file]::file-selector-button { font:inherit; font-size:11px; margin-inline-end:6px;
    padding:4px 9px; border-radius:6px; border:1px solid var(--line); background:#0b1220;
    color:#cbd5e1; cursor:pointer; }
  .up .hint { font-size:10.5px; color:#64748b; }
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <div class="sub">
    {{ $project->name($lang) }} · <span class="mono">{{ $project->code }}</span>
    · <span class="pill pack">{{ $project->standards_pack }}</span> · {{ $jalaliDate }}
  </div>

  @if (session('status'))
    <div class="ok">{{ session('status') }}</div>
  @endif

  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <div class="docstats">
    @foreach($states as $s)
      <div class="dstat">
        <div class="k">{{ \App\Support\CdeState::label($s, $lang) }}</div>
        <div class="v">{{ $num($counts[$s] ?? 0) }}</div>
      </div>
    @endforeach
    <div class="dstat">
      <div class="k">{{ $L('total') }}</div>
      <div class="v">{{ $num(count($documents)) }}</div>
    </div>
  </div>

  @if($canCreate)
    <fieldset>
      <legend>{{ $L('new') }}</legend>
      <form method="post" action="/projects/{{ $project->code }}/documents?lang={{ $lang }}">
        @csrf
        <div class="frow">
          <div>
            <label for="discipline">{{ $L('disc') }}</label>
            <select id="discipline" name="discipline" required>
              @foreach($disciplines as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
            </select>
          </div>
          <div>
            <label for="doc_type">{{ $L('type') }}</label>
            <select id="doc_type" name="doc_type" required>
              @foreach($docTypes as $dt)<option value="{{ $dt }}">{{ $dt }}</option>@endforeach
            </select>
          </div>
          <div>
            <label for="title_en">{{ $L('ten') }}</label>
            <input id="title_en" name="title_en" value="{{ old('title_en') }}" required dir="ltr">
          </div>
          <div>
            <label for="title_fa">{{ $L('tfa') }}</label>
            <input id="title_fa" name="title_fa" value="{{ old('title_fa') }}" required dir="rtl">
          </div>
        </div>
        <button type="submit" class="btn">{{ $L('save') }}</button>
      </form>
    </fieldset>
  @endif

  @if(count($documents) === 0)
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;">
      {{ $L('empty') }}
    </div>
  @else
    {{-- data-tt opts this table into filter/sort (partials/tabletools). --}}
    <table data-tt>
      <thead>
        <tr>
          <th data-sort="text">{{ $L('number') }}</th>
          <th data-sort="text">{{ $L('dtitle') }}</th>
          <th data-sort="text">{{ $L('rev') }}</th>
          {{-- Faceted: "show me everything still in review" is the single most
               common question asked of a document register. --}}
          <th data-sort="text" data-facet>{{ $L('state') }}</th>
          <th data-sort="text">{{ $L('suit') }}</th>
          <th data-sort="text" data-facet>{{ $L('owner') }}</th>
          <th data-sort="num">{{ $L('files') }}</th>
          <th>{{ $L('actions') }}</th>
        </tr>
      </thead>
      <tbody>
      @foreach($documents as $doc)
        @php $rev = $doc->currentRevision; @endphp
        <tr>
          {{-- Sort by the parts, not the rendered string: numbers are shown
               in Persian digits and the sequence must order numerically. --}}
          <td data-sortkey="{{ $doc->discipline }}-{{ $doc->doc_type }}-{{ str_pad((string) $doc->sequence, 6, '0', STR_PAD_LEFT) }}"><span class="mono">{{ $doc->number }}</span></td>
          <td>{{ $doc->title($lang) }}</td>
          <td><span class="mono">{{ $rev?->revision ?? '—' }}</span></td>
          {{-- Sort by LIFECYCLE order, not by the translated label. Sorting
               the Persian strings alphabetically would interleave the states
               meaninglessly; the whole point of the column is the pipeline. --}}
          <td data-sortkey="{{ $rev ? \App\Support\CdeState::ordinalOf($rev->cde_state) : 9 }}">
            @if($rev)
              <span class="st {{ $rev->cde_state }}">{{ $rev->stateLabel($lang) }}</span>
            @endif
          </td>
          <td><span class="mono">{{ $rev?->suitability ?? '—' }}</span></td>
          <td><span class="pill pack">{{ $doc->company->code }}</span></td>
          @php $revFiles = $rev?->files ?? collect(); @endphp
          <td data-sortkey="{{ $revFiles->count() }}">
            @php
              // Published/archived revisions are a matter of record: their
              // attachments stay readable but can no longer be changed.
              $openRev = $rev && in_array($rev->cde_state, [\App\Support\CdeState::WIP, \App\Support\CdeState::SHARED], true);
            @endphp
            @if($revFiles->isEmpty())
              <span class="nofiles">{{ $L('nofiles') }}</span>
            @else
              <div class="files">
                @foreach($revFiles as $f)
                  <div class="f">
                    <span class="ext">{{ $f->extension }}</span>
                    <a class="nm"
                       href="/projects/{{ $project->code }}/documents/{{ $doc->id }}/files/{{ $f->id }}?lang={{ $lang }}{{ $f->isInlineViewable() ? '' : '&download=1' }}"
                       target="_blank" rel="noopener"
                       title="{{ $f->original_name }} · SHA-256 {{ $f->sha256 }}">{{ $f->original_name }}</a>
                    <span class="sz">{{ $f->humanSize($lang) }}</span>
                    <span class="hash" dir="ltr">{{ $f->shortHash() }}</span>
                    @if($openRev && $canIssue)
                      <form method="post" style="display:inline"
                            action="/projects/{{ $project->code }}/documents/{{ $doc->id }}/files/{{ $f->id }}/delete?lang={{ $lang }}"
                            onsubmit="return confirm('{{ $L('confirm') }}')">
                        @csrf
                        <button type="submit" class="x" title="{{ $L('rm') }}" aria-label="{{ $L('rm') }}">&times;</button>
                      </form>
                    @endif
                  </div>
                @endforeach
              </div>
            @endif

            @if($openRev && $canIssue)
              <form class="up" method="post" enctype="multipart/form-data"
                    action="/projects/{{ $project->code }}/documents/{{ $doc->id }}/files?lang={{ $lang }}">
                @csrf
                <input type="file" name="file" required
                       accept="{{ collect($allowedExtensions)->map(fn ($e) => '.'.$e)->implode(',') }}"
                       aria-label="{{ $L('attach') }}">
                <button type="submit" class="btn ghost">{{ $L('attach') }}</button>
              </form>
            @endif
          </td>
          <td>
            @php
              $cmts = $rev?->comments ?? collect();
              $blockingCount = $cmts->filter(fn ($c) => $c->isBlocking())->count();
            @endphp
            {{-- The comment sheet is one click from every row, with the count
                 of what is blocking it: a reviewer should never have to open a
                 document to discover it has no comments. --}}
            <div style="margin-bottom:7px;">
              <a href="/projects/{{ $project->code }}/documents/{{ $doc->id }}/review?lang={{ $lang }}"
                 style="font-size:12px;color:{{ $blockingCount > 0 ? '#fca5a5' : 'var(--muted)' }};text-decoration:none;border-bottom:1px dotted currentColor;">
                {{ $L('review') }}@if($cmts->count() > 0) ({{ $num($cmts->count()) }})@endif
                @if($blockingCount > 0)
                  <strong>· {{ $num($blockingCount) }} {{ $L('blocking') }}</strong>
                @endif
              </a>
            </div>
            @php $next = $rev?->nextStates() ?? []; @endphp
            @if($rev && count($next) > 0)
              {{-- Only transitions the state machine actually permits are
                   offered; the service re-checks on POST regardless. --}}
              <form class="inline-form" method="post"
                    action="/projects/{{ $project->code }}/documents/{{ $doc->id }}?lang={{ $lang }}">
                @csrf
                <select name="cde_state" aria-label="{{ $L('move') }}">
                  @foreach($next as $n)
                    <option value="{{ $n }}">{{ \App\Support\CdeState::label($n, $lang) }}</option>
                  @endforeach
                </select>
                <select name="suitability" aria-label="{{ $L('suit') }}">
                  <option value="">—</option>
                  @foreach(array_unique(array_merge(...array_map(fn ($n) => \App\Support\CdeState::suitabilityFor($n), $next))) as $code)
                    <option value="{{ $code }}">{{ $code }}</option>
                  @endforeach
                </select>
                <button type="submit" class="btn">{{ $L('apply') }}</button>
              </form>
            @elseif($rev && $rev->cde_state === \App\Support\CdeState::PUBLISHED)
              <span style="font-size:12px;color:var(--muted);">—</span>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <div class="foot">
    {{ $fa
      ? 'حالت‌ها بر پایهٔ ایزو ۱۹۶۵۰: در دست تهیه ← ارسال برای بررسی ← منتشر شده ← بایگانی. گذارهای غیرمجاز در سرور رد می‌شوند.'
      : 'States follow ISO 19650: WIP → Shared → Published → Archived. Illegal transitions are refused server-side.' }}
    <br>
    {{ $fa
      ? 'فایل‌ها فقط به بازنگری‌های باز افزوده می‌شوند و با اثر انگشت SHA-256 ذخیره می‌گردند. '.$L('maxsz').' '.$maxUploadLabel.'.'
      : 'Files attach to open revisions only and are stored with a SHA-256 fingerprint. '.$L('maxsz').' '.$maxUploadLabel.'.' }}
  </div>
</div>
</div>
</body>
</html>
