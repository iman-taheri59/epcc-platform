<!doctype html><html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('quality_nominations.title') }}</title>@include('partials.shell')</head><body><div class="app">@include('partials.sidebar')<div class="wrap">@include('partials.topbar')<section style="max-width:1100px;padding:20px;overflow-wrap:anywhere">
<h1>{{ __('quality_nominations.title') }}</h1>
<p>{{ __('quality_nominations.boundary') }}</p>
<p><a href="{{ route('quality.nominations',['projectCode'=>$project->code,'lang'=>$lang==='fa'?'en':'fa']) }}">{{ $lang==='fa'?'English':'فارسی' }}</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

<h2>{{ __('quality_nominations.your_authority') }}</h2>
{{-- data-tt opts this table into the shared filter/sort toolbar; without it TableToolsTest fails the build. --}}
<table data-tt border="1" cellpadding="6" cellspacing="0"><thead><tr><th data-sort="text" data-facet>{{ __('quality_nominations.action') }}</th><th data-sort="text" data-facet>{{ __('quality_nominations.you') }}</th></tr></thead><tbody>
@foreach($actions as $action)
<tr><td>{{ __('quality_nominations.action_'.$action) }}</td><td id="nominated-{{ $action }}" data-facet-value="{{ $nominations[$action] ? __('quality_nominations.nominated') : __('quality_nominations.not_nominated') }}">{{ $nominations[$action] ? __('quality_nominations.nominated') : __('quality_nominations.not_nominated') }}@if($nominations[$action]) <code style="font-size:80%">{{ $nominations[$action] }}</code>@endif</td></tr>
@endforeach
</tbody></table>

<h2>{{ __('quality_nominations.register') }}</h2>
@if($current->isEmpty())
<p id="register-empty">{{ __('quality_nominations.none') }}</p>
@else
<table data-tt border="1" cellpadding="6" cellspacing="0" id="register"><thead><tr>
<th data-sort="text" data-facet>{{ __('quality_nominations.company') }}</th><th data-sort="text" data-facet>{{ __('quality_nominations.action') }}</th><th data-sort="num">{{ __('quality_nominations.version') }}</th><th data-sort="text" data-facet>{{ __('quality_nominations.state') }}</th><th>{{ __('quality_nominations.selectors') }}</th><th>{{ __('quality_nominations.reason') }}</th>
</tr></thead><tbody>
@foreach($current as $row)
<tr><td>{{ $companies[$row->target_company_id]->code ?? $row->target_company_id }}</td><td>{{ __('quality_nominations.action_'.$row->action) }}</td><td>{{ $row->version }}</td><td>{{ $row->suspended ? __('quality_nominations.suspended') : __('quality_nominations.active') }}</td><td><code style="font-size:80%">{{ implode(', ', (array) json_decode($row->selectors, true)) }}</code></td><td>{{ $row->reason }}</td></tr>
@endforeach
</tbody></table>
@endif

@if(! $writable)
<p>{{ __('quality_nominations.readonly') }}</p>
@else
<h2>{{ __('quality_nominations.new') }}</h2>
<p>{{ __('quality_nominations.who_may') }}</p>
<form id="nomination-form" method="post" action="{{ route('quality.nominations.store',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="policy[suspend]" value="0">
<p><label>{{ __('quality_nominations.company') }}
<select name="policy[target_company_id]" required class="pair" data-pair="nominate">@foreach($companies as $id=>$company)@if($mayNominateFor[$id] ?? false)<option value="{{ $id }}">{{ $company->code }} — {{ $company->name_en ?? $company->name_fa ?? '' }}</option>@endif @endforeach</select></label>
<label>{{ __('quality_nominations.action') }}
<select name="policy[action]" required class="pair" data-pair="nominate">@foreach($actions as $action)<option value="{{ $action }}">{{ __('quality_nominations.action_'.$action) }}</option>@endforeach</select></label>
{{ __('quality_nominations.version') }} <output id="version-out-nominate">0</output><input type="hidden" name="policy[version]" value="0" id="version-nominate"></p>
<fieldset><legend>{{ __('quality_nominations.selectors') }}</legend>
@foreach($options as $selector=>$label)
<label style="display:block"><input type="checkbox" name="policy[selectors][]" value="{{ $selector }}"> {{ $label }}</label>
@endforeach
</fieldset>
<label style="display:block">{{ __('quality_nominations.reason') }}<textarea required maxlength="2000" name="policy[reason]" style="display:block;width:100%"></textarea></label>
<button type="submit" id="nominate">{{ __('quality_nominations.save') }}</button>
</form>

<h2>{{ __('quality_nominations.suspend_title') }}</h2>
<p>{{ __('quality_nominations.suspend_help') }}</p>
<form id="suspend-form" method="post" action="{{ route('quality.nominations.store',['projectCode'=>$project->code,'lang'=>$lang]) }}">@csrf
<input type="hidden" name="policy[suspend]" value="1">
<p><label>{{ __('quality_nominations.company') }}
<select name="policy[target_company_id]" required class="pair" data-pair="suspend">@foreach($current as $row)<option value="{{ $row->target_company_id }}">{{ $companies[$row->target_company_id]->code ?? $row->target_company_id }} — {{ __('quality_nominations.action_'.$row->action) }}</option>@endforeach</select></label>
<input type="hidden" name="policy[action]" id="action-suspend" value="">
{{ __('quality_nominations.version') }} <output id="version-out-suspend">0</output><input type="hidden" name="policy[version]" value="0" id="version-suspend"></p>
<label style="display:block">{{ __('quality_nominations.reason') }}<textarea required maxlength="2000" name="policy[reason]" style="display:block;width:100%"></textarea></label>
<button type="submit" id="suspend">{{ __('quality_nominations.suspend') }}</button>
</form>

<script>
  var versions = {!! json_encode($versions, JSON_UNESCAPED_UNICODE) !!};
  function key(company, action) { return company + '|' + action; }
  function sync(which) {
    var company = document.querySelector('select.pair[data-pair="' + which + '"]');
    var action = which === 'suspend' ? document.getElementById('action-suspend') : document.querySelector('form#nomination-form select[name="policy[action]"]');
    var k = key(company.value, action.value);
    var v = Object.prototype.hasOwnProperty.call(versions, k) ? versions[k] : 0;
    document.getElementById('version-' + which).value = v;
    document.getElementById('version-out-' + which).textContent = v;
  }
  ['nominate', 'suspend'].forEach(function (which) {
    var company = document.querySelector('select.pair[data-pair="' + which + '"]');
    if (!company) { return; }
    company.addEventListener('change', function () {
      if (which === 'suspend') {
        var row = {!! json_encode($current->map(fn ($r) => ['company' => $r->target_company_id, 'action' => $r->action])->values()->all()) !!}[company.selectedIndex];
        if (row) { document.getElementById('action-suspend').value = row.action; }
      }
      sync(which);
    });
    if (which === 'nominate') {
      document.querySelector('form#nomination-form select[name="policy[action]"]').addEventListener('change', function () { sync('nominate'); });
    }
    if (which === 'suspend') {
      var first = {!! json_encode($current->map(fn ($r) => ['company' => $r->target_company_id, 'action' => $r->action])->values()->all()) !!}[0];
      if (first) { document.getElementById('action-suspend').value = first.action; }
    }
    sync(which);
  });
</script>
@endif
</section></div></div></body></html>
