<?php
    $fa = $lang === 'fa';
    $t = [
        'title'  => ['fa' => 'ارجاع‌ها به این استاندارد', 'en' => 'Where this standard is cited'],
        'sub'    => ['fa' => 'این همان فهرستی است که روز تغییر ویرایش لازم می‌شود: هر ردیف بازرسی که به این کد استناد کرده و باید بازبینی شود.',
                     'en' => 'This is the list needed on the day the edition changes: every inspection row that leans on this code and has to be re-checked.'],
        'back'   => ['fa' => 'بازگشت به استانداردها', 'en' => 'Back to standards'],
        'ed'     => ['fa' => 'ویرایش در این پروژه', 'en' => 'Edition on this project'],
        'cat_ed' => ['fa' => 'ویرایش کتابخانه', 'en' => 'Library edition'],
        'itp'    => ['fa' => 'برنامهٔ بازرسی', 'en' => 'Inspection plan'],
        'disc'   => ['fa' => 'دیسیپلین', 'en' => 'Discipline'],
        'clause' => ['fa' => 'بند ردیف', 'en' => 'Row'],
        'act'    => ['fa' => 'فعالیت', 'en' => 'Activity'],
        'cited'  => ['fa' => 'بند استاندارد', 'en' => 'Clause cited'],
        'state'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'none'   => ['fa' => 'هیچ ردیفی در این پروژه به این استاندارد ارجاع نداده است.',
                     'en' => 'Nothing in this project cites this standard yet.'],
        'notadopted' => ['fa' => 'این استاندارد در فهرست این پروژه نیست.',
                         'en' => 'This standard is not on this project’s list.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];

    $stateLabel = fn (string $s): string => match ($s) {
        'ACTIVE' => $fa ? 'لازم‌الاجرا' : 'In force',
        'DRAFT' => $fa ? 'پیش‌نویس' : 'Draft',
        default => $fa ? 'جایگزین‌شده' : 'Superseded',
    };
?>
<!doctype html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($standard->code()); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><bdi class="mono"><?php echo e($standard->code()); ?></bdi></h1>
  <div class="sub"><?php echo e($fa ? ($standard->title_fa ?: $standard->title_en) : $standard->title_en); ?></div>

  <div style="margin-top:10px;display:flex;gap:22px;flex-wrap:wrap;font-size:12.5px;">
    <span>
      <span style="color:var(--muted);"><?php echo e($L('ed')); ?>:</span>
      <bdi class="mono"><?php echo e($num($adoption?->effectiveEdition() ?? '—')); ?></bdi>
    </span>
    <span>
      <span style="color:var(--muted);"><?php echo e($L('cat_ed')); ?>:</span>
      <bdi class="mono"><?php echo e($num($standard->edition ?? '—')); ?></bdi>
    </span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($adoption)): ?>
      <span style="color:#fbbf24;"><?php echo e($L('notadopted')); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>

  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:10px 0 0;max-width:860px;"><?php echo e($L('sub')); ?></p>

  <p style="margin:10px 0 0;">
    <a href="/projects/<?php echo e($project->code); ?>/standards?<?php echo e(http_build_query($q)); ?>"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; <?php echo e($L('back')); ?></a>
  </p>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rows->isEmpty()): ?>
    <p style="color:var(--muted);font-size:13px;margin-top:18px;"><?php echo e($L('none')); ?></p>
  <?php else: ?>
    <div style="overflow-x:auto;">
    <table data-tt style="margin-top:16px;min-width:880px;">
      <thead>
        <tr>
          <th style="white-space:nowrap;"><?php echo e($L('disc')); ?></th>
          <th><?php echo e($L('itp')); ?></th>
          <th style="white-space:nowrap;"><?php echo e($L('clause')); ?></th>
          <th><?php echo e($L('act')); ?></th>
          <th style="white-space:nowrap;"><?php echo e($L('cited')); ?></th>
          <th style="white-space:nowrap;"><?php echo e($L('state')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <tr>
            <td style="white-space:nowrap;font-size:12px;"><?php echo e($r->discipline); ?></td>
            <td style="font-size:12.5px;">
              <a href="/projects/<?php echo e($project->code); ?>/itp/<?php echo e($r->itp_id); ?>?<?php echo e(http_build_query($q)); ?>"
                 style="color:#93c5fd;text-decoration:none;"><?php echo e($fa ? $r->itp_title_fa : $r->itp_title_en); ?></a>
            </td>
            <td style="white-space:nowrap;"><bdi class="mono"><?php echo e($num($r->stage_clause)); ?></bdi></td>
            <td style="font-size:12.5px;"><?php echo e($fa ? $r->title_fa : $r->title_en); ?></td>
            <td style="white-space:nowrap;font-size:12px;"><bdi class="mono"><?php echo e($r->cited_clause ?? '—'); ?></bdi></td>
            <td style="white-space:nowrap;font-size:11.5px;color:var(--muted);"><?php echo e($stateLabel($r->state)); ?></td>
          </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/standards/usage.blade.php ENDPATH**/ ?>