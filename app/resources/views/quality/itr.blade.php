<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('quality_itr.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1100px;padding:20px;overflow-wrap:anywhere">
<h1>{{ __('quality_itr.title') }}</h1>
<p>{{ __('quality_itr.boundary') }}</p>
<p><a href="{{ route('quality.itr',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa','pack'=>$pack?->id]) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('quality.nominations',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.nominations') }}</a> · <a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.results') }}</a> · <a href="{{ route('quality.sign-offs',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.signoffs') }}</a> · <a href="{{ route('quality.mtr',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.mtr') }}</a> · <a href="{{ route('quality.legacy',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.legacy') }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

<h2>{{ __('quality_itr.packs') }}</h2>
@if($packs->isEmpty())
<p id="no-packs">{{ __('quality_itr.no_packs') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr>
<th data-sort="text">{{ __('quality_itr.pack') }}</th><th data-sort="text" data-facet>{{ __('quality_itr.state') }}</th><th data-sort="text" data-facet>{{ __('quality_itr.subsystem') }}</th><th>{{ __('quality_itr.select') }}</th>
</tr></thead><tbody>
@foreach($packs as $p)
<tr><td>{{ $p->code }}</td><td data-facet-value="{{ $p->state }}">{{ $p->state }}</td><td data-facet-value="{{ $p->subsystem?->code ?? '' }}">{{ $p->subsystem?->code ?? '—' }}</td><td><a href="{{ route('quality.itr',['projectCode'=>$project->code,'lang'=>$lang,'pack'=>$p->id]) }}">{{ __('quality_itr.open') }}</a></td></tr>
@endforeach
</tbody></table>
@endif

@if($pack)
<h3 id="pack-heading">{{ __('quality_itr.pack') }} {{ $pack->code }} — {{ $pack->state }}</h3>

<h4>{{ __('quality_itr.dossier') }}</h4>
<p id="dossier-kind">{{ __('quality_itr.kind') }}</p>
<p id="completeness">{{ ($completeness['is_complete'] ?? false) ? __('quality_itr.complete_yes') : __('quality_itr.complete_no') }}</p>
<p>{{ __('quality_itr.counts') }}: {{ __('quality_itr.lines') }} {{ (int) ($completeness['counts']['lines'] ?? 0) }} · {{ __('quality_itr.joints') }} {{ (int) ($completeness['counts']['joints'] ?? 0) }} · {{ __('quality_itr.welds') }} {{ (int) ($completeness['counts']['welds'] ?? 0) }} · {{ __('quality_itr.ndt') }} {{ (int) ($completeness['counts']['ndt'] ?? 0) }} · {{ __('quality_itr.test_results') }} {{ (int) ($completeness['counts']['test_results'] ?? 0) }}</p>

<h4>{{ __('quality_itr.gaps') }}</h4>
@php $missing = $completeness['missing'] ?? []; @endphp
@if(($completeness['is_complete'] ?? false))
<p id="no-gaps">{{ __('quality_itr.no_gaps') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="gap-table"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_itr.gaps') }}</th><th>{{ __('quality_itr.line_number') }} / {{ __('quality_itr.joint') }}</th>
</tr></thead><tbody>
@foreach(['lines_not_released'=>'gap_lines_not_released','joints_not_fitted'=>'gap_joints_not_fitted','joints_without_weld_record'=>'gap_joints_without_weld_record','joints_without_ndt'=>'gap_joints_without_ndt','joints_with_open_rejection'=>'gap_joints_with_open_rejection'] as $key=>$label)
@if(! empty($missing[$key]))
<tr><td data-facet-value="{{ $key }}">{{ __('quality_itr.'.$label) }}</td><td>@foreach($missing[$key] as $m)<span>{{ $m['line_number'] }}@if(($m['joint_seq'] ?? null) !== null)/J{{ $m['joint_seq'] }}@endif</span>@if(! $loop->last) · @endif @endforeach</td></tr>
@endif
@endforeach
@if(! empty($missing['hold_notifications_not_cleared']))
<tr><td data-facet-value="hold_notifications_not_cleared">{{ __('quality_itr.gap_hold_notifications_not_cleared') }}</td><td>@foreach($missing['hold_notifications_not_cleared'] as $h)<span>{{ $h['number'] }} — {{ __('quality_itr.hold_'.strtolower((string) $h['hold_state'])) }}</span>@if(! $loop->last) · @endif @endforeach</td></tr>
@endif
@if(! empty($missing['pack_without_passed_result']))
<tr><td data-facet-value="pack_without_passed_result">{{ __('quality_itr.gap_pack_without_passed_result') }}</td><td>—</td></tr>
@endif
</tbody></table>
@endif

<h4 id="notices-heading">{{ __('quality_itr.notices_title') }}</h4>
<p>{{ __('quality_itr.notices_help') }}</p>
@if($notifications->isEmpty())
<p id="no-notices">{{ __('quality_itr.no_notices') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="notice-table"><thead><tr>
<th data-sort="text">{{ __('quality_itr.notice') }}</th><th data-sort="text">{{ __('quality_itr.point') }}</th><th data-sort="text" data-facet>{{ __('quality_itr.holder') }}</th><th>{{ __('quality_itr.stage') }}</th><th>{{ __('quality_itr.outcome') }}</th>
</tr></thead><tbody>
@foreach($notifications as $n)
<tr><td>{{ $n['number'] }}</td><td>{{ $n['point'] }}</td><td data-facet-value="{{ \Illuminate\Support\Str::lower($n['hold_state']) }}">{{ __('quality_itr.hold_'.\Illuminate\Support\Str::lower($n['hold_state'])) }}</td><td>{{ $n['stage_description'] }}</td><td>@if($n['signoffs'] === []){{ __('quality_itr.signoff_none') }}@else @foreach($n['signoffs'] as $so)<div>{{ __('quality_itr.outcome_'.$so['outcome']) }}@if($so['is_employer']) <small>({{ __('quality_itr.employer_tag') }})</small>@endif <small>{{ $so['signed_at'] }}</small></div>@endforeach @endif</td></tr>
@endforeach
</tbody></table>
@endif

<h4>{{ __('quality_itr.link_title') }}</h4>
<p>{{ __('quality_itr.link_help') }}</p>
@if($eligible->isEmpty())
<p id="no-eligible">{{ __('quality_itr.no_eligible') }}</p>
@else
<form id="link-form" method="post" action="{{ route('quality.itr.link',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="test_pack_id" value="{{ $pack->id }}"><input type="hidden" name="request_id" value="{{ $linkRequestId }}">
<label>{{ __('quality_itr.select_notice') }} <select name="notification_id" id="notice-select">
@foreach($eligible as $e)
<option value="{{ $e->id }}">{{ $e->number }} — {{ $e->point }} — {{ $lang === 'fa' ? ($e->stage_description_fa ?: $e->stage_description_en) : ($e->stage_description_en ?: $e->stage_description_fa) }}</option>
@endforeach
</select></label>
<button type="submit" id="link-notice">{{ __('quality_itr.submit_link') }}</button></form>
@endif

@if($pack->state === 'DRAFT')
<p id="draft-note">{{ __('quality_itr.draft_note') }}</p>
@else
<h4>{{ __('quality_itr.freeze_title') }}</h4>
<p>{{ __('quality_itr.freeze_help') }}</p>
<form id="freeze-form" method="post" action="{{ route('quality.itr.freeze',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="test_pack_id" value="{{ $pack->id }}"><input type="hidden" name="request_id" value="{{ $requestId }}">
<button type="submit" id="freeze-snapshot">{{ __('quality_itr.submit') }}</button></form>
@endif

<h4>{{ __('quality_itr.snapshots') }}</h4>
@if($snapshots->isEmpty())
<p id="no-snapshots">{{ __('quality_itr.no_snapshots') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="snapshot-table"><thead><tr>
<th data-sort="text">{{ __('quality_itr.compiled_at') }}</th><th data-sort="text">{{ __('quality_itr.digest') }}</th><th data-sort="text" data-facet>{{ __('quality_itr.digest_valid') }}</th><th>{{ __('quality_itr.export') }}</th>
</tr></thead><tbody>
@foreach($snapshots as $s)
<tr><td>{{ $s['row']->compiled_at }}</td><td style="font-family:monospace">{{ substr((string) $s['row']->digest, 0, 16) }}…</td><td data-facet-value="{{ $s['valid'] ? 'valid' : 'mismatch' }}">{{ $s['valid'] ? __('quality_itr.digest_yes') : __('quality_itr.digest_no') }}</td><td>@if($pdfAvailable)<a id="pdf-{{ Str::of($s['row']->id)->substr(0,8) }}" href="{{ route('quality.itr.pdf',['projectCode'=>$project->code,'lang'=>$lang,'snapshotId'=>$s['row']->id]) }}">{{ __('quality_itr.export') }}</a>@else—@endif</td></tr>
@endforeach
</tbody></table>
@endif
@endif
</section></div></div></body></html>
