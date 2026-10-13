<ul>@foreach($screen['findings'] as $findingIndex=>$finding)
<li data-finding="{{ $finding['code'] }}"><strong>{{ $s($finding['severity']) }}</strong>: {{ $finding['message'][$lang] }}
<br><small><bdi>{{ $finding['field'] }}</bdi></small>
@if($isHistorical??false)
@include('piping.partials.material-finding-responses')
@endif
</li>
@endforeach</ul>
<details><summary>{{ $s('evidence') }}</summary>
<p class="spec-source"><bdi>{{ $screen['ruleset'] }}</bdi><br><bdi>{{ $screen['input_sha256'] }}</bdi></p>
@foreach($screen['references'] as $reference)
<p class="spec-source"><bdi>{{ $reference['ref_id'] }} · {{ $reference['version'] }}</bdi><br>{{ $reference['source'] }}<br><bdi>{{ $reference['sha256'] }}</bdi></p>
@endforeach
</details>
