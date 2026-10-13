<?php
$ndtTarget=$class->id.':'.($ndtEditing?->id??'new');
$ndtValue=function($field,$fallback=null)use($ndtTarget){$value=old('ndt.target')===$ndtTarget?old('ndt.'.$field,$fallback):$fallback;return is_scalar($value)||$value===null?$value:$fallback;};
?>
<section class="pms-card" id="pms-ndt">
<h2><?php echo e($t('ndt_title')); ?></h2>
<p class="pms-help"><?php echo e($t('ndt_scope')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canWrite && (!$ndtEditing || $ndtEditing->company_id===$user->company_id)): ?>
<details <?php if($ndtEditing || $errors->has('ndt.*') || $errors->has('ndt')): ?> open <?php endif; ?>><summary><?php echo e($t($ndtEditing?'ndt_edit':'ndt_add')); ?></summary>
<p class="pms-help"><?php echo e($t('ndt_help')); ?></p>
<form method="post" action="<?php echo e($url('/'.$class->id.'/ndt')); ?>" id="pms-ndt-form"><?php echo csrf_field(); ?>
<input type="hidden" name="ndt[target]" value="<?php echo e($ndtTarget); ?>">
<input type="hidden" name="ndt[id]" value="<?php echo e($ndtEditing?->id); ?>">
<input type="hidden" name="ndt[version]" value="<?php echo e($ndtValue('version',$class->entry_version)); ?>">
<div class="pms-grid">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = \App\Models\PipingClassNdtRequirement::FIELDS; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field=>$limit): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<label class="<?php echo \Illuminate\Support\Arr::toCssClasses(['pms-wide'=>$field!=='method']); ?>"><?php echo e($t('ndt_'.$field)); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(in_array($field,\App\Models\PipingClassNdtRequirement::REQUIRED,true)): ?> * <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<textarea name="ndt[<?php echo e($field); ?>]" rows="<?php echo e($field==='method'?1:2); ?>" maxlength="<?php echo e($limit); ?>" dir="auto" <?php if(in_array($field,\App\Models\PipingClassNdtRequirement::REQUIRED,true)): echo 'required'; endif; ?>><?php echo e($ndtValue($field,$ndtEditing?->{$field})); ?></textarea></label>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<label class="pms-wide"><?php echo e($t('reason')); ?> *<textarea name="ndt[reason]" maxlength="4000" rows="2" required><?php echo e($ndtValue('reason')); ?></textarea></label>
</div><button class="pms-save"><?php echo e($t('save')); ?></button>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ndtEditing): ?><a href="<?php echo e($url('/'.$class->id)); ?>#pms-ndt"><?php echo e($t('ndt_cancel')); ?></a><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</form></details>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $class->ndtRequirements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $requirement): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
<article data-ndt-requirement="<?php echo e($requirement->id); ?>" style="border-top:1px solid var(--line);margin-top:18px;padding-top:12px">
<h3><bdi><?php echo e($requirement->method); ?></bdi></h3>
<p class="pms-help"><bdi><?php echo e($requirement->id); ?></bdi></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canWrite && $requirement->company_id===$user->company_id): ?><a href="<?php echo e($url('/'.$class->id,['ndt'=>$requirement->id])); ?>#pms-ndt"><?php echo e($t('ndt_edit')); ?></a><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($requirement->missingDetails()): ?><p class="pms-warning" data-ndt-incomplete><?php echo e($t('ndt_missing')); ?>: <?php echo e(implode('، ',array_map(fn($field)=>$t('ndt_'.$field),$requirement->missingDetails()))); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<dl class="pms-pairs"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = \App\Models\PipingClassNdtRequirement::FIELDS; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field=>$limit): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><dt><?php echo e($t('ndt_'.$field)); ?></dt><dd class="pms-source" dir="auto"><?php echo e($requirement->{$field}??$t('ndt_not_recorded')); ?></dd><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></dl>
</article>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p class="pms-help"><?php echo e($t('ndt_empty')); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/pms-ndt.blade.php ENDPATH**/ ?>