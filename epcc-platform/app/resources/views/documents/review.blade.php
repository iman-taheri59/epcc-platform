@php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'برگهٔ نظرات', 'en' => 'Comment sheet'],
        'back'     => ['fa' => 'بازگشت به دفتر مدارک', 'en' => 'Back to register'],
        'seq'      => ['fa' => 'ردیف', 'en' => '#'],
        'cat'      => ['fa' => 'دسته', 'en' => 'Category'],
        'ref'      => ['fa' => 'ارجاع', 'en' => 'Reference'],
        'body'     => ['fa' => 'متن نظر', 'en' => 'Comment'],
        'by'       => ['fa' => 'بررسی‌کننده', 'en' => 'Reviewer'],
        'disp'     => ['fa' => 'پاسخ', 'en' => 'Disposition'],
        'act'      => ['fa' => 'اقدام', 'en' => 'Action'],
        'add'      => ['fa' => 'ثبت نظر جدید', 'en' => 'Raise a comment'],
        'save'     => ['fa' => 'ثبت', 'en' => 'Submit'],
        'apply'    => ['fa' => 'ثبت پاسخ', 'en' => 'Respond'],
        'note'     => ['fa' => 'توضیح', 'en' => 'Justification'],
        'empty'    => ['fa' => 'هنوز نظری ثبت نشده است.', 'en' => 'No comments raised yet.'],
        'total'    => ['fa' => 'کل نظرها', 'en' => 'Total'],
        'pending'  => ['fa' => 'بی‌پاسخ', 'en' => 'Pending'],
        'blocking' => ['fa' => 'بازدارنده', 'en' => 'Blocking'],
        'closure'  => ['fa' => 'درصد بسته‌شده', 'en' => 'Closure'],
        'blocked'  => ['fa' => 'تا پاسخ‌دادن به نظرهای بازدارنده، انتشار این مدرک ممکن نیست.',
                       'en' => 'This document cannot be published until the blocking comments are answered.'],
        'clear'    => ['fa' => 'هیچ نظر بازدارنده‌ای باز نیست؛ مدرک قابل انتشار است.',
                       'en' => 'No blocking comments are open; this document can be published.'],
        'notshared'=> ['fa' => 'تنها مدارکِ «ارسال برای بررسی» قابل اظهار نظر هستند.',
                       'en' => 'Only documents in the Shared state are open for review.'],
        'ownersay' => ['fa' => 'شما تهیه‌کنندهٔ این مدرک هستید؛ می‌توانید به نظرها پاسخ دهید.',
                       'en' => 'You are the originator of this document; you may respond to comments.'],
        'refhint'  => ['fa' => 'مثلاً شمارهٔ شیت یا تگ', 'en' => 'e.g. sheet number or tag'],
        'pendinglabel' => ['fa' => 'در انتظار پاسخ', 'en' => 'Pending'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $document->number }}</title>
