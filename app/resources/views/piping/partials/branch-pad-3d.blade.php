@php
    $pad3dSnapshot = is_array($pad->pad_geometry_snapshot) ? $pad->pad_geometry_snapshot : [];
    $pad3dInputs = is_array($pad3dSnapshot['inputs'] ?? null) ? $pad3dSnapshot['inputs'] : [];
    $pad3dAssumptions = is_array($pad3dSnapshot['assumptions'] ?? null) ? $pad3dSnapshot['assumptions'] : [];
    $pad3dSaddle = is_array($pad3dSnapshot['saddle'] ?? null) ? $pad3dSnapshot['saddle'] : [];
    $pad3dOuterPerimeter = is_array($pad3dSaddle['outer_perimeter_profile'] ?? null) ? $pad3dSaddle['outer_perimeter_profile'] : [];
    $pad3dPerimeterPoints = is_array($pad3dOuterPerimeter['sampled_points_mm'] ?? null) ? $pad3dOuterPerimeter['sampled_points_mm'] : [];
    $pad3dPerimeterJson = json_encode($pad3dPerimeterPoints, JSON_THROW_ON_ERROR);
    $pad3dMaterials = is_array($pad3dSnapshot['materials'] ?? null) ? $pad3dSnapshot['materials'] : [];
    $pad3dPadMaterial = is_array($pad3dMaterials['pad'] ?? null) ? $pad3dMaterials['pad'] : [];
    $pad3dMaterialIdentity = is_array($pad3dPadMaterial['identity'] ?? null) ? $pad3dPadMaterial['identity'] : [];
    $pad3dMaterialLabel = trim((string) ($pad3dMaterialIdentity['spec'] ?? '').' '.(string) ($pad3dMaterialIdentity['grade'] ?? ''));
    if (is_string($pad3dMaterialIdentity['psl'] ?? null) && $pad3dMaterialIdentity['psl'] !== '') {
        $pad3dMaterialLabel .= ' '.(string) $pad3dMaterialIdentity['psl'];
    }
