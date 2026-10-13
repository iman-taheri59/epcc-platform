<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($note->kind!=='REVIEW_NOTE'): ?>
<?php
    $fd=fn($key)=>__('finding_dispositions.'.$key,[],$lang);
    $dispositionHistory=$note->dispositions??[];
    $latestDisposition=$dispositionHistory ? $dispositionHistory[array_key_last($dispositionHistory)] : null;
    $oldDisposition=old('finding_disposition.response_id')===$note->id;
    $dv=function($key,$default=null)use($oldDisposition){$v=$oldDisposition?old('finding_disposition.'.$key,$default):$default;return is_scalar($v)||$v===null?$v:$default;};
?>
<section class="finding-disposition" data-disposition-response="<?php echo e($note->id); ?>" style="border-inline-start:3px solid #8d78d8;padding-inline-start:12px;margin-top:16px">
<h5><?php echo e($fd('title')); ?></h5>
<p class="spec-help"><?php echo e($fd('notice')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latestDisposition): ?>
<p data-current-disposition="<?php echo e($latestDisposition->kind); ?>" data-disposition-id="<?php echo e($latestDisposition->id); ?>"><strong><?php echo e($fd('current')); ?>: <?php echo e($fd($latestDisposition->kind)); ?></strong> · <?php echo e($fd('sequence')); ?> <?php echo e($num($latestDisposition->sequence)); ?></p>
<details><summary><?php echo e($fd('history')); ?></summary>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $dispositionHistory; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $decision): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<div data-disposition-event="<?php echo e($decision->id); ?>" style="border-top:1px solid var(--line);padding:10px 0">
<p><strong><?php echo e($fd('sequence')); ?> <?php echo e($num($decision->sequence)); ?> · <?php echo e($fd($decision->kind)); ?></strong></p>
<p><?php echo e($decision->actor_name); ?> · <?php echo e($fd('stage')); ?> <?php echo e($num($decision->review_stage)); ?> · <?php echo e(\App\Support\JalaliDate::formatWithTime(\Carbon\Carbon::parse($decision->created_at)->utc(),$lang)); ?> UTC</p>
<p class="spec-source" dir="auto"><?php echo e($decision->rationale); ?></p>
<p><?php echo e($fd('spec_number')); ?>: <bdi><?php echo e($decision->spec_number); ?></bdi> · <?php echo e($fd('spec_revision')); ?>: <bdi><?php echo e($decision->spec_revision); ?></bdi> · <?php echo e($fd('spec_clause')); ?>: <bdi><?php echo e($decision->spec_clause); ?></bdi></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($decision->source_quote): ?><blockquote class="spec-source" dir="auto"><?php echo e($decision->source_quote); ?></blockquote><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($decision->supersedes_id): ?><p class="spec-source"><?php echo e($fd('supersedes_id')); ?>: <bdi><?php echo e($decision->supersedes_id); ?></bdi><br><?php echo e($fd('change_reason')); ?>: <?php echo e($decision->change_reason); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></details>
<?php else: ?><p><?php echo e($fd('pending')); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canCreate && $responseContext['role']==='REVIEWER' && $note->created_by!==$user->id): ?>
<details class="disposition-editor"><summary><?php echo e($fd($latestDisposition?'replace':'new')); ?></summary>
<p class="spec-help"><?php echo e($fd('citation_note')); ?></p>
<form method="post" action="<?php echo e($action('/'.$row->id.'/finding-responses/'.$note->id.'/dispositions')); ?>" class="spec-grid finding-disposition-form">
<?php echo csrf_field(); ?><input type="hidden" name="lang" value="<?php echo e($lang); ?>">
<input type="hidden" name="finding_disposition[response_id]" value="<?php echo e($note->id); ?>">
<input type="hidden" name="finding_disposition[run_id]" value="<?php echo e($note->run_id); ?>">
<input type="hidden" name="finding_disposition[finding_key]" value="<?php echo e($note->finding_key); ?>">
<input type="hidden" name="finding_disposition[request_key]" value="<?php echo e($dv('request_key',(string)\Illuminate\Support\Str::uuid())); ?>">
<input type="hidden" name="finding_disposition[supersedes_id]" value="<?php echo e($dv('supersedes_id',$latestDisposition?->id)); ?>">
<label><?php echo e($fd('kind')); ?><select name="finding_disposition[kind]" required><option value=""><?php echo e($fd('choose')); ?></option>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = \App\Services\Engineering\MaterialFindingDispositions::options($note->kind); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kind): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($kind); ?>" <?php if($dv('kind')===$kind): echo 'selected'; endif; ?>><?php echo e($fd($kind)); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></select></label>
<label class="spec-wide"><?php echo e($fd('rationale')); ?><textarea name="finding_disposition[rationale]" rows="3" required maxlength="4000"><?php echo e($dv('rationale')); ?></textarea></label>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['spec_number'=>120,'spec_revision'=>60,'spec_clause'=>120]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field=>$max): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<label><?php echo e($fd($field)); ?><input name="finding_disposition[<?php echo e($field); ?>]" maxlength="<?php echo e($max); ?>" value="<?php echo e($dv($field,$latestDisposition?->{$field}??$note->{$field})); ?>" required></label>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<label class="spec-wide"><?php echo e($fd('source_quote')); ?><textarea name="finding_disposition[source_quote]" maxlength="4000" rows="3"><?php echo e($dv('source_quote',$latestDisposition?->source_quote??$note->source_quote)); ?></textarea></label>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latestDisposition): ?><label class="spec-wide"><?php echo e($fd('change_reason')); ?><textarea name="finding_disposition[change_reason]" required maxlength="4000"><?php echo e($dv('change_reason')); ?></textarea></label><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<div class="spec-wide"><button class="spec-button"><?php echo e($fd('save')); ?></button></div>
</form></details>
<?php else: ?><p class="spec-help"><?php echo e($fd('read_only')); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/partials/material-finding-dispositions.blade.php ENDPATH**/ ?>