{{--
  Inspection Release Note (تأییدیهٔ آزادسازیِ بازرسی) — ADR-060.

  One form per order line, because a release is per LINE: an order whose valves
  were released says nothing about its gaskets. No JavaScript — the line is
  carried by the route, so each form posts to its own endpoint.

  The inspector recorded here is the signed-in nominated issuer. An external
  inspector (a person at an inspection firm who will never hold an account) is
  supported by the service and the guard, but naming one needs a company
  picker this screen does not have yet — recorded as the next increment.
--}}
@php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
@endphp
@if($canIssueReleaseNote ?? false)
<section class="po-note" aria-labelledby="release-note-title">
<h2 id="release-note-title">{{ __('inspection_release_notes.title') }}</h2>
<p class="help">{{ __('inspection_release_notes.scope') }}</p>
<p class="help">{{ __('inspection_release_notes.independence') }}</p>

@foreach($order->items as $line)
@php $prefix = 'release'; @endphp
<details class="po-note" @if(old('release.line_id') === $line->id) open @endif>
<summary>{{ __('inspection_release_notes.line') }} {{ $line->line_no }} · {{ $line->item_code }} · {{ $line->description_en }}</summary>
<form method="post" action="{{ route('procurement.orders.release-notes.create', ['projectCode'=>$project->code,'orderId'=>$order->id,'lineId'=>$line->id,'lang'=>$lang]) }}">
@csrf
<input type="hidden" name="release[inspector_user_id]" value="{{ $user->id }}">
<div class="po-grid">
<label>{{ __('inspection_release_notes.stage') }}
<select name="release[stage]" required>
<option value="">—</option>
@foreach(['WORKS','PRE_SHIPMENT','ON_ARRIVAL'] as $stage)
<option value="{{ $stage }}" @selected($safeOld('release.stage') === $stage)>{{ \App\Support\ProcurementState::stageLabel($stage)[$lang] }}</option>
@endforeach
</select></label>

<label>{{ __('inspection_release_notes.outcome') }}
<select name="release[outcome]" required>
<option value="">—</option>
@foreach(['RELEASED','RELEASED_WITH_HOLDS','NOT_RELEASED'] as $outcome)
<option value="{{ $outcome }}" @selected($safeOld('release.outcome') === $outcome)>{{ __('inspection_release_notes.outcome_'.$outcome) }}</option>
@endforeach
</select></label>

<label>{{ __('inspection_release_notes.date') }}
<input type="date" name="release[released_at]" required value="{{ $safeOld('release.released_at') }}"></label>
</div>

@foreach(['en','fa'] as $fLang)
<label style="display:block">{{ __('inspection_release_notes.findings_'.$fLang) }}
<textarea name="release[findings_{{ $fLang }}]" rows="3" maxlength="8000">{{ $safeOld('release.findings_'.$fLang) }}</textarea></label>
@endforeach

@foreach(['en','fa'] as $hLang)
<label style="display:block">{{ __('inspection_release_notes.holds_'.$hLang) }}
<textarea name="release[holds_note_{{ $hLang }}]" rows="2" maxlength="8000">{{ $safeOld('release.holds_note_'.$hLang) }}</textarea></label>
@endforeach

<p class="help">{{ __('inspection_release_notes.findings_rule') }}</p>
<button class="btn" type="submit">{{ __('inspection_release_notes.save') }}</button>
</form>
</details>
@endforeach
</section>
@endif
