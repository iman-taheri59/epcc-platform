<section class="spec-card" id="review-policy">
<h2><?php echo e($r('workflow')); ?> · <?php echo e($m($family)); ?></h2>
<p class="spec-help"><?php echo e($m('policy_scope')); ?></p>
<p class="spec-help"><?php echo e($r('policy_note')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($reviewPolicy): ?>
<p><?php echo e($r('version')); ?>: <?php echo e($num($reviewPolicy->version)); ?></p>
<ol><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $reviewSteps; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $step): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($step['label']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ol>
<?php else: ?><p class="spec-error"><?php echo e($r('not_configured')); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canConfigure && $canCreate): ?>
<details><summary><?php echo e($r('configure')); ?></summary>
<p class="spec-help"><?php echo e($r('config_note')); ?></p>
<form method="post" action="<?php echo e($action('/review-policy')); ?>" class="spec-grid" id="review-policy-form">
<?php echo csrf_field(); ?><input type="hidden" name="lang" value="<?php echo e($lang); ?>">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i=0;$i<5;$i++): ?>
<label><?php echo e($r('stage')); ?> <?php echo e($num($i+1)); ?>

<select name="stages[<?php echo e($i); ?>]" <?php if($i===0): echo 'required'; endif; ?>>
<option value=""><?php echo e($r('none')); ?></option>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $reviewOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value=>$label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($value); ?>" <?php if(old('stages.'.$i,$reviewSteps[$i]['selector']??'')===$value): echo 'selected'; endif; ?>><?php echo e($label); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</select></label>
<?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<div class="spec-wide"><button class="spec-button"><?php echo e($r('configure')); ?></button></div>
</form></details>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/partials/fitting-review-policy.blade.php ENDPATH**/ ?>