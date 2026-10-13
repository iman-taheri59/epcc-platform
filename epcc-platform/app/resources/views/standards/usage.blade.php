@php
    $fa = $lang === 'fa';
    $t = [
        'title'  => ['fa' => 'ارجاع‌ها به این استاندارد', 'en' => 'Where this standard is cited'],
        'sub'    => ['fa' => 'این همان فهرستی است که روز تغییر ویرایش لازم می‌شود: هر ردیف بازرسی که به این کد استناد کرده و باید بازبینی شود.',
                     'en' => 'This is the list needed on the day the edition changes: every inspection row that leans on this code and has to be re-checked.'],
        'back'   => ['fa' => 'بازگشت به استانداردها', 'en' => 'Back to standards'],
        'ed'     => ['fa' => 'ویرایش در این پروژه', 'en' => 'Edition on this project'],
        'cat_ed' => ['fa' => 'ویرایش کتابخانه', 'en' => 'Library edition'],
        'itp'    => ['fa' => 'برنامهٔ بازرسی', 'en' => 'Inspection plan'],
        'disc'   => ['fa' => 'دیسیپلین', 'en' => 'Discipline'],
        'clause' => ['fa' => 'بند ردیف', 'en' => 'Row'],
        'act'    => ['fa' => 'فعالیت', 'en' => 'Activity'],
        'cited'  => ['fa' => 'بند استاندارد', 'en' => 'Clause cited'],
        'state'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'none'   => ['fa' => 'هیچ ردیفی در این پروژه به این استاندارد ارجاع نداده است.',
                     'en' => 'Nothing in this project cites this standard yet.'],
        'notadopted' => ['fa' => 'این استاندارد در فهرست این پروژه نیست.',
                         'en' => 'This standard is not on this project’s list.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];

    $stateLabel = fn (string $s): string => match ($s) {
        'ACTIVE' => $fa ? 'لازم‌الاجرا' : 'In force',
        'DRAFT' => $fa ? 'پیش‌نویس' : 'Draft',
        default => $fa ? 'جایگزین‌شده' : 'Superseded',
    };
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $standard->code() }}</title>
@include('partials.shell')
@include('partials.tabletools')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1><bdi class="mono">{{ $standard->code() }}</bdi></h1>
  <div class="sub">{{ $fa ? ($standard->title_fa ?: $standard->title_en) : $standard->title_en }}</div>

  <div style="margin-top:10px;display:flex;gap:22px;flex-wrap:wrap;font-size:12.5px;">
    <span>
      <span style="color:var(--muted);">{{ $L('ed') }}:</span>
      <bdi class="mono">{{ $num($adoption?->effectiveEdition() ?? '—') }}</bdi>
    </span>
    <span>
      <span style="color:var(--muted);">{{ $L('cat_ed') }}:</span>
      <bdi class="mono">{{ $num($standard->edition ?? '—') }}</bdi>
    </span>
    @unless($adoption)
      <span style="color:#fbbf24;">{{ $L('notadopted') }}</span>
    @endunless
  </div>

  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:10px 0 0;max-width:860px;">{{ $L('sub') }}</p>

  <p style="margin:10px 0 0;">
    <a href="/projects/{{ $project->code }}/standards?{{ http_build_query($q) }}"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; {{ $L('back') }}</a>
  </p>

  @if($rows->isEmpty())
    <p style="color:var(--muted);font-size:13px;margin-top:18px;">{{ $L('none') }}</p>
  @else
    <div style="overflow-x:auto;">
    <table data-tt style="margin-top:16px;min-width:880px;">
      <thead>
        <tr>
          <th style="white-space:nowrap;">{{ $L('disc') }}</th>
          <th>{{ $L('itp') }}</th>
          <th style="white-space:nowrap;">{{ $L('clause') }}</th>
          <th>{{ $L('act') }}</th>
          <th style="white-space:nowrap;">{{ $L('cited') }}</th>
          <th style="white-space:nowrap;">{{ $L('state') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $r)
          <tr>
            <td style="white-space:nowrap;font-size:12px;">{{ $r->discipline }}</td>
            <td style="font-size:12.5px;">
              <a href="/projects/{{ $project->code }}/itp/{{ $r->itp_id }}?{{ http_build_query($q) }}"
                 style="color:#93c5fd;text-decoration:none;">{{ $fa ? $r->itp_title_fa : $r->itp_title_en }}</a>
            </td>
            <td style="white-space:nowrap;"><bdi class="mono">{{ $num($r->stage_clause) }}</bdi></td>
            <td style="font-size:12.5px;">{{ $fa ? $r->title_fa : $r->title_en }}</td>
            <td style="white-space:nowrap;font-size:12px;"><bdi class="mono">{{ $r->cited_clause ?? '—' }}</bdi></td>
            <td style="white-space:nowrap;font-size:11.5px;color:var(--muted);">{{ $stateLabel($r->state) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif

</div>
</div>
</body>
</html>
