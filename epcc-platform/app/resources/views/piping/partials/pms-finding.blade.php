<div class="pms-warning"><p><bdi>{{ $finding['code'] }}</bdi> · {{ $lang==='fa'?($finding['severity']==='DISCREPANCY'?'مغایرت':'اطلاعات ناقص'):$finding['severity'] }}</p>
@if($lang==='fa')
<p>{{ \Illuminate\Support\Facades\Lang::has('pms_findings.'.$finding['code'],'fa')?__('pms_findings.'.$finding['code'],[],'fa'):'این مورد نیازمند بررسی است؛ جزئیات فنی را مشاهده کنید.' }}</p>
<details><summary>جزئیات فنی مرجع (English)</summary><p dir="ltr">{{ $finding['message'] }}</p></details>
@else<p>{{ $finding['message'] }}</p>@endif
</div>
