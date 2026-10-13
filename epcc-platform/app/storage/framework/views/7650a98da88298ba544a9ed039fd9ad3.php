<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'ایجاد پروژهٔ جدید', 'en' => 'Create a project'],
        'sub'     => ['fa' => 'بستهٔ استاندارد در زمان ایجاد انتخاب می‌شود و بعداً قابل تغییر نیست.', 'en' => 'The standards pack is chosen at creation and cannot be changed later.'],
        'ident'   => ['fa' => '۱. شناسهٔ پروژه', 'en' => '1. Project identity'],
        'code'    => ['fa' => 'کد پروژه', 'en' => 'Project code'],
        'codeh'   => ['fa' => 'مثال: PH-15 — فقط حروف بزرگ لاتین، عدد و خط تیره', 'en' => 'e.g. PH-15 — capitals, digits and hyphens only'],
        'nameen'  => ['fa' => 'نام پروژه (انگلیسی)', 'en' => 'Project name (English)'],
        'namefa'  => ['fa' => 'نام پروژه (فارسی)', 'en' => 'Project name (Persian)'],
        'pack'    => ['fa' => '۲. چارچوب استاندارد', 'en' => '2. Standards framework'],
        'packh'   => ['fa' => 'تنها بسته‌هایی نمایش داده می‌شوند که طرح اشتراک شما آنها را پوشش می‌دهد.', 'en' => 'Only packs your subscription plan covers are listed.'],
        'partners'=> ['fa' => '۳. شرکت‌های مشارکت‌کننده', 'en' => '3. Participating companies'],
        'parth'   => ['fa' => 'هر شرکت فقط داده‌های خودش را می‌بیند؛ دیوار تجاری را پایگاه‌داده اعمال می‌کند.', 'en' => 'Each company sees only its own data; the commercial firewall is enforced by the database.'],
        'submit'  => ['fa' => 'ایجاد پروژه', 'en' => 'Create project'],
        'cancel'  => ['fa' => 'انصراف', 'en' => 'Cancel'],
        'owner'   => ['fa' => 'کارفرما (شما)', 'en' => 'Employer (you)'],
        'role'    => ['fa' => 'نقش قراردادی', 'en' => 'Contract role'],
        'user'    => ['fa' => 'کاربر', 'en' => 'Signed in as'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $packLabel = [
        'ips-iran' => ['fa' => 'IPS ایران — استاندارد نفت و گاز ایران', 'en' => 'IPS Iran — Iranian oil & gas standard'],
        'iso'      => ['fa' => 'ISO — چارچوب بین‌المللی پایه', 'en' => 'ISO — international baseline'],
        'awp'      => ['fa' => 'AWP — برنامه‌ریزی کار پیشرفته (CII)', 'en' => 'AWP — Advanced Work Packaging (CII)'],
    ];
    $contractRoles = [
        'CONTRACTOR' => ['fa' => 'پیمانکار', 'en' => 'Contractor'],
        'CONSULTANT' => ['fa' => 'مشاور / ناظر', 'en' => 'Consultant / PMC'],
        'MC'         => ['fa' => 'مدیریت پیمان', 'en' => 'Management contractor'],
        'TPI'        => ['fa' => 'بازرسی شخص ثالث', 'en' => 'Third Party Inspection'],
        'VENDOR'     => ['fa' => 'فروشنده', 'en' => 'Vendor'],
        'SUB'        => ['fa' => 'پیمانکار جزء', 'en' => 'Subcontractor'],
    ];
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<style>
  .field { margin-bottom:16px; }
  .field label { display:block; font-size:13px; color:var(--muted); margin-bottom:6px; }
  .field input[type=text] { width:100%; background:#0b1220; color:#e2e8f0; border:1px solid var(--line);
    border-radius:9px; padding:11px 13px; font-size:14px; font-family:inherit; }
  .field input:focus { outline:none; border-color:var(--accent); }
  .field .hint { font-size:11.5px; color:var(--muted); margin-top:5px; }
  .ltr { direction:ltr; text-align:<?php echo e($fa ? 'right' : 'left'); ?>; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:18px; margin:0 0 18px; background:var(--card); }
  legend { font-size:14px; font-weight:700; padding:0 8px; color:#e2e8f0; }
  .packopt { display:block; background:#0b1220; border:1px solid var(--line); border-radius:10px;
    padding:12px 14px; margin-bottom:9px; cursor:pointer; }
  .packopt:hover { border-color:var(--accent); }
  .packopt input { margin-<?php echo e($fa ? 'left' : 'right'); ?>:9px; }
  .packopt .pid { font-family:ui-monospace,monospace; font-size:11.5px; color:#c4b5fd; direction:ltr; display:inline-block; }
  .prow { display:flex; align-items:center; gap:10px; background:#0b1220; border:1px solid var(--line);
    border-radius:10px; padding:10px 13px; margin-bottom:8px; }
  .prow .nm { flex:1; font-size:13.5px; }
  .prow .cd { font-family:ui-monospace,monospace; font-size:11px; color:var(--muted); }
  .prow select { font-size:12.5px; padding:6px 9px; }
  .owned { border-color:rgba(139,92,246,.45); background:rgba(139,92,246,.08); }
  .actions { display:flex; gap:12px; align-items:center; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:9px; padding:12px 26px;
    font-size:15px; font-weight:600; font-family:inherit; cursor:pointer; }
  .btn:hover { background:#7c3aed; }
  .btn.ghost { background:none; border:1px solid var(--line); color:var(--muted); }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:11px 14px; font-size:13px; margin-bottom:16px; }
  .err ul { margin:0; padding-<?php echo e($fa ? 'right' : 'left'); ?>:18px; }
</style>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub"><?php echo e($L('sub')); ?></div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div class="err">
      <ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($e); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <form method="post" action="/projects?lang=<?php echo e($lang); ?>">
    <?php echo csrf_field(); ?>

    <fieldset>
      <legend><?php echo e($L('ident')); ?></legend>

      <div class="field">
        <label for="code"><?php echo e($L('code')); ?></label>
        <input class="ltr" id="code" name="code" type="text" value="<?php echo e(old('code')); ?>" required
               maxlength="32" placeholder="PH-15" style="text-transform:uppercase;">
        <div class="hint"><?php echo e($L('codeh')); ?></div>
      </div>

      <div class="field">
        <label for="name_fa"><?php echo e($L('namefa')); ?></label>
        <input id="name_fa" name="name_fa" type="text" value="<?php echo e(old('name_fa')); ?>" required maxlength="255">
      </div>

      <div class="field">
        <label for="name_en"><?php echo e($L('nameen')); ?></label>
        <input class="ltr" id="name_en" name="name_en" type="text" value="<?php echo e(old('name_en')); ?>" required maxlength="255">
      </div>
    </fieldset>

    <fieldset>
      <legend><?php echo e($L('pack')); ?></legend>
      <div class="hint" style="font-size:12px;color:var(--muted);margin-bottom:12px;"><?php echo e($L('packh')); ?></div>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $packs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <label class="packopt">
          <input type="radio" name="standards_pack" value="<?php echo e($p); ?>" required
                 <?php if(old('standards_pack') === $p || (! old('standards_pack') && $loop->first)): echo 'checked'; endif; ?>>
          <strong><?php echo e($packLabel[$p][$lang] ?? $p); ?></strong>
          <span class="pid"><?php echo e($p); ?></span>
        </label>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </fieldset>

    <fieldset>
      <legend><?php echo e($L('partners')); ?></legend>
      <div class="hint" style="font-size:12px;color:var(--muted);margin-bottom:12px;"><?php echo e($L('parth')); ?></div>

      <div class="prow owned">
        <span class="nm"><strong><?php echo e($acting->name($lang)); ?></strong> <span class="cd"><?php echo e($acting->code); ?></span></span>
        <span class="pill pack"><?php echo e($L('owner')); ?></span>
      </div>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $partners; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div class="prow">
          <input type="checkbox" name="partners[]" value="<?php echo e($c->id); ?>" id="p<?php echo e($c->id); ?>"
                 <?php if(in_array($c->id, old('partners', []), true)): echo 'checked'; endif; ?>>
          <label class="nm" for="p<?php echo e($c->id); ?>" style="margin:0;cursor:pointer;">
            <?php echo e($c->name($lang)); ?> <span class="cd"><?php echo e($c->code); ?></span>
          </label>
          <select name="partner_roles[<?php echo e($c->id); ?>]" aria-label="<?php echo e($L('role')); ?>">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $contractRoles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rk => $rl): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <option value="<?php echo e($rk); ?>"
                <?php if(($rk === 'CONSULTANT' && $c->tenant_type === 'PMC') || ($rk === 'CONTRACTOR' && $c->tenant_type === 'EPC')): echo 'selected'; endif; ?>>
                <?php echo e($rl[$lang]); ?>

              </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        </div>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </fieldset>

    <div class="actions">
      <button class="btn" type="submit"><?php echo e($L('submit')); ?></button>
      <a class="btn ghost" href="/?lang=<?php echo e($lang); ?>" style="text-decoration:none;"><?php echo e($L('cancel')); ?></a>
    </div>
  </form>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/projects/create.blade.php ENDPATH**/ ?>