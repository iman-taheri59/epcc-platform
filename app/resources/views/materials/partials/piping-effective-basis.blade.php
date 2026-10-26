<section class="custody-effective-basis"><h3>{{ __('piping_amendments.current') }}</h3>
@if($basis!==null)<p>{{ __('piping_amendments.bound') }}: <code>{{ $basis['amendment_id'] ?? __('piping_amendments.original') }}</code></p><p>{{ __('piping_amendments.heat') }}: {{ $basis['heat_ref'] ?? '—' }}</p>
@if($basis['source_snapshot'])<p>{{ $basis['source_snapshot']['number'] }} · #{{ $basis['source_snapshot']['line_no'] }} · {{ $basis['source_snapshot']['item_code'] }} · {{ $basis['source_snapshot']['unit'] }}</p>@else<p>{{ $basis['unlinked_reason'] }}</p>@endif
@else<p role="alert">{{ __('piping_amendments.hidden') }}</p>@endif
<p>{{ __('piping_amendments.refresh') }}</p><a href="{{ route('materials.piping-custody-amendments',['projectCode'=>$project->code,'receiptId'=>$receipt->id,'lang'=>$lang]) }}">{{ __('piping_amendments.title') }}</a></section>
