<?php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'مدل خط', 'en' => 'Line model'],
        'sub'      => ['fa' => 'قطعات روی این خط و سرهای بازی که هنوز به چیزی وصل نیستند',
                       'en' => 'The components on this line and the ends not yet connected to anything'],
        'back'     => ['fa' => 'بازگشت به فهرست خطوط', 'en' => 'Back to the line register'],
        'seq'      => ['fa' => 'ردیف', 'en' => 'No.'],
        'type'     => ['fa' => 'نوع', 'en' => 'Type'],
        'size'     => ['fa' => 'سایز', 'en' => 'Size'],
        'ports'    => ['fa' => 'پورت‌ها', 'en' => 'Ports'],
        'length'   => ['fa' => 'طول', 'en' => 'Length'],
        'actions'  => ['fa' => 'اقدام', 'en' => 'Actions'],
        'remove'   => ['fa' => 'حذف', 'en' => 'Remove'],
        'none'     => ['fa' => 'هنوز چیزی روی این خط مدل نشده است. اولین قطعه را اضافه کنید.',
                       'en' => 'Nothing modelled on this line yet. Add the first component.'],
        'openends' => ['fa' => 'سرهای باز', 'en' => 'Open ends'],
        'complete' => ['fa' => 'سر بازی نمانده — این خط از نظر ساختاری کامل است.',
                       'en' => 'No open ends — this line is structurally complete.'],
        'attach'   => ['fa' => 'اتصال قطعه به این سر', 'en' => 'Attach a component to this end'],
        'attachbtn'=> ['fa' => 'اتصال', 'en' => 'Attach'],
        'add'      => ['fa' => 'افزودن قطعهٔ مستقل', 'en' => 'Add a standalone component'],
        'addbtn'   => ['fa' => 'افزودن', 'en' => 'Add'],
        'branch'   => ['fa' => 'سایز انشعاب', 'en' => 'Branch size'],
        'small'    => ['fa' => 'سر کوچک', 'en' => 'Small end'],
        'lenmm'    => ['fa' => 'طول (میلی‌متر)', 'en' => 'Length (mm)'],
        'sched'    => ['fa' => 'رده', 'en' => 'Schedule'],
        'inherit'  => ['fa' => 'سایز از پورت والد به ارث می‌رسد و قابل تایپ نیست.',
                       'en' => 'The size is inherited from the parent port and cannot be typed.'],
        'locked'   => ['fa' => 'این خط ابلاغ شده است؛ تغییر مدل آن نیاز به بازگشت به پیش‌نویس دارد.',
                       'en' => 'This line is released; changing its model needs a revert to draft first.'],
        'portsnote'=> ['fa' => 'تعداد پورت‌ها از نوع قطعه می‌آید — سه‌راه همیشه سه پورت دارد و کسی آن را وارد نمی‌کند.',
                       'en' => 'The port count comes from the component type — a tee always has three, and nobody types that.'],
        'joints'   => ['fa' => 'اتصالات', 'en' => 'Joints'],
        'jnone'    => ['fa' => 'هنوز اتصالی روی این خط ثبت نشده است.', 'en' => 'No joints on this line yet.'],
        'jnum'     => ['fa' => 'شماره', 'en' => 'No.'],
        'jtype'    => ['fa' => 'نوع', 'en' => 'Type'],
        'jbetween' => ['fa' => 'بین', 'en' => 'Between'],
        'jstate'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'jdia'     => ['fa' => 'قطر-اینچ', 'en' => 'Dia-inch'],
        'junnum'   => ['fa' => 'بدون شماره', 'en' => 'not numbered'],
        'jwelds'   => ['fa' => 'جوش', 'en' => 'welds'],
        'jfitted'  => ['fa' => 'مونتاژشده', 'en' => 'fitted'],
        'jdianote' => ['fa' => 'قطر-اینچ همیشه از سر کوچک‌تر گرفته می‌شود؛ ذخیره نمی‌شود و هر بار محاسبه می‌گردد.',
                       'en' => 'Dia-inch always takes the smaller end; it is computed, never stored.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    // Inch marks inside an <option>: no markup is allowed there, so <bdi> is
    // out. A trailing LRM pins the quote to its number rather than letting
    // the bidi algorithm float it to the visual start of the line.
    $inch = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang).'"'.($lang === 'fa' ? "\u{200E}" : '');

    $tplName = function (string $code) use ($templates, $lang): string {
        foreach ($templates as $tpl) {
            if ($tpl['code'] === $code) {
                return $tpl['name'][$lang] ?? $tpl['name']['en'] ?? $code;
            }
        }
        return $code;
    };

    $q = ['lang' => $lang] + (request()->has('sid') ? ['sid' => request()->query('sid')] : []);
    $base = "/projects/{$project->code}/piping/{$line->id}/model?".http_build_query($q);
