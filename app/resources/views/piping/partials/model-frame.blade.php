<section id="model-frame" style="margin-top:20px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:var(--card);">
  <h2 style="margin:0;font-size:15px;">{{ $L('modelFrame') }}</h2>
  <p style="margin:5px 0;color:var(--muted);font-size:11px;line-height:1.7;">{{ $L('frameAxes') }}</p>

  @if($currentModelFrame)
    <div style="font-size:12px;line-height:1.7;">
      <bdi>{{ $L('frameRevision') }} {{ $num($currentModelFrame->revision) }}</bdi>
      · <span>{{ $currentModelFrame->origin_description }}</span>
    </div>
  @else
    <div style="color:#fcd34d;font-size:12px;line-height:1.7;">{{ $L('frameNone') }}</div>
  @endif

  @if($canMaintainModel)
    <form method="POST"
          action="{{ route('piping.model.frame', ['projectCode' => $project->code, 'lineId' => $line->id]) }}?{{ http_build_query($q) }}"
          style="display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:8px;align-items:end;margin-top:9px;">
      @csrf
      <label style="font-size:11px;color:var(--muted);">
        {{ $L('frameOrigin') }}
        <textarea name="origin_description" required maxlength="1000" rows="2"
                  style="width:100%;box-sizing:border-box;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">{{ old('origin_description', $currentModelFrame?->origin_description) }}</textarea>
      </label>
      <button type="submit" style="padding:7px 12px;border-radius:7px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
        {{ $L('frameSave') }}
      </button>
    </form>
    <small style="display:block;margin-top:6px;color:var(--muted);font-size:10px;line-height:1.6;">{{ $L('frameHelp') }}</small>
  @endif
</section>
