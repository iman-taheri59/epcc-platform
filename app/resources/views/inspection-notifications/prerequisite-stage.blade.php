<aside id="inspection-prerequisite-stage" style="border:1px solid #b8c4d5;padding:14px;margin:16px 0;overflow-wrap:anywhere">
<h3>{{ __('inspection_notifications.prerequisite_itp_title') }}</h3>
<p>{{ __('inspection_notifications.prerequisite_itp_hint') }}</p>
@if($prerequisiteStage!==null)
<dl>
<dt>{{ __('inspection_notifications.prerequisite_itp_document') }}</dt><dd>{{ $prerequisiteStage['document_number'] }} / <bdi dir="ltr">{{ $prerequisiteStage['document_id'] }}</bdi></dd>
<dt>{{ __('inspection_notifications.prerequisite_itp_revision') }}</dt><dd>{{ $prerequisiteStage['revision'] }} / <bdi dir="ltr">{{ $prerequisiteStage['revision_id'] }}</bdi></dd>
<dt>{{ __('inspection_notifications.prerequisite_itp_stage') }}</dt><dd><bdi dir="ltr">{{ $prerequisiteStage['stage_sequence'] }} / {{ $prerequisiteStage['point'] }} / {{ $prerequisiteStage['stage_id'] }}</bdi></dd>
@php
$recordedDescription=$prerequisiteStage['description_'.$lang];
if ($recordedDescription===null || preg_match('/\S/u',$recordedDescription)!==1) {
    $recordedDescription=is_string($prerequisiteStage['description_en']) && preg_match('/\S/u',$prerequisiteStage['description_en'])===1 ? $prerequisiteStage['description_en'] : $prerequisiteStage['description_fa'];
}
@endphp
<dd style="white-space:pre-wrap;unicode-bidi:plaintext">{{ $recordedDescription }}</dd>
</dl>
@else
<p role="status">{{ __('inspection_notifications.prerequisite_itp_unknown') }}</p>
@endif
</aside>
