<?php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'ورود به سامانه', 'en' => 'Sign in'],
        'sub'      => ['fa' => 'سامانهٔ مدیریت پروژه‌های EPCC', 'en' => 'EPCC Project Management Platform'],
        'email'    => ['fa' => 'پست الکترونیک', 'en' => 'Email'],
        'password' => ['fa' => 'گذرواژه', 'en' => 'Password'],
        'remember' => ['fa' => 'مرا به خاطر بسپار', 'en' => 'Remember me'],
        'submit'   => ['fa' => 'ورود', 'en' => 'Sign in'],
        'demo'     => ['fa' => 'حساب‌های نمایشی (گذرواژهٔ همه: password)', 'en' => 'Demo accounts (password for all: password)'],
        'hint'     => ['fa' => 'برای پر شدن خودکار فرم، روی یک حساب کلیک کنید.', 'en' => 'Click an account to fill the form.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?></title>
<style>
  :root { --bg:#0f172a; --card:#1e293b; --line:#334155; --muted:#94a3b8; --accent:#8b5cf6; --bad:#ef4444; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:#e2e8f0; min-height:100vh;
    font-family:<?php echo e($fa ? "Vazirmatn, Tahoma, sans-serif" : "ui-sans-serif, system-ui, sans-serif"); ?>;
    display:flex; align-items:center; justify-content:center; padding:24px; line-height:1.7; }
  .wrap { width:100%; max-width:940px; display:grid; grid-template-columns:<?php echo e($showDemo ? '1fr 1fr' : '1fr'); ?>; gap:22px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:26px; }
  h1 { font-size:21px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13.5px; margin-bottom:22px; }
  label { display:block; font-size:13px; color:var(--muted); margin:14px 0 6px; }
  input[type=email], input[type=password] { width:100%; background:#0b1220; color:#e2e8f0;
    border:1px solid var(--line); border-radius:9px; padding:11px 13px; font-size:14px;
    font-family:inherit; direction:ltr; text-align:<?php echo e($fa ? 'right' : 'left'); ?>; }
  input:focus { outline:none; border-color:var(--accent); }
  .row { display:flex; align-items:center; gap:8px; margin-top:14px; font-size:13px; color:var(--muted); }
  button { width:100%; margin-top:20px; background:var(--accent); color:#fff; border:0;
    border-radius:9px; padding:12px; font-size:15px; font-weight:600; font-family:inherit; cursor:pointer; }
  button:hover { background:#7c3aed; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:11px 13px; font-size:13px; margin-bottom:16px; }
  .lang { position:fixed; top:18px; <?php echo e($fa ? 'left' : 'right'); ?>:18px; }
  .lang a { color:var(--accent); font-size:13px; text-decoration:none; font-weight:600; }
  h2 { font-size:14px; margin:0 0 4px; }
  .hint { font-size:12px; color:var(--muted); margin-bottom:14px; }
  .u { display:flex; justify-content:space-between; gap:10px; align-items:center; width:100%;
    background:#0b1220; border:1px solid var(--line); border-radius:9px; padding:9px 12px;
    margin-bottom:7px; cursor:pointer; text-align:<?php echo e($fa ? 'right' : 'left'); ?>; color:inherit;
    font-family:inherit; font-size:13px; }
  .u:hover { border-color:var(--accent); }
  .u .who { font-weight:600; }
  .u .mail { font-size:11.5px; color:var(--muted); direction:ltr; }
  .u .co { font-size:11px; color:#c4b5fd; font-family:ui-monospace,monospace; white-space:nowrap; }
  @media (max-width:760px){ .wrap{grid-template-columns:1fr;} }
</style>
</head>
<body>
<div class="lang"><a href="?lang=<?php echo e($fa ? 'en' : 'fa'); ?>"><?php echo e($fa ? 'English' : 'فارسی'); ?></a></div>

<div class="wrap">
  <div class="card">
    <h1><?php echo e($L('title')); ?></h1>
    <div class="sub"><?php echo e($L('sub')); ?></div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
      <div class="err"><?php echo e($errors->first()); ?></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <form method="post" action="/login">
      <?php echo csrf_field(); ?>
      <label for="email"><?php echo e($L('email')); ?></label>
      <input id="email" name="email" type="email" value="<?php echo e(old('email')); ?>" required autofocus>

      <label for="password"><?php echo e($L('password')); ?></label>
      <input id="password" name="password" type="password" required>

      <div class="row">
        <input id="remember" name="remember" type="checkbox" value="1">
        <label for="remember" style="margin:0;"><?php echo e($L('remember')); ?></label>
      </div>

      <button type="submit"><?php echo e($L('submit')); ?></button>
    </form>
  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showDemo): ?>
  <div class="card">
    <h2><?php echo e($L('demo')); ?></h2>
    <div class="hint"><?php echo e($L('hint')); ?></div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $demoUsers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <button type="button" class="u" onclick="fill(<?php echo \Illuminate\Support\Js::from($u->email)->toHtml() ?>)">
        <span>
          <span class="who"><?php echo e($u->name); ?></span><br>
          <span class="mail"><?php echo e($u->email); ?></span>
        </span>
        <span class="co"><?php echo e($u->company->code); ?></span>
      </button>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showDemo): ?>
<script>
  function fill(email) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = 'password';
  }
</script>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/auth/login.blade.php ENDPATH**/ ?>