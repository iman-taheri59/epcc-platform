@php
$fa = $lang === 'fa';
$status = $revision->standardBasisStatus();
$title = $fa ? 'مبنای استانداردهای نسخه سند' : 'Document revision standard basis';
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $title }}</title>
@include('partials.shell')
<style>main{max-width:1100px;margin:32px auto;padding:20px}nav{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0}table{width:100%;border-collapse:collapse}th,td{text-align:start;padding:12px;border-bottom:1px solid #64748b55}.table-wrap{overflow:auto}input{max-width:180px;padding:8px;color:inherit;background:transparent;border:1px solid #64748b}button{padding:10px 22px;margin:20px 0}small{display:block;line-height:1.8}.notice{padding:16px;border:1px solid #b58946;margin:16px 0}</style>
</head><body><main>
<a href="{{ route('documents', ['projectCode'=>$project->code,'lang'=>$lang]) }}">{{ $fa ? 'بازگشت به دفتر مدارک' : 'Back to document register' }}</a>
<h1>{{ $title }}</h1><p><bdi>{{ $document->number }}</bdi> · {{ $fa ? 'بازنگری' : 'Revision' }} <bdi>{{ $revision->revision }}</bdi></p>
<small>{{ $fa ? 'دامنه فعلی: ارجاع‌های ساختاریافته ITP؛ نه نسخه کامل ITP، متن استاندارد یا داده‌های PMS. ثبت ویرایش منبع به معنی پذیرش قراردادی استاندارد نیست.' : 'Scope: structured ITP references, not the complete ITP, standard text or PMS data. Recording a source edition does not constitute contractual adoption.' }}</small>
<nav aria-label="{{ $fa ? 'بازنگری‌ها' : 'Revisions' }}">@foreach($document->revisions as $r)<a href="{{ route('documents.standard-references',['projectCode'=>$project->code,'documentId'=>$document->id,'revision'=>$r->id,'lang'=>$lang]) }}" @if($r->id === $revision->id) aria-current="page" @endif><bdi>{{ $r->revision }}</bdi> · {{ $r->stateLabel($lang) }}</a>@endforeach</nav>
@if($errors->any())<div role="alert" class="notice">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($status === 'LEGACY_UNCAPTURED')
<div class="notice">{{ __('epcc.standards.history_uncaptured') }}</div>
@elseif($status === 'FROZEN')
<p>{{ $fa ? 'مبنای ثابت هنگام انتشار — فقط خواندنی' : 'Frozen at publication — read only' }}</p>
<div class="table-wrap"><table><thead><tr><th>{{ $fa ? 'ردیف ITP' : 'ITP clause' }}</th><th>{{ $fa ? 'استاندارد، ویرایش و بند' : 'Standard, edition and clause' }}</th></tr></thead><tbody>
@forelse($revision->standard_reference_snapshot['stages'] as $stage)
<tr><td><bdi>{{ $stage['stage_clause'] }}</bdi></td><td>@forelse($stage['citations'] as $citation)<div><bdi>{{ \App\Models\StandardCitation::printFrozen($citation) }}</bdi></div>@empty — @endforelse</td></tr>
@empty<tr><td colspan="2">{{ $fa ? 'در دامنه این ثبت، ارجاع ساختاریافته‌ای وجود نداشت.' : 'No structured references were present within this capture scope.' }}</td></tr>@endforelse
</tbody></table></div>
@else
<div class="notice">{{ $fa ? 'پیش‌نویس؛ مبنا هنوز تثبیت نشده است. ویرایش منبع را صریحاً ثبت کنید. در نبود آن، فقط ویرایش صریح پروژه قابل استفاده است؛ ویرایش کاتالوگ جایگزین نمی‌شود. متن استاندارد حل‌نشده پیش از انتشار باید تعیین تکلیف شود.' : 'Draft: not frozen. Enter source editions explicitly. If absent, only an explicit project edition can be used, never the catalogue fallback. Unresolved standard text must be resolved before publication.' }}</div>
<form method="post" action="{{ route('documents.standard-editions',['projectCode'=>$project->code,'documentId'=>$document->id,'lang'=>$lang]) }}">@csrf<input type="hidden" name="revision_id" value="{{ $revision->id }}">
<div class="table-wrap"><table><thead><tr><th>{{ $fa ? 'ردیف ITP' : 'ITP clause' }}</th><th>{{ $fa ? 'ارجاع' : 'Reference' }}</th><th>{{ $fa ? 'ویرایش منبع' : 'Source edition' }}</th></tr></thead><tbody>
@foreach($stages as $stage)
@if($stage->reference)<tr><td><bdi>{{ $stage->clause }}</bdi></td><td colspan="2"><bdi>{{ $stage->reference }}</bdi> — {{ $fa ? 'حل‌نشده' : 'Unresolved' }}</td></tr>@endif
@foreach($stage->standardCitations as $citation)<tr><td><bdi>{{ $stage->clause }}</bdi></td><td><bdi>{{ $citation->standard->code() }} {{ $citation->clause }}</bdi></td><td>@if($canEdit)<input aria-label="{{ $fa ? 'ویرایش منبع' : 'Source edition' }} {{ $citation->standard->code() }}" name="editions[{{ $citation->id }}]" value="{{ old('editions.'.$citation->id, $citation->source_edition) }}" maxlength="32" dir="auto">@else<bdi>{{ $citation->source_edition ?? '—' }}</bdi>@endif</td></tr>@endforeach
@endforeach
</tbody></table></div>
@if($canEdit && $stages->sum(fn($s) => $s->standardCitations->count()) > 0)<button type="submit">{{ $fa ? 'ثبت ویرایش‌های منبع' : 'Save source editions' }}</button>@endif</form>
@endif
</main></body></html>
