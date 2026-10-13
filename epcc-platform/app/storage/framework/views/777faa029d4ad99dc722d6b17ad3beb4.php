<div class="pms-warning"><p><bdi><?php echo e($finding['code']); ?></bdi> · <?php echo e($lang==='fa'?($finding['severity']==='DISCREPANCY'?'مغایرت':'اطلاعات ناقص'):$finding['severity']); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($lang==='fa'): ?>
<p><?php echo e(\Illuminate\Support\Facades\Lang::has('pms_findings.'.$finding['code'],'fa')?__('pms_findings.'.$finding['code'],[],'fa'):'این مورد نیازمند بررسی است؛ جزئیات فنی را مشاهده کنید.'); ?></p>
<details><summary>جزئیات فنی مرجع (English)</summary><p dir="ltr"><?php echo e($finding['message']); ?></p></details>
<?php else: ?><p><?php echo e($finding['message']); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/partials/pms-finding.blade.php ENDPATH**/ ?>