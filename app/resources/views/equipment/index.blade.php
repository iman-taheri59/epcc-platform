@php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('equipment.title') }} — {{ $project->code }}</title>
@include('partials.shell')
<style>
  .eq-card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px; margin:14px 0; }
  .eq-card h2 { font-size:15px; margin:0 0 8px; }
  .eq-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; }
  .eq-grid label { display:flex; flex-direction:column; gap:4px; font-size:12px; }
  .eq-grid input, .eq-grid select, .eq-grid textarea { width:100%; box-sizing:border-box; border:1px solid var(--line);
      border-radius:7px; padding:7px 8px; background:var(--bg); color:var(--fg); }
  .help { font-size:11px; color:var(--muted); }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th, td { text-align:left; padding:7px 9px; border-bottom:1px solid var(--line); }
  th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
  .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ __('equipment.title') }}</h1>
  <div class="sub">{{ $project->name($lang) }} · <span class="mono">{{ $project->code }}</span></div>
  <p class="help">{{ __('equipment.scope') }}</p>

  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if($errors->any())
    <div class="err"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  @if($canMaintain)
  <details class="eq-card" @if($errors->any()) open @endif>
    <summary style="cursor:pointer;font-weight:600">{{ __('equipment.add') }}</summary>
    <form method="post" action="{{ route('equipment.store', ['projectCode' => $project->code]) }}">
      @csrf
      <div class="eq-grid" style="margin-top:10px">
        <label>{{ __('equipment.tag_number') }}
          <input name="tag_number" value="{{ $safeOld('tag_number') }}" maxlength="64" required>
        </label>
        <label>{{ __('equipment.equipment_type') }}
          <select name="equipment_type">
            @foreach($types as $type)<option value="{{ $type }}" @selected(old('equipment_type') === $type)>{{ $type }}</option>@endforeach
          </select>
        </label>
        <label>{{ __('equipment.name_en') }}
          <input name="name_en" value="{{ $safeOld('name_en') }}" maxlength="200" required>
        </label>
        <label>{{ __('equipment.name_fa') }}
          <input name="name_fa" value="{{ $safeOld('name_fa') }}" maxlength="200" required dir="rtl">
        </label>
        <label>{{ __('equipment.subsystem') }}
          <select name="subsystem_id">
            <option value="">—</option>
            @foreach($subsystems as $ss)<option value="{{ $ss->id }}" @selected(old('subsystem_id') === $ss->id)>{{ $ss->code }}</option>@endforeach
          </select>
        </label>
        <label>{{ __('equipment.state') }}
          <select name="state">
            @foreach($states as $st)<option value="{{ $st }}" @selected(old('state', 'PLANNED') === $st)>{{ $st }}</option>@endforeach
          </select>
        </label>
        <label>{{ __('equipment.design_pressure') }}
          <input name="design_pressure" value="{{ $safeOld('design_pressure') }}" maxlength="32">
        </label>
        <label>{{ __('equipment.design_temperature') }}
          <input name="design_temperature" value="{{ $safeOld('design_temperature') }}" maxlength="32">
        </label>
        <label>{{ __('equipment.design_code') }}
          <input name="design_code" value="{{ $safeOld('design_code') }}" maxlength="64">
        </label>
        <label>{{ __('equipment.external_ref') }}
          <input name="external_ref" value="{{ $safeOld('external_ref') }}" maxlength="200">
        </label>
      </div>
      <label style="margin-top:10px">{{ __('equipment.service_description') }}
        <textarea name="service_description" rows="2" maxlength="2000">{{ $safeOld('service_description') }}</textarea>
      </label>
      <button type="submit" style="margin-top:12px">{{ __('equipment.add') }}</button>
    </form>
  </details>
  @endif

  <div class="eq-card">
    <h2>{{ __('equipment.title') }}</h2>
    @if($equipment->isEmpty())
      <p class="help">{{ __('equipment.empty') }}</p>
    @else
      <table>
        <thead>
          <tr>
            <th>{{ __('equipment.tag_number') }}</th>
            <th>{{ __('equipment.equipment_type') }}</th>
            <th>{{ $lang === 'fa' ? __('equipment.name_fa') : __('equipment.name_en') }}</th>
            <th>{{ __('equipment.subsystem') }}</th>
            <th>{{ __('equipment.state') }}</th>
            <th>{{ __('equipment.nozzle_count') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($equipment as $item)
            <tr>
              <td class="mono">
                <a href="{{ route('equipment.show', ['projectCode' => $project->code, 'equipmentId' => $item->id]) }}">{{ $item->tag_number }}</a>
              </td>
              <td>{{ $item->equipment_type }}</td>
              <td>{{ $lang === 'fa' ? $item->name_fa : $item->name_en }}</td>
              <td class="mono">{{ $item->subsystem?->code ?? '—' }}</td>
              <td>{{ $item->state }}</td>
              <td>{{ $item->nozzles_count }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

</div>
</div>
</body>
</html>
