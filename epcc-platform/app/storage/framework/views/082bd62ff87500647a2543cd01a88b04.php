
<div class="topbar">
  <button type="button" class="sb-burger" id="sb-burger"
          aria-controls="sb" aria-expanded="true"
          aria-label="<?php echo e($lang === 'fa' ? 'باز و بستن منو' : 'Toggle navigation'); ?>"
          title="<?php echo e($lang === 'fa' ? 'باز و بستن منو' : 'Toggle navigation'); ?>">&#9776;</button>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($topbarTitle)): ?>
    <span class="tb-t"><?php echo e($topbarTitle); ?></span>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/user/epcc-platform/app/resources/views/partials/topbar.blade.php ENDPATH**/ ?>