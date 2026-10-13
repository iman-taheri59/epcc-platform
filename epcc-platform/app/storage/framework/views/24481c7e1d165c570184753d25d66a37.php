<div id="pms-special-summary" class="pms-wide">
<h3><?php echo e($t('special_title')); ?></h3>
<p class="pms-help"><?php echo e($t('special_scope')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$specialValid): ?>
<p class="pms-warning"><?php echo e($t('special_legacy')); ?></p>
<pre dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo e($pretty($specialRaw)); ?></pre>
<?php elseif($specialEntries===[]): ?>
<p class="pms-help"><?php echo e($t('special_empty')); ?></p>
<?php else: ?>
<ol><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $specialEntries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $entry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo e($entry); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ol>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/pms-special-summary.blade.php ENDPATH**/ ?>