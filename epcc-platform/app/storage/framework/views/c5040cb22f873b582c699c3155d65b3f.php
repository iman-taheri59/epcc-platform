<ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $screen['findings']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $findingIndex=>$finding): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<li data-finding="<?php echo e($finding['code']); ?>"><strong><?php echo e($s($finding['severity'])); ?></strong>: <?php echo e($finding['message'][$lang]); ?>

<br><small><bdi><?php echo e($finding['field']); ?></bdi></small>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isHistorical??false): ?>
<?php echo $__env->make('piping.partials.material-finding-responses', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</li>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
<details><summary><?php echo e($s('evidence')); ?></summary>
<p class="spec-source"><bdi><?php echo e($screen['ruleset']); ?></bdi><br><bdi><?php echo e($screen['input_sha256']); ?></bdi></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $screen['references']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reference): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<p class="spec-source"><bdi><?php echo e($reference['ref_id']); ?> · <?php echo e($reference['version']); ?></bdi><br><?php echo e($reference['source']); ?><br><bdi><?php echo e($reference['sha256']); ?></bdi></p>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</details>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/partials/material-screening-findings.blade.php ENDPATH**/ ?>