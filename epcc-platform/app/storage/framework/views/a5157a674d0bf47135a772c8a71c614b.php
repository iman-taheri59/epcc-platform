<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'خطوط لوله', 'en' => 'Piping lines'],
        'sub'     => ['fa' => 'رجیستر خطوط، شماره‌گذاری پروژه‌ای و دروازهٔ AFC',
                      'en' => 'Line register, project numbering and the AFC gate'],
        'number'  => ['fa' => 'شمارهٔ خط', 'en' => 'Line number'],
        'subsys'  => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'size'    => ['fa' => 'سایز', 'en' => 'Size'],
        'service' => ['fa' => 'سرویس', 'en' => 'Service'],
        'class'   => ['fa' => 'کلاس', 'en' => 'Class'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'evidence'=> ['fa' => 'مدارک AFC', 'en' => 'AFC evidence'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'new'     => ['fa' => 'ثبت خط جدید', 'en' => 'Add a line'],
        'add'     => ['fa' => 'ثبت خط', 'en' => 'Add line'],
        'empty'   => ['fa' => 'هنوز خطی ثبت نشده است.', 'en' => 'No lines yet.'],
        'total'   => ['fa' => 'خط', 'en' => 'lines'],
        'draft'   => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
        'afc'     => ['fa' => 'تأیید AFC', 'en' => 'AFC'],
        'rel'     => ['fa' => 'ابلاغ‌شده', 'en' => 'Released'],
        'ext'     => ['fa' => 'شمارهٔ بیرونی', 'en' => 'External no.'],
        'pattern' => ['fa' => 'قالب شمارهٔ پروژه', 'en' => 'Project number format'],
        'autonum' => ['fa' => 'خالی بگذارید تا سیستم شماره بدهد', 'en' => 'Leave blank to allocate a number'],
        'orsupply'=> ['fa' => 'یا شمارهٔ موجود (از P&ID یا مدل سه‌بعدی)', 'en' => 'or an existing number (from P&ID or the 3D model)'],
        'missing' => ['fa' => 'مدرک ندارد:', 'en' => 'Missing:'],
        'notappr' => ['fa' => 'تأییدنشده:', 'en' => 'Not approved:'],
        'ready'   => ['fa' => 'آمادهٔ AFC', 'en' => 'Ready for AFC'],
        'note'    => ['fa' => 'توضیح', 'en' => 'Note'],
        'reason'  => ['fa' => 'دلیل بازگشت (الزامی)', 'en' => 'Reason (required)'],
        'nonconf' => ['fa' => 'خارج از قالب پروژه', 'en' => 'Outside the project format'],
        'pickdoc' => ['fa' => 'مدرک…', 'en' => 'Document…'],
        'link'    => ['fa' => 'پیوست', 'en' => 'Link'],
        'unlink'  => ['fa' => 'حذف پیوست', 'en' => 'Remove link'],
        'config'  => ['fa' => 'تنظیم شماره‌گذاری', 'en' => 'Numbering setup'],
        'model'   => ['fa' => 'مدل', 'en' => 'Model'],
        'noreg'   => ['fa' => 'این فهرست برای پروژه تعریف نشده است؛ تا تعریف نشود شماره‌گذاری خودکار ممکن نیست.',
                      'en' => 'Not set up for this project yet - automatic numbering needs it first.'],
        'dp'      => ['fa' => 'فشار طراحی', 'en' => 'Design pressure'],
        'dt'      => ['fa' => 'دمای طراحی', 'en' => 'Design temperature'],
        'tp'      => ['fa' => 'فشار تست', 'en' => 'Test pressure'],
        'cond'    => ['fa' => 'شرایط طراحی', 'en' => 'Design conditions'],
        'units'   => ['fa' => 'واحدها', 'en' => 'Units'],
        'unitsave'=> ['fa' => 'ذخیرهٔ واحدها', 'en' => 'Save units'],
        'mine'    => ['fa' => 'فقط برای من', 'en' => 'Just for me'],
        'forproj' => ['fa' => 'پیش‌فرض پروژه', 'en' => 'Project default'],
        'asent'   => ['fa' => 'ثبت‌شده', 'en' => 'as entered'],
        'unitsnote'=> ['fa' => 'مقدار همان‌طور که وارد شده ذخیره می‌شود؛ این انتخاب فقط نحوهٔ نمایش را عوض می‌کند.',
                       'en' => 'The value is stored exactly as entered; this choice only changes how it is displayed.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    // Render a stored value in the reader's chosen unit, and say what was
    // actually typed when the two differ. Showing only the converted number
    // would quietly replace the figure that appears on the drawing.
    $reg = app(\App\Support\Units\UnitRegistry::class);
    $show = function ($value, ?string $unit, string $quantity) use ($reg, $displayUnits, $num, $t, $lang): string {
        if ($value === null || $unit === null || $unit === '') {
            return '—';
        }
        $want = $displayUnits[$quantity] ?? $unit;
        $entered = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        if ($want === $unit || ! $reg->knows($unit)) {
            return $num($entered).' '.$unit;
        }

        try {
            $converted = $reg->convert((float) $value, $unit, $want);
        } catch (\Throwable $e) {
            // Gauge/absolute, most likely: refusing is correct, so show the
            // original rather than a number nobody can defend.
            return $num($entered).' '.$unit;
        }

        $shown = rtrim(rtrim(number_format($converted, 2, '.', ''), '0'), '.');
        $note = $t['asent'][$lang] ?? 'as entered';

        return $num($shown).' '.$want
            .' <span style="color:var(--muted);font-size:11px;" title="'.$note.'">('.$num($entered).' '.$unit.')</span>';
    };
?>
<!doctype html>
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

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub">
    <span class="mono"><?php echo e($project->code); ?></span>
    · <?php echo e($project->name($lang)); ?>

    · <?php echo e($jalaliDate); ?>

  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(127,29,29,.35);border:1px solid #991b1b;color:#fecaca;font-size:13px;line-height:1.8;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($error); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="bar">
    <span class="count"><b><?php echo e($num($stats['total'])); ?></b> <?php echo e($L('total')); ?></span>
    <span class="pill"><?php echo e($L('draft')); ?>: <?php echo e($num($stats['draft'])); ?></span>
    <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;"><?php echo e($L('afc')); ?>: <?php echo e($num($stats['afc'])); ?></span>
    <span class="pill ok"><?php echo e($L('rel')); ?>: <?php echo e($num($stats['released'])); ?></span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stats['external'] > 0): ?>
      <span class="pill" style="opacity:.8;"><?php echo e($L('ext')); ?>: <?php echo e($num($stats['external'])); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <span style="margin-<?php echo e($fa ? 'right' : 'left'); ?>:auto;font-size:12px;color:var(--muted);">
      <?php echo e($L('pattern')); ?>: <code><?php echo e($pattern); ?></code>
      · <a href="/projects/<?php echo e($project->code); ?>/piping/config?lang=<?php echo e($lang); ?>"><?php echo e($L('config')); ?></a>
      · <a href="/projects/<?php echo e($project->code); ?>/piping/pms?lang=<?php echo e($lang); ?>"><?php echo e($lang === 'fa' ? 'ورود اطلاعات PMS' : 'PMS data entry'); ?></a>
      · <a href="/projects/<?php echo e($project->code); ?>/piping/project-fittings?lang=<?php echo e($lang); ?>"><?php echo e($lang === 'fa' ? 'تعریف‌های اختصاصی متریال' : 'Project material declarations'); ?></a>
    </span>
  </div>

  <table data-tt>
    <thead>
      <tr>
        <th data-sort="text"><?php echo e($L('number')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('subsys')); ?></th>
        <th data-sort="text"><?php echo e($L('size')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('class')); ?></th>
        <th data-sort="text"><?php echo e($L('dp')); ?></th>
        <th data-sort="text"><?php echo e($L('dt')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('state')); ?></th>
        <th><?php echo e($L('evidence')); ?></th>
        <th><?php echo e($L('actions')); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $line): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
      <?php
        $b = $blockers[$line->id] ?? null;
        $ready = $b !== null && $b['missing'] === [] && $b['unapproved'] === [];
        $label = \App\Support\LineReleaseState::label((string) $line->release_state);
      ?>
      <tr>
        <td class="mono">
          <?php echo e($line->line_number); ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($line->sequence === null): ?>
            
            <div style="font-size:11px;color:var(--muted);"><?php echo e($L('ext')); ?></div>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td><?php echo e($line->subsystem?->code); ?></td>
        <td><?php echo e($line->nominal_size ? $num($line->nominal_size).'"' : '—'); ?></td>
        <td><?php echo e($line->pipingClass?->code ?? '—'); ?></td>
        <td class="mono" style="white-space:nowrap;">
          <?php echo $show($line->design_pressure, $line->design_pressure_unit, 'PRESSURE'); ?>

        </td>
        <td class="mono" style="white-space:nowrap;">
          <?php echo $show($line->design_temperature, $line->design_temperature_unit, 'TEMPERATURE'); ?>

        </td>
        <td>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($line->release_state === \App\Support\LineReleaseState::RELEASED): ?>
            <span class="pill ok"><?php echo e($fa ? $label['fa'] : $label['en']); ?></span>
          <?php elseif($line->release_state === \App\Support\LineReleaseState::AFC_CONFIRMED): ?>
            <span class="pill" style="background:#78350f;color:#fde68a;border:1px solid #92400e;"><?php echo e($fa ? $label['fa'] : $label['en']); ?></span>
          <?php else: ?>
            <span class="pill"><?php echo e($fa ? $label['fa'] : $label['en']); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td style="font-size:11.5px;line-height:1.7;min-width:230px;">
          <?php $links = $attached[$line->id] ?? collect(); ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $links; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $link): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
              $rev = $link->document?->currentRevision;
              $ok = $rev !== null && \App\Support\CdeState::isApproved((string) $rev->cde_state, $rev->suitability);
            ?>
            <div style="display:flex;gap:5px;align-items:baseline;">
              <span style="color:<?php echo e($ok ? '#6ee7b7' : '#fcd34d'); ?>;"><?php echo e($ok ? '✓' : '•'); ?></span>
              <span class="mono" style="font-size:11px;"><?php echo e($link->document?->number); ?></span>
              <span style="color:var(--muted);font-size:10.5px;">
                <?php echo e($link->evidence_kind); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rev): ?> · <?php echo e($rev->cde_state); ?>/<?php echo e($rev->suitability ?: '—'); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </span>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\LineReleaseState::isEditable((string) $line->release_state)): ?>
                <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/evidence/<?php echo e($link->id); ?>/detach?lang=<?php echo e($lang); ?>" style="display:inline;">
                  <?php echo csrf_field(); ?>
                  <button type="submit" title="<?php echo e($L('unlink')); ?>"
                          style="border:0;background:none;color:#fca5a5;cursor:pointer;font:inherit;font-size:11px;padding:0 2px;">×</button>
                </form>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($b !== null): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ready): ?>
              <div style="color:#6ee7b7;margin-top:3px;"><?php echo e($L('ready')); ?></div>
            <?php else: ?>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($b['missing'] !== []): ?>
                <div style="color:#fca5a5;"><?php echo e($L('missing')); ?> <?php echo e(implode(', ', $b['missing'])); ?></div>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($b['unapproved'] !== []): ?>
                <div style="color:#fcd34d;"><?php echo e($L('notappr')); ?> <?php echo e(implode(', ', $b['unapproved'])); ?></div>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\LineReleaseState::isEditable((string) $line->release_state)): ?>
            <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/evidence?lang=<?php echo e($lang); ?>"
                  style="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;">
              <?php echo csrf_field(); ?>
              <select name="document_id" required style="max-width:118px;padding:3px 4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:10.5px;">
                <option value=""><?php echo e($L('pickdoc')); ?></option>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $documents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($doc->id); ?>"><?php echo e($doc->number); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
              <select name="evidence_kind" required style="max-width:96px;padding:3px 4px;border-radius:5px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:10.5px;">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $evidenceKinds; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kind): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($kind); ?>" <?php if(in_array($kind, $requiredKinds, true) && in_array($kind, $b['missing'] ?? [], true)): echo 'selected'; endif; ?>><?php echo e($kind); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
              <button type="submit" style="padding:3px 8px;border-radius:5px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:10.5px;cursor:pointer;">
                <?php echo e($L('link')); ?>

              </button>
            </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
          
          <a href="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/model?lang=<?php echo e($lang); ?>"
             style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font-size:11.5px;text-decoration:none;">
            <?php echo e($L('model')); ?>

          </a>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = \App\Support\LineReleaseState::nextStates((string) $line->release_state); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $next): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/<?php echo e($line->id); ?>/transition?lang=<?php echo e($lang); ?>"
                  style="display:inline-flex;gap:4px;align-items:center;">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="to" value="<?php echo e($next); ?>">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($next === \App\Support\LineReleaseState::DRAFT): ?>
                <input type="text" name="note" required maxlength="500"
                       placeholder="<?php echo e($L('reason')); ?>"
                       style="width:120px;padding:4px 6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:11px;">
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              <?php $nl = \App\Support\LineReleaseState::label($next); ?>
              <button type="submit" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:11.5px;cursor:pointer;">
                <?php echo e($fa ? $nl['fa'] : $nl['en']); ?>

              </button>
            </form>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
      <tr><td colspan="7" class="empty"><?php echo e($L('empty')); ?></td></tr>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:22px;padding:14px;border-radius:10px;border:1px solid var(--line);background:var(--card);">
    <div style="font-size:13px;font-weight:600;margin-bottom:10px;"><?php echo e($L('new')); ?></div>
    <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping?lang=<?php echo e($lang); ?>"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;">
      <?php echo csrf_field(); ?>
      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('subsys')); ?>

        <select name="subsystem_id" required style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $subsystems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($s->id); ?>"><?php echo e($s->code); ?></option>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
      </label>

      <?php
          // A token the pattern uses but the project has not populated cannot
          // be composed - the engine refuses rather than invent a value. Show
          // that as an empty, disabled register instead of a free-text box
          // that can only produce an error on submit.
          $needs = fn (string $tok): bool => in_array($tok, $patternTokens, true);
      ?>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($needs('size')): ?>
      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('size')); ?>

        <select name="nominal_size" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $vocab['size'] ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($v); ?>"><?php echo e($v); ?>"</option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
      </label>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($needs('service')): ?>
      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('service')); ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($vocab['service'] ?? []) === []): ?>
          <div style="margin-top:4px;padding:6px;border-radius:6px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:11px;line-height:1.6;">
            <?php echo e($L('noreg')); ?>

          </div>
        <?php else: ?>
          <select name="service_code" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <option value="">—</option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $vocab['service']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($v); ?>"><?php echo e($v); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </label>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($needs('class')): ?>
      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L('class')); ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($classes->isEmpty()): ?>
          <div style="margin-top:4px;padding:6px;border-radius:6px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:11px;line-height:1.6;">
            <?php echo e($L('noreg')); ?>

          </div>
        <?php else: ?>
          <select name="piping_class_id" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <option value="">—</option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $classes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($c->id); ?>"><?php echo e($c->code); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </label>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($needs('coating')): ?>
      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($fa ? 'پوشش' : 'Coating'); ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($vocab['coating'] ?? []) === []): ?>
          <div style="margin-top:4px;padding:6px;border-radius:6px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:11px;line-height:1.6;">
            <?php echo e($L('noreg')); ?>

          </div>
        <?php else: ?>
          <select name="coating_code" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <option value="">—</option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $vocab['coating']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($v); ?>"><?php echo e($v); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </label>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

      
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = [['design_pressure','dp','PRESSURE'],['design_temperature','dt','TEMPERATURE'],['test_pressure','tp','PRESSURE']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$field, $key, $qty]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <label style="font-size:12px;color:var(--muted);">
        <?php echo e($L($key)); ?>

        <span style="display:flex;gap:4px;margin-top:4px;">
          <input type="number" step="any" name="<?php echo e($field); ?>" value="<?php echo e(old($field)); ?>"
                 style="flex:1 1 60%;min-width:0;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <select name="<?php echo e($field); ?>_unit"
                  style="flex:0 0 38%;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $unitMenu[$qty]['units']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <option value="<?php echo e($u['code']); ?>"
                <?php if(old($field.'_unit', $displayUnits[$qty] ?? '') === $u['code']): echo 'selected'; endif; ?>><?php echo e($u['code']); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        </span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = [$field.'_unit'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
          <span style="display:block;margin-top:3px;color:#fca5a5;font-size:11px;"><?php echo e($message); ?></span>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </label>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

      <label style="font-size:12px;color:var(--muted);grid-column:span 2;">
        <?php echo e($L('orsupply')); ?>

        <input type="text" name="line_number" placeholder="<?php echo e($L('autonum')); ?>" style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
      </label>

      <button type="submit" style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;cursor:pointer;">
        <?php echo e($L('add')); ?>

      </button>
    </form>
  </div>

  
  <details style="margin-top:18px;border:1px solid var(--line);border-radius:10px;background:var(--card);">
    <summary style="padding:10px 14px;cursor:pointer;font-weight:600;"><?php echo e($L('units')); ?></summary>
    <form method="POST" action="<?php echo e(route('piping.units', ['projectCode' => $project->code, 'lang' => $lang])); ?>"
          style="padding:0 14px 14px;">
      <?php echo csrf_field(); ?>
      <p style="font-size:12px;color:var(--muted);line-height:1.7;margin:4px 0 12px;"><?php echo e($L('unitsnote')); ?></p>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $unitMenu; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $qty => $meta): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <label style="font-size:12px;color:var(--muted);">
          <?php echo e($meta['label']); ?>

          <select name="units[<?php echo e($qty); ?>]"
                  style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $meta['units']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <option value="<?php echo e($u['code']); ?>" <?php if(($displayUnits[$qty] ?? '') === $u['code']): echo 'selected'; endif; ?>>
                <?php echo e($u['label']); ?> (<?php echo e($u['code']); ?>)
              </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        </label>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </div>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['units'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
        <p style="margin:10px 0 0;color:#fca5a5;font-size:12px;"><?php echo e($message); ?></p>
      <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

      <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <button type="submit" name="scope" value="user"
                style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;cursor:pointer;">
          <?php echo e($L('unitsave')); ?> — <?php echo e($L('mine')); ?>

        </button>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($unitsLocked)): ?>
          <button type="submit" name="scope" value="project"
                  style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
            <?php echo e($L('unitsave')); ?> — <?php echo e($L('forproj')); ?>

          </button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </div>
    </form>
  </details>

  <div class="foot">
    <?php echo e($fa
        ? 'قالب شمارهٔ خط از PMS پروژه می‌آید و قابل پیکربندی است؛ خطوطی که از P&ID یا مدل سه‌بعدی می‌آیند با شمارهٔ خودشان ثبت می‌شوند و سیستم شماره‌ای اختراع نمی‌کند.'
        : 'The line number format comes from the project PMS and is configurable; lines arriving from a P&ID or the 3D model keep their own number and the system never invents one.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/index.blade.php ENDPATH**/ ?>