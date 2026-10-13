<?php
    $safeOld = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
    $currentSelectors = array_column($mrAuthorAccess['selectors'], 'selector');
    $selectedAuthors = old('access.selectors', $currentSelectors);
    $selectedAuthors = is_array($selectedAuthors) ? $selectedAuthors : [];
?>
<style>
.mr-card { border:1px solid var(--line);border-radius:12px;background:var(--card);padding:16px;margin:16px 0; }
.mr-card h2 { font-size:16px;margin:0 0 10px; }.mr-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0; }
.mr-card label { display:flex;flex-direction:column;gap:5px;font-size:12px; }
.mr-card input:not([type=checkbox]),.mr-card select,.mr-card textarea { width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:6px;background:#0b1220;color:#e2e8f0;padding:8px;font:inherit; }
.mr-card details { padding:9px 0;border-top:1px solid var(--line); }.mr-card summary { cursor:pointer; }.mr-card .help { font-size:12px;color:var(--muted);line-height:1.8; }
</style>
<section class="mr-card" aria-labelledby="mr-access-title">
<h2 id="mr-access-title"><?php echo e(__('mr.access_title')); ?></h2>
<p class="help"><?php echo e(__('mr.access_scope')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$mrAuthorAccess['current']): ?>
<p data-mr-access="unconfigured"><?php echo e(__('mr.access_unconfigured')); ?></p>
<?php elseif($mrAuthorAccess['selectors'] === []): ?>
<p data-mr-access="suspended"><?php echo e(__('mr.access_suspended')); ?></p>
<?php else: ?>
<p data-mr-access="configured"><?php echo e(__('mr.access_version')); ?> <?php echo e($mrAuthorAccess['current']->version); ?></p>
<ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $mrAuthorAccess['selectors']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $selection): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($selection['label']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mrAuthorAccess['canConfigure']): ?>
<details <?php if(!$mrAuthorAccess['current'] || old('access') !== null): ?> open <?php endif; ?>><summary><?php echo e(__('mr.access_configure')); ?></summary>
<form method="post" id="mr-access-form" action="<?php echo e(route('procurement.author-access', ['projectCode'=>$project->code,'lang'=>$lang])); ?>"><?php echo csrf_field(); ?>
<input type="hidden" name="access[version]" value="<?php echo e($safeOld('access.version', $mrAuthorAccess['current']->version ?? 0)); ?>">
<div class="mr-grid">
<label><?php echo e(__('mr.access_selectors')); ?><select name="access[selectors][]" multiple size="8">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $mrAuthorAccess['options']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value=>$label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($value); ?>" <?php if(in_array($value,$selectedAuthors,true)): echo 'selected'; endif; ?>><?php echo e($label); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</select></label>
<label><?php echo e(__('mr.reason')); ?><textarea name="access[reason]" required rows="4" maxlength="4000"><?php echo e($safeOld('access.reason')); ?></textarea></label>
</div>
<p class="help"><?php echo e(__('mr.access_selector_help')); ?></p>
<input type="hidden" name="access[suspend]" value="0">
<label style="display:block;margin:12px 0"><input type="checkbox" name="access[suspend]" value="1" <?php if($safeOld('access.suspend') === '1'): echo 'checked'; endif; ?>> <?php echo e(__('mr.access_suspend')); ?></label>
<button class="btn" type="submit"><?php echo e(__('mr.access_save')); ?></button>
</form></details>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mrAuthorAccess['history']->isNotEmpty()): ?>
<details><summary><?php echo e(__('mr.access_history')); ?></summary><p class="help"><?php echo e(__('mr.access_history_limit')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $mrAuthorAccess['history']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $policy): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<details><summary><?php echo e(__('mr.access_version')); ?> <?php echo e($policy->version); ?> · <?php echo e($policy->actor_name); ?> · <?php echo e($policy->created_at); ?> UTC</summary>
<p><?php echo e($policy->reason); ?></p><ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = json_decode($policy->selectors,true); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $selection): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><li><?php echo e($selection['label']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><li><?php echo e(__('mr.access_suspended')); ?></li><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
</details>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></details>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<section class="mr-card" aria-labelledby="mr-entry-title">
<h2 id="mr-entry-title"><?php echo e(__('mr.new_draft')); ?></h2>
<p class="help"><?php echo e(__('mr.draft_scope')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$canRaiseMr): ?>
<p data-mr-entry="blocked"><?php echo e(__('mr.not_author')); ?></p>
<?php else: ?>
<details <?php if(old('mr') !== null): ?> open <?php endif; ?>><summary><?php echo e(__('mr.open_form')); ?></summary>
<form method="post" id="mr-create-form" action="<?php echo e(route('procurement.requisitions.create', ['projectCode'=>$project->code,'lang'=>$lang])); ?>"><?php echo csrf_field(); ?>
<div class="mr-grid">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['title_fa'=>255, 'title_en'=>255, 'discipline'=>32]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field=>$limit): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<label><?php echo e(__('mr.'.$field)); ?><input name="mr[<?php echo e($field); ?>]" required maxlength="<?php echo e($limit); ?>" value="<?php echo e($safeOld('mr.'.$field)); ?>" <?php if($field !== 'title_fa'): ?> dir="ltr" <?php endif; ?>></label>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<p class="help"><?php echo e(__('mr.line_help')); ?></p>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i=0; $i<20; $i++): ?>
<details <?php if($i===0 || (is_array(old('mr.lines.'.$i)) && count(array_filter(old('mr.lines.'.$i), fn($v)=>is_scalar($v) && (string)$v !== '')) > 0)): ?> open <?php endif; ?>>
<summary><?php echo e(__('mr.line')); ?> <?php echo e($num($i+1)); ?></summary>
<div class="mr-grid">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['item_code'=>64, 'description_fa'=>4000, 'description_en'=>4000, 'unit'=>16, 'quantity'=>19, 'specification'=>255]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field=>$limit): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<label><?php echo e(__('mr.'.$field)); ?><input name="mr[lines][<?php echo e($i); ?>][<?php echo e($field); ?>]" maxlength="<?php echo e($limit); ?>" value="<?php echo e($safeOld('mr.lines.'.$i.'.'.$field)); ?>" <?php if(in_array($field,['item_code','unit','quantity','description_en'])): ?> dir="ltr" <?php endif; ?> <?php if($field==='quantity'): ?> inputmode="decimal" <?php endif; ?>></label>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<label><?php echo e(__('mr.subsystem')); ?><select name="mr[lines][<?php echo e($i); ?>][subsystem_id]"><option value="">—</option>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $mrSubsystems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $subsystem): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($subsystem->id); ?>" <?php if($safeOld('mr.lines.'.$i.'.subsystem_id') === $subsystem->id): echo 'selected'; endif; ?>><?php echo e($subsystem->code); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</select></label>
</div></details>
<?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<p class="help"><?php echo e(__('mr.quantity_help')); ?></p>
<button type="submit" class="btn"><?php echo e(__('mr.save_draft')); ?></button>
</form></details>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH /home/user/epcc-platform/app/resources/views/materials/mr-entry.blade.php ENDPATH**/ ?>