@include('partials.shell')
@include('partials.tabletools')
<style>
  .docstats { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
  .dstat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:110px; }
  .dstat .k { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .dstat .v { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:2px; }
  .dstat.warn .v { color:#fca5a5; }
  .dstat.good .v { color:#6ee7b7; }
  .cat { display:inline-block; font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .cat.HOLD  { background:rgba(239,68,68,.18);  color:#fca5a5; border:1px solid rgba(239,68,68,.45); }
  .cat.CODE1 { background:rgba(239,68,68,.13);  color:#fca5a5; border:1px solid rgba(239,68,68,.32); }
  .cat.CODE2 { background:rgba(245,158,11,.16); color:#fcd34d; border:1px solid rgba(245,158,11,.38); }
  .cat.CODE3 { background:rgba(56,132,255,.16); color:#93c5fd; border:1px solid rgba(56,132,255,.35); }
  .cat.CODE4 { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .cat.INFO  { background:rgba(148,163,184,.16);color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .dsp { display:inline-block; font-size:11px; font-weight:600; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .dsp.PENDING  { background:rgba(148,163,184,.14); color:#94a3b8; border:1px solid rgba(148,163,184,.3); }
  .dsp.ACCEPTED { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .dsp.REJECTED { background:rgba(239,68,68,.14);  color:#fca5a5; border:1px solid rgba(239,68,68,.35); }
  .dsp.NOTED    { background:rgba(56,132,255,.14); color:#93c5fd; border:1px solid rgba(56,132,255,.32); }
  .cbody { max-width:420px; line-height:1.55; }
  .cnote { margin-top:6px; font-size:12px; color:var(--muted); border-inline-start:2px solid var(--line); padding-inline-start:8px; }
  .banner { border-radius:10px; padding:11px 15px; font-size:13px; margin-bottom:16px; line-height:1.6; }
  .banner.bad  { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.38); color:#fca5a5; }
  .banner.good { background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.35); color:#6ee7b7; }
  .banner.note { background:rgba(148,163,184,.09); border:1px solid var(--line); color:var(--muted); }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select, .frow textarea { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .frow textarea { min-height:74px; resize:vertical; line-height:1.6; }
  .dispose-form { display:flex; flex-direction:column; gap:5px; min-width:172px; }
  .dispose-form select, .dispose-form input { padding:5px 8px; font-size:12px; border-radius:6px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; width:100%; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .backlink { display:inline-block; font-size:12.5px; color:var(--muted); text-decoration:none; margin-bottom:12px; }
  .backlink:hover { color:#e2e8f0; }
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <a class="backlink" href="/projects/{{ $project->code }}/documents?lang={{ $lang }}">&larr; {{ $L('back') }}</a>

  <h1>{{ $L('title') }}</h1>
  <div class="sub">
    <span class="mono">{{ $document->number }}</span> · {{ $document->title($lang) }}
    @if($revision)
      · {{ $fa ? 'بازنگری' : 'Rev' }} <span class="mono">{{ $revision->revision }}</span>
      · <span class="pill pack">{{ $revision->stateLabel($lang) }}</span>
    @endif
    · {{ $jalaliDate }}
  </div>

  @if(session('status'))
    <div class="ok">{{ session('status') }}</div>
  @endif
  @if($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  @if($stats)
    <div class="docstats">
      <div class="dstat">
        <div class="k">{{ $L('total') }}</div>
        <div class="v">{{ $num($stats['total']) }}</div>
      </div>
      <div class="dstat {{ $stats['pending'] > 0 ? 'warn' : 'good' }}">
        <div class="k">{{ $L('pending') }}</div>
        <div class="v">{{ $num($stats['pending']) }}</div>
      </div>
      <div class="dstat {{ $stats['blocking'] > 0 ? 'warn' : 'good' }}">
        <div class="k">{{ $L('blocking') }}</div>
        <div class="v">{{ $num($stats['blocking']) }}</div>
      </div>
      <div class="dstat {{ $stats['closure'] === 100 ? 'good' : '' }}">
        <div class="k">{{ $L('closure') }}</div>
        <div class="v">{{ $num($stats['closure']) }}٪</div>
      </div>
    </div>

    {{-- The consequence, stated plainly. The number of blocking comments is
         only meaningful if the user knows what it stops them doing. --}}
    @if($stats['blocking'] > 0)
      <div class="banner bad">{{ $L('blocked') }}</div>
    @elseif($stats['total'] > 0)
      <div class="banner good">{{ $L('clear') }}</div>
    @endif
  @endif

  @if($canComment)
    <fieldset>
      <legend>{{ $L('add') }}</legend>
      <form method="post" action="/projects/{{ $project->code }}/documents/{{ $document->id }}/review?lang={{ $lang }}">
        @csrf
        <div class="frow">
          <div>
            <label for="category">{{ $L('cat') }}</label>
            <select id="category" name="category" required>
              @foreach($categories as $c)
                <option value="{{ $c }}">{{ \App\Support\CommentCategory::label($c, $lang) }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="reference">{{ $L('ref') }}</label>
            <input id="reference" name="reference" maxlength="128" placeholder="{{ $L('refhint') }}">
          </div>
        </div>
        <div class="frow" style="grid-template-columns:1fr;">
          <div>
            <label for="body">{{ $L('body') }}</label>
            <textarea id="body" name="body" required maxlength="4000"></textarea>
          </div>
        </div>
        <button type="submit" class="btn">{{ $L('save') }}</button>
      </form>
    </fieldset>
  @elseif($isOriginator)
    <div class="banner note">{{ $L('ownersay') }}</div>
  @elseif($revision && $revision->cde_state !== \App\Support\CdeState::SHARED)
    <div class="banner note">{{ $L('notshared') }}</div>
  @endif

  @if($comments->isEmpty())
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;">
      {{ $L('empty') }}
    </div>
  @else
    {{-- data-tt opts this table into filter/sort (partials/tabletools). --}}
    <table data-tt>
      <thead>
        <tr>
          <th data-sort="num">{{ $L('seq') }}</th>
          <th data-sort="text" data-facet>{{ $L('cat') }}</th>
          <th data-sort="text">{{ $L('ref') }}</th>
          <th data-sort="text">{{ $L('body') }}</th>
          <th data-sort="text" data-facet>{{ $L('by') }}</th>
          <th data-sort="text" data-facet>{{ $L('disp') }}</th>
          @if($canDispose)<th>{{ $L('act') }}</th>@endif
        </tr>
      </thead>
      <tbody>
      @foreach($comments as $c)
        <tr>
          <td class="mono">{{ $num($c->seq) }}</td>
          {{-- Sort by severity, not by the translated label: HOLD first is the
               order a reviewer reads the sheet in. --}}
          <td data-sortkey="{{ array_search($c->category, $categories, true) }}"
              data-facet-value="{{ $c->categoryCode($lang) }}">
            <span class="cat {{ $c->category }}" title="{{ $c->categoryLabel($lang) }}">{{ $c->categoryCode($lang) }}</span>
          </td>
          <td class="mono">{{ $c->reference ?: '—' }}</td>
          <td class="cbody">
            {{ $c->body }}
            @if($c->disposition_note)
              <div class="cnote"><strong>{{ $L('note') }}:</strong> {{ $c->disposition_note }}</div>
            @endif
          </td>
          <td><span class="pill pack">{{ $c->reviewer->code }}</span></td>
          <td data-facet-value="{{ $c->dispositionLabel($lang) }}">
            <span class="dsp {{ $c->disposition }}">{{ $c->dispositionLabel($lang) }}</span>
          </td>
          @if($canDispose)
            <td>
              @if($c->isPending())
                {{-- Only the originator sees this column at all, and the
                     service re-checks on POST regardless. --}}
                <form class="dispose-form" method="post"
                      action="/projects/{{ $project->code }}/documents/{{ $document->id }}/review/{{ $c->id }}?lang={{ $lang }}">
                  @csrf
                  <select name="disposition" aria-label="{{ $L('disp') }}">
                    @foreach($dispositions as $d)
                      <option value="{{ $d }}">{{ \App\Support\CommentDisposition::label($d, $lang) }}</option>
                    @endforeach
                  </select>
                  <input name="disposition_note" maxlength="4000" placeholder="{{ $L('note') }}"
                         aria-label="{{ $L('note') }}">
                  <button type="submit" class="btn">{{ $L('apply') }}</button>
                </form>
              @else
                <span style="font-size:12px;color:var(--muted);">—</span>
              @endif
            </td>
          @endif
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <div class="foot">
    {{ $fa
      ? 'دسته‌های توقف، کد ۱ و کد ۲ تا زمانی که پاسخ داده نشوند مانع انتشار مدرک می‌شوند. رد کردن یک نظر بدون ذکر دلیل ممکن نیست.'
      : 'Hold, Code 1 and Code 2 comments block publication until they are answered. A comment cannot be rejected without a written justification.' }}
  </div>
</div>
</div>
</body>
</html>