@endphp
<details data-pad3d-root
         data-method="{{ $pad->pad_geometry_method }}"
         data-outer-perimeter="{{ $pad3dPerimeterJson }}"
         data-major-direction="{{ $pad->pad_major_axis_direction }}"
         data-minor-direction="{{ $pad->pad_minor_axis_direction }}"
         data-header-od="{{ (float) ($pad3dInputs['header_od_mm'] ?? 0) }}"
         data-branch-od="{{ (float) ($pad3dInputs['branch_od_mm'] ?? 0) }}"
         data-major="{{ (float) $pad->pad_major_axis_mm }}"
         data-minor="{{ (float) $pad->pad_minor_axis_mm }}"
         data-thickness="{{ (float) $pad->pad_thickness_mm }}"
         data-beta="{{ (float) $pad->pad_geometry_beta_deg }}"
         data-offset="{{ array_key_exists('branch_offset_mm', $pad3dAssumptions) ? (float) $pad3dAssumptions['branch_offset_mm'] : 'unknown' }}"
         data-render-error="{{ $L('pad3dRenderError') }}"
         data-span-error="{{ $L('pad3dSpanError') }}"
         style="margin-top:10px;border-top:1px solid var(--line);padding-top:8px;">
  <summary style="cursor:pointer;color:var(--accent,#60a5fa);font-weight:600;">{{ $L('pad3dTitle') }}</summary>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:10px;align-items:start;margin-top:10px;">
    <div style="min-width:0;">
      <canvas data-pad3d-canvas width="720" height="340" tabindex="0" role="img"
              aria-label="{{ $L('pad3dCanvasFallback') }}"
              style="display:block;width:100%;height:320px;border:1px solid var(--line);border-radius:8px;background:linear-gradient(180deg,rgba(15,23,42,.72),rgba(2,6,23,.92));touch-action:none;cursor:grab;">
        {{ $L('pad3dCanvasFallback') }}
      </canvas>
      <div data-pad3d-status role="status" hidden
           style="margin-top:6px;padding:7px 9px;border:1px solid #f59e0b;border-radius:7px;color:#fcd34d;font-size:11px;line-height:1.6;"></div>
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:7px;">
        <button type="button" data-pad3d-rotate="-0.24" aria-label="{{ $L('pad3dRotateLeft') }}" title="{{ $L('pad3dRotateLeft') }}"
                style="padding:4px 9px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:inherit;cursor:pointer;">↶</button>
        <button type="button" data-pad3d-rotate="0.24" aria-label="{{ $L('pad3dRotateRight') }}" title="{{ $L('pad3dRotateRight') }}"
                style="padding:4px 9px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:inherit;cursor:pointer;">↷</button>
        <button type="button" data-pad3d-reset
                style="padding:4px 9px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:inherit;font:inherit;font-size:11px;cursor:pointer;">{{ $L('pad3dReset') }}</button>
        <span style="color:var(--muted);font-size:10px;">{{ $L('pad3dControls') }}</span>
      </div>
    </div>

    <div style="display:grid;gap:6px;">
      <div style="padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:var(--bg);">
        <small style="display:block;color:var(--muted);">A · {{ $L('padMajorAxisShort') }} ·
          @if($pad->pad_major_axis_direction === 'AROUND_HEADER'){{ $L('padDirectionAround') }}@else{{ $pad->pad_major_axis_direction }}@endif
        </small>
        <strong><bdi>{{ $num($pad->pad_major_axis_mm) }} mm</bdi></strong>
      </div>
      <div style="padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:var(--bg);">
        <small style="display:block;color:var(--muted);">B · {{ $L('padMinorAxisShort') }} ·
          @if($pad->pad_minor_axis_direction === 'ALONG_HEADER'){{ $L('padDirectionAlong') }}@else{{ $pad->pad_minor_axis_direction }}@endif
        </small>
        <strong><bdi>{{ $num($pad->pad_minor_axis_mm) }} mm</bdi></strong>
      </div>
      <div style="padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:var(--bg);">
        <small style="display:block;color:var(--muted);">T · {{ $L('padThickness') }}</small>
        <strong><bdi>{{ $num($pad->pad_thickness_mm) }} mm</bdi></strong>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;">
        <div style="padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:var(--bg);">
          <small style="display:block;color:var(--muted);">{{ $L('padBeta') }}</small>
          <strong><bdi>{{ $num($pad->pad_geometry_beta_deg) }}°</bdi></strong>
        </div>
        <div style="padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:var(--bg);">
          <small style="display:block;color:var(--muted);">{{ $L('padZoneHalfWidth') }}</small>
          <strong><bdi>{{ $num($pad->pad_geometry_d2_mm) }} mm</bdi></strong>
        </div>
      </div>
      <div style="padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:var(--bg);font-size:10.5px;line-height:1.8;">
        <div>
          <span style="color:var(--muted);">{{ $L('pad3dHeaderOD') }}:</span>
          <bdi>{{ $num($pad3dInputs['header_od_mm'] ?? '—') }} mm · {{ $L('pad3dSchedule') }} {{ $num($pad3dInputs['header_schedule'] ?? '—') }} · {{ $num($pad3dInputs['header_effective_wall_mm'] ?? '—') }} mm</bdi>
        </div>
        <div>
          <span style="color:var(--muted);">{{ $L('pad3dBranchOD') }}:</span>
          <bdi>{{ $num($pad3dInputs['branch_od_mm'] ?? '—') }} mm · {{ $L('pad3dSchedule') }} {{ $num($pad3dInputs['branch_schedule'] ?? '—') }} · {{ $num($pad3dInputs['branch_effective_wall_mm'] ?? '—') }} mm</bdi>
        </div>
        <div>
          <span style="color:var(--muted);">{{ $L('pad3dCa') }}:</span>
          <bdi>{{ $num($pad3dInputs['governing_corrosion_allowance_mm'] ?? '—') }} mm</bdi>
          <span style="color:var(--line);">|</span>
          <span style="color:var(--muted);">{{ $L('pad3dOffset') }}:</span>
          <bdi>{{ $num($pad3dAssumptions['branch_offset_mm'] ?? '—') }} mm</bdi>
        </div>
        @if($pad3dMaterialLabel !== '')
          <div>
            <span style="color:var(--muted);">{{ $L('padMaterial') }}:</span>
            <bdi>{{ $pad3dMaterialLabel }}</bdi>
            <small style="color:var(--muted);">({{ !empty($pad3dPadMaterial['defaulted_from_header']) ? $L('padMaterialDefault') : $L('padMaterialPms') }})</small>
            @if(is_numeric($pad3dPadMaterial['allowable_stress_mpa_at_design_temperature'] ?? null))
              · <bdi>S={{ $num($pad3dPadMaterial['allowable_stress_mpa_at_design_temperature']) }} MPa</bdi>
            @endif
            @if(is_numeric($pad3dPadMaterial['allowable_stress_ratio_to_run'] ?? null))
              · {{ $L('padStressRatio') }}: <bdi>{{ $num($pad3dPadMaterial['allowable_stress_ratio_to_run']) }}</bdi>
            @endif
          </div>
        @endif
      </div>
      <p style="margin:0;color:var(--muted);font-size:10.5px;line-height:1.7;">{{ $L('pad3dNote') }}</p>
    </div>
  </div>
</details>
