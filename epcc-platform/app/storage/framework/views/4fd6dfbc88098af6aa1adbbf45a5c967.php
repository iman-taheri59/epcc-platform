<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'سبد پروژه‌ها', 'en' => 'Project Portfolio'],
        'sub'     => ['fa' => 'چند پروژه — دسترسی هر شرکت را پستگرس تعیین می‌کند', 'en' => 'Multi-project — access decided by PostgreSQL, per company'],
        'acting'  => ['fa' => 'کاربر', 'en' => 'Signed in as'],
        'logout'  => ['fa' => 'خروج', 'en' => 'Sign out'],
        'visible' => ['fa' => 'پروژهٔ قابل مشاهده', 'en' => 'projects visible'],
        'note'    => ['fa' => 'شرکت را عوض کنید: هر شرکت فقط پروژه‌هایی را می‌بیند که در آن مشارکت دارد. پک استاندارد هر پروژه جداگانه انتخاب می‌شود.', 'en' => 'Switch company: each sees only projects it participates in. The standards pack is selected per project.'],
        'certs'   => ['fa' => 'صورت‌وضعیت', 'en' => 'Certificates'],
        'blocked' => ['fa' => 'مسدود', 'en' => 'Blocked'],
        'net'     => ['fa' => 'جمع خالص (ریال)', 'en' => 'Net total (IRR)'],
        'ungated' => ['fa' => 'بدون دروازه', 'en' => 'Not gated'],
        'empty'   => ['fa' => 'این شرکت در هیچ پروژه‌ای مشارکت ندارد. این خطا نیست.', 'en' => 'This company participates in no projects. That is not an error.'],
        'owner'   => ['fa' => 'کارفرما', 'en' => 'Employer'],
        'gate'    => ['fa' => 'آزمایشگاه موتور قواعد', 'en' => 'Rule-engine playground'],
        'new'     => ['fa' => '+ پروژهٔ جدید', 'en' => '+ New project'],
        'team'    => ['fa' => 'تیم', 'en' => 'Team'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits(number_format((float) $n), $lang);
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub"><?php echo e($L('sub')); ?> · <?php echo e($jalaliDate); ?></div>

  <div class="bar">
    <label><?php echo e($L('acting')); ?>:</label>
    <strong style="font-size:14px;"><?php echo e($user->name); ?></strong>
    <span class="pill pack"><?php echo e($acting->code); ?></span>
    <span style="font-size:12.5px;color:var(--muted);"><?php echo e($acting->name($lang)); ?></span>
    <span class="count"><b><?php echo e(count($cards)); ?></b> <?php echo e($L('visible')); ?></span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($archivedCount > 0): ?>
      
      <a href="?lang=<?php echo e($lang); ?>&amp;archived=<?php echo e($showArchived ? 0 : 1); ?>"
         class="pill"
         style="background:<?php echo e($showArchived ? '#78350f' : 'transparent'); ?>;color:<?php echo e($showArchived ? '#fde68a' : 'var(--muted)'); ?>;border:1px solid #92400e;text-decoration:none;">
        <?php echo e($showArchived ? __('epcc.project.state.archived') : __('epcc.project.show_archived')); ?>

        · <?php echo e(\App\Support\JalaliDate::digits((string) $archivedCount, $lang)); ?>

      </a>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>

  <div class="note"><?php echo e($L('note')); ?></div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($cards) === 0): ?>
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;"><?php echo e($L('empty')); ?></div>
  <?php else: ?>
    <div class="grid">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $cards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $card): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $p = $card['project']; ?>
        <a class="pcard" href="/projects/<?php echo e($p->code); ?>?lang=<?php echo e($lang); ?>" <?php if($p->isArchived()): ?> style="opacity:.72;" <?php endif; ?>>
          <h3><?php echo e($p->name($lang)); ?></h3>
          <span class="code"><?php echo e($p->code); ?></span>
          <span class="pill pack"><?php echo e($p->standards_pack); ?></span>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($p->isArchived()): ?>
            <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;"><?php echo e(__('epcc.project.state.archived')); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <div class="stats">
            <div class="stat">
              <div class="k"><?php echo e($L('certs')); ?></div>
              <div class="v"><?php echo e(\App\Support\JalaliDate::digits((string) $card['count'], $lang)); ?></div>
            </div>
            <div class="stat">
              <div class="k"><?php echo e($L('blocked')); ?></div>
              <div class="v <?php echo e($card['blocked'] > 0 ? 'bad' : 'ok'); ?>"><?php echo e(\App\Support\JalaliDate::digits((string) $card['blocked'], $lang)); ?></div>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($card['ungated'] > 0): ?>
            <div class="stat">
              <div class="k"><?php echo e($L('ungated')); ?></div>
              <div class="v" style="color:#cbd5e1;"><?php echo e(\App\Support\JalaliDate::digits((string) $card['ungated'], $lang)); ?></div>
            </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <div class="stat" style="margin-<?php echo e($fa ? 'right' : 'left'); ?>:auto;text-align:<?php echo e($fa ? 'left' : 'right'); ?>;">
              <div class="k"><?php echo e($L('net')); ?></div>
              <div class="v"><?php echo e($num($card['net'])); ?></div>
            </div>
          </div>
        </a>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="foot"><?php echo e($fa ? 'احکام از بستهٔ epcc/standards-engine می‌آیند؛ پک استاندارد از روی پروژه انتخاب می‌شود.' : 'Verdicts come from epcc/standards-engine; the standards pack is selected per project.'); ?></div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/portfolio.blade.php ENDPATH**/ ?>