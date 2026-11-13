@php
    $fa = $lang === 'fa';
    $t = [
        'title' => ['fa' => 'جنس پد در پیش‌نویس PMS', 'en' => 'PMS PAD materials'],
        'back' => ['fa' => 'بازگشت به دفتر مدارک', 'en' => 'Back to document register'],
        'intro' => ['fa' => 'این صفحه فقط نسخهٔ کاریِ بازنگری جاری را ویرایش می‌کند؛ کلاس منتشرشده و snapshotهای قبلی دست‌نخورده می‌مانند.', 'en' => 'This page edits only the current revision working copy; the published class and earlier snapshots stay unchanged.'],
        'doc' => ['fa' => 'مدرک', 'en' => 'Document'],
        'revision' => ['fa' => 'بازنگری', 'en' => 'Revision'],
        'state' => ['fa' => 'وضعیت', 'en' => 'State'],
        'class' => ['fa' => 'کلاس PMS', 'en' => 'PMS class'],
        'branch' => ['fa' => 'قاعدهٔ انشعاب', 'en' => 'Branch rule'],
        'header_range' => ['fa' => 'بازهٔ هدر (DN)', 'en' => 'Header range (DN)'],
        'branch_range' => ['fa' => 'بازهٔ انشعاب (DN)', 'en' => 'Branch range (DN)'],
        'thickness' => ['fa' => 'ضخامت اسمی پد (mm)', 'en' => 'Nominal PAD thickness (mm)'],
        'effective' => ['fa' => 'جنس مؤثر پد', 'en' => 'Effective PAD material'],
        'explicit' => ['fa' => 'جنس جداگانه در PMS', 'en' => 'Separate PMS material'],
        'inherited' => ['fa' => 'ارث‌بری از جنس PIPE_MATERIAL هدر', 'en' => 'Inherited from header PIPE_MATERIAL'],
        'header_default' => ['fa' => 'از جنس لولهٔ هدر استفاده شود (پیش‌فرض)', 'en' => 'Use header pipe material (default)'],
        'custom' => ['fa' => 'جنس جداگانه برای PAD', 'en' => 'Specify a separate PAD material'],
        'spec' => ['fa' => 'استاندارد / مشخصه', 'en' => 'Specification'],
        'grade' => ['fa' => 'گرید', 'en' => 'Grade'],
        'psl' => ['fa' => 'PSL (در صورت کاربرد)', 'en' => 'PSL (if applicable)'],
        'stress' => ['fa' => 'تنش مجاز دستی S (MPa)', 'en' => 'Manual allowable stress S (MPa)'],
        'pno' => ['fa' => 'شمارهٔ P (Section IX)', 'en' => 'P-No (Section IX)'],
        'group' => ['fa' => 'شمارهٔ گروه (Section IX)', 'en' => 'Group No (Section IX)'],
        'manual_help' => ['fa' => 'برای جنس جدول A-1، S را خالی بگذارید. ورود S دستی، شمارهٔ P و شمارهٔ گروه را الزامی می‌کند.', 'en' => 'Leave S blank for a Table A-1 material. A manual S value requires both Section IX P-No and Group No.'],
        'save' => ['fa' => 'ذخیره در پیش‌نویس', 'en' => 'Save to WIP draft'],
        'locked' => ['fa' => 'این بازنگری در وضعیت WIP نیست و فقط‌خواندنی است.', 'en' => 'This revision is not WIP and is read-only.'],
        'no_authority' => ['fa' => 'ویرایش فقط برای نویسندهٔ نامزدشدهٔ PMS در شرکت مبدأ و دارای snapshot تأییدشدهٔ قبلی مجاز است.', 'en' => 'Editing requires the nominated PMS author from the originating company and a prior approved snapshot.'],
        'empty' => ['fa' => 'در نسخهٔ کاری جاری، قاعدهٔ PAD قابل ویرایشی یافت نشد. پس از تأیید یک PMS و صدور بازنگری بعدی، نسخهٔ کاری اینجا ساخته می‌شود.', 'en' => 'No editable PAD rule was found in the current working copy. The next working copy is created after an approved PMS revision is superseded.'],
        'no_header_material' => ['fa' => 'قاعدهٔ PIPE_MATERIAL برای این بازه پیدا نشد؛ بازنگری را تا رفع نقص منتشر نکنید.', 'en' => 'No PIPE_MATERIAL rule covers this range; do not issue this revision until it is corrected.'],
        'range' => ['fa' => 'بازه', 'en' => 'Range'],
        'fallback_note' => ['fa' => 'جنس PAD برای هر DN از قاعدهٔ PIPE_MATERIAL همان DN در هدر گرفته می‌شود.', 'en' => 'For each DN, the PAD inherits the header PIPE_MATERIAL rule covering that DN.'],
    ];
    $L = fn (string $key) => $t[$key][$lang];
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }} — {{ $project->code }}</title>
@include('partials.shell')
<style>
  .pms-wrap { max-width:1040px; margin:0 auto; }
  .pms-intro { color:var(--muted); font-size:13px; margin:6px 0 18px; }
  .pms-meta,.pad-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:14px; }
  .pms-meta { display:flex; flex-wrap:wrap; gap:18px; align-items:center; }
  .pms-meta strong,.pad-card strong { color:#f1f5f9; }
  .pms-meta .k,.pad-card .k { color:var(--muted); font-size:11px; display:block; }
  .pms-back { display:inline-block; margin-bottom:10px; text-decoration:none; font-size:13px; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7; border-radius:9px; padding:10px 14px; font-size:13px; margin:12px 0; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5; border-radius:9px; padding:10px 14px; font-size:13px; margin:12px 0; }
  .err ul { margin:0; padding-inline-start:20px; }
  .pad-card h2 { margin:0; font-size:16px; }
  .pad-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; padding-bottom:12px; border-bottom:1px solid var(--line); }
  .tag { display:inline-block; padding:3px 9px; border:1px solid rgba(139,92,246,.4); border-radius:999px; color:#c4b5fd; background:rgba(139,92,246,.12); font:11px ui-monospace,monospace; direction:ltr; }
  .facts { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; margin:14px 0; }
  .fact { border:1px solid rgba(148,163,184,.15); border-radius:8px; padding:8px 10px; min-width:0; }
  .fact .v { font-size:13px; font-weight:650; overflow-wrap:anywhere; }
  .mono { font:12px ui-monospace,monospace; direction:ltr; display:inline-block; }
  .material { margin:12px 0; padding:10px 12px; border-radius:9px; background:rgba(56,132,255,.08); border:1px solid rgba(56,132,255,.25); font-size:13px; }
  .material ul { margin:5px 0 0; padding-inline-start:20px; }
  .hint { color:var(--muted); font-size:11.5px; margin:7px 0 0; }
  .edit-form { border-top:1px solid var(--line); padding-top:14px; margin-top:14px; }
  .edit-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin:12px 0; }
  .edit-grid label,.mode-label { display:block; color:var(--muted); font-size:11.5px; margin-bottom:4px; }
  .edit-grid input,.mode-select { width:100%; min-width:0; padding:8px 9px; border-radius:7px; border:1px solid var(--line); background:#0b1220; color:#e2e8f0; font:13px inherit; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:8px 13px; font:inherit; font-size:12px; font-weight:650; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .locked-note { color:#fcd34d; font-size:12px; margin:12px 0 0; }
  .empty { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:24px; color:var(--muted); }
  @media(max-width:600px){ .app > .wrap { padding:16px; } .pms-meta { gap:10px; } }
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')
<div class="pms-wrap">
  <a class="pms-back" href="{{ route('documents', ['projectCode' => $project->code, 'lang' => $lang]) }}">← {{ $L('back') }}</a>
  <h1>{{ $L('title') }}</h1>
  <p class="pms-intro">{{ $L('intro') }}</p>

  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="err"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

  <div class="pms-meta">
    <div><span class="k">{{ $L('doc') }}</span><strong class="mono">{{ $document->number }}</strong> · {{ $document->title($lang) }}</div>
    <div><span class="k">{{ $L('revision') }}</span><strong class="mono">{{ $revision?->revision ?? '—' }}</strong></div>
    <div><span class="k">{{ $L('state') }}</span><strong>{{ $revision ? \App\Support\CdeState::label($revision->cde_state, $lang) : '—' }}</strong></div>
    <div><span class="k">{{ $project->name($lang) }}</span><strong class="mono">{{ $project->code }}</strong></div>
  </div>

  @if(count($pads) === 0)
    <div class="empty">{{ $L('empty') }}</div>
  @else
    @foreach($pads as $pad)
      @php
        $id = $pad['branch_rule_id'];
        $explicit = is_array($pad['pad_material']) ? $pad['pad_material'] : [];
        $mode = old('mode.'.$id, $explicit !== [] ? 'CUSTOM' : 'HEADER');
        $inputMaterial = old('pad_material.'.$id, $mode === 'CUSTOM' ? $explicit : []);
        $inputMaterial = is_array($inputMaterial) ? $inputMaterial : [];
        $className = $lang === 'fa' ? ($pad['class_name_fa'] ?: $pad['class_name_en']) : ($pad['class_name_en'] ?: $pad['class_name_fa']);
      @endphp
      <section class="pad-card">
        <div class="pad-head">
          <div>
            <h2>{{ $L('class') }}: <span class="mono">{{ $pad['class_code'] }}</span>@if($className) · {{ $className }}@endif</h2>
            <div class="hint">{{ $L('branch') }} · <span class="mono">{{ $pad['component_type'] }}{{ $pad['p2p_kind'] ? ' / '.$pad['p2p_kind'] : '' }}</span></div>
          </div>
          <span class="tag">PAD · {{ $pad['branch_rule_id'] }}</span>
        </div>

        <div class="facts">
          <div class="fact"><span class="k">{{ $L('header_range') }}</span><div class="v mono">{{ $pad['header_range'] }}</div></div>
          <div class="fact"><span class="k">{{ $L('branch_range') }}</span><div class="v mono">{{ $pad['branch_range'] }}</div></div>
          <div class="fact"><span class="k">{{ $L('thickness') }}</span><div class="v mono">{{ $pad['pad_thickness'] }} mm</div></div>
        </div>

        <div class="material">
          <strong>{{ $L('effective') }}:</strong>
          @if($explicit !== [])
            {{ $L('explicit') }} — <span class="mono">{{ trim(($explicit['spec'] ?? '').' '.($explicit['grade'] ?? '').(empty($explicit['psl']) ? '' : ' '.$explicit['psl'])) }}</span>
            @if(isset($explicit['s_mpa_override'])) <span class="mono"> · S={{ $explicit['s_mpa_override'] }} MPa</span>@endif
          @else
            {{ $L('inherited') }}. {{ $L('fallback_note') }}
            @if(count($pad['header_materials']) > 0)
              <ul>
                @foreach($pad['header_materials'] as $headerMaterial)
                  <li><span class="mono">{{ $headerMaterial['label'] }}</span> · {{ $L('range') }} <span class="mono">{{ $headerMaterial['range'] }}</span></li>
                @endforeach
              </ul>
            @else
              <p class="locked-note">{{ $L('no_header_material') }}</p>
            @endif
          @endif
        </div>

        @if($pad['can_edit'])
          <form class="edit-form" method="post"
                action="{{ route('documents.pms.pad-materials.update', ['projectCode' => $project->code, 'documentId' => $document->id, 'classId' => $pad['class_id'], 'branchRuleId' => $id, 'lang' => $lang]) }}">
            @csrf
            <label class="mode-label" for="mode-{{ $id }}">{{ $L('effective') }}</label>
            <select class="mode-select" id="mode-{{ $id }}" name="mode[{{ $id }}]" required>
              <option value="HEADER" {{ $mode === 'HEADER' ? 'selected' : '' }}>{{ $L('header_default') }}</option>
              <option value="CUSTOM" {{ $mode === 'CUSTOM' ? 'selected' : '' }}>{{ $L('custom') }}</option>
            </select>
            <div class="edit-grid">
              <div><label for="spec-{{ $id }}">{{ $L('spec') }}</label><input id="spec-{{ $id }}" name="pad_material[{{ $id }}][spec]" value="{{ $inputMaterial['spec'] ?? '' }}" maxlength="80" dir="ltr"></div>
              <div><label for="grade-{{ $id }}">{{ $L('grade') }}</label><input id="grade-{{ $id }}" name="pad_material[{{ $id }}][grade]" value="{{ $inputMaterial['grade'] ?? '' }}" maxlength="80" dir="ltr"></div>
              <div><label for="psl-{{ $id }}">{{ $L('psl') }}</label><input id="psl-{{ $id }}" name="pad_material[{{ $id }}][psl]" value="{{ $inputMaterial['psl'] ?? '' }}" maxlength="16" dir="ltr"></div>
              <div><label for="stress-{{ $id }}">{{ $L('stress') }}</label><input id="stress-{{ $id }}" name="pad_material[{{ $id }}][s_mpa_override]" value="{{ $inputMaterial['s_mpa_override'] ?? '' }}" type="number" min="0.000001" step="any" dir="ltr"></div>
              <div><label for="pno-{{ $id }}">{{ $L('pno') }}</label><input id="pno-{{ $id }}" name="pad_material[{{ $id }}][p_no]" value="{{ $inputMaterial['p_no'] ?? '' }}" maxlength="8" dir="ltr"></div>
              <div><label for="group-{{ $id }}">{{ $L('group') }}</label><input id="group-{{ $id }}" name="pad_material[{{ $id }}][group_no]" value="{{ $inputMaterial['group_no'] ?? '' }}" type="number" min="1" step="1" dir="ltr"></div>
            </div>
            <p class="hint">{{ $L('manual_help') }}</p>
            <button class="btn" type="submit" style="margin-top:10px">{{ $L('save') }}</button>
          </form>
        @elseif($revision?->cde_state !== \App\Support\CdeState::WIP)
          <p class="locked-note">{{ $L('locked') }}</p>
        @else
          <p class="locked-note">{{ $L('no_authority') }}</p>
        @endif
      </section>
    @endforeach
  @endif
</div>
</div>
</div>
</body>
</html>
