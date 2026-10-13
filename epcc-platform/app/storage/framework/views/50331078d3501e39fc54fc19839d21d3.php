<section class="pms-card" aria-labelledby="pms-access-title">
<h2 id="pms-access-title"><?php echo e($t('access_title')); ?></h2>
<p class="pms-help"><?php echo e($t('access_scope')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$authorAccess['current']): ?>
<p class="pms-warning" data-pms-access="unconfigured"><?php echo e($t('access_unconfigured')); ?></p>
<?php elseif($authorAccess['selectors'] === []): ?>
<p class="pms-warning" data-pms-access="suspended"><?php echo e($t('access_suspended')); ?></p>
<?php else: ?>
<p data-pms-access="configured"><?php echo e($t('access_configured')); ?> · <?php echo e($t('access_version')); ?> <?php echo e($authorAccess['current']->version); ?></p>
<ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $authorAccess['selectors']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $selection): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($selection['label']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($authorAccess['canConfigure']): ?>
<details <?php if(!$authorAccess['current'] || $errors->has('pms_access')): ?> open <?php endif; ?>><summary><?php echo e($t('access_configure')); ?></summary>
<form method="post" action="<?php echo e($url('/access')); ?>" id="pms-access-form"><?php echo csrf_field(); ?>
<input type="hidden" name="access[version]" value="<?php echo e($authorAccess['current']->version ?? 0); ?>">
<div class="pms-grid"><label class="pms-wide"><?php echo e($t('access_selectors')); ?>

<select name="access[selectors][]" multiple size="8" aria-describedby="pms-access-selector-help">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $authorAccess['options']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value=>$label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($value); ?>" <?php if(in_array($value,array_column($authorAccess['selectors'],'selector'),true)): echo 'selected'; endif; ?>><?php echo e($label); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</select></label>
<p class="pms-help pms-wide" id="pms-access-selector-help"><?php echo e($t('access_selector_help')); ?></p>
<label class="pms-wide"><?php echo e($t('reason')); ?><textarea name="access[reason]" rows="2" required maxlength="4000"></textarea></label>
<div class="pms-wide"><input type="hidden" name="access[suspend]" value="0"><label><span><input type="checkbox" name="access[suspend]" value="1" style="width:auto"> <?php echo e($t('access_suspend')); ?></span></label></div>
</div><button class="pms-save" type="submit"><?php echo e($t('access_save')); ?></button>
</form></details>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($authorAccess['history']->isNotEmpty()): ?>
<details><summary><?php echo e($t('access_history')); ?></summary><p class="pms-help"><?php echo e($t('access_history_limit')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $authorAccess['history']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $policy): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<details><summary><?php echo e($t('access_version')); ?> <?php echo e($policy->version); ?> · <?php echo e($policy->actor_name); ?> · <?php echo e($policy->created_at); ?> UTC</summary>
<p class="pms-source"><?php echo e($policy->reason); ?></p>
<ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = json_decode($policy->selectors,true); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $selection): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><li><?php echo e($selection['label']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><li><?php echo e($t('access_suspended')); ?></li><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
</details>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></details>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/pms-author-access.blade.php ENDPATH**/ ?>