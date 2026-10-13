<?php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'برنامه‌های بازرسی و آزمون (ITP)', 'en' => 'Inspection & Test Plans (ITP)'],
        'sub'      => ['fa' => 'هر دیسیپلین یک ITP دارد. لوله‌کشی واحد و خط لولهٔ برون‌شهری عمداً جدا هستند: کدهای حاکم، ارکان بازرسی و معیارهای پذیرش‌شان یکی نیست.',
                       'en' => 'One ITP per discipline. Plant piping and cross-country pipeline are deliberately separate: different governing codes, different inspection bodies, different acceptance criteria.'],
        'new'      => ['fa' => 'ساخت از روی الگوی استاندارد', 'en' => 'Create from a standard template'],
        'template' => ['fa' => 'الگو', 'en' => 'Template'],
        'number'   => ['fa' => 'شماره (اختیاری)', 'en' => 'Number (optional)'],
        'not_registered' => ['fa' => 'ثبت‌نشده', 'en' => 'not registered'],
        'create'   => ['fa' => 'بساز', 'en' => 'Create'],
        'disc'     => ['fa' => 'دیسیپلین', 'en' => 'Discipline'],
        'num'      => ['fa' => 'شماره', 'en' => 'Number'],
        'ttl'      => ['fa' => 'عنوان', 'en' => 'Title'],
        'stages'   => ['fa' => 'ردیف', 'en' => 'Stages'],
        'state'    => ['fa' => 'وضعیت', 'en' => 'State'],
        'act'      => ['fa' => 'لازم‌الاجرا کن', 'en' => 'Put in force'],
        'none'     => ['fa' => 'هنوز ITP ای ساخته نشده است.', 'en' => 'No ITP has been created yet.'],
        'open'     => ['fa' => 'مشاهده', 'en' => 'Open'],
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
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;max-width:820px;"><?php echo e($L('sub')); ?></p>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $message): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($message); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <form method="post" action="/projects/<?php echo e($project->code); ?>/itp/from-template?<?php echo e(http_build_query($q)); ?>"
        style="margin-top:16px;padding:14px;border:1px solid var(--line,#1f2937);border-radius:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <?php echo csrf_field(); ?>
    <div style="display:grid;gap:5px;">
      <label style="font-size:12px;color:var(--muted);"><?php echo e($L('template')); ?></label>
      <select name="template_id" required
              style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;min-width:320px;">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $templates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tpl): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <option value="<?php echo e($tpl->id); ?>"><?php echo e($tpl->discipline); ?> — <?php echo e($tpl->title($lang)); ?></option>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </select>
    </div>
    <div style="display:grid;gap:5px;">
      <label style="font-size:12px;color:var(--muted);"><?php echo e($L('number')); ?></label>
      <input type="text" name="number"
             style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
    </div>
    <button type="submit" class="btn"><?php echo e($L('create')); ?></button>
  </form>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($itps) === 0): ?>
    <p style="color:var(--muted);font-size:13px;margin-top:14px;"><?php echo e($L('none')); ?></p>
  <?php else: ?>
    <table data-tt style="margin-top:16px;">
      <thead><tr>
        <th><?php echo e($L('disc')); ?></th><th><?php echo e($L('num')); ?></th><th><?php echo e($L('ttl')); ?></th>
        <th><?php echo e($L('stages')); ?></th><th><?php echo e($L('state')); ?></th><th></th>
      </tr></thead>
      <tbody>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $itps; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
          <td style="font-size:12px;"><span class="mono"><?php echo e($row->discipline); ?></span></td>
          <td style="font-size:12px;white-space:nowrap;">
            <bdi class="mono"><?php echo e($row->displayNumber()); ?></bdi>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($row->isRegistered())): ?>
              <div style="color:#fbbf24;font-size:10px;"><?php echo e($L('not_registered')); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td style="font-size:13px;">
            <a href="/projects/<?php echo e($project->code); ?>/itp/<?php echo e($row->id); ?>?<?php echo e(http_build_query($q)); ?>"
               style="color:var(--accent,#60a5fa);"><?php echo e($row->title($lang)); ?></a>
          </td>
          <td style="font-size:12px;"><?php echo e($num($row->stages_count)); ?></td>
          <td style="font-size:12px;color:<?php echo e($row->state === 'ACTIVE' ? '#86efac' : ($row->state === 'DRAFT' ? '#fcd34d' : 'var(--muted)')); ?>;">
            <?php echo e($stateLabel($row->state)); ?>

          </td>
          <td>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row->state === 'DRAFT'): ?>
              <form method="post" action="/projects/<?php echo e($project->code); ?>/itp/<?php echo e($row->id); ?>/activate?<?php echo e(http_build_query($q)); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;"><?php echo e($L('act')); ?></button>
              </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="foot">
    <?php echo e($fa
        ? 'نقطهٔ توقف (H) کار را متوقف می‌کند؛ نقطهٔ شاهد (W) فقط دعوت می‌خواهد. همین تفاوت است که تعیین می‌کند هزینهٔ یک هفته توقف با کیست، و به همین دلیل با یک تیک «بازرسی لازم است» جایگزین نشده است.'
        : 'A hold point (H) stops the work; a witness point (W) only requires an invitation. That difference decides who pays for a week of standby, which is why it is not collapsed into one "inspection required" tick box.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/itp/index.blade.php ENDPATH**/ ?>