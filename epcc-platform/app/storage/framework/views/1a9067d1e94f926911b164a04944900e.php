<?php
    $fr=fn($key)=>__('finding_responses.'.$key,[],$lang);
    $responseContext=$findingResponses[$row->id]??['role'=>null,'threads'=>[]];
    $thread=$responseContext['threads'][$findingIndex]??[];
    $runId=$historical['record']->run_id;
    $findingKey=\App\Services\Engineering\MaterialFindingResponses::findingKey($historical['record']->result_sha256,$findingIndex);
    $oldIndex=old('finding_response.finding_index');
    $isOld=old('finding_response.run_id')===$runId && is_scalar($oldIndex) && (string)$oldIndex===(string)$findingIndex;
    $value=function($key,$default=null)use($isOld){
        $v=$isOld?old('finding_response.'.$key,$default):$default;
        return is_scalar($v)||$v===null?$v:$default;
    };
?>
<details class="finding-thread" data-finding-thread="<?php echo e($findingIndex); ?>" style="margin:12px 0;padding:10px;border:1px solid var(--line);border-radius:8px">
<summary><?php echo e($fr('title')); ?> · <?php echo e($num(count($thread))); ?></summary>
<p class="spec-help"><?php echo e($fr('notice')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $thread; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $note): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
<div class="response-entry" data-response="<?php echo e($note->id); ?>" style="padding:12px 0;border-bottom:1px solid var(--line)">
<p><strong><?php echo e($fr('sequence')); ?> <?php echo e($num($note->sequence)); ?> · <?php echo e($fr($note->kind)); ?></strong></p>
<p><?php echo e($note->actor_name); ?> · <?php echo e($fr($note->actor_kind)); ?> <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($note->review_stage): ?> · <?php echo e($fr('stage')); ?> <?php echo e($num($note->review_stage)); ?> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></p>
<p><bdi><?php echo e($note->company_id); ?></bdi> · <?php echo e(\App\Support\JalaliDate::formatWithTime(\Carbon\Carbon::parse($note->created_at)->utc(),$lang)); ?> UTC</p>
<p class="spec-source" dir="auto"><?php echo e($note->message); ?></p>
<p><?php echo e($fr('spec_number')); ?>: <bdi><?php echo e($note->spec_number); ?></bdi> · <?php echo e($fr('spec_revision')); ?>: <bdi><?php echo e($note->spec_revision); ?></bdi> · <?php echo e($fr('spec_clause')); ?>: <bdi><?php echo e($note->spec_clause); ?></bdi></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($note->source_quote): ?><blockquote class="spec-source" dir="auto"><?php echo e($note->source_quote); ?></blockquote><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php echo $__env->make('piping.partials.material-finding-dispositions', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p class="spec-help"><?php echo e($fr('empty')); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canCreate && $responseContext['role']): ?>
<form method="post" action="<?php echo e($action('/'.$row->id.'/finding-responses')); ?>" class="spec-grid finding-response-form" style="margin-top:16px">
<?php echo csrf_field(); ?>
<input type="hidden" name="lang" value="<?php echo e($lang); ?>">
<input type="hidden" name="finding_response[run_id]" value="<?php echo e($runId); ?>">
<input type="hidden" name="finding_response[finding_index]" value="<?php echo e($findingIndex); ?>">
<input type="hidden" name="finding_response[finding_key]" value="<?php echo e($findingKey); ?>">
<input type="hidden" name="finding_response[request_key]" value="<?php echo e($value('request_key',(string)\Illuminate\Support\Str::uuid())); ?>">
<label><?php echo e($fr('kind')); ?><select name="finding_response[kind]" required>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ($responseContext['role']==='REVIEWER'?['REVIEW_NOTE']:['EXPLANATION','NA_REQUEST']); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kind): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<option value="<?php echo e($kind); ?>" <?php if($value('kind')===$kind): echo 'selected'; endif; ?>><?php echo e($fr($kind)); ?></option>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></select></label>
<label class="spec-wide"><?php echo e($fr('message')); ?><textarea name="finding_response[message]" required maxlength="4000" rows="3"><?php echo e($value('message')); ?></textarea></label>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['spec_number'=>120,'spec_revision'=>60,'spec_clause'=>120]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field=>$max): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<label><?php echo e($fr($field)); ?><input name="finding_response[<?php echo e($field); ?>]" maxlength="<?php echo e($max); ?>" value="<?php echo e($value($field,$row->{$field})); ?>" required></label>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<label class="spec-wide"><?php echo e($fr('source_quote')); ?><textarea name="finding_response[source_quote]" maxlength="4000" rows="3"><?php echo e($value('source_quote')); ?></textarea></label>
<div class="spec-wide"><button class="spec-button" type="submit"><?php echo e($fr('save')); ?></button></div>
</form>
<?php else: ?><p class="spec-help"><?php echo e($fr('read_only')); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</details>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/partials/material-finding-responses.blade.php ENDPATH**/ ?>