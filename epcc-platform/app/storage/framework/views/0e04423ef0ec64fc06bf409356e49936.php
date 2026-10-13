<?php
    $fa = $lang === 'fa';
    $t = [
        'title'      => ['fa' => 'صورت‌وضعیت‌های موقت', 'en' => 'Interim Payment Certificates'],
        'acting'     => ['fa' => 'کاربر', 'en' => 'Signed in as'],
        'logout'     => ['fa' => 'خروج', 'en' => 'Sign out'],
        'project'    => ['fa' => 'پروژه', 'en' => 'Project'],
        'visible'    => ['fa' => 'ردیف قابل مشاهده', 'en' => 'rows visible'],
        'rlsnote'    => ['fa' => 'دامنه روی این پروژه محدود شده است (epcc.project_id). تعداد ردیف‌ها را پستگرس تعیین می‌کند، نه کد برنامه.', 'en' => 'Scoped to this project (epcc.project_id). Row visibility is decided by PostgreSQL, not application code.'],
        'number'     => ['fa' => 'شماره', 'en' => 'Number'],
        'contractor' => ['fa' => 'پیمانکار', 'en' => 'Contractor'],
        'net'        => ['fa' => 'مبلغ خالص (ریال)', 'en' => 'Net (IRR)'],
        'gate'       => ['fa' => 'دروازهٔ ترخیص', 'en' => 'Release gate'],
        'cleared'    => ['fa' => 'مجاز', 'en' => 'Cleared'],
        'blocked'    => ['fa' => 'مسدود', 'en' => 'Blocked'],
        'absolute'   => ['fa' => 'قطعی — بدون امکان چشم‌پوشی', 'en' => 'Hard — no waiver'],
        'empty'      => ['fa' => 'هیچ صورت‌وضعیتی برای این شرکت در این پروژه قابل مشاهده نیست. این خطا نیست — RLS در حال کار است.', 'en' => 'No certificates visible to this company on this project. That is not an error — RLS is working.'],
        'limit'      => ['fa' => 'حد اختیارات', 'en' => 'Authority limit'],
        'back'       => ['fa' => 'سبد پروژه‌ها', 'en' => 'Portfolio'],
        'pack'       => ['fa' => 'پک', 'en' => 'Pack'],
        'ungated'    => ['fa' => 'بدون دروازه', 'en' => 'Not gated'],
        'ungatedwhy' => ['fa' => 'پک استاندارد این پروژه دروازه‌ای برای این نوع سند تعریف نکرده است — هیچ بررسی‌ای انجام نشده.', 'en' => 'This project\'s standards pack defines no gate for this object type — nothing was checked.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits(number_format((float) $n), $lang);
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($project->name($lang)); ?> — <?php echo e($L('title')); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($project->name($lang)); ?></h1>
  <div class="sub">
    <span class="mono"><?php echo e($project->code); ?></span>
    · <span class="pill pack"><?php echo e($L('pack')); ?>: <?php echo e($project->standards_pack); ?></span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project->isArchived()): ?>
      · <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;"><?php echo e(__('epcc.project.archived_badge')); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    · <?php echo e($jalaliDate); ?>

  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project->isArchived()): ?>
    
    <div style="margin:14px 0;padding:12px 14px;border-radius:10px;background:rgba(120,53,15,.28);border:1px solid #92400e;color:#fde68a;font-size:13px;line-height:1.7;">
      <strong><?php echo e(__('epcc.project.archived_badge')); ?></strong>
      <div><?php echo e(__('epcc.project.read_only')); ?></div>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project->archived_at): ?>
        <div style="opacity:.85;margin-top:4px;">
          <?php echo e(__('epcc.project.archived_on', [
              'date' => \App\Support\JalaliDate::format($project->archived_at, $lang),
              'user' => $archivedByName ?? '—',
          ])); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project->archive_reason): ?> — <?php echo e($project->archive_reason); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canArchive): ?>
        <form method="POST" action="/projects/<?php echo e($project->code); ?>/reopen?lang=<?php echo e($lang); ?>" style="margin-top:10px;">
          <?php echo csrf_field(); ?>
          <button type="submit" style="padding:7px 14px;border-radius:8px;border:1px solid #92400e;background:#78350f;color:#fde68a;font:inherit;cursor:pointer;">
            <?php echo e(__('epcc.project.reopen')); ?>

          </button>
        </form>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(6,78,59,.35);border:1px solid #065f46;color:#a7f3d0;font-size:13px;">
      <?php echo e(session('status')); ?>

    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(127,29,29,.35);border:1px solid #991b1b;color:#fecaca;font-size:13px;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($error); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="bar">
    <label><?php echo e($L('acting')); ?>:</label>
    <strong style="font-size:14px;"><?php echo e($user->name); ?></strong>
    <span class="pill pack"><?php echo e($acting->code); ?></span>
    <span style="font-size:12.5px;color:var(--muted);"><?php echo e($L('limit')); ?>: <?php echo e($num($limit)); ?></span>
    <span class="count"><b><?php echo e(count($rows)); ?></b> <?php echo e($L('visible')); ?></span>
  </div>

  <div class="note"><?php echo e($L('rlsnote')); ?></div>

  
  <table data-tt>
    <thead>
      <tr>
        <th data-sort="text"><?php echo e($L('number')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('contractor')); ?></th>
        <th data-sort="num"><?php echo e($L('net')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('gate')); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
      <?php $c = $row['cert']; $v = $row['verdict']; ?>
      <tr>
        <td><span class="mono"><?php echo e($c->number); ?></span></td>
        <td><?php echo e($c->company->name($lang)); ?></td>
        
        <td class="amt" data-sortkey="<?php echo e($c->net_amount); ?>"><?php echo e($num($c->net_amount)); ?></td>
        
        <?php
          $verdictKey = $v->isUngated() ? 'ungated' : ($v->passed ? 'cleared' : 'blocked');
        ?>
        
        <td data-sortkey="<?php echo e($verdictKey); ?>" data-facet-value="<?php echo e($L($verdictKey)); ?>">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($v->isUngated()): ?>
            <span class="pill none"><?php echo e($L('ungated')); ?></span>
            <div class="whynot"><?php echo e($L('ungatedwhy')); ?></div>
          <?php else: ?>
            <span class="pill <?php echo e($v->passed ? 'ok' : 'bad'); ?>"><?php echo e($v->passed ? $L('cleared') : $L('blocked')); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($v->isAbsolutelyBlocked()): ?><span class="pill abs"><?php echo e($L('absolute')); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($v->failures)): ?>
            <ul class="rs">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $v->failures; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $f): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><code><?php echo e($f->check); ?></code><?php echo e($fa ? $f->messageFa : $f->messageEn); ?></li>
              <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </ul>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
      <tr><td colspan="4" class="empty"><?php echo e($L('empty')); ?></td></tr>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canArchive && ! $project->isArchived()): ?>
    <div style="margin-top:22px;padding:14px;border-radius:10px;border:1px solid var(--line);background:var(--card);">
      <div style="font-size:13px;font-weight:600;margin-bottom:4px;"><?php echo e(__('epcc.project.archive')); ?></div>
      <div style="font-size:12.5px;color:var(--muted);line-height:1.7;margin-bottom:10px;"><?php echo e(__('epcc.project.archive_confirm')); ?></div>
      <form method="POST" action="/projects/<?php echo e($project->code); ?>/archive?lang=<?php echo e($lang); ?>"
            onsubmit="return confirm(<?php echo \Illuminate\Support\Js::from(__('epcc.project.archive_confirm'))->toHtml() ?>);"
            style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php echo csrf_field(); ?>
        <input type="text" name="reason" maxlength="500"
               placeholder="<?php echo e(__('epcc.project.archive_reason')); ?>"
               style="flex:1;min-width:220px;padding:7px 10px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
        <button type="submit" style="padding:7px 14px;border-radius:8px;border:1px solid #92400e;background:#78350f;color:#fde68a;font:inherit;cursor:pointer;">
          <?php echo e(__('epcc.project.archive')); ?>

        </button>
      </form>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/certificates.blade.php ENDPATH**/ ?>