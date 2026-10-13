<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'کدها و استانداردها', 'en' => 'Codes & standards'],
        'sub'     => ['fa' => 'کتابخانهٔ استانداردها یک بار نگهداری می‌شود و همهٔ پروژه‌ها از آن استفاده می‌کنند. هر پروژه مشخص می‌کند به کدام استانداردها و کدام ویرایش متعهد است — ویرایشِ پروژه با به‌روزرسانی کتابخانه تغییر نمی‌کند.',
                       'en' => 'The library is maintained once and shared by every project. Each project records which codes it works to and which edition it is bound to — updating the library never moves a project to a new edition.'],
        'applies' => ['fa' => 'قابل اجرا در این پروژه', 'en' => 'Applicable to this project'],
        'cat'     => ['fa' => 'کتابخانه', 'en' => 'Library'],
        'code'    => ['fa' => 'کد', 'en' => 'Code'],
        'ttl'     => ['fa' => 'عنوان', 'en' => 'Title'],
        'ed'      => ['fa' => 'ویرایش', 'en' => 'Edition'],
        'cat_col' => ['fa' => 'دسته', 'en' => 'Category'],
        'cited'   => ['fa' => 'ارجاع‌ها', 'en' => 'Cited'],
        'adopt'   => ['fa' => 'افزودن به پروژه', 'en' => 'Add to project'],
        'add'     => ['fa' => 'افزودن', 'en' => 'Add'],
        'none'    => ['fa' => 'هنوز استانداردی برای این پروژه ثبت نشده است. با ساخته‌شدن ITP، استانداردهای ردیف‌ها به‌صورت خودکار اینجا ثبت می‌شوند.',
                      'en' => 'No standards recorded for this project yet. They are added automatically as ITP rows cite them.'],
        'all'     => ['fa' => 'همه', 'en' => 'All'],
        'pinned'  => ['fa' => 'ویرایش تثبیت‌شده توسط پروژه', 'en' => 'edition pinned by the project'],
        'unused'  => ['fa' => 'بدون ارجاع', 'en' => 'not cited'],
        'inlib'   => ['fa' => 'در فهرست پروژه', 'en' => 'on the project list'],
        'view'    => ['fa' => 'کجا ارجاع شده', 'en' => 'Where cited'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];

    $adoptedIds = $adopted->pluck('standard_id')->flip();
