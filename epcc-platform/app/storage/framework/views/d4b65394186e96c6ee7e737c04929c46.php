<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'تیم پروژه', 'en' => 'Project team'],
        'parts'   => ['fa' => 'شرکت‌های مشارکت‌کننده', 'en' => 'Participating companies'],
        'partsh'  => ['fa' => 'دیدن نام یک شرکت به معنی دسترسی به داده‌های آن نیست.', 'en' => 'Seeing a company listed is not the same as seeing its data.'],
        'people'  => ['fa' => 'افراد', 'en' => 'People'],
        'add'     => ['fa' => 'افزودن همکار از شرکت شما', 'en' => 'Add a colleague from your company'],
        'addh'    => ['fa' => 'شما فقط می‌توانید افراد شرکت خودتان را اضافه کنید.', 'en' => 'You may only add people from your own company.'],
        'person'  => ['fa' => 'فرد', 'en' => 'Person'],
        'roles'   => ['fa' => 'نقش‌ها', 'en' => 'Roles'],
        'limit'   => ['fa' => 'حد اختیارات (ریال)', 'en' => 'Authority limit (IRR)'],
        'limith'  => ['fa' => 'خالی = پیش‌فرض شرکت', 'en' => 'blank = company default'],
        'submit'  => ['fa' => 'افزودن', 'en' => 'Add'],
        'back'    => ['fa' => 'بازگشت به پروژه', 'en' => 'Back to project'],
        'company' => ['fa' => 'شرکت', 'en' => 'Company'],
        'name'    => ['fa' => 'نام', 'en' => 'Name'],
        'scope'   => ['fa' => 'دامنهٔ رشته', 'en' => 'Discipline scope'],
        'all'     => ['fa' => 'همهٔ رشته‌ها', 'en' => 'All disciplines'],
        'default' => ['fa' => 'پیش‌فرض', 'en' => 'default'],
        'nobody'  => ['fa' => 'همهٔ همکاران شما از قبل عضو این پروژه هستند.', 'en' => 'All your colleagues are already on this project.'],
        'ro'      => ['fa' => 'شما اجازهٔ مدیریت تیم این پروژه را ندارید — این فهرست فقط خواندنی است.', 'en' => 'You cannot manage this project\'s team — this list is read-only.'],
        'user'    => ['fa' => 'کاربر', 'en' => 'Signed in as'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits(number_format((float) $n), $lang);
    $contractRole = [
        'EMPLOYER' => ['fa' => 'کارفرما', 'en' => 'Employer'],
        'CONTRACTOR' => ['fa' => 'پیمانکار', 'en' => 'Contractor'],
        'CONSULTANT' => ['fa' => 'مشاور / ناظر', 'en' => 'Consultant / PMC'],
        'MC' => ['fa' => 'مدیریت پیمان', 'en' => 'Management contractor'],
        'TPI' => ['fa' => 'بازرسی شخص ثالث', 'en' => 'Third Party Inspection'],
        'VENDOR' => ['fa' => 'فروشنده', 'en' => 'Vendor'],
        'SUB' => ['fa' => 'پیمانکار جزء', 'en' => 'Subcontractor'],
    ];
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?> — <?php echo e($project->name($lang)); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<style>
  fieldset { border:1px solid var(--line); border-radius:12px; padding:18px; margin:0 0 18px; background:var(--card); }
  legend { font-size:14px; font-weight:700; padding:0 8px; }
  .grid2 { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; align-items:end; }
  .field label { display:block; font-size:12.5px; color:var(--muted); margin-bottom:6px; }
  .field input, .field select { width:100%; background:#0b1220; color:#e2e8f0; border:1px solid var(--line);
    border-radius:9px; padding:10px 12px; font-size:13.5px; font-family:inherit; }
  .field .hint { font-size:11px; color:var(--muted); margin-top:4px; }
  .chk { display:flex; flex-wrap:wrap; gap:8px; }
  .chk label { display:flex; align-items:center; gap:6px; background:#0b1220; border:1px solid var(--line);
    border-radius:8px; padding:7px 11px; font-size:12.5px; cursor:pointer; }
  .chk label:hover { border-color:var(--accent); }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:9px; padding:10px 22px;
    font-size:14px; font-weight:600; font-family:inherit; cursor:pointer; }
  .btn:hover { background:#7c3aed; }
  .rolepill { display:inline-block; background:rgba(139,92,246,.14); color:#c4b5fd; border:1px solid rgba(139,92,246,.3);
    border-radius:999px; font-size:11px; padding:2px 9px; margin-<?php echo e($fa ? 'left' : 'right'); ?>:4px; }
  .mine { background:rgba(16,185,129,.1); }
  .ro { font-size:12.5px; color:var(--muted); background:rgba(148,163,184,.1);
    border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:11px 14px; margin-bottom:16px; }
</style>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub">
    <?php echo e($project->name($lang)); ?> · <span class="mono"><?php echo e($project->code); ?></span>
    · <span class="pill pack"><?php echo e($project->standards_pack); ?></span>
  </div>

  <fieldset>
    <legend><?php echo e($L('parts')); ?></legend>
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px;"><?php echo e($L('partsh')); ?></div>
    
    <table data-tt>
      <thead><tr>
        <th data-sort="text"><?php echo e($L('company')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('roles')); ?></th>
      </tr></thead>
      <tbody>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $participants; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <tr class="<?php echo e($p->id === $acting->id ? 'mine' : ''); ?>">
            <td><?php echo e($fa ? $p->name_fa : $p->name_en); ?> <span class="mono" style="color:var(--muted);"><?php echo e($p->code); ?></span></td>
            <td><span class="pill pack"><?php echo e($contractRole[$p->role][$lang] ?? $p->role); ?></span></td>
          </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
  </fieldset>

  <fieldset>
    <legend><?php echo e($L('people')); ?></legend>
    <table>
      <thead><tr><th><?php echo e($L('name')); ?></th><th><?php echo e($L('company')); ?></th><th><?php echo e($L('roles')); ?></th><th><?php echo e($L('scope')); ?></th><th><?php echo e($L('limit')); ?></th></tr></thead>
      <tbody>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $members; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <tr class="<?php echo e($m->user->company_id === $acting->id ? 'mine' : ''); ?>">
            <td><?php echo e($m->user->name); ?></td>
            <td><span class="mono" style="color:var(--muted);"><?php echo e($m->user->company->code); ?></span></td>
            <td><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $m->role_codes ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><span class="rolepill"><?php echo e(optional($roles->firstWhere('code', $rc))->name($lang) ?? $rc); ?></span><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></td>
            <td style="font-size:12.5px;color:var(--muted);">
              <?php echo e($m->discipline_scope ? implode('، ', $m->discipline_scope) : $L('all')); ?>

            </td>
            <td class="amt" style="font-size:13px;">
              <?php echo e($m->authority_limit !== null ? $num($m->authority_limit) : $L('default')); ?>

            </td>
          </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
  </fieldset>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManage): ?>
    <fieldset>
      <legend><?php echo e($L('add')); ?></legend>
      <div style="font-size:12px;color:var(--muted);margin-bottom:14px;"><?php echo e($L('addh')); ?></div>

      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($candidates->isEmpty()): ?>
        <div style="font-size:13px;color:var(--muted);"><?php echo e($L('nobody')); ?></div>
      <?php else: ?>
        <form method="post" action="/projects/<?php echo e($project->code); ?>/team?lang=<?php echo e($lang); ?>">
          <?php echo csrf_field(); ?>
          <div class="grid2">
            <div class="field">
              <label for="user_id"><?php echo e($L('person')); ?></label>
              <select id="user_id" name="user_id" required>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $candidates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($c->id); ?>"><?php echo e($c->name); ?> — <?php echo e($c->email); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
            </div>
            <div class="field">
              <label for="authority_limit"><?php echo e($L('limit')); ?></label>
              <input id="authority_limit" name="authority_limit" type="number" min="0" step="1" placeholder="—">
              <div class="hint"><?php echo e($L('limith')); ?></div>
            </div>
          </div>

          <div class="field" style="margin-top:14px;">
            <label><?php echo e($L('roles')); ?></label>
            <div class="chk">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <label><input type="checkbox" name="role_codes[]" value="<?php echo e($r->code); ?>"> <?php echo e($r->name($lang)); ?></label>
              <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
          </div>

          <button class="btn" type="submit" style="margin-top:16px;"><?php echo e($L('submit')); ?></button>
        </form>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </fieldset>
  <?php else: ?>
    <div class="ro"><?php echo e($L('ro')); ?></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/projects/team.blade.php ENDPATH**/ ?>