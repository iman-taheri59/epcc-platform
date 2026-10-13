<section aria-label="<?php echo e($t('units_title')); ?>" data-pms-measurements>
<h3><?php echo e($t('units_title')); ?></h3><p class="pms-help"><?php echo e($t('units_display_help')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $measurements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field=>$measurement): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<div style="border-top:1px solid var(--line);padding:12px 0" data-measurement-summary="<?php echo e($field); ?>">
<h4><?php echo e($t($field)); ?></h4>
<dl class="pms-pairs">
<dt><?php echo e($t('units_entered')); ?></dt><dd><bdi><?php echo e($measurement['value'] !== null ? $measurement['value'].' '.$measurement['unit'] : '—'); ?></bdi></dd>
<dt><?php echo e($t('units_canonical')); ?></dt><dd><bdi><?php echo e($measurement['canonical'] !== null ? $measurement['canonical'].' '.$measurement['canonical_unit'] : '—'); ?></bdi></dd>
<dt><?php echo e($t('units_preferred')); ?></dt><dd><bdi><?php echo e($measurement['preferred_value'] !== null ? $measurement['preferred_value'].' '.$measurement['preferred_unit'] : '—'); ?></bdi></dd>
</dl>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($measurement['legacy']): ?><p class="pms-warning"><?php echo e($t('units_legacy')); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/pms-measurement-summary.blade.php ENDPATH**/ ?>