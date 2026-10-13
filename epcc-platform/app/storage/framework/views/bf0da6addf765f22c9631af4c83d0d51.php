<?php
    $t = [
        'title'        => ['fa' => 'دروازه ترخیص صورت‌وضعیت', 'en' => 'Payment Certificate Release Gate'],
        'subtitle'     => ['fa' => 'آزمایشگاه بدون پایگاه داده — موتور قواعد مستقل از فریم‌ورک', 'en' => 'Stateless playground — framework-free rule engine'],
        'pack'         => ['fa' => 'پک استاندارد', 'en' => 'Standards pack'],
        'date'         => ['fa' => 'تاریخ', 'en' => 'Date'],
        'facts'        => ['fa' => 'وضعیت پرونده (برای آزمایش تغییر دهید)', 'en' => 'Case state (toggle to test)'],
        'sheets'       => ['fa' => 'برگه‌های متره پیوست است', 'en' => 'Measurement sheets attached'],
        'consultant'   => ['fa' => 'تأیید مهندس مشاور', 'en' => 'Verified by consultant'],
        'deductions'   => ['fa' => 'کسورات محاسبه شده', 'en' => 'Deductions calculated'],
        'over'         => ['fa' => 'فراتر از حدود اختیارات', 'en' => 'Exceeds approver authority'],
        'apply'        => ['fa' => 'اعمال', 'en' => 'Apply'],
        'verdict'      => ['fa' => 'حکم دروازه', 'en' => 'Gate verdict'],
        'passed'       => ['fa' => 'مجاز به ترخیص', 'en' => 'Cleared for release'],
        'blocked'      => ['fa' => 'مسدود', 'en' => 'Blocked'],
        'absolute'     => ['fa' => 'مسدودیت قطعی — قابل چشم‌پوشی نیست', 'en' => 'Hard block — no waiver possible'],
        'waivable'     => ['fa' => 'قابل چشم‌پوشی با مجوز', 'en' => 'Waivable with authority'],
        'net'          => ['fa' => 'مبلغ خالص قابل پرداخت', 'en' => 'Net payable'],
        'rial'         => ['fa' => 'ریال', 'en' => 'IRR'],
        'engine'       => ['fa' => 'این حکم توسط بستهٔ epcc/standards-engine صادر شده — هیچ وابستگی به لاراول ندارد.', 'en' => 'This verdict comes from the epcc/standards-engine package — zero Laravel dependency.'],
        'back'         => ['fa' => 'بازگشت به فهرست پروژه‌ها', 'en' => 'Back to projects'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $fa = $lang === 'fa';
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?></title>
<style>
  :root { --bg:#0f172a; --card:#1e293b; --line:#334155; --muted:#94a3b8; --ok:#10b981; --bad:#ef4444; --warn:#f59e0b; --accent:#8b5cf6; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:#e2e8f0; font-family:<?php echo e($fa ? "Vazirmatn, Tahoma, sans-serif" : "ui-sans-serif, system-ui, sans-serif"); ?>; line-height:1.7; padding:24px; }
  .wrap { max-width:920px; margin:0 auto; }
  h1 { font-size:22px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:14px; margin-bottom:20px; }
  .meta { display:flex; flex-wrap:wrap; gap:16px; font-size:13px; color:var(--muted); margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid var(--line); }
  .meta b { color:#e2e8f0; font-weight:600; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:18px; }
  .card h2 { font-size:14px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:0 0 14px; font-weight:600; }
  label { display:flex; align-items:center; gap:10px; padding:8px 0; font-size:14px; cursor:pointer; }
  input[type=checkbox] { width:17px; height:17px; accent-color:var(--accent); cursor:pointer; }
  button { background:var(--accent); color:#fff; border:0; border-radius:8px; padding:9px 20px; font-size:14px; font-weight:600; cursor:pointer; margin-top:10px; font-family:inherit; }
  .verdict { display:flex; align-items:center; gap:12px; font-size:18px; font-weight:700; margin-bottom:6px; }
  .dot { width:14px; height:14px; border-radius:50%; flex:none; }
  .ok .dot { background:var(--ok); } .bad .dot { background:var(--bad); }
  .ok { color:var(--ok); } .bad { color:var(--bad); }
  .tag { display:inline-block; font-size:12px; font-weight:600; padding:3px 10px; border-radius:999px; margin-<?php echo e($fa ? 'right' : 'left'); ?>:8px; }
  .tag.abs { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.35); }
  .tag.wv { background:rgba(245,158,11,.16); color:#fcd34d; border:1px solid rgba(245,158,11,.35); }
  ul.reasons { list-style:none; padding:0; margin:14px 0 0; }
  ul.reasons li { background:rgba(239,68,68,.08); border-<?php echo e($fa ? 'right' : 'left'); ?>:3px solid var(--bad); padding:10px 14px; border-radius:6px; margin-bottom:8px; font-size:14px; }
  ul.reasons code { color:#fca5a5; font-size:12px; display:block; margin-bottom:3px; font-family:ui-monospace,monospace; direction:ltr; text-align:<?php echo e($fa ? 'right' : 'left'); ?>; }
  .amount { font-size:26px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums; }
  .note { font-size:12.5px; color:var(--muted); border-top:1px solid var(--line); padding-top:14px; margin-top:16px; }
  a.lang { color:var(--accent); text-decoration:none; font-size:13px; font-weight:600; }
</style>
</head>
<body>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
  <?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<div class="<?php echo e(auth()->check() ? 'app' : ''); ?>">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
  <?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<div class="wrap">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
  <?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <div style="margin-bottom:14px;display:flex;gap:16px;">
    <a class="lang" href="?lang=<?php echo e($fa ? 'en' : 'fa'); ?>"><?php echo e($fa ? 'English' : 'فارسی'); ?></a>
    <a class="lang" href="/?lang=<?php echo e($lang); ?>"><?php echo e($L('back')); ?></a>
  </div>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub"><?php echo e($L('subtitle')); ?></div>

  <div class="meta">
    <span><?php echo e($L('pack')); ?>: <b><?php echo e($packId); ?><?php echo e('@'); ?><?php echo e($packVersion); ?></b></span>
    <span><?php echo e($L('date')); ?>: <b><?php echo e($jalaliDate); ?></b><?php echo e($fa ? ' ('.$jalaliMonth.')' : ''); ?></span>
    <span>Laravel <b><?php echo e(app()->version()); ?></b> · PHP <b><?php echo e(PHP_VERSION); ?></b></span>
  </div>

  <div class="card">
    <h2><?php echo e($L('facts')); ?></h2>
    <form method="get">
      <input type="hidden" name="lang" value="<?php echo e($lang); ?>">
      <input type="hidden" name="submitted" value="1">
      <label><input type="checkbox" name="sheets" value="1" <?php if($toggles['sheets']): echo 'checked'; endif; ?>> <?php echo e($L('sheets')); ?></label>
      <label><input type="checkbox" name="consultant" value="1" <?php if($toggles['consultant']): echo 'checked'; endif; ?>> <?php echo e($L('consultant')); ?></label>
      <label><input type="checkbox" name="deductions" value="1" <?php if($toggles['deductions']): echo 'checked'; endif; ?>> <?php echo e($L('deductions')); ?></label>
      <label><input type="checkbox" name="over" value="1" <?php if($toggles['overLimit']): echo 'checked'; endif; ?>> <?php echo e($L('over')); ?></label>
      <button type="submit"><?php echo e($L('apply')); ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?php echo e($L('net')); ?></h2>
    <div class="amount">
      <?php echo e(\App\Support\JalaliDate::digits(number_format($net), $lang)); ?>

      <span style="font-size:14px;color:var(--muted);font-weight:400;"><?php echo e($L('rial')); ?></span>
    </div>
  </div>

  <div class="card">
    <h2><?php echo e($L('verdict')); ?></h2>
    <div class="verdict <?php echo e($verdict->passed ? 'ok' : 'bad'); ?>">
      <span class="dot"></span>
      <span><?php echo e($verdict->passed ? $L('passed') : $L('blocked')); ?></span>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($verdict->isAbsolutelyBlocked()): ?>
        <span class="tag abs"><?php echo e($L('absolute')); ?></span>
      <?php elseif($verdict->isWaivable()): ?>
        <span class="tag wv"><?php echo e($L('waivable')); ?></span>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($verdict->failures) > 0): ?>
      <ul class="reasons">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $verdict->failures; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $f): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <li>
            <code><?php echo e($f->check); ?></code>
            <?php echo e($fa ? $f->messageFa : $f->messageEn); ?>

          </li>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </ul>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="note"><?php echo e($L('engine')); ?></div>
  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/gate-demo.blade.php ENDPATH**/ ?>