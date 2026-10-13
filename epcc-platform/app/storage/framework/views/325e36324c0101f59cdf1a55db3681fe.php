
<?php
    $fa = $lang === 'fa';
?>

<div class="scrim" id="scrim" aria-hidden="true"></div>

<aside class="sb" id="sb" aria-label="<?php echo e($fa ? 'ناوبری اصلی' : 'Main navigation'); ?>">
  <div class="sb-brand">
    <span class="sb-logo" aria-hidden="true">EP</span>
    <span class="sb-brand-t">
      <b><?php echo e($fa ? 'سامانهٔ EPCC' : 'EPCC Platform'); ?></b>
      <small><?php echo e($acting->code ?? ''); ?></small>
    </span>
  </div>

  <nav class="sb-nav">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $nav; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $section): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($section['items']) > 0): ?>
        <div class="sb-sec"><?php echo e($section['label']); ?></div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $section['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item['state'] === 'available'): ?>
            <a class="sb-i<?php echo e($item['active'] ? ' on' : ''); ?>"
               href="<?php echo e($item['url']); ?>"
               <?php if($item['active']): ?> aria-current="page" <?php endif; ?>>
              <i class="sb-ic"><?php echo $item['icon']; ?></i>
              <span><?php echo e($item['label']); ?></span>
            </a>
          <?php else: ?>
            
            <span class="sb-i off <?php echo e($item['state']); ?>" title="<?php echo e($item['hint']); ?>" aria-disabled="true">
              <i class="sb-ic"><?php echo $item['icon']; ?></i>
              <span><?php echo e($item['label']); ?></span>
              <em class="sb-b <?php echo e($item['state']); ?>"><?php echo e($item['badge']); ?></em>
            </span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </nav>

  <div class="sb-foot">
    <div class="sb-user">
      <span class="sb-av" aria-hidden="true"><?php echo e(mb_substr($user->name, 0, 1)); ?></span>
      <span class="sb-user-t">
        <b><?php echo e($user->name); ?></b>
        <small><?php echo e($acting->name($lang) ?? ''); ?></small>
      </span>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($plan ?? null) !== null): ?>
      <div class="sb-plan">
        <span><?php echo e($fa ? 'پلن' : 'Plan'); ?></span>
        <span class="pill pack"><?php echo e($plan->name($lang)); ?></span>
      </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="sb-acts">
      <a href="<?php echo e($langSwitchUrl ?? ('?lang='.($fa ? 'en' : 'fa'))); ?>"><?php echo e($fa ? 'English' : 'فارسی'); ?></a>
      <form method="post" action="/logout">
        <?php echo csrf_field(); ?>
        <button type="submit"><?php echo e($fa ? 'خروج' : 'Sign out'); ?></button>
      </form>
    </div>
  </div>
</aside>


<script>
(function () {
  // WAIT FOR THE DOM.
  //
  // This partial renders the sidebar, which must come first in the document
  // for the flex layout - but the toggle button lives in the content column
  // (partials/topbar), i.e. LATER in the document. Running immediately meant
  // getElementById('sb-burger') returned null and the whole script bailed at
  // the guard below: the button rendered, looked right, and did nothing.
  //
  // Do not "fix" this by moving the button into the sidebar - it has to stay
  // outside, or it slides off-canvas with the drawer on mobile and strands
  // the user. Waiting for DOMContentLoaded is the fix, and it keeps this
  // partial independent of include order.
  function init() {
  var body = document.body,
      burger = document.getElementById('sb-burger'),
      scrim = document.getElementById('scrim');

  if (!burger) return;

  // Feature-detected: on older Safari/Android WebViews matchMedia or its
  // addEventListener may be missing, and an unguarded call kills the whole
  // script - which previously blanked the prototype.
  var mq = window.matchMedia ? window.matchMedia('(max-width:860px)') : null;
  var MOBILE = function () { return mq ? mq.matches : (window.innerWidth || 1024) <= 860; };

  var KEY = 'epcc.sb.rail';

  // Restore the desktop preference before the first paint below.
  // <html> already carries the class from the pre-paint script in the shell;
  // mirror it onto <body> so both selectors agree from here on.
  if (document.documentElement.classList.contains('rail')) body.classList.add('rail');

  function sync() {
    var open = MOBILE() ? body.classList.contains('drawer') : !body.classList.contains('rail');
    burger.setAttribute('aria-expanded', String(open));
    burger.textContent = (MOBILE() && open) ? '\u2715' : '\u2630';
  }

  function toggle() {
    if (MOBILE()) {
      body.classList.toggle('drawer');
    } else {
      var railed = body.classList.toggle('rail');
      document.documentElement.classList.toggle('rail', railed);
      try { localStorage.setItem(KEY, railed ? '1' : '0'); } catch (e) {}
    }
    sync();
  }

  function closeDrawer() {
    if (body.classList.contains('drawer')) { body.classList.remove('drawer'); sync(); }
  }

  burger.addEventListener('click', toggle);
  if (scrim) scrim.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

  // Following a link closes the drawer; otherwise it stays over the new page.
  var nav = document.querySelector('.sb-nav');
  if (nav) nav.addEventListener('click', function (e) { if (e.target.closest('a')) closeDrawer(); });

  // Crossing the breakpoint must not leave a stale state behind.
  var onBreak = function () { body.classList.remove('drawer'); sync(); };
  if (mq && mq.addEventListener) mq.addEventListener('change', onBreak);
  else if (mq && mq.addListener) mq.addListener(onBreak); // legacy Safari
  else window.addEventListener('resize', onBreak);

  sync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
<?php /**PATH /home/user/epcc-platform/app/resources/views/partials/sidebar.blade.php ENDPATH**/ ?>