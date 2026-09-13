@php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'تیم پروژه', 'en' => 'Project team'],
        'parts'   => ['fa' => 'شرکت‌های مشارکت‌کننده', 'en' => 'Participating companies'],
        'partsh'  => ['fa' => 'دیدن نام یک شرکت به معنی دسترسی به داده‌های آن نیست.', 'en' => 'Seeing a company listed is not the same as seeing its data.'],
        'people'  => ['fa' => 'افراد', 'en' => 'People'],
        'add'     => ['fa' => 'افزودن همکار از شرکت شما', 'en' => 'Add a colleague from your company'],
        'addh'    => ['fa' => 'شما فقط می‌توانید افراد شرکت خودتان را اضافه کنید.', 'en' => 'You may only add people from your own company.'],
        'person'  => ['fa' => 'فرد', 'en' => 'Person'],
        'roles'   => ['fa' => 'نقش‌ها', 'en' => 'Roles'],
        'limit'   => ['fa' => 'حد اختیارات (ریال)', 'en' => 'Authority limit (IRR)'],
        'limith'  => ['fa' => 'خالی = پیش‌فرض شرکت', 'en' => 'blank = company default'],
        'submit'  => ['fa' => 'افزودن', 'en' => 'Add'],
        'back'    => ['fa' => 'بازگشت به پروژه', 'en' => 'Back to project'],
        'company' => ['fa' => 'شرکت', 'en' => 'Company'],
        'name'    => ['fa' => 'نام', 'en' => 'Name'],
        'scope'   => ['fa' => 'دامنهٔ رشته', 'en' => 'Discipline scope'],
        'all'     => ['fa' => 'همهٔ رشته‌ها', 'en' => 'All disciplines'],
        'default' => ['fa' => 'پیش‌فرض', 'en' => 'default'],
        'nobody'  => ['fa' => 'همهٔ همکاران شما از قبل عضو این پروژه هستند.', 'en' => 'All your colleagues are already on this project.'],
        'ro'      => ['fa' => 'شما اجازهٔ مدیریت تیم این پروژه را ندارید — این فهرست فقط خواندنی است.', 'en' => 'You cannot manage this project\'s team — this list is read-only.'],
        'user'    => ['fa' => 'کاربر', 'en' => 'Signed in as'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits(number_format((float) $n), $lang);
    $contractRole = [
        'EMPLOYER' => ['fa' => 'کارفرما', 'en' => 'Employer'],
        'CONTRACTOR' => ['fa' => 'پیمانکار', 'en' => 'Contractor'],
        'CONSULTANT' => ['fa' => 'مشاور / ناظر', 'en' => 'Consultant / PMC'],
        'MC' => ['fa' => 'مدیریت پیمان', 'en' => 'Management contractor'],
        'TPI' => ['fa' => 'بازرسی شخص ثالث', 'en' => 'Third Party Inspection'],
        'VENDOR' => ['fa' => 'فروشنده', 'en' => 'Vendor'],
        'SUB' => ['fa' => 'پیمانکار جزء', 'en' => 'Subcontractor'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $project->name($lang) }}</title>
@include('partials.shell')
@include('partials.tabletools')
<style>
  fieldset { border:1px solid var(--line); border-radius:12px; padding:18px; margin:0 0 18px; background:var(--card); }
  legend { font-size:14px; font-weight:700; padding:0 8px; }
  .grid2 { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; align-items:end; }
  .field label { display:block; font-size:12.5px; color:var(--muted); margin-bottom:6px; }
  .field input, .field select { width:100%; background:#0b1220; color:#e2e8f0; border:1px solid var(--line);
    border-radius:9px; padding:10px 12px; font-size:13.5px; font-family:inherit; }
  .field .hint { font-size:11px; color:var(--muted); margin-top:4px; }
  .chk { display:flex; flex-wrap:wrap; gap:8px; }
  .chk label { display:flex; align-items:center; gap:6px; background:#0b1220; border:1px solid var(--line);
    border-radius:8px; padding:7px 11px; font-size:12.5px; cursor:pointer; }
  .chk label:hover { border-color:var(--accent); }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:9px; padding:10px 22px;
    font-size:14px; font-weight:600; font-family:inherit; cursor:pointer; }
  .btn:hover { background:#7c3aed; }
  .rolepill { display:inline-block; background:rgba(139,92,246,.14); color:#c4b5fd; border:1px solid rgba(139,92,246,.3);
    border-radius:999px; font-size:11px; padding:2px 9px; margin-{{ $fa ? 'left' : 'right' }}:4px; }
  .mine { background:rgba(16,185,129,.1); }
  .ro { font-size:12.5px; color:var(--muted); background:rgba(148,163,184,.1);
    border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:11px 14px; margin-bottom:16px; }
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
    · <span class="pill pack">{{ $project->standards_pack }}</span>
  </div>

  <fieldset>
    <legend>{{ $L('parts') }}</legend>
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">{{ $L('partsh') }}</div>
    {{-- data-tt opts this table into filter/sort (partials/tabletools). --}}
    <table data-tt>
      <thead><tr>
        <th data-sort="text">{{ $L('company') }}</th>
        <th data-sort="text" data-facet>{{ $L('roles') }}</th>
      </tr></thead>
      <tbody>
        @foreach($participants as $p)
          <tr class="{{ $p->id === $acting->id ? 'mine' : '' }}">
            <td>{{ $fa ? $p->name_fa : $p->name_en }} <span class="mono" style="color:var(--muted);">{{ $p->code }}</span></td>
            <td><span class="pill pack">{{ $contractRole[$p->role][$lang] ?? $p->role }}</span></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </fieldset>

  <fieldset>
    <legend>{{ $L('people') }}</legend>
    <table>
      <thead><tr><th>{{ $L('name') }}</th><th>{{ $L('company') }}</th><th>{{ $L('roles') }}</th><th>{{ $L('scope') }}</th><th>{{ $L('limit') }}</th></tr></thead>
      <tbody>
        @foreach($members as $m)
          <tr class="{{ $m->user->company_id === $acting->id ? 'mine' : '' }}">
            <td>{{ $m->user->name }}</td>
            <td><span class="mono" style="color:var(--muted);">{{ $m->user->company->code }}</span></td>
            <td>@foreach($m->role_codes ?? [] as $rc)<span class="rolepill">{{ optional($roles->firstWhere('code', $rc))->name($lang) ?? $rc }}</span>@endforeach</td>
            <td style="font-size:12.5px;color:var(--muted);">
              {{ $m->discipline_scope ? implode('، ', $m->discipline_scope) : $L('all') }}
            </td>
            <td class="amt" style="font-size:13px;">
              {{ $m->authority_limit !== null ? $num($m->authority_limit) : $L('default') }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </fieldset>

  @if($canManage)
    <fieldset>
      <legend>{{ $L('add') }}</legend>
      <div style="font-size:12px;color:var(--muted);margin-bottom:14px;">{{ $L('addh') }}</div>

      @if($candidates->isEmpty())
        <div style="font-size:13px;color:var(--muted);">{{ $L('nobody') }}</div>
      @else
        <form method="post" action="/projects/{{ $project->code }}/team?lang={{ $lang }}">
          @csrf
          <div class="grid2">
            <div class="field">
              <label for="user_id">{{ $L('person') }}</label>
              <select id="user_id" name="user_id" required>
                @foreach($candidates as $c)
                  <option value="{{ $c->id }}">{{ $c->name }} — {{ $c->email }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="authority_limit">{{ $L('limit') }}</label>
              <input id="authority_limit" name="authority_limit" type="number" min="0" step="1" placeholder="—">
              <div class="hint">{{ $L('limith') }}</div>
            </div>
          </div>

          <div class="field" style="margin-top:14px;">
            <label>{{ $L('roles') }}</label>
            <div class="chk">
              @foreach($roles as $r)
                <label><input type="checkbox" name="role_codes[]" value="{{ $r->code }}"> {{ $r->name($lang) }}</label>
              @endforeach
            </div>
          </div>

          <button class="btn" type="submit" style="margin-top:16px;">{{ $L('submit') }}</button>
        </form>
      @endif
    </fieldset>
  @else
    <div class="ro">{{ $L('ro') }}</div>
  @endif
</div>
</div>
</body>
</html>
