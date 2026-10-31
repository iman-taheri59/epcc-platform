<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('quality_itr.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1100px;padding:20px;overflow-wrap:anywhere">
@php
  // One control carries every kind, so the language toggle has to rebuild the
  // very token the select posted rather than lose the scope on the way round.
  $selValue = match ($scopeKind) {
      'PROJECT' => 'PROJECT',
      'SUBSYSTEM' => 'SUB:'.$scopeRef,
      'SYSTEM' => 'SYS:'.$scopeRef,
      'AREA' => 'AREA:'.$scopeRef,
      'FACILITY' => 'FAC:'.$scopeRef,
      default => 'PACK:'.$scopeRef,
  };
  // An area code is only meaningful inside its facility, so the picker says
  // which one - AR-22 alone would be ambiguous on a project with two plants.
  $facilityCodeOf = fn (mixed $id): ?string => collect($facilities)->firstWhere('id', $id)?->code;
@endphp
<h1>{{ __('quality_itr.title') }}</h1>
<p>{{ __('quality_itr.boundary') }}</p>
<p><a href="{{ route('quality.itr',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa','sel'=>$selValue]) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('quality.nominations',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.nominations') }}</a> · <a href="{{ route('quality.results',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.results') }}</a> · <a href="{{ route('quality.sign-offs',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.signoffs') }}</a> · <a href="{{ route('quality.mtr',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.mtr') }}</a> · <a href="{{ route('quality.legacy',['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ __('quality_itr.legacy') }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

<h2>{{ __('quality_itr.scopes') }}</h2>
<p>{{ __('quality_itr.scopes_help') }}</p>
<form id="scope-form" method="get" action="{{ route('quality.itr',['projectCode'=>$project->code,'lang'=>$lang]) }}">
<label>{{ __('quality_itr.scope') }} <select name="sel" id="scope-select">
<optgroup label="{{ __('quality_itr.scope_SYSTEM') }}">
@foreach($systems as $sy)
<option value="SYS:{{ $sy->id }}" @selected($scopeKind==='SYSTEM' && $scopeRef===$sy->id)>{{ $sy->code }}@if($sy->startup_priority !== null) · P{{ $sy->startup_priority }}@endif @if($sy->name_en) · {{ $sy->name_en }}@endif</option>
@endforeach
</optgroup>
<optgroup label="{{ __('quality_itr.scope_FACILITY') }}">
@foreach($facilities as $fc)
<option value="FAC:{{ $fc->id }}" @selected($scopeKind==='FACILITY' && $scopeRef===$fc->id)>{{ $fc->code }}@if($fc->name_en) · {{ $fc->name_en }}@endif</option>
@endforeach
</optgroup>
<optgroup label="{{ __('quality_itr.scope_AREA') }}">
@foreach($areas as $ar)
<option value="AREA:{{ $ar->id }}" @selected($scopeKind==='AREA' && $scopeRef===$ar->id)>{{ $ar->code }}@if($facilityCodeOf($ar->facility_id)) · {{ $facilityCodeOf($ar->facility_id) }}@endif @if($ar->name_en) · {{ $ar->name_en }}@endif</option>
@endforeach
</optgroup>
<option value="PROJECT" @selected($scopeKind==='PROJECT')>{{ __('quality_itr.scope_PROJECT') }} — {{ $project->code }}</option>
<optgroup label="{{ __('quality_itr.scope_TEST_PACK') }}">
@foreach($packs as $p)
<option value="PACK:{{ $p->id }}" @selected($scopeKind==='TEST_PACK' && $scopeRef===$p->id)>{{ $p->code }}@if($p->subsystem?->code) · {{ $p->subsystem?->code }}@endif</option>
@endforeach
</optgroup>
<optgroup label="{{ __('quality_itr.scope_SUBSYSTEM') }}">
@foreach($subsystems as $sub)
<option value="SUB:{{ $sub->id }}" @selected($scopeKind==='SUBSYSTEM' && $scopeRef===$sub->id)>{{ $sub->code }}@if($sub->name_en) · {{ $sub->name_en }}@endif</option>
@endforeach
</optgroup>
</select></label>
<button type="submit" id="open-scope">{{ __('quality_itr.open') }}</button></form>

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

@if($dossier !== null)
<h3 id="scope-heading">@if($scopeKind==='PROJECT'){{ __('quality_itr.scope_PROJECT') }} — {{ $project->code }}@elseif($scopeKind==='SUBSYSTEM'){{ __('quality_itr.scope_SUBSYSTEM') }} — {{ collect($subsystems)->firstWhere('id',$scopeRef)?->code }}@elseif($scopeKind==='SYSTEM'){{ __('quality_itr.scope_SYSTEM') }} — {{ collect($systems)->firstWhere('id',$scopeRef)?->code }}@elseif($scopeKind==='AREA'){{ __('quality_itr.scope_AREA') }} — {{ collect($areas)->firstWhere('id',$scopeRef)?->code }}@elseif($scopeKind==='FACILITY'){{ __('quality_itr.scope_FACILITY') }} — {{ collect($facilities)->firstWhere('id',$scopeRef)?->code }}@else{{ __('quality_itr.pack') }} {{ $pack->code }} — {{ $pack->state }}@endif</h3>

<h4>{{ __('quality_itr.dossier') }}</h4>
<p id="dossier-kind">{{ __('quality_itr.kind') }}</p>
{{--
  The cross-reference. Every grouping level answers the same two questions:
  what does it hold, and where does it sit. A system names the facilities it
  spans; an area names the SYSTEMS that reach into it, which is the answer the
  construction-side reader needs and the one nothing else in the platform
  gives. AWP calls keeping this live a duty; here it is a join, not a
  spreadsheet.
--}}
@if(in_array($scopeKind, ['SYSTEM','AREA','FACILITY'], true))
<p id="scope-spans">{{ __('quality_itr.spans') }}: @foreach(($dossier['scope']['subsystem_codes'] ?? []) as $c)<span>{{ $c }}</span>@if(! $loop->last) · @endif @endforeach
@if(($dossier['scope']['startup_priority'] ?? null) !== null) · {{ __('quality_itr.priority') }}: P{{ $dossier['scope']['startup_priority'] }}@endif
@if(($dossier['scope']['system_codes'] ?? []) !== []) · {{ __('quality_itr.systems') }}: {{ implode(', ', $dossier['scope']['system_codes']) }}@endif
@if(($dossier['scope']['area_codes'] ?? []) !== []) · {{ __('quality_itr.areas') }}: {{ implode(', ', $dossier['scope']['area_codes']) }}@endif
@if(($dossier['scope']['facility_codes'] ?? []) !== []) · {{ __('quality_itr.facilities') }}: {{ implode(', ', $dossier['scope']['facility_codes']) }}@endif
</p>
@endif
<p id="completeness">{{ ($completeness['is_complete'] ?? false) ? __('quality_itr.complete_yes') : __('quality_itr.complete_no') }}</p>
<p>{{ __('quality_itr.counts') }}: {{ __('quality_itr.lines') }} {{ (int) ($completeness['counts']['lines'] ?? 0) }} · {{ __('quality_itr.joints') }} {{ (int) ($completeness['counts']['joints'] ?? 0) }} · {{ __('quality_itr.welds') }} {{ (int) ($completeness['counts']['welds'] ?? 0) }} · {{ __('quality_itr.ndt') }} {{ (int) ($completeness['counts']['ndt'] ?? 0) }} · {{ __('quality_itr.test_results') }} {{ (int) ($completeness['counts']['test_results'] ?? 0) }} · {{ __('quality_itr.packs') }} {{ (int) ($completeness['counts']['packs'] ?? 0) }}</p>

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
@if(! empty($missing['packs_without_passed_result']))
<tr><td data-facet-value="packs_without_passed_result">{{ __('quality_itr.gap_packs_without_passed_result') }}</td><td>@foreach($missing['packs_without_passed_result'] as $pp)<span>{{ $pp['pack_code'] }}</span>@if(! $loop->last) · @endif @endforeach</td></tr>
@endif
@if(! empty($missing['lines_without_test_pack']))
<tr><td data-facet-value="lines_without_test_pack">{{ __('quality_itr.gap_lines_without_test_pack') }}</td><td>@foreach($missing['lines_without_test_pack'] as $ln)<span>{{ $ln['line_number'] }}</span>@if(! $loop->last) · @endif @endforeach</td></tr>
@endif
</tbody></table>
@endif

@if($pack !== null)
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
@endif

@if($scopeKind === 'TEST_PACK' && $pack->state === 'DRAFT')
<p id="draft-note">{{ __('quality_itr.draft_note') }}</p>
@else
<h4>{{ __('quality_itr.freeze_title') }}</h4>
<p>{{ __('quality_itr.freeze_help') }}</p>
<form id="freeze-form" method="post" action="{{ route('quality.itr.freeze',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="scope_kind" value="{{ $scopeKind }}"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="request_id" value="{{ $requestId }}">
<button type="submit" id="freeze-snapshot">{{ __('quality_itr.submit') }}</button></form>
@endif

<h4>{{ __('quality_itr.snapshots') }}</h4>
@if($snapshots->isEmpty())
<p id="no-snapshots">{{ __('quality_itr.no_snapshots') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="snapshot-table"><thead><tr>
<th data-sort="text">{{ __('quality_itr.compiled_at') }}</th><th data-sort="text">{{ __('quality_itr.digest') }}</th><th data-sort="text" data-facet>{{ __('quality_itr.digest_valid') }}</th><th data-sort="text" data-facet>{{ __('quality_itr.signatures') }}</th><th>{{ __('quality_itr.export') }}</th>
</tr></thead><tbody>
@foreach($snapshots as $s)
<tr><td>{{ $s['row']->compiled_at }}</td><td style="font-family:monospace">{{ substr((string) $s['row']->digest, 0, 16) }}…</td><td data-facet-value="{{ $s['valid'] ? 'valid' : 'mismatch' }}">{{ $s['valid'] ? __('quality_itr.digest_yes') : __('quality_itr.digest_no') }}</td>
@php $state = $signatureState->get((string) $s['row']->id); $sigs = $state['signatures'] ?? collect(); @endphp
<td data-facet-value="{{ $sigs->isEmpty() ? 'none' : ($sigs->contains(fn ($g) => $g->outcome !== 'REFUSED') ? 'endorsed' : 'refused') }}">@forelse($sigs as $g)<div>{{ $g->company_code ?? '—' }} — {{ __('quality_itr.sig_'.$g->outcome) }} <small>{{ $g->signer_name ?? '' }} · {{ $g->signed_at }}</small></div>@empty{{ __('quality_itr.signature_none') }}@endforelse</td><td>@if($pdfAvailable)<a id="pdf-{{ Str::of($s['row']->id)->substr(0,8) }}" href="{{ route('quality.itr.pdf',['projectCode'=>$project->code,'lang'=>$lang,'snapshotId'=>$s['row']->id]) }}">{{ __('quality_itr.export') }}</a>@else—@endif</td></tr>
@endforeach
</tbody></table>
@endif

<h4>{{ __('quality_itr.signatures') }}</h4>
<p>{{ __('quality_itr.signatures_help') }}</p>
@if(! $maySign)
<p id="no-nomination">{{ __('quality_itr.sign_not_nominated') }}</p>
@elseif($signable->isEmpty())
<p id="nothing-signable">{{ __('quality_itr.no_snapshots') }}</p>
@else
<p>{{ __('quality_itr.sign_help', ['company' => $acting->name($lang)]) }}</p>
@foreach($signable as $opt)
<form id="sign-form-{{ Str::of($opt['id'])->substr(0, 8) }}" method="post" action="{{ route('quality.itr.sign', ['projectCode' => $project->code, 'lang' => $lang]) }}">@csrf
<input type="hidden" name="test_pack_id" value="{{ $pack?->id }}"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="snapshot_id" value="{{ $opt['id'] }}">
<input type="hidden" name="previous_signature_id" value="{{ $opt['head_id'] ?? '' }}"><input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}">
<fieldset><legend><span style="font-family:monospace">{{ $opt['compiled_at'] }} · {{ substr($opt['digest'], 0, 16) }}…</span></legend>
@if(! $opt['digest_ok'])
<p id="intact-note-{{ Str::of($opt['id'])->substr(0, 8) }}">{{ __('quality_itr.sign_intact_note') }}</p>
@else
@if(! $opt['is_complete'])<p id="gaps-note-{{ Str::of($opt['id'])->substr(0, 8) }}">{{ __('quality_itr.sign_gaps_note') }}</p>@endif
<label>{{ __('quality_itr.sign_outcome') }} <select name="outcome" id="outcome-{{ Str::of($opt['id'])->substr(0, 8) }}">
@if($opt['is_complete'])<option value="ENDORSED">{{ __('quality_itr.sig_ENDORSED') }}</option>@endif
<option value="ENDORSED_WITH_COMMENTS">{{ __('quality_itr.sig_ENDORSED_WITH_COMMENTS') }}</option>
<option value="REFUSED">{{ __('quality_itr.sig_REFUSED') }}</option>
</select></label>
<label>{{ __('quality_itr.sign_reason') }} <textarea name="reason" rows="2" maxlength="4000" id="reason-{{ Str::of($opt['id'])->substr(0, 8) }}"></textarea></label>
<button type="submit" id="sign-{{ Str::of($opt['id'])->substr(0, 8) }}">{{ __('quality_itr.sign_submit') }}</button>
@if($opt['head_outcome'] !== null)<small>{{ __('quality_itr.signed', ['outcome' => __('quality_itr.sig_'.$opt['head_outcome'])]) }}</small>@endif
@endif
</fieldset></form>
@endforeach
@endif
@endif
</section></div></div></body></html>
