<?php
    $fa = $lang === 'fa';
    $t = fn (string $k): string => __('epcc.line.config.'.$k, [], $lang);
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);

    $kindLabel = [
        'attribute' => ['fa' => 'ویژگی خط', 'en' => 'Line attribute'],
        'parent'    => ['fa' => 'از والد', 'en' => 'From parent'],
        'counter'   => ['fa' => 'شمارنده', 'en' => 'Counter'],
        'literal'   => ['fa' => 'متن ثابت', 'en' => 'Fixed text'],
    ];
    $inp = 'width:100%;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;';
?>
<!doctype html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($project->name($lang)); ?> — <?php echo e($t('title')); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($t('title')); ?></h1>
  <div class="sub">
    <span class="mono"><?php echo e($project->code); ?></span> · <?php echo e($project->name($lang)); ?>

    · <a href="/projects/<?php echo e($project->code); ?>/piping?lang=<?php echo e($lang); ?>"><?php echo e($fa ? 'بازگشت به خطوط' : 'Back to lines'); ?></a>
  </div>

  <p style="max-width:70ch;font-size:13px;line-height:1.9;color:var(--muted);margin:14px 0 20px;">
    <?php echo e($t('intro')); ?>

  </p>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(6,78,59,.35);border:1px solid #065f46;color:#6ee7b7;font-size:13px;">
      <?php echo e(session('status')); ?>

    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;background:rgba(127,29,29,.35);border:1px solid #991b1b;color:#fecaca;font-size:13px;line-height:1.9;">
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($error); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($canEdit)): ?>
    <div style="margin:14px 0;padding:10px 14px;border-radius:10px;border:1px dashed #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:13px;line-height:1.8;">
      <?php echo e($t('owner_only')); ?>

    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <div style="padding:16px;border-radius:10px;border:1px solid var(--line);background:var(--card);">
    <div style="font-size:13px;font-weight:600;margin-bottom:4px;"><?php echo e($t('pattern')); ?></div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:14px;">
      <?php echo e($t('preview')); ?>: <code style="font-size:13px;"><?php echo e($pattern->sketch()); ?></code>
    </div>

    <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/config/pattern?lang=<?php echo e($lang); ?>">
      <?php echo csrf_field(); ?>

      <label style="display:inline-block;font-size:12px;color:var(--muted);margin-bottom:14px;">
        <?php echo e($t('separator')); ?>

        <input type="text" name="separator" value="<?php echo e($pattern->separator); ?>" maxlength="3"
               <?php echo e($canEdit ? '' : 'disabled'); ?>

               style="<?php echo e($inp); ?>width:70px;margin-top:4px;text-align:center;">
      </label>

      
      <div style="overflow-x:auto;">
      <table data-tt-exempt style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="text-align:<?php echo e($fa ? 'right' : 'left'); ?>;color:var(--muted);">
            <th style="padding:6px 8px;font-weight:500;">#</th>
            <th style="padding:6px 8px;font-weight:500;"><?php echo e($fa ? 'نام توکن' : 'Token'); ?></th>
            <th style="padding:6px 8px;font-weight:500;"><?php echo e($fa ? 'منبع' : 'Source'); ?></th>
            <th style="padding:6px 8px;font-weight:500;"><?php echo e($fa ? 'ستون / مسیر' : 'Attribute'); ?></th>
            <th style="padding:6px 8px;font-weight:500;"><?php echo e($fa ? 'عرض' : 'Width'); ?></th>
            <th style="padding:6px 8px;font-weight:500;"><?php echo e($fa ? 'اختیاری' : 'Optional'); ?></th>
            <th style="padding:6px 8px;font-weight:500;"><?php echo e($fa ? 'مقادیر ثابت' : 'Fixed values'); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $pattern->tokens(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $tok): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <tr style="border-top:1px solid var(--line);">
            <td style="padding:6px 8px;color:var(--muted);"><?php echo e($num($i + 1)); ?></td>
            <td style="padding:6px 8px;">
              <input type="text" name="tokens[<?php echo e($i); ?>][token]" value="<?php echo e($tok->name); ?>" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>">
            </td>
            <td style="padding:6px 8px;">
              <select name="tokens[<?php echo e($i); ?>][source]" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tokenKinds; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($k); ?>" <?php if($tok->kind === $k): echo 'selected'; endif; ?>><?php echo e($fa ? $kindLabel[$k]['fa'] : $kindLabel[$k]['en']); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
            </td>
            <td style="padding:6px 8px;">
              <input type="text" name="tokens[<?php echo e($i); ?>][attribute]" value="<?php echo e($tok->attribute); ?>" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>">
            </td>
            <td style="padding:6px 8px;">
              <input type="number" name="tokens[<?php echo e($i); ?>][width]" value="<?php echo e($tok->width ?: ''); ?>" min="0" max="9" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>width:64px;">
            </td>
            <td style="padding:6px 8px;text-align:center;">
              <input type="checkbox" name="tokens[<?php echo e($i); ?>][optional]" value="1" <?php if($tok->optional): echo 'checked'; endif; ?> <?php echo e($canEdit ? '' : 'disabled'); ?>>
            </td>
            <td style="padding:6px 8px;">
              <input type="text" name="tokens[<?php echo e($i); ?>][values]" value="<?php echo e(implode(', ', $tok->values)); ?>" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>">
            </td>
          </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($j = count($pattern->tokens()); $j < count($pattern->tokens()) + 2; $j++): ?>
          <tr style="border-top:1px solid var(--line);opacity:.75;">
            <td style="padding:6px 8px;color:var(--muted);"><?php echo e($num($j + 1)); ?></td>
            <td style="padding:6px 8px;"><input type="text" name="tokens[<?php echo e($j); ?>][token]" placeholder="<?php echo e($fa ? 'افزودن…' : 'add…'); ?>" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>"></td>
            <td style="padding:6px 8px;">
              <select name="tokens[<?php echo e($j); ?>][source]" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tokenKinds; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($k); ?>"><?php echo e($fa ? $kindLabel[$k]['fa'] : $kindLabel[$k]['en']); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
            </td>
            <td style="padding:6px 8px;"><input type="text" name="tokens[<?php echo e($j); ?>][attribute]" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>"></td>
            <td style="padding:6px 8px;"><input type="number" name="tokens[<?php echo e($j); ?>][width]" min="0" max="9" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>width:64px;"></td>
            <td style="padding:6px 8px;text-align:center;"><input type="checkbox" name="tokens[<?php echo e($j); ?>][optional]" value="1" <?php echo e($canEdit ? '' : 'disabled'); ?>></td>
            <td style="padding:6px 8px;"><input type="text" name="tokens[<?php echo e($j); ?>][values]" <?php echo e($canEdit ? '' : 'disabled'); ?> style="<?php echo e($inp); ?>"></td>
          </tr>
        <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </tbody>
      </table>
      </div>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($issued > 0): ?>
        <div style="margin-top:12px;font-size:11.5px;color:var(--muted);line-height:1.8;">
          <?php echo e($fa
              ? 'این پروژه تا شمارهٔ '.$num($issued).' خط صادر کرده است؛ عرض شمارنده نمی‌تواند کمتر از تعداد ارقام آن شود.'
              : 'This project has issued up to line '.$issued.'; the counter cannot be narrower than that many digits.'); ?>

        </div>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canEdit): ?>
        <button type="submit" style="margin-top:14px;padding:7px 16px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
          <?php echo e($t('save')); ?>

        </button>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </form>
  </div>

  
  <div style="margin-top:22px;font-size:13px;font-weight:600;"><?php echo e($t('registers')); ?></div>
  <div style="font-size:12px;color:var(--muted);margin:4px 0 12px;"><?php echo e($t('in_use_note')); ?></div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $registers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reg): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php $values = (array) ($config->{$reg} ?? []); ?>
      <div style="padding:14px;border-radius:10px;border:1px solid var(--line);background:var(--card);">
        <form method="POST" action="/projects/<?php echo e($project->code); ?>/piping/config/register?lang=<?php echo e($lang); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="register" value="<?php echo e($reg); ?>">
          <div style="font-size:12.5px;font-weight:600;margin-bottom:2px;"><?php echo e($t($reg)); ?></div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:8px;">
            <?php echo e($t('one_per_line')); ?> · <?php echo e($num(count($values))); ?>

          </div>
          <textarea name="values" rows="6" <?php echo e($canEdit ? '' : 'disabled'); ?>

                    style="<?php echo e($inp); ?>font-family:ui-monospace,monospace;resize:vertical;"><?php echo e(implode("\n", $values)); ?></textarea>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canEdit): ?>
            <button type="submit" style="margin-top:10px;padding:6px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
              <?php echo e($t('save')); ?>

            </button>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </form>
      </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>

  <div class="foot">
    <?php echo e($fa
        ? 'شماره‌های صادرشده هرگز با تغییر این تنظیمات بازنویسی نمی‌شوند؛ قالب تازه فقط بر خطوط بعدی اعمال می‌شود.'
        : 'Numbers already issued are never rewritten by a change here; a new format applies to subsequent lines only.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/piping/config.blade.php ENDPATH**/ ?>