<?php $state=$reviewStates[$row->id]; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row->revises_id): ?>
<p><?php echo e($r('parent')); ?>: <bdi><?php echo e($row->revises_id); ?></bdi></p>
<p class="spec-source"><?php echo e($r('correction_reason')); ?>: <?php echo e($row->correction_reason); ?></p>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state['successor']): ?><p><?php echo e($r('successor')); ?>: <bdi><?php echo e($state['successor']); ?></bdi></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state['run']): ?>
<p><?php echo e($r('version')); ?>: <?php echo e($num($state['policy']->version)); ?></p>
<ol><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $state['steps']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $step): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($step['label']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ol>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state['state']==='IN_REVIEW'): ?><p><?php echo e($r('current_step')); ?>: <?php echo e($num(count($state['decisions'])+1)); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state['decisions']): ?>
<h4><?php echo e($r('history')); ?></h4>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $state['decisions']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $decision): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<p><?php echo e($r('stage')); ?> <?php echo e($num($decision->stage)); ?> · <?php echo e($decision->actor_name); ?> · <?php echo e($r($decision->decision)); ?> · <?php echo e(\App\Support\JalaliDate::format(\Carbon\Carbon::parse($decision->created_at),$lang)); ?></p>
<p class="spec-source" dir="auto"><?php echo e($decision->comment); ?></p>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canCreate): ?>
<div class="spec-meta" style="margin-top:16px">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row->company_id===$user->company_id && !$state['successor']): ?>
<a href="<?php echo e($base.'?'.http_build_query($q+['correct'=>$row->id])); ?>"><?php echo e($r('correct')); ?></a>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state['state']==='UNREVIEWED' && $reviewPolicy): ?>
<form method="post" action="<?php echo e($action('/'.$row->id.'/submit')); ?>" class="review-submit"><?php echo csrf_field(); ?><input type="hidden" name="lang" value="<?php echo e($lang); ?>"><button class="spec-button"><?php echo e($r('submit')); ?></button></form>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state['can_decide']): ?>
<form method="post" action="<?php echo e($action('/'.$row->id.'/decide')); ?>" class="spec-grid review-decision" style="margin-top:16px">
<?php echo csrf_field(); ?><input type="hidden" name="lang" value="<?php echo e($lang); ?>"><input type="hidden" name="stage" value="<?php echo e(count($state['decisions'])+1); ?>">
<label><?php echo e($r('current_step')); ?><select name="decision"><option value="APPROVE"><?php echo e($r('APPROVE')); ?></option><option value="RETURN"><?php echo e($r('RETURN')); ?></option></select></label>
<label class="spec-wide"><?php echo e($r('comment')); ?><textarea name="comment" required maxlength="4000"><?php echo e(old('comment')); ?></textarea></label>
<div><button class="spec-button"><?php echo e($r('save_decision')); ?></button></div>
</form>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/partials/fitting-review-actions.blade.php ENDPATH**/ ?>