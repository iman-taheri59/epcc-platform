@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'کدها و استانداردها', 'en' => 'Codes & standards'],
        'sub'     => ['fa' => 'کتابخانهٔ استانداردها یک بار نگهداری می‌شود و همهٔ پروژه‌ها از آن استفاده می‌کنند. هر پروژه مشخص می‌کند به کدام استانداردها و کدام ویرایش متعهد است — ویرایشِ پروژه با به‌روزرسانی کتابخانه تغییر نمی‌کند.',
                       'en' => 'The library is maintained once and shared by every project. Each project records which codes it works to and which edition it is bound to — updating the library never moves a project to a new edition.'],
        'applies' => ['fa' => 'قابل اجرا در این پروژه', 'en' => 'Applicable to this project'],
        'cat'     => ['fa' => 'کتابخانه', 'en' => 'Library'],
        'code'    => ['fa' => 'کد', 'en' => 'Code'],
        'ttl'     => ['fa' => 'عنوان', 'en' => 'Title'],
        'ed'      => ['fa' => 'ویرایش', 'en' => 'Edition'],
        'cat_col' => ['fa' => 'دسته', 'en' => 'Category'],
        'cited'   => ['fa' => 'ارجاع‌ها', 'en' => 'Cited'],
        'adopt'   => ['fa' => 'افزودن به پروژه', 'en' => 'Add to project'],
        'add'     => ['fa' => 'افزودن', 'en' => 'Add'],
        'none'    => ['fa' => 'هنوز استانداردی برای این پروژه ثبت نشده است. با ساخته‌شدن ITP، استانداردهای ردیف‌ها به‌صورت خودکار اینجا ثبت می‌شوند.',
                      'en' => 'No standards recorded for this project yet. They are added automatically as ITP rows cite them.'],
        'all'     => ['fa' => 'همه', 'en' => 'All'],
        'pinned'  => ['fa' => 'ویرایش تثبیت‌شده توسط پروژه', 'en' => 'edition pinned by the project'],
        'unused'  => ['fa' => 'بدون ارجاع', 'en' => 'not cited'],
        'inlib'   => ['fa' => 'در فهرست پروژه', 'en' => 'on the project list'],
        'view'    => ['fa' => 'کجا ارجاع شده', 'en' => 'Where cited'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];

    $adoptedIds = $adopted->pluck('standard_id')->flip();
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }}</title>
@include('partials.shell')
@include('partials.tabletools')
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;max-width:860px;">{{ $L('sub') }}</p>

  @if($errors->any())
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  {{-- ---------------------------------------------------------------- --}}
  {{-- What this project works to. The contractual list, so it goes first. --}}
  <h2 style="margin-top:26px;font-size:16px;">{{ $L('applies') }}</h2>

  @if($adopted->isEmpty())
    <p style="color:var(--muted);font-size:13px;margin-top:8px;">{{ $L('none') }}</p>
  @else
    <div style="overflow-x:auto;">
    <table data-tt style="margin-top:12px;min-width:820px;">
      <thead>
        <tr>
          <th style="white-space:nowrap;">{{ $L('code') }}</th>
          <th>{{ $L('ttl') }}</th>
          <th style="white-space:nowrap;">{{ $L('ed') }}</th>
          <th style="white-space:nowrap;">{{ $L('cat_col') }}</th>
          <th style="white-space:nowrap;">{{ $L('cited') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($adopted as $ps)
          @php $s = $ps->standard; $n = (int) ($usage[$ps->standard_id] ?? 0); @endphp
          <tr>
            <td style="white-space:nowrap;">
              <a href="/projects/{{ $project->code }}/standards/{{ $ps->standard_id }}?{{ http_build_query($q) }}"
                 style="color:#93c5fd;text-decoration:none;"><bdi class="mono">{{ $s?->code() }}</bdi></a>
            </td>
            <td style="font-size:12.5px;">{{ $fa ? ($s?->title_fa ?: $s?->title_en) : $s?->title_en }}</td>
            <td style="white-space:nowrap;font-size:12px;">
              <bdi class="mono">{{ $num($ps->effectiveEdition() ?? '—') }}</bdi>
              {{-- A project that has frozen an older edition is making a
                   contractual statement, not forgetting to update. Say so. --}}
              @if($ps->isPinnedBehindCatalogue())
                <div style="color:#fbbf24;font-size:10px;">{{ $L('pinned') }}</div>
              @endif
            </td>
            <td style="white-space:nowrap;font-size:11.5px;color:var(--muted);">{{ $s?->category }}</td>
            <td style="white-space:nowrap;font-size:12px;">
              @if($n > 0)
                <bdi class="mono">{{ $num($n) }}</bdi>
              @else
                <span style="color:var(--muted);">{{ $L('unused') }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif

  {{-- ---------------------------------------------------------------- --}}
  {{-- The catalogue to draw from. --}}
  <h2 style="margin-top:30px;font-size:16px;">{{ $L('cat') }}</h2>

  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;font-size:12px;">
    <a href="/projects/{{ $project->code }}/standards?{{ http_build_query($q) }}"
       style="padding:3px 10px;border-radius:999px;text-decoration:none;border:1px solid var(--line,#1f2937);
              color:{{ $category === null ? '#0b1220' : 'var(--muted)' }};
              background:{{ $category === null ? '#93c5fd' : 'transparent' }};">{{ $L('all') }}</a>
    @foreach($categories as $c)
      <a href="/projects/{{ $project->code }}/standards?{{ http_build_query($q + ['category' => $c]) }}"
         style="padding:3px 10px;border-radius:999px;text-decoration:none;border:1px solid var(--line,#1f2937);
                color:{{ $category === $c ? '#0b1220' : 'var(--muted)' }};
                background:{{ $category === $c ? '#93c5fd' : 'transparent' }};">{{ $c }}</a>
    @endforeach
  </div>

  <div style="overflow-x:auto;">
  <table data-tt style="margin-top:12px;min-width:820px;">
    <thead>
      <tr>
        <th style="white-space:nowrap;">{{ $L('code') }}</th>
        <th>{{ $L('ttl') }}</th>
        <th style="white-space:nowrap;">{{ $L('ed') }}</th>
        <th style="white-space:nowrap;">{{ $L('cat_col') }}</th>
        <th style="white-space:nowrap;">{{ $L('adopt') }}</th>
      </tr>
    </thead>
    <tbody>
      @foreach($catalogue as $s)
        <tr>
          <td style="white-space:nowrap;"><bdi class="mono">{{ $s->code() }}</bdi></td>
          <td style="font-size:12.5px;">{{ $fa ? ($s->title_fa ?: $s->title_en) : $s->title_en }}</td>
          <td style="white-space:nowrap;font-size:12px;"><bdi class="mono">{{ $num($s->edition ?? '—') }}</bdi></td>
          <td style="white-space:nowrap;font-size:11.5px;color:var(--muted);">{{ $s->category }}</td>
          <td style="white-space:nowrap;">
            @if($adoptedIds->has($s->id))
              <span style="font-size:11.5px;color:#4ade80;">{{ $L('inlib') }}</span>
            @else
              <form method="post" action="/projects/{{ $project->code }}/standards/adopt?{{ http_build_query($q) }}"
                    style="margin:0;display:flex;gap:5px;align-items:center;">
                @csrf
                <input type="hidden" name="standard_id" value="{{ $s->id }}">
                {{-- The edition box defaults to the catalogue's but stays
                     editable: pinning an older edition is the normal case on
                     a project whose contract predates the current issue. --}}
                <input type="text" name="edition" value="{{ $s->edition }}" size="6"
                       style="font-size:11.5px;padding:2px 5px;width:66px;">
                <button type="submit" style="font-size:11.5px;padding:3px 9px;cursor:pointer;">{{ $L('add') }}</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>

</div>
</div>
</body>
</html>
