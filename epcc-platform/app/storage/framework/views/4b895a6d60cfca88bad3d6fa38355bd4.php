<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($screenings[$row->id])): ?>
<?php $screen=$screenings[$row->id]; $s=fn($key)=>__('material_screening.'.$key,[],$lang); ?>
<section class="spec-card" data-screening="<?php echo e($row->id); ?>" aria-label="<?php echo e($s('title')); ?>">
<h4><?php echo e($s('current_title')); ?> · <?php echo e($s($screen['status'])); ?></h4>
<p class="spec-help"><?php echo e($s('notice')); ?> <?php echo e($s('current_note')); ?></p>
<?php echo $__env->make('piping.partials.material-screening-findings', ['isHistorical'=>false], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></section>
<?php $historical=$reviewStates[$row->id]['screeningSnapshot']; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($historical): ?>
<?php $screen=$historical['result']; ?>
<section class="spec-card" data-screening-snapshot="<?php echo e($historical['record']->run_id); ?>" data-result-sha256="<?php echo e($historical['record']->result_sha256); ?>">
<h4><?php echo e($s('snapshot_title')); ?> · <?php echo e($s($screen['status'])); ?></h4>
<p class="spec-help"><?php echo e($s('snapshot_note')); ?></p>
<p><?php echo e($s('submitted_at')); ?>: <?php echo e(\App\Support\JalaliDate::formatWithTime(\Carbon\Carbon::parse($historical['record']->created_at)->utc(),$lang)); ?> UTC</p>
<?php echo $__env->make('piping.partials.material-screening-findings', ['isHistorical'=>true], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<details><summary><?php echo e($s('snapshot_identity')); ?></summary>
<p class="spec-source"><?php echo e($s('run')); ?>: <bdi><?php echo e($historical['record']->run_id); ?></bdi><br>
<?php echo e($s('definition')); ?>: <bdi><?php echo e($historical['record']->definition_id); ?></bdi><br>
<?php echo e($s('submitter')); ?>: <bdi><?php echo e($historical['record']->created_by); ?></bdi><br>
<?php echo e($s('result_hash')); ?>: <bdi><?php echo e($historical['record']->result_sha256); ?></bdi><br>
<?php echo e($s('implementation_hash')); ?>: <bdi><?php echo e($historical['record']->implementation_sha256); ?></bdi></p>
<pre class="spec-source" dir="ltr"><?php echo e(json_encode($historical['input'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre>
</details></section>
<?php elseif($reviewStates[$row->id]['run']): ?>
<p class="spec-help" data-screening-snapshot-missing><?php echo e($s('legacy_missing')); ?></p>
<?php else: ?>
<p class="spec-help"><?php echo e($s('capture_on_submit')); ?></p>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/partials/material-screening.blade.php ENDPATH**/ ?>