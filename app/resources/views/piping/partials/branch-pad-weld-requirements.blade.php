@php
    $padWeldSnapshot = is_array($pad->pad_geometry_snapshot) ? $pad->pad_geometry_snapshot : [];
    $padWeldRequirements = is_array($padWeldSnapshot['pad_weld_requirements'] ?? null)
        ? $padWeldSnapshot['pad_weld_requirements']
        : [];
    $padWeldRows = [
        [
            'key' => 'pad_to_header',
            'joint' => $pad->headerJoint,
            'label' => $L('padHeaderJoint'),
        ],
        [
            'key' => 'pad_to_branch',
            'joint' => $pad->branchJoint,
            'label' => $L('padBranchJoint'),
        ],
    ];
@endphp
<div style="margin-top:9px;padding:8px 10px;border:1px solid var(--line);border-radius:7px;background:var(--bg);">
  <strong style="font-size:11px;">{{ $L('padWeldRequirements') }}</strong>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:7px;margin-top:7px;">
    @foreach($padWeldRows as $row)
      @php
        $requirement = $padWeldRequirements[$row['key']] ?? [];
        $requirement = is_array($requirement) ? $requirement : [];
      @endphp
      @if($requirement !== [])
        <div style="padding:7px 8px;border:1px solid var(--line);border-radius:6px;font-size:10.5px;line-height:1.7;">
          <strong>{{ $row['label'] }}</strong>
          <span style="color:var(--muted);">· {{ $L('jnum') }} <bdi>{{ $num($row['joint']?->displayNumber() ?? '—') }}</bdi></span>
          @if(is_numeric($requirement['minimum_throat_mm'] ?? null))
            <div>
              <span style="color:var(--muted);">{{ $L('padWeldThroat') }}:</span>
              <bdi>{{ $num($requirement['minimum_throat_mm']) }} mm</bdi>
            </div>
          @endif
          @if(is_numeric($requirement['equal_leg_equivalent_mm'] ?? null))
            <div>
              <span style="color:var(--muted);">{{ $L('padWeldEqualLeg') }}:</span>
              <bdi>{{ $num($requirement['equal_leg_equivalent_mm']) }} mm</bdi>
            </div>
          @endif
          @if(is_string($requirement['reference'] ?? null) && $requirement['reference'] !== '')
            <small style="display:block;color:var(--muted);">{{ $requirement['reference'] }}</small>
          @endif
        </div>
      @endif
    @endforeach
  </div>
  <small style="display:block;margin-top:6px;color:var(--muted);font-size:10px;line-height:1.6;">{{ $L('padWeldBasis') }}</small>
</div>
