@php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
    $name = $lang === 'fa' ? $equipment->name_fa : $equipment->name_en;
    $openEnds = collect($schedule)->filter(fn ($n) => $n['is_open_end'])->count();
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $equipment->tag_number }} — {{ $project->code }}</title>
@include('partials.shell')
<style>
  .eq-card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px; margin:14px 0; }
  .eq-card h2 { font-size:15px; margin:0 0 8px; }
  .eq-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px; }
  .eq-grid label { display:flex; flex-direction:column; gap:4px; font-size:12px; }
  .eq-grid input, .eq-grid select { width:100%; box-sizing:border-box; border:1px solid var(--line);
      border-radius:7px; padding:7px 8px; background:var(--bg); color:var(--fg); }
  .help { font-size:11px; color:var(--muted); }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th, td { text-align:left; padding:7px 9px; border-bottom:1px solid var(--line); }
  th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
  .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .pill { display:inline-block; padding:1px 7px; border-radius:999px; font-size:11px; border:1px solid var(--line); }
  .pill.open { color:#fca5a5; border-color:#fca5a5; }
  .pill.spare { color:var(--muted); }
</style>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1><span class="mono">{{ $equipment->tag_number }}</span></h1>
  <div class="sub">{{ $name }} · {{ $project->name($lang) }} · <span class="mono">{{ $project->code }}</span></div>

  <p class="help">
    <a href="{{ route('equipment', ['projectCode' => $project->code, 'lang' => $lang]) }}">← {{ __('equipment.title') }}</a>
  </p>

  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if($errors->any())
    <div class="err"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <div class="eq-grid">
    <div><span class="help">{{ __('equipment.equipment_type') }}</span><br>{{ $equipment->equipment_type }}</div>
    <div><span class="help">{{ __('equipment.state') }}</span><br>{{ $equipment->state }}</div>
    <div><span class="help">{{ __('equipment.subsystem') }}</span><br><span class="mono">{{ $equipment->subsystem?->code ?? '—' }}</span></div>
    <div><span class="help">{{ __('equipment.design_pressure') }}</span><br>{{ $equipment->design_pressure ?? '—' }}</div>
    <div><span class="help">{{ __('equipment.design_temperature') }}</span><br>{{ $equipment->design_temperature ?? '—' }}</div>
    <div><span class="help">{{ __('equipment.design_code') }}</span><br>{{ $equipment->design_code ?? '—' }}</div>
  </div>

  @if($equipment->service_description)
    <div class="eq-card"><span class="help">{{ __('equipment.service_description') }}</span><br>{{ $equipment->service_description }}</div>
  @endif

  @if($openEnds > 0)
    <div class="eq-card">
      <strong>{{ __('equipment.open_end') }}:</strong>
      {{ \App\Support\JalaliDate::digits((string) $openEnds, $lang) }}
      <span class="help">{{ __('equipment.schedule_scope') }}</span>
    </div>
  @endif

  <div class="eq-card">
    <h2>{{ __('equipment.schedule_title') }}</h2>
    @if($schedule === [])
      <p class="help">{{ __('equipment.no_nozzles') }}</p>
    @else
      <table>
        <thead>
          <tr>
            <th>{{ __('equipment.tag') }}</th>
            <th>{{ __('equipment.nozzle_type') }}</th>
            <th>{{ __('equipment.nps') }}</th>
            <th>{{ __('equipment.dn') }}</th>
            <th>{{ __('equipment.end_type') }}</th>
            <th>{{ __('equipment.rating') }}</th>
            <th>{{ __('equipment.facing') }}</th>
            <th>{{ __('equipment.connected_line') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($schedule as $row)
            <tr>
              <td class="mono">{{ $row['tag'] }}</td>
              <td>
                {{ $row['nozzle_type'] }}
                @if($row['is_spare'])<span class="pill spare">{{ __('equipment.spare') }}</span>@endif
              </td>
              <td class="mono">{{ $row['nps_label'] }}</td>
              <td class="mono">{{ $row['dn'] ?? '—' }}</td>
              <td>{{ $row['end_type'] ?? '—' }}</td>
              <td>{{ $row['rating'] ?? '—' }}</td>
              <td>{{ $row['facing'] ?? '—' }}</td>
              <td>
                @if($row['connected_line'] !== null)
                  <span class="mono">{{ $row['connected_line'] }}</span>
                @elseif($row['is_open_end'])
                  <span class="pill open">{{ __('equipment.unconnected') }}</span>
                @else
                  <span class="pill spare">{{ __('equipment.spare') }}</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  @if($canMaintain)
  <details class="eq-card" @if($errors->any()) open @endif>
    <summary style="cursor:pointer;font-weight:600">{{ __('equipment.add_nozzle') }}</summary>
    <form method="post" action="{{ route('equipment.nozzles.store', ['projectCode' => $project->code, 'equipmentId' => $equipment->id]) }}">
      @csrf
      <div class="eq-grid" style="margin-top:10px">
        <label>{{ __('equipment.tag') }}
          <input name="tag" value="{{ $safeOld('tag') }}" maxlength="64" required>
        </label>
        <label>{{ __('equipment.nozzle_type') }}
          <select name="nozzle_type">
            @foreach($nozzleTypes as $nt)<option value="{{ $nt }}" @selected(old('nozzle_type') === $nt)>{{ $nt }}</option>@endforeach
          </select>
        </label>
        <label>{{ __('equipment.nps') }}
          <input name="nps_label" value="{{ $safeOld('nps_label') }}" maxlength="16" required>
        </label>
        <label>{{ __('equipment.dn') }}
          <input type="number" name="dn" value="{{ $safeOld('dn') }}" min="1" max="4000">
        </label>
        <label>{{ __('equipment.end_type') }}
          <input name="end_type" value="{{ $safeOld('end_type') }}" maxlength="32">
        </label>
        <label>{{ __('equipment.rating') }}
          <input name="rating" value="{{ $safeOld('rating') }}" maxlength="32">
        </label>
        <label>{{ __('equipment.facing') }}
          <input name="facing" value="{{ $safeOld('facing') }}" maxlength="32">
        </label>
        <label style="flex-direction:row;align-items:center;gap:7px">
          <input type="checkbox" name="is_spare" value="1" @checked(old('is_spare')) style="width:auto">
          {{ __('equipment.is_spare') }}
        </label>
      </div>
      <button type="submit" style="margin-top:12px">{{ __('equipment.add_nozzle') }}</button>
    </form>
  </details>
  @endif

</div>
</div>
</body>
</html>
