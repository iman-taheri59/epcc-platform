<?php
    use App\Support\Itp\InterventionCode;
    use App\Support\Itp\StageResult;
    use App\Support\TenantType;

    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'بازرسی ورود متریال', 'en' => 'Incoming material inspection'],
        'prog'    => ['fa' => 'پیشرفت', 'en' => 'Progress'],
        'blocked' => ['fa' => 'این لات قابل ترخیص نیست', 'en' => 'This lot cannot be released'],
        'blocksub'=> ['fa' => 'کالا در انبار می‌ماند و شمرده می‌شود، اما تا آزادسازی نقطه‌های توقف زیر، اجازهٔ نصب ندارد.',
                      'en' => 'The material stays in the warehouse and is counted, but it may not be installed until the hold points below are released.'],
        'free'    => ['fa' => 'قابل ترخیص برای نصب', 'en' => 'Released for installation'],
        'clause'  => ['fa' => 'بند', 'en' => 'Clause'],
        'act'     => ['fa' => 'فعالیت', 'en' => 'Activity'],
        'who'     => ['fa' => 'ارکان', 'en' => 'Parties'],
        'res'     => ['fa' => 'نتیجه', 'en' => 'Result'],
        'action'  => ['fa' => 'اقدام', 'en' => 'Action'],
        'sign'    => ['fa' => 'ثبت نتیجه', 'en' => 'Record the outcome'],
        'invite'  => ['fa' => 'ثبت دعوت', 'en' => 'Record the invitation'],
        'ref'     => ['fa' => 'شمارهٔ نامه / مرجع', 'en' => 'Letter number / reference'],
        'note'    => ['fa' => 'شرح و دلیل', 'en' => 'Findings and reason'],
        'none'    => ['fa' => 'برای این لات هنوز ITP باز نشده است.', 'en' => 'No ITP has been opened for this lot yet.'],
        'openitp' => ['fa' => 'باز کردن ITP برای این لات', 'en' => 'Open an ITP for this lot'],
        'disc'    => ['fa' => 'دیسیپلین', 'en' => 'Discipline'],
        'go'      => ['fa' => 'باز کن', 'en' => 'Open'],
        'by'      => ['fa' => 'امضا', 'en' => 'Signed'],
        'invited' => ['fa' => 'دعوت‌شده', 'en' => 'Invited'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];
    $base = "/projects/{$project->code}/itp/lots/{$lot->id}";

    $colour = fn (string $c): string => match ($c) {
        InterventionCode::HOLD => '#f87171',
        InterventionCode::WITNESS => '#fbbf24',
        InterventionCode::MONITOR => '#60a5fa',
        InterventionCode::REVIEW => '#a78bfa',
        default => 'var(--muted)',
    };

    $resultColour = fn (string $r): string => match ($r) {
        StageResult::PASSED => '#86efac',
        StageResult::CONDITIONAL => '#fcd34d',
        StageResult::FAILED => '#fca5a5',
        StageResult::WAIVED => '#c4b5fd',
        StageResult::NOTIFIED => '#60a5fa',
        default => 'var(--muted)',
    };