?>
<!doctype html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($L('title')); ?></h1>
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;max-width:860px;"><?php echo e($L('sub')); ?></p>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $message): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($message); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  
  <h2 style="margin-top:26px;font-size:16px;"><?php echo e($L('applies')); ?></h2>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($adopted->isEmpty()): ?>
    <p style="color:var(--muted);font-size:13px;margin-top:8px;"><?php echo e($L('none')); ?></p>
  <?php else: ?>
    <div style="overflow-x:auto;">
    <table data-tt style="margin-top:12px;min-width:820px;">
      <thead>
        <tr>
          <th style="white-space:nowrap;"><?php echo e($L('code')); ?></th>
          <th><?php echo e($L('ttl')); ?></th>
          <th style="white-space:nowrap;"><?php echo e($L('ed')); ?></th>
          <th style="white-space:nowrap;"><?php echo e($L('cat_col')); ?></th>
          <th style="white-space:nowrap;"><?php echo e($L('cited')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $adopted; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ps): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <?php $s = $ps->standard; $n = (int) ($usage[$ps->standard_id] ?? 0); ?>
          <tr>
            <td style="white-space:nowrap;">
              <a href="/projects/<?php echo e($project->code); ?>/standards/<?php echo e($ps->standard_id); ?>?<?php echo e(http_build_query($q)); ?>"
                 style="color:#93c5fd;text-decoration:none;"><bdi class="mono"><?php echo e($s?->code()); ?></bdi></a>
            </td>
            <td style="font-size:12.5px;"><?php echo e($fa ? ($s?->title_fa ?: $s?->title_en) : $s?->title_en); ?></td>
            <td style="white-space:nowrap;font-size:12px;">
              <bdi class="mono"><?php echo e($num($ps->effectiveEdition() ?? '—')); ?></bdi>
              
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ps->isPinnedBehindCatalogue()): ?>
                <div style="color:#fbbf24;font-size:10px;"><?php echo e($L('pinned')); ?></div>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </td>
            <td style="white-space:nowrap;font-size:11.5px;color:var(--muted);"><?php echo e($s?->category); ?></td>
            <td style="white-space:nowrap;font-size:12px;">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($n > 0): ?>
                <bdi class="mono"><?php echo e($num($n)); ?></bdi>
              <?php else: ?>
                <span style="color:var(--muted);"><?php echo e($L('unused')); ?></span>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  
  <h2 style="margin-top:30px;font-size:16px;"><?php echo e($L('cat')); ?></h2>

  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;font-size:12px;">
    <a href="/projects/<?php echo e($project->code); ?>/standards?<?php echo e(http_build_query($q)); ?>"
       style="padding:3px 10px;border-radius:999px;text-decoration:none;border:1px solid var(--line,#1f2937);
              color:<?php echo e($category === null ? '#0b1220' : 'var(--muted)'); ?>;
              background:<?php echo e($category === null ? '#93c5fd' : 'transparent'); ?>;"><?php echo e($L('all')); ?></a>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <a href="/projects/<?php echo e($project->code); ?>/standards?<?php echo e(http_build_query($q + ['category' => $c])); ?>"
         style="padding:3px 10px;border-radius:999px;text-decoration:none;border:1px solid var(--line,#1f2937);
                color:<?php echo e($category === $c ? '#0b1220' : 'var(--muted)'); ?>;
                background:<?php echo e($category === $c ? '#93c5fd' : 'transparent'); ?>;"><?php echo e($c); ?></a>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>

  <div style="overflow-x:auto;">
  <table data-tt style="margin-top:12px;min-width:820px;">
    <thead>
      <tr>
        <th style="white-space:nowrap;"><?php echo e($L('code')); ?></th>
        <th><?php echo e($L('ttl')); ?></th>
        <th style="white-space:nowrap;"><?php echo e($L('ed')); ?></th>
        <th style="white-space:nowrap;"><?php echo e($L('cat_col')); ?></th>
        <th style="white-space:nowrap;"><?php echo e($L('adopt')); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $catalogue; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
          <td style="white-space:nowrap;"><bdi class="mono"><?php echo e($s->code()); ?></bdi></td>
          <td style="font-size:12.5px;"><?php echo e($fa ? ($s->title_fa ?: $s->title_en) : $s->title_en); ?></td>
          <td style="white-space:nowrap;font-size:12px;"><bdi class="mono"><?php echo e($num($s->edition ?? '—')); ?></bdi></td>
          <td style="white-space:nowrap;font-size:11.5px;color:var(--muted);"><?php echo e($s->category); ?></td>
          <td style="white-space:nowrap;">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($adoptedIds->has($s->id)): ?>
              <span style="font-size:11.5px;color:#4ade80;"><?php echo e($L('inlib')); ?></span>
            <?php else: ?>
              <form method="post" action="/projects/<?php echo e($project->code); ?>/standards/adopt?<?php echo e(http_build_query($q)); ?>"
                    style="margin:0;display:flex;gap:5px;align-items:center;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="standard_id" value="<?php echo e($s->id); ?>">
                
                <input type="text" name="edition" value="<?php echo e($s->edition); ?>" size="6"
                       style="font-size:11.5px;padding:2px 5px;width:66px;">
                <button type="submit" style="font-size:11.5px;padding:3px 9px;cursor:pointer;"><?php echo e($L('add')); ?></button>
              </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
  </div>

</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/standards/index.blade.php ENDPATH**/ ?>