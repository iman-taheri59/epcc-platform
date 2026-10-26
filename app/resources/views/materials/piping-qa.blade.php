<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('piping_qa.title') }} — {{ $project->code }}</title>@include('partials.shell')
<style>.qa-card{max-width:1100px;border:1px solid var(--line);border-radius:12px;padding:20px;margin:18px 0;overflow-wrap:anywhere;background:var(--card)}.qa-card label{display:block;margin:14px 0}.qa-card textarea,.qa-card select{display:block;width:100%;box-sizing:border-box;font:inherit;background:var(--bg);color:var(--text);padding:8px}.qa-card select[multiple]{min-height:150px}.qa-text{white-space:pre-wrap;unicode-bidi:plaintext}.qa-card details{border-top:1px solid var(--line);padding:14px 0}.qa-card code{overflow-wrap:anywhere}</style></head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section class="qa-card">
<h1>{{ __('piping_qa.title') }}</h1><p>CUST-{{ $receipt->sequence }} · {{ $receipt->shipment_ref }} · {{ $receipt->quantity }} {{ $receipt->unit }} · {{ $receipt->heat_ref ?? '—' }}</p>
<p><a href="{{ route('materials.piping-qa',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang==='fa'?'en':'fa']) }}">{{ $lang==='fa'?'English':'فارسی' }}</a> · <a href="{{ route('materials.piping-custody.show',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">{{ __('piping_qa.back') }}</a> · <a href="{{ route('materials.piping-receiving-qc',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">QC</a></p>
@foreach(['boundary','policy','sod'] as $key)<p>{{ __('piping_qa.'.$key) }}</p>@endforeach
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@include('materials.partials.piping-effective-basis')
<h2>{{ __('piping_qa.latest') }}</h2>@if($latest)<p id="piping-qa-latest">#{{ $latest->sequence }} — {{ __('piping_qa.'.$latest->decision) }}</p><p>{{ __('piping_qa.'.($latest->review_id===($review->id??null) && ($latest->amendment_id??null)===$amendmentId?'current':'superseded')) }}</p>@else<p>{{ __('piping_qa.none') }}</p>@endif
@if($writable)
@php
$restore=old('qa_project')===$project->id && old('qa_custody')===$receipt->id;
$safe=fn($key,$default='')=>$restore ? (is_scalar(old('qa.'.$key)) ? (string)old('qa.'.$key) : '') : $default;
$previous=$restore ? $safe('previous_decision_id') : ($latest->id??'');
@endphp
<h2>{{ __('piping_qa.new') }}</h2><form id="piping-qa-form" method="post" action="{{ route('materials.piping-qa.store',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="qa[amendment_id]" value="{{ $safe('amendment_id',$amendmentId??'') }}">
<input type="hidden" name="qa_project" value="{{ $project->id }}"><input type="hidden" name="qa_custody" value="{{ $receipt->id }}">
@foreach(['expected_policy_id'=>$safe('expected_policy_id',$policyId),'previous_decision_id'=>$previous,'review_id'=>$safe('review_id',$review->id),'request_id'=>$safe('request_id',$requestId)] as $key=>$value)<input type="hidden" name="qa[{{ $key }}]" value="{{ $value }}">@endforeach
<p>{{ __('piping_qa.qc') }}: #{{ $review->sequence }} · {{ __('piping_receiving_qc.'.$review->observed_result) }} · <code>{{ $review->id }}</code></p>
<label>{{ __('piping_qa.decision') }}<select required name="qa[decision]"><option value="">—</option>@foreach(['HOLD','REJECTED','ACCEPTED'] as $choice)<option value="{{ $choice }}" @selected($safe('decision')===$choice)>{{ __('piping_qa.'.$choice) }}</option>@endforeach</select></label>
<label>{{ __('piping_qa.reason') }}<textarea required name="qa[reason]" rows="6" maxlength="4000">{{ $safe('reason') }}</textarea></label>
<label>{{ __('piping_qa.open_conditions') }}<textarea name="qa[open_conditions]" rows="3" maxlength="4000">{{ $safe('open_conditions') }}</textarea></label>
<fieldset><legend>{{ __('piping_qa.declarations') }}</legend>@foreach(\App\Services\PipingQaDecisionService::DECLARATIONS as $key)<input type="hidden" name="qa[declarations][{{ $key }}]" value="0"><label><input type="checkbox" name="qa[declarations][{{ $key }}]" value="1" @checked($restore && in_array(old('qa.declarations.'.$key),[true,1,'1'],true))> {{ __('piping_qa.'.$key) }}</label>@endforeach</fieldset>
<p>{{ __('piping_qa.files_help') }}</p>
@if($documentReader)
@foreach(['requirements_file_ids'=>'requirements','evidence_file_ids'=>'evidence'] as $key=>$label)
@php $oldValue=$restore?old('qa.'.$key):null; $selected=is_array($oldValue)?implode("\n",array_filter($oldValue,'is_string')):(is_string($oldValue)?$oldValue:''); @endphp
<label>{{ __('piping_qa.'.$label) }}<textarea dir="ltr" name="qa[{{ $key }}]" rows="3" placeholder="UUID">{{ $selected }}</textarea></label>
@endforeach
<p>{{ __('piping_qa.ids_help') }}</p>
<details><summary>{{ __('piping_qa.catalog') }}</summary><p>{{ __('piping_amendments.bound') }}: <code>{{ $decision->amendment_id ?? __('piping_amendments.original') }}</code></p>
@foreach($fileOptions as $file)<p>{{ $file->number }} · {{ $file->revision }} · {{ $file->original_name }} · {{ $file->cde_state }} {{ $file->suitability }}<br><code>{{ $file->id }}</code><br>SHA256 <code>{{ $file->sha256 }}</code></p>@endforeach
<p>@if($fileOptions->previousPageUrl())<a target="_blank" rel="noopener" href="{{ $fileOptions->previousPageUrl() }}">←</a>@endif {{ $fileOptions->currentPage() }} / {{ $fileOptions->lastPage() }} @if($fileOptions->nextPageUrl())<a target="_blank" rel="noopener" href="{{ $fileOptions->nextPageUrl() }}">→</a>@endif</p></details>
@else<p>{{ __('piping_qa.file_access') }}</p>@endif
<button type="submit" class="btn">{{ __('piping_qa.save') }}</button></form>
@else<p>{{ __('piping_qa.readonly') }}</p>@endif
<h2>{{ __('piping_qa.history') }}</h2><p>{{ __('piping_qa.citation_help') }}</p>
@foreach($decisions as $decision)<details id="piping-qa-{{ $decision->id }}"><summary>#{{ $decision->sequence }} · {{ __('piping_qa.'.$decision->decision) }} · {{ $decision->actor_name }}</summary><p>{{ __('piping_amendments.bound') }}: <code>{{ $decision->amendment_id ?? __('piping_amendments.original') }}</code></p><p>{{ $decision->company_id }} · {{ __('piping_qa.created_at') }} <bdi dir="ltr">{{ \Carbon\CarbonImmutable::parse($decision->created_at)->utc()->format('Y-m-d\TH:i:sP') }}</bdi></p><p>{{ __('piping_qa.qc') }}: <code>{{ $decision->review_id }}</code></p><p class="qa-text">{{ $decision->reason }}</p><p class="qa-text">{{ $decision->open_conditions }}</p>
@foreach(json_decode($decision->declarations,true) as $key=>$confirmed)<p>{{ $confirmed?'✓':'—' }} {{ __('piping_qa.'.$key) }}</p>@endforeach
@if($amendedSourceBases->has($decision->id))<h4>{{ __('piping_custody.source_snapshot') }}</h4>@php $qaSource=json_decode($amendedSourceBases->get($decision->id)->snapshot,true); @endphp @foreach($qaSource as $key=>$value)<p>{{ $key }}: {{ $value??'—' }}</p>@endforeach @endif
@foreach($citations->get($decision->id,collect()) as $citation)@php $snap=json_decode($citation->snapshot,true); @endphp<p>{{ __('piping_qa.'.($citation->kind==='REQUIREMENT'?'requirements':'evidence')) }}: {{ $snap['number'] }} · {{ $snap['revision'] }} · {{ $snap['original_name'] }} · {{ $snap['cde_state'] }} {{ $snap['suitability'] }}<br><code>{{ $snap['revision_id'] }} · {{ $snap['id'] }} · SHA256 {{ $snap['sha256'] }} · {{ $snap['size_bytes'] }} bytes</code></p>@endforeach
</details>@endforeach
{{ $decisions->links() }}</section></div></div></body></html>