?>
<!doctype html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($line->line_number); ?> — <?php echo e($L('title')); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub">
    <span class="mono"><?php echo e($line->line_number); ?></span>
    · <?php echo e($line->subsystem?->code); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($line->nominal_size): ?> · <bdi><?php echo e($num($line->nominal_size)); ?>"</bdi> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($line->pipingClass): ?> · <?php echo e($line->pipingClass->code); ?> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;"><?php echo e($L('sub')); ?></p>
  <p style="margin:10px 0 0;">
    <a href="/projects/<?php echo e($project->code); ?>/piping?<?php echo e(http_build_query($q)); ?>"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; <?php echo e($L('back')); ?></a>
    <a href="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/baseline?<?php echo e(http_build_query($q)); ?>"
       style="color:var(--accent,#60a5fa);font-size:13px;margin-inline-start:14px;"><?php echo e($lang === 'fa' ? 'آشتی‌دادن MTO با مدل' : 'MTO versus model'); ?> &rarr;</a>
  </p>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! $editable): ?>
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:13px;line-height:1.7;">
      <?php echo e($L('locked')); ?>

    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $message): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($message); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <h2 style="margin-top:26px;font-size:16px;"><?php echo e($L('openends')); ?>

    <span style="color:var(--muted);font-weight:400;font-size:13px;">(<?php echo e($num(count($open))); ?>)</span>
  </h2>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($open) === 0): ?>
    <div style="margin-top:10px;padding:10px 12px;border-radius:8px;border:1px solid #14532d;background:rgba(20,83,45,.25);color:#86efac;font-size:13px;">
      <?php echo e($L('complete')); ?>

    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-top:10px;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $open; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $port): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="border:1px solid var(--line);border-radius:10px;background:var(--card);padding:10px 12px;">
          <div class="mono" style="font-size:13px;"><bdi><?php echo e($port->component->label()); ?></bdi></div>
          <div style="color:var(--muted);font-size:12px;margin-top:2px;"><bdi><?php echo e($port->label()); ?></bdi>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($port->end_type): ?> · <?php echo e($port->end_type); ?> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </div>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($editable): ?>
          <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/model/attach?<?php echo e(http_build_query($q)); ?>"
                style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="port_id" value="<?php echo e($port->id); ?>">
            <select name="template_code" required
                    style="flex:1 1 60%;min-width:0;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $templates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tpl): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($tpl['code']); ?>"><?php echo e($tpl['name'][$lang] ?? $tpl['name']['en']); ?> (<?php echo e($num($tpl['ports'])); ?>)</option>
              <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
            <button type="submit"
                    style="padding:5px 12px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
              <?php echo e($L('attachbtn')); ?>

            </button>
          </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:8px;"><?php echo e($L('inherit')); ?></p>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <h2 style="margin-top:28px;font-size:16px;"><?php echo e($L('title')); ?>

    <span style="font-weight:400;font-size:11.5px;color:var(--muted);margin-inline-start:10px;">
      <span style="color:#86efac;">●</span> <?php echo e($fa ? 'متصل' : 'connected'); ?>

      &nbsp;<span style="color:#fcd34d;">○</span> <?php echo e($fa ? 'باز' : 'open'); ?>

    </span>
  </h2>

  <table data-tt style="margin-top:10px;">
    <thead>
      <tr>
        <th data-sort="number"><?php echo e($L('seq')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('type')); ?></th>
        <th data-sort="text"><?php echo e($L('size')); ?></th>
        <th><?php echo e($L('ports')); ?></th>
        <th data-sort="number"><?php echo e($L('length')); ?></th>
        <th><?php echo e($L('actions')); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
      <tr>
        <td class="mono"><?php echo e($num($c->sequence)); ?></td>
        <td><?php echo e($tplName($c->template_code)); ?>

          <div style="color:var(--muted);font-size:11px;" class="mono"><?php echo e($c->template_code); ?></div>
        </td>
        <td class="mono"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($c->nominal_size): ?><bdi><?php echo e($num($c->nominal_size)); ?>"</bdi><?php else: ?>—<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></td>
        <td style="font-size:12px;">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $c->ports; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            
            <span style="display:inline-block;margin:1px 3px 1px 0;padding:1px 7px;border-radius:999px;font-size:11px;
                         border:1px <?php echo e($p->is_connected ? 'solid #15803d' : 'dashed #f59e0b'); ?>;
                         background:<?php echo e($p->is_connected ? 'rgba(21,128,61,.28)' : 'rgba(245,158,11,.12)'); ?>;
                         color:<?php echo e($p->is_connected ? '#86efac' : '#fcd34d'); ?>;"
                  title="<?php echo e($p->is_connected ? ($fa ? 'متصل' : 'connected') : ($fa ? 'باز' : 'open')); ?>">
              
              <?php echo e($p->is_connected ? '●' : '○'); ?>

              <bdi>P<?php echo e($num($p->seq)); ?> <?php echo e($p->role); ?><?php echo e($p->nominal_size ? ' '.$num($p->nominal_size).'"' : ''); ?></bdi>
            </span>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td class="mono"><?php echo e($c->length_mm ? $num(rtrim(rtrim(number_format((float) $c->length_mm, 1, '.', ''), '0'), '.')) : '—'); ?></td>
        <td>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($editable): ?>
          <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/model/<?php echo e($c->id); ?>/delete?<?php echo e(http_build_query($q)); ?>"
                onsubmit="return confirm('<?php echo e($L('remove')); ?>?')">
            <?php echo csrf_field(); ?>
            <button type="submit"
                    style="padding:4px 10px;border-radius:6px;border:1px solid var(--line);background:transparent;color:#fca5a5;font:inherit;font-size:12px;cursor:pointer;">
              <?php echo e($L('remove')); ?>

            </button>
          </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
      <tr><td colspan="6" style="color:var(--muted);"><?php echo e($L('none')); ?></td></tr>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>

  
  <h2 style="margin-top:28px;font-size:16px;"><?php echo e($L('joints')); ?>

    <span style="font-weight:400;font-size:11.5px;color:var(--muted);margin-inline-start:10px;">
      
      <bdi><?php echo e($num($metrics['welds'])); ?> <?php echo e($L('jwelds')); ?></bdi>
      <span style="color:var(--line);">|</span>
      <bdi><?php echo e($num($metrics['fitted'])); ?> <?php echo e($L('jfitted')); ?></bdi>
      <span style="color:var(--line);">|</span>
      <bdi><?php echo e($num(rtrim(rtrim(number_format($metrics['dia_inch'], 2, '.', ''), '0'), '.'))); ?> <?php echo e($L('jdia')); ?></bdi>
    </span>
  </h2>
  <p style="color:var(--muted);font-size:11.5px;margin:4px 0 0;"><?php echo e($L('jdianote')); ?></p>

  <table data-tt style="margin-top:10px;">
    <thead>
      <tr>
        <th data-sort="number"><?php echo e($L('jnum')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('jtype')); ?></th>
        <th><?php echo e($L('jbetween')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('jstate')); ?></th>
        <th data-sort="number"><?php echo e($L('jdia')); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $joints; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $j): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
      <?php
        $dia = $diaInch($j);
        $cA = $j->portA?->component;
        $cB = $j->portB?->component;
      ?>
      <tr>
        <td class="mono">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($j->displayNumber()): ?>
            <bdi><?php echo e($num($j->displayNumber())); ?></bdi>
          <?php else: ?>
            
            <span style="color:var(--muted);font-size:11px;"><?php echo e($L('junnum')); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td style="font-size:12px;"><bdi><?php echo e($j->joint_type); ?></bdi>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($j->compatibility_state !== 'VALID'): ?>
            <span style="display:inline-block;margin-inline-start:6px;padding:1px 7px;border-radius:999px;font-size:10.5px;
                         border:1px dashed #f59e0b;background:rgba(245,158,11,.12);color:#fcd34d;"
                  title="<?php echo e(implode(' · ', collect($j->compatibility_notes['reasons'] ?? [])->map(fn ($r) => __('epcc.joint.reason.'.$r['code'], $r['params']))->all())); ?>">
              ⚠ <?php echo e($j->compatibility_state); ?>

            </span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td style="font-size:12px;">
          <bdi><?php echo e($cA ? $num($cA->sequence).' '.$cA->template_code : '?'); ?></bdi>
          <span style="color:var(--muted);">↔</span>
          <bdi><?php echo e($cB ? $num($cB->sequence).' '.$cB->template_code : '?'); ?></bdi>
        </td>
        <td style="font-size:12px;">
          <span style="display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;
                       border:1px <?php echo e($j->isFitted() ? 'solid #15803d' : 'dashed var(--line)'); ?>;
                       background:<?php echo e($j->isFitted() ? 'rgba(21,128,61,.28)' : 'transparent'); ?>;
                       color:<?php echo e($j->isFitted() ? '#86efac' : 'var(--muted)'); ?>;">
            <?php echo e($j->isFitted() ? '●' : '○'); ?> <bdi><?php echo e($j->state); ?></bdi>
          </span>
        </td>
        <td class="mono">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($dia !== null): ?>
            <bdi><?php echo e($num(rtrim(rtrim(number_format($dia, 2, '.', ''), '0'), '.'))); ?></bdi>
          <?php else: ?>
            <span style="color:var(--muted);">—</span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
      <tr><td colspan="5" style="color:var(--muted);"><?php echo e($L('jnone')); ?></td></tr>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>

  
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($editable): ?>
  <div style="margin-top:22px;border:1px solid var(--line);border-radius:10px;background:var(--card);padding:14px;">
    <h2 style="margin:0 0 4px;font-size:15px;"><?php echo e($L('add')); ?></h2>
    <p style="color:var(--muted);font-size:12px;margin:0 0 12px;line-height:1.7;"><?php echo e($L('portsnote')); ?></p>

    <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/model?<?php echo e(http_build_query($q)); ?>"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;">
      <?php echo csrf_field(); ?>

      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('type')); ?>

        <select name="template_code" required
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $templates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tpl): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($tpl['code']); ?>" <?php if(old('template_code') === $tpl['code']): echo 'selected'; endif; ?>>
              <?php echo e($tpl['name'][$lang] ?? $tpl['name']['en']); ?> — <?php echo e($num($tpl['ports'])); ?> <?php echo e($L('ports')); ?>

            </option>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('size')); ?>

        <select name="nominal_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $sizes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($s); ?>" <?php if(old('nominal_size', $line->nominal_size) === $s): echo 'selected'; endif; ?>><?php echo e($inch($s)); ?></option>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('branch')); ?>

        <select name="branch_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $sizes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($s); ?>" <?php if(old('branch_size') === $s): echo 'selected'; endif; ?>><?php echo e($inch($s)); ?></option>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('small')); ?>

        <select name="small_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $sizes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($s); ?>" <?php if(old('small_size') === $s): echo 'selected'; endif; ?>><?php echo e($inch($s)); ?></option>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('lenmm')); ?>

        <input type="number" step="any" min="0" name="length_mm" value="<?php echo e(old('length_mm')); ?>"
               style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
      </label>

      <button type="submit"
              style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
        <?php echo e($L('addbtn')); ?>

      </button>
    </form>
  </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="foot">
    <?php echo e($fa
        ? 'تعداد و نقش پورت‌ها از تمپلیت قطعه می‌آید، نه از ورودی کاربر؛ سایز هنگام اتصال از پورت والد به ارث می‌رسد. به همین دلیل «لولهٔ ۶ اینچ روی انشعاب ۲ اینچ» اصلاً قابل بیان نیست.'
        : 'Port count and roles come from the component template, not from user input; size is inherited from the parent port on attachment. That is why "6\" pipe onto a 2\" branch" is not expressible at all.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/model.blade.php ENDPATH**/ ?>