?>
<!doctype html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?> — <?php echo e($lot->lot_code); ?></title>
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
    <span class="mono"><?php echo e($lot->lot_code); ?></span> · <?php echo e($lot->item_code); ?> ·
    <?php echo e($fa ? $lot->description_fa : $lot->description_en); ?>

  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $message): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($message); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($records) === 0): ?>
    <p style="color:var(--muted);font-size:13px;margin-top:14px;"><?php echo e($L('none')); ?></p>
    <form method="post" action="<?php echo e($base); ?>/open?<?php echo e(http_build_query($q)); ?>"
          style="margin-top:12px;padding:14px;border:1px solid var(--line,#1f2937);border-radius:10px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <?php echo csrf_field(); ?>
      <div style="display:grid;gap:5px;">
        <label style="font-size:12px;color:var(--muted);"><?php echo e($L('disc')); ?></label>
        <select name="discipline"
                style="font-size:13px;padding:6px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:7px;">
          <option value="PIPING">PIPING</option>
          <option value="PIPELINE">PIPELINE</option>
        </select>
      </div>
      <button type="submit" class="btn"><?php echo e($L('go')); ?></button>
    </form>
  <?php else: ?>
    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($holds) > 0): ?>
      <div style="margin-top:14px;padding:12px 14px;border-radius:9px;border:1px solid #7f1d1d;background:rgba(127,29,29,.22);">
        <strong style="color:#fca5a5;font-size:14px;"><?php echo e($L('blocked')); ?></strong>
        <div style="color:var(--muted);font-size:12px;line-height:1.8;margin-top:4px;max-width:780px;"><?php echo e($L('blocksub')); ?></div>
        <div style="margin-top:8px;font-size:12px;">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $holds; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $h): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div>
              <span class="mono" style="color:#fca5a5;"><?php echo e($num($h['stage']->clause)); ?></span>
              <?php echo e($h['stage']->title($lang)); ?>

              <span style="color:var(--muted);">— <?php echo e(implode('، ', $h['parties'])); ?></span>
            </div>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div style="margin-top:14px;padding:10px 14px;border-radius:9px;border:1px solid #14532d;background:rgba(20,83,45,.22);color:#86efac;font-size:13px;">
        <?php echo e($L('free')); ?>

      </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div style="margin-top:12px;font-size:12px;color:var(--muted);">
      <?php echo e($L('prog')); ?>: <?php echo e($num($progress['settled'])); ?> / <?php echo e($num($progress['total'])); ?>

      (<bdi><?php echo e($num($progress['percent'])); ?>%</bdi>)
    </div>

    <table data-tt style="margin-top:12px;">
      <thead><tr>
        <th><?php echo e($L('clause')); ?></th><th><?php echo e($L('act')); ?></th><th><?php echo e($L('who')); ?></th>
        <th><?php echo e($L('res')); ?></th><th><?php echo e($L('action')); ?></th>
      </tr></thead>
      <tbody>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $records; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $record): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $stage = $record->stage; ?>
        <tr>
          <td class="mono" style="font-size:12px;white-space:nowrap;">
            <?php echo e($num($stage->clause)); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->isHoldPoint()): ?>
              <div style="color:#f87171;font-size:10px;"><?php echo e($fa ? 'توقف' : 'HOLD'); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td style="font-size:13px;">
            <?php echo e($stage->title($lang)); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->acceptance($lang)): ?>
              <div style="color:var(--muted);font-size:11px;line-height:1.6;max-width:340px;"><?php echo e($stage->acceptance($lang)); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td style="font-size:12px;white-space:nowrap;">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $stage->parties; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <span style="color:<?php echo e($colour($p->intervention)); ?>;font-family:ui-monospace,monospace;"><?php echo e($p->tenant_type); ?>:<?php echo e($p->intervention); ?></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! $loop->last): ?> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td style="font-size:12px;color:<?php echo e($resultColour($record->result)); ?>;">
            <?php echo e(StageResult::label($record->result)[$lang]); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->signed_at): ?>
              <div style="color:var(--muted);font-size:11px;">
                <?php echo e($L('by')); ?>: <?php echo e($record->signed_tenant_type); ?> · <?php echo e(\App\Support\JalaliDate::format($record->signed_at, $lang)); ?>

              </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->notified_at && $record->isOpen()): ?>
              <div style="color:var(--muted);font-size:11px;">
                <?php echo e($L('invited')); ?>: <bdi class="mono"><?php echo e($record->notice_reference); ?></bdi>
              </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->findings($lang)): ?>
              <div style="color:var(--muted);font-size:11px;line-height:1.6;max-width:280px;"><?php echo e($record->findings($lang)); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->isOpen()): ?>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->notified_at === null): ?>
                <form method="post" action="<?php echo e($base); ?>/<?php echo e($record->id); ?>/notify?<?php echo e(http_build_query($q)); ?>"
                      style="display:flex;gap:5px;margin-bottom:6px;">
                  <?php echo csrf_field(); ?>
                  <input type="text" name="notice_reference" required placeholder="<?php echo e($L('ref')); ?>"
                         style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;width:150px;">
                  <button type="submit" class="btn" style="font-size:12px;padding:4px 8px;"><?php echo e($L('invite')); ?></button>
                </form>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

              <form method="post" action="<?php echo e($base); ?>/<?php echo e($record->id); ?>/sign?<?php echo e(http_build_query($q)); ?>"
                    style="display:grid;gap:5px;min-width:250px;">
                <?php echo csrf_field(); ?>
                <select name="result"
                        style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;">
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = [StageResult::PASSED, StageResult::CONDITIONAL, StageResult::FAILED, StageResult::WAIVED, StageResult::NOT_APPLICABLE]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! ($option === StageResult::WAIVED && $stage->isHoldPoint())): ?>
                      <option value="<?php echo e($option); ?>"><?php echo e(StageResult::label($option)[$lang]); ?></option>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
                <input type="text" name="<?php echo e($fa ? 'findings_fa' : 'findings_en'); ?>" placeholder="<?php echo e($L('note')); ?>"
                       style="font-size:12px;padding:5px;background:var(--panel,#111827);color:inherit;border:1px solid var(--line,#1f2937);border-radius:6px;">
                <button type="submit" class="btn" style="font-size:12px;padding:4px 9px;"><?php echo e($L('sign')); ?></button>
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
        ? 'نقطهٔ توقف، رسید انبار را مسدود نمی‌کند — کامیون ساعت ۲ بامداد می‌رسد و کالا باید شمرده شود. چیزی که مسدود می‌شود، ترخیص برای نصب است: کالا هست، دیده می‌شود، اما تا امضای رکن صاحب توقف داخل خط جوش نمی‌شود.'
        : 'A hold point does not block the goods receipt — the truck arrives at 2am and the material must be counted. What it blocks is release for installation: the material exists and is visible, but it is not welded into the plant until the holding party signs.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/itp/lot.blade.php ENDPATH**/ ?>