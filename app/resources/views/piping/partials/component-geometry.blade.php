@php
    $pose = $component->latestPoseRevision;
    $status = $geometry['status'] ?? 'UNRESOLVED';
    $reason = $geometry['reason'] ?? null;
    $isCurrentForm = old('component_id') === $component->id;
    $poseValue = fn (string $field, mixed $stored): mixed => $isCurrentForm ? old($field) : $stored;
    $inputGeometry = is_array($pose?->input_geometry) ? $pose->input_geometry : [];
    $capExtentInputs = is_array($inputGeometry['cap_extent_inputs'] ?? null) ? $inputGeometry['cap_extent_inputs'] : [];
    $profileSnapshot = is_array($pose?->resolved_port_geometry) ? $pose->resolved_port_geometry : [];
    $profileParameters = is_array($profileSnapshot['profile_parameters'] ?? null) ? $profileSnapshot['profile_parameters'] : [];
    $savedInputMode = $inputGeometry['input_mode'] ?? 'AZIMUTH_INCLINATION_ROLL';
    $inputMode = $isCurrentForm ? old('input_mode', $savedInputMode) : $savedInputMode;
    $triangleFormOpen = $inputMode === 'DIMENSIONED_TRIANGLE';
@endphp

@if(! in_array($component->template_code, ['PIPE', 'NIPPLE', 'ELBOW', 'CAP', 'TEE', 'REDUCER'], true))
  <div style="color:var(--muted);font-size:10px;line-height:1.6;">{{ $L('geometryUnsupported') }}</div>
@else
  <div style="font-size:11px;line-height:1.6;">
    @if($component->template_code === 'CAP')
      <div style="margin-bottom:4px;color:var(--muted);font-size:10px;line-height:1.6;">{{ $L('capGeometryHelp') }}</div>
    @endif
    @if($component->template_code === 'ELBOW')
      <div style="margin-bottom:4px;color:var(--muted);font-size:10px;line-height:1.6;">
        {{ $L($component->fitting_standard === 'B16.11' ? 'forgedElbowGeometryHelp' : 'elbowGeometryHelp') }}
      </div>
      @if($component->fitting_standard === 'B16.11' && isset($profileParameters['dimension_mm']))
        <div style="margin-bottom:4px;color:var(--muted);font-size:10px;">
          <bdi>ASME B16.11-2021 · {{ $profileParameters['source_table'] }} · Class {{ $profileParameters['class_designation'] }} · {{ $profileParameters['bend_angle_label'] }} · {{ $profileParameters['dimension_symbol'] }} ({{ $profileParameters['dimension'] }}) = {{ $formatMm($profileParameters['dimension_mm']) }} mm</bdi>
          @if(($profileParameters['end_type'] ?? null) === 'SW')
            · <bdi>J (minimum socket depth) = {{ $formatMm($profileParameters['socket_depth_min_mm']) }} mm · port datum SOCKET_BOTTOM</bdi>
          @else
            · <bdi>port datum THREADED_END_PLANE</bdi>
          @endif
        </div>
      @elseif(isset($profileParameters['center_to_end_mm']))
        <div style="margin-bottom:4px;color:var(--muted);font-size:10px;">
          <bdi>{{ $profileParameters['standard_code'] ?? 'B16.9' }}-{{ $profileParameters['standard_edition_year'] ?? '2018' }} · {{ $profileParameters['source_table'] ?? 'Table 6.1-1' }} · {{ $profileParameters['dimension_symbol'] ?? '—' }} = {{ $formatMm($profileParameters['center_to_end_mm']) }} mm</bdi>
        </div>
      @endif
    @endif
    @if($component->template_code === 'TEE' && isset($profileParameters['run_centre_to_end_mm']))
      <div style="margin-bottom:4px;color:var(--muted);font-size:10px;">
        <bdi>B16.9-2018 · {{ $profileParameters['source_table'] ?? 'Table 6.1-7' }} · C (run) = {{ $formatMm($profileParameters['run_centre_to_end_mm']) }} mm · M (branch) = {{ $formatMm($profileParameters['branch_centre_to_end_mm']) }} mm</bdi>
      </div>
    @endif
    @if($component->template_code === 'REDUCER' && isset($profileParameters['end_to_end_h_mm']))
      <div style="margin-bottom:4px;color:var(--muted);font-size:10px;">
        <bdi>B16.9-2018 · {{ $profileParameters['source_table'] ?? 'Table 6.1-11' }} · H = {{ $formatMm($profileParameters['end_to_end_h_mm']) }} mm @if(($profileParameters['fitting_qualifier'] ?? '') === 'ECCENTRIC' && isset($profileParameters['eccentric_offset_y_mm'])) · {{ $L('eccentricOffset') ?? 'eccentric offset' }} = {{ $formatMm($profileParameters['eccentric_offset_y_mm']) }} mm @endif</bdi>
      </div>
    @endif
    <div>
      <strong>{{ $L('geometryStatus') }}:</strong>
      <span style="color:{{ $status === 'RESOLVED' ? '#86efac' : ($status === 'STALE' ? '#fca5a5' : '#fcd34d') }};">{{ $status }}</span>
      @if($pose)
        · <bdi>{{ $L('poseRevision') }} {{ $num($pose->revision) }}</bdi>
      @endif
    </div>
    @if(! $pose)
      <div style="color:var(--muted);">{{ $L('poseNone') }}</div>
    @elseif($reason)
      <div class="mono" style="color:var(--muted);font-size:10px;">{{ $reason }}</div>
    @endif
    @if($component->template_code === 'CAP' && $status === 'RESOLVED' && isset($profileParameters['body_extent_status']))
      @php
          $capThicknessSelection = is_array($profileParameters['body_thickness_selection'] ?? null)
              ? $profileParameters['body_thickness_selection']
              : [];
          $capStandardReference = is_array($profileParameters['body_standard_reference'] ?? null)
              ? $profileParameters['body_standard_reference']
              : [];
      @endphp
      <div class="mono" style="color:var(--muted);font-size:10px;">
        @if($profileParameters['body_extent_status'] === 'RESOLVED')
          {{ $L('capBodyExtentResolved') }} · B16.9 {{ $profileParameters['body_standard_reference']['standard']['editionYear'] ?? '—' }} · {{ $profileParameters['body_source_table'] ?? '—' }} · {{ $profileParameters['body_dimension_symbol'] ?? '—' }} = {{ $formatMm($profileParameters['body_length_mm'] ?? 0) }} mm
          · {{ $L('capThickness') }} {{ $formatMm($capThicknessSelection['wallThicknessMm'] ?? 0) }} mm
          · B16.9 note ({{ $capThicknessSelection['sourceNote'] ?? '—' }}) · E limit {{ $formatMm($capThicknessSelection['limitingWallThicknessMm'] ?? 0) }} mm
          · {{ $L('capThicknessSource') }}: {{ $profileParameters['body_thickness_source'] ?? '—' }}
          <small style="display:block;margin-top:2px;">{{ $L('referenceSource') }}: <bdi>{{ $capStandardReference['refId'] ?? '—' }} v{{ $capStandardReference['version'] ?? '—' }}</bdi> · <bdi>{{ $capStandardReference['source'] ?? '—' }}</bdi></small>
        @else
          {{ $L('capBodyExtentUnresolved') }}: {{ $profileParameters['body_extent_reason'] ?? 'CAP_BODY_EXTENT_NOT_RESOLVED' }}
        @endif
      </div>
    @endif

    @if($status === 'RESOLVED')
      <div style="margin-top:4px;color:var(--muted);">{{ $L('geometryPoints') }}</div>
      @foreach($geometry['ports'] ?? [] as $portGeometry)
        @php
            $pointText = implode(', ', array_map($formatMm, $portGeometry['point_model']));
            $axisText = implode(', ', array_map($formatMm, $portGeometry['outward_direction_model']));
        @endphp
        <div style="border-top:1px solid var(--line);padding-top:3px;margin-top:3px;">
          <bdi>P{{ $num($portGeometry['seq']) }} {{ $portGeometry['role'] }}</bdi>
          · {{ $L('geometryPoint') }} <bdi>[{{ $pointText }}]</bdi> mm
          · {{ $L('geometryAxis') }} <bdi>[{{ $axisText }}]</bdi>
        </div>
      @endforeach
      @foreach($geometry['reference_points'] ?? [] as $referencePointName => $referencePoint)
        @php $referencePointText = implode(', ', array_map($formatMm, $referencePoint['point_model'] ?? [])); @endphp
        <div style="border-top:1px solid var(--line);padding-top:3px;margin-top:3px;">
          {{ $L('capLengthSelection') }} ({{ $referencePointName }}): <bdi>[{{ $referencePointText }}]</bdi> mm
        </div>
      @endforeach
    @endif

    @if($pose)
      <details style="margin-top:4px;">
        <summary style="cursor:pointer;color:var(--muted);">{{ $L('poseRevision') }} {{ $num($pose->revision) }} · {{ $L('frameRevision') }} {{ $num($pose->modelFrameRevision?->revision ?? '—') }}</summary>
        <div style="padding:4px 6px;border-inline-start:2px solid var(--line);margin-top:3px;">
          <div>α <bdi>{{ $formatMm($pose->input_azimuth_deg) }}°</bdi> · ε <bdi>{{ $formatMm($pose->input_inclination_deg) }}°</bdi> · ρ <bdi>{{ $formatMm($pose->input_roll_deg) }}°</bdi></div>
          <div>{{ $L('anchorE') }} <bdi>{{ $formatMm($pose->anchor_easting_mm) }}</bdi> · {{ $L('anchorN') }} <bdi>{{ $formatMm($pose->anchor_northing_mm) }}</bdi> · {{ $L('anchorZ') }} <bdi>{{ $formatMm($pose->anchor_elevation_mm) }}</bdi></div>
          @if(($inputGeometry['input_mode'] ?? null) === 'DIMENSIONED_TRIANGLE')
            <div style="margin-top:4px;color:var(--muted);">{{ $L('triangleRaw') }}</div>
            <div>
              <bdi>{{ $inputGeometry['adapter_method'] ?? '—' }}</bdi>
              · {{ $L('triangleAzimuth') }} <bdi>{{ $formatMm($inputGeometry['azimuth_plane_deg'] ?? $pose->input_azimuth_deg) }}°</bdi>
              · {{ $L('roll') }} <bdi>{{ $formatMm($inputGeometry['roll_deg'] ?? $pose->input_roll_deg) }}°</bdi>
            </div>
            <div>
              {{ $L('run') }} <bdi>{{ $formatMm($inputGeometry['run_mm'] ?? 0) }}</bdi>
              · {{ $L('rise') }} <bdi>{{ $formatMm($inputGeometry['rise_mm'] ?? 0) }}</bdi>
              · {{ $L('inclination') }} <bdi>{{ $formatMm($pose->input_inclination_deg) }}°</bdi>
            </div>
            <div>
              {{ $L('calculatedHypotenuse') }}
              <bdi>{{ $formatMm($inputGeometry['calculated_hypotenuse_mm'] ?? 0) }}</bdi> mm
              @if(($inputGeometry['measured_hypotenuse_mm'] ?? null) !== null)
                · {{ $L('measuredHypotenuse') }} <bdi>{{ $formatMm($inputGeometry['measured_hypotenuse_mm']) }}</bdi>
              @endif
              · {{ $L('triangleResidual') }}
              <bdi>{{ ($inputGeometry['closure_residual_mm'] ?? null) === null ? '—' : $formatMm($inputGeometry['closure_residual_mm']) }}</bdi> mm
              @if(($inputGeometry['closure_residual_mm'] ?? null) !== null)
                · <span style="color:#fcd34d;">{{ $L('residualUnassessed') }}</span>
              @endif
            </div>
            <div style="color:var(--muted);font-size:10px;">
              <bdi>{{ $inputGeometry['angle_calculation_method'] ?? '—' }}</bdi>
              @if(($inputGeometry['closure_calculation_method'] ?? null) !== null)
                · <bdi>{{ $inputGeometry['closure_calculation_method'] }}</bdi>
              @endif
              · {{ $inputGeometry['angle_unit'] ?? 'DEG' }} / {{ $inputGeometry['length_unit'] ?? 'MM' }}
            </div>
          @else
            <div style="color:var(--muted);font-size:10px;">
              <bdi>{{ $inputGeometry['adapter_method'] ?? $pose->orientation_method }}</bdi>
              · {{ $inputGeometry['angle_unit'] ?? 'DEG' }}
            </div>
          @endif
        </div>
      </details>
    @endif

    @if($canEditPose && $currentModelFrame)
      <details style="margin-top:6px;" {{ $triangleFormOpen ? '' : 'open' }}>
        <summary style="cursor:pointer;color:var(--accent,#60a5fa);">{{ $L('poseForm') }}</summary>
        <form method="POST"
              action="{{ route('piping.model.pose', ['projectCode' => $project->code, 'lineId' => $line->id, 'componentId' => $component->id]) }}?{{ http_build_query($q) }}"
              style="display:grid;grid-template-columns:repeat(2,minmax(90px,1fr));gap:5px;margin-top:6px;">
          @csrf
          <input type="hidden" name="input_mode" value="AZIMUTH_INCLINATION_ROLL">
          <input type="hidden" name="component_id" value="{{ $component->id }}">
          <input type="hidden" name="model_frame_revision_id" value="{{ $currentModelFrame->id }}">
          @if($component->template_code === 'CAP')
            <label style="grid-column:1/-1;font-size:10px;color:var(--muted);">{{ $L('capThickness') }}
              <input type="number" step="any" min="0" name="cap_specified_wall_thickness_mm" value="{{ $poseValue('cap_specified_wall_thickness_mm', $capExtentInputs['specified_wall_thickness_mm'] ?? null) }}"
                     style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            </label>
            <label style="grid-column:1/-1;font-size:10px;color:var(--muted);">{{ $L('capThicknessSource') }}
              <input type="text" maxlength="240" name="cap_thickness_source" value="{{ $poseValue('cap_thickness_source', $capExtentInputs['thickness_source'] ?? null) }}"
                     style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
              <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('capThicknessSourceHelp') }}</small>
            </label>
          @endif
          <label style="font-size:10px;color:var(--muted);">{{ $L('azimuth') }}
            <input type="number" step="any" name="azimuth_deg" required value="{{ $poseValue('azimuth_deg', $pose?->input_azimuth_deg) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('inclination') }}
            <input type="number" step="any" min="-90" max="90" name="inclination_deg" required value="{{ $poseValue('inclination_deg', $pose?->input_inclination_deg) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('roll') }}
            <input type="number" step="any" name="roll_deg" required value="{{ $poseValue('roll_deg', $pose?->input_roll_deg) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('anchorE') }}
            <input type="number" step="any" name="anchor_easting_mm" required value="{{ $poseValue('anchor_easting_mm', $pose?->anchor_easting_mm) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('anchorN') }}
            <input type="number" step="any" name="anchor_northing_mm" required value="{{ $poseValue('anchor_northing_mm', $pose?->anchor_northing_mm) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('anchorZ') }}
            <input type="number" step="any" name="anchor_elevation_mm" required value="{{ $poseValue('anchor_elevation_mm', $pose?->anchor_elevation_mm) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <button type="submit" style="grid-column:1/-1;padding:5px 8px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:10px;cursor:pointer;">
            {{ $L('poseSave') }}
          </button>
        </form>
      </details>

      <details style="margin-top:6px;" {{ $triangleFormOpen ? 'open' : '' }}>
        <summary style="cursor:pointer;color:var(--accent,#60a5fa);">{{ $L('trianglePoseForm') }}</summary>
        <p style="margin:5px 0;color:var(--muted);font-size:10px;line-height:1.6;">{{ $L('triangleHelp') }}</p>
        <form method="POST"
              action="{{ route('piping.model.pose', ['projectCode' => $project->code, 'lineId' => $line->id, 'componentId' => $component->id]) }}?{{ http_build_query($q) }}"
              style="display:grid;grid-template-columns:repeat(2,minmax(90px,1fr));gap:5px;margin-top:6px;">
          @csrf
          <input type="hidden" name="input_mode" value="DIMENSIONED_TRIANGLE">
          <input type="hidden" name="component_id" value="{{ $component->id }}">
          <input type="hidden" name="model_frame_revision_id" value="{{ $currentModelFrame->id }}">
          @if($component->template_code === 'CAP')
            <label style="grid-column:1/-1;font-size:10px;color:var(--muted);">{{ $L('capThickness') }}
              <input type="number" step="any" min="0" name="cap_specified_wall_thickness_mm" value="{{ $poseValue('cap_specified_wall_thickness_mm', $capExtentInputs['specified_wall_thickness_mm'] ?? null) }}"
                     style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            </label>
            <label style="grid-column:1/-1;font-size:10px;color:var(--muted);">{{ $L('capThicknessSource') }}
              <input type="text" maxlength="240" name="cap_thickness_source" value="{{ $poseValue('cap_thickness_source', $capExtentInputs['thickness_source'] ?? null) }}"
                     style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
              <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('capThicknessSourceHelp') }}</small>
            </label>
          @endif
          <label style="font-size:10px;color:var(--muted);">{{ $L('triangleAzimuth') }}
            <input type="number" step="any" name="azimuth_deg" required value="{{ $poseValue('azimuth_deg', $inputGeometry['azimuth_plane_deg'] ?? $pose?->input_azimuth_deg) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('roll') }}
            <input type="number" step="any" name="roll_deg" required value="{{ $poseValue('roll_deg', $inputGeometry['roll_deg'] ?? $pose?->input_roll_deg) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('run') }}
            <input type="number" step="any" min="0" name="run_mm" required value="{{ $poseValue('run_mm', $inputGeometry['run_mm'] ?? null) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('rise') }}
            <input type="number" step="any" name="rise_mm" required value="{{ $poseValue('rise_mm', $inputGeometry['rise_mm'] ?? null) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="grid-column:1/-1;font-size:10px;color:var(--muted);">{{ $L('measuredHypotenuse') }}
            <input type="number" step="any" min="0" name="measured_hypotenuse_mm" value="{{ $poseValue('measured_hypotenuse_mm', $inputGeometry['measured_hypotenuse_mm'] ?? null) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('anchorE') }}
            <input type="number" step="any" name="anchor_easting_mm" required value="{{ $poseValue('anchor_easting_mm', $pose?->anchor_easting_mm) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="font-size:10px;color:var(--muted);">{{ $L('anchorN') }}
            <input type="number" step="any" name="anchor_northing_mm" required value="{{ $poseValue('anchor_northing_mm', $pose?->anchor_northing_mm) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <label style="grid-column:1/-1;font-size:10px;color:var(--muted);">{{ $L('anchorZ') }}
            <input type="number" step="any" name="anchor_elevation_mm" required value="{{ $poseValue('anchor_elevation_mm', $pose?->anchor_elevation_mm) }}"
                   style="width:100%;box-sizing:border-box;margin-top:2px;padding:4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          </label>
          <button type="submit" style="grid-column:1/-1;padding:5px 8px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:10px;cursor:pointer;">
            {{ $L('poseSave') }}
          </button>
        </form>
      </details>
    @elseif($canEditPose)
      <div style="margin-top:5px;color:#fcd34d;font-size:10px;"><a href="#model-frame" style="color:inherit;">{{ $L('frameRequired') }}</a></div>
    @elseif($line->release_state !== \App\Support\LineReleaseState::DRAFT)
      <div style="margin-top:5px;color:var(--muted);font-size:10px;">{{ $L('poseDraftOnly') }}</div>
    @endif
  </div>
@endif
