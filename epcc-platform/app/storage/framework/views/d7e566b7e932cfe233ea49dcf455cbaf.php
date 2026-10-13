<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'کنترل کیفیت و فهرست نواقص', 'en' => 'QA/QC & punch list'],
        'sub'     => ['fa' => 'بازرسی زیرسیستم‌ها، رفع نواقص و صدور گواهی تکمیل مکانیکی',
                      'en' => 'Subsystem walkdown, defect closure and Mechanical Completion'],
        'number'  => ['fa' => 'شماره', 'en' => 'Number'],
        'subsys'  => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'cat'     => ['fa' => 'رده', 'en' => 'Cat.'],
        'desc'    => ['fa' => 'شرح نقص', 'en' => 'Description'],
        'loc'     => ['fa' => 'موقعیت', 'en' => 'Location'],
        'raisedby'=> ['fa' => 'ثبت‌کننده', 'en' => 'Raised by'],
        'resp'    => ['fa' => 'مسئول رفع', 'en' => 'Responsible'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'new'     => ['fa' => 'ثبت نقص جدید', 'en' => 'Raise a punch item'],
        'den'     => ['fa' => 'شرح (انگلیسی)', 'en' => 'Description (English)'],
        'dfa'     => ['fa' => 'شرح (فارسی)', 'en' => 'Description (Farsi)'],
        'raise'   => ['fa' => 'ثبت نقص', 'en' => 'Raise item'],
        'assign'  => ['fa' => 'ارجاع', 'en' => 'Assign'],
        'fix'     => ['fa' => 'اعلام رفع', 'en' => 'Report fixed'],
        'verify'  => ['fa' => 'تأیید', 'en' => 'Verify'],
        'reject'  => ['fa' => 'رد', 'en' => 'Reject'],
        'close'   => ['fa' => 'بستن', 'en' => 'Close'],
        'note'    => ['fa' => 'توضیح', 'en' => 'Note'],
        'empty'   => ['fa' => 'نقصی ثبت نشده است.', 'en' => 'No punch items yet.'],
        'total'   => ['fa' => 'نقص', 'en' => 'items'],
        'opena'   => ['fa' => 'نواقص باز ردهٔ A', 'en' => 'Open A-items'],
        'closure' => ['fa' => 'درصد رفع', 'en' => 'Closure'],
        'mcc'     => ['fa' => 'گواهی تکمیل مکانیکی', 'en' => 'Mechanical Completion'],
        'blocked' => ['fa' => 'مسدود', 'en' => 'Blocked'],
        'ready'   => ['fa' => 'آمادهٔ صدور', 'en' => 'Ready to issue'],
        'issued'  => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'draftc'  => ['fa' => 'ایجاد پیش‌نویس گواهی', 'en' => 'Start certificate'],
        'issuec'  => ['fa' => 'صدور گواهی', 'en' => 'Issue certificate'],
        'excep'   => ['fa' => 'تأیید استثنا (کارفرما)', 'en' => 'Approve exception (employer)'],
        'excr'    => ['fa' => 'دلیل تأیید استثنا', 'en' => 'Reason for the exception'],
        'exon'    => ['fa' => 'استثنای تأییدشده', 'en' => 'Exception approved'],
        'blockedby' => ['fa' => 'مسدود به دلیل نواقص باز ردهٔ A:', 'en' => 'Blocked by open A-items:'],
        'waived'  => ['fa' => 'نواقص مشمول استثنا:', 'en' => 'Waived items:'],
        'nosub'   => ['fa' => 'زیرسیستمی تعریف نشده است.', 'en' => 'No subsystems defined.'],
        'pdf'     => ['fa' => 'دریافت گواهی (PDF)', 'en' => 'Download certificate (PDF)'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);

    $openA = $items->filter(fn ($i) => $i->blocksMc());
    $closed = $items->filter(fn ($i) => ! $i->isOpen());
    $closurePct = count($items) > 0 ? (int) round($closed->count() / count($items) * 100) : 100;
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?> — <?php echo e($project->code); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<style>
  .docstats { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
  .dstat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:120px; }
  .dstat .k { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .dstat .v { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:2px; }
  .dstat.warn .v { color:#fca5a5; }
  .dstat.good .v { color:#6ee7b7; }
  .st { display:inline-block; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
  .st.RAISED   { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.ASSIGNED { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.FIXED    { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.VERIFIED { background:rgba(139,92,246,.16); color:#c4b5fd; border:1px solid rgba(139,92,246,.35); }
  .st.CLOSED   { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.REJECTED { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .cat { display:inline-block; font-size:12px; font-weight:800; width:26px; height:26px; line-height:26px;
    text-align:center; border-radius:7px; }
  .cat.A { background:rgba(239,68,68,.18); color:#fca5a5; border:1px solid rgba(239,68,68,.45); }
  .cat.B { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.4); }
  .cat.C { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .desc { max-width:340px; font-size:12.5px; line-height:1.7; }
  .desc .loc { display:block; color:var(--muted); font-size:11px; margin-top:2px; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .btn.danger { background:rgba(239,68,68,.18); border:1px solid rgba(239,68,68,.45); color:#fca5a5; }
  .acts { display:flex; flex-direction:column; gap:6px; align-items:flex-start; }
  .acts form { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
  .acts input[type=text], .acts select { padding:4px 7px; font-size:11.5px; border-radius:6px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; max-width:150px; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select, .frow textarea { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .hint { font-size:11px; color:#64748b; margin-top:4px; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

  .sscard { background:var(--card); border:1px solid var(--line); border-radius:12px;
    padding:14px 16px; margin-bottom:12px; }
  .sscard .sshead { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; }
  .sscard .ssname { font-size:14px; font-weight:700; color:#e2e8f0; }
  .sscard .ssmeta { font-size:11.5px; color:var(--muted); margin-top:2px; }
  .gate { margin-top:10px; padding:10px 12px; border-radius:9px; font-size:12.5px; line-height:1.8; }
  .gate.blocked { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.35); color:#fca5a5; }
  .gate.ready   { background:rgba(16,185,129,.10); border:1px solid rgba(16,185,129,.35); color:#6ee7b7; }
  .gate.issued  { background:rgba(59,130,246,.10); border:1px solid rgba(59,130,246,.35); color:#93c5fd; }
  .gate .mono { color:#e2e8f0; }
  .gate form { margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
  .gate input[type=text] { padding:5px 9px; font-size:12px; border-radius:6px; min-width:240px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
</style>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub">
    <?php echo e($project->name($lang)); ?> · <span class="mono"><?php echo e($project->code); ?></span> · <?php echo e($L('sub')); ?> · <?php echo e($jalaliDate); ?>

  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
    <div class="ok"><?php echo e(session('status')); ?></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div class="err"><ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($e); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="docstats">
    <div class="dstat">
      <div class="k"><?php echo e($L('total')); ?></div>
      <div class="v"><?php echo e($num(count($items))); ?></div>
    </div>
    <div class="dstat <?php echo e($openA->count() > 0 ? 'warn' : 'good'); ?>">
      <div class="k"><?php echo e($L('opena')); ?></div>
      <div class="v"><?php echo e($num($openA->count())); ?></div>
    </div>
    <div class="dstat <?php echo e($closurePct === 100 ? 'good' : ''); ?>">
      <div class="k"><?php echo e($L('closure')); ?></div>
      <div class="v"><?php echo e($num($closurePct)); ?>٪</div>
    </div>
  </div>

  
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $subsystems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
    <?php
      $cert = $s->certificate;
      $block = $blocking[$s->id] ?? collect();
      $state = $cert && $cert->isIssued() ? 'issued' : ($block->isNotEmpty() && ! ($cert && $cert->hasApprovedException()) ? 'blocked' : 'ready');
    ?>
    <div class="sscard">
      <div class="sshead">
        <div>
          <div class="ssname"><?php echo e($s->name($lang)); ?></div>
          <div class="ssmeta"><span class="mono"><?php echo e($s->code); ?></span> · <?php echo e($s->discipline); ?> ·
            <?php echo e($L('closure')); ?> <?php echo e($num($s->closurePercent())); ?>٪</div>
        </div>
        <div>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($cert): ?>
            <span class="st <?php echo e($cert->isIssued() ? 'CLOSED' : 'ASSIGNED'); ?>">
              <?php echo e($cert->number); ?> · <?php echo e($cert->isIssued() ? $L('issued') : $L('mcc')); ?>

            </span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
      </div>

      <div class="gate <?php echo e($state); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($state === 'issued'): ?>
          ✓ <?php echo e($L('mcc')); ?> — <span class="mono"><?php echo e($cert->number); ?></span> · <?php echo e($L('issued')); ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($cert->hasApprovedException()): ?>
            <br>⚠ <?php echo e($L('exon')); ?>: <?php echo e($cert->exception_reason); ?>

            <br><?php echo e($L('waived')); ?>

            <span class="mono"><?php echo e(collect($cert->exception_items)->pluck('number')->implode('، ')); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php elseif($state === 'blocked'): ?>
          <?php echo e($L('blockedby')); ?>

          <span class="mono"><?php echo e($block->pluck('number')->implode('، ')); ?></span>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isEmployer && $cert): ?>
            
            <form method="post" action="/projects/<?php echo e($project->code); ?>/subsystems/<?php echo e($s->id); ?>/certificate?lang=<?php echo e($lang); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="except">
              <input type="text" name="reason" required placeholder="<?php echo e($L('excr')); ?>">
              <button type="submit" class="btn danger"><?php echo e($L('excep')); ?></button>
            </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php else: ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($cert && $cert->hasApprovedException()): ?>
            ⚠ <?php echo e($L('exon')); ?>: <?php echo e($cert->exception_reason); ?>

          <?php else: ?>
            ✓ <?php echo e($L('ready')); ?>

          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($cert): ?>
            <form method="post" action="/projects/<?php echo e($project->code); ?>/subsystems/<?php echo e($s->id); ?>/certificate?lang=<?php echo e($lang); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="issue">
              <button type="submit" class="btn"><?php echo e($L('issuec')); ?></button>
            </form>
          <?php else: ?>
            <form method="post" action="/projects/<?php echo e($project->code); ?>/subsystems/<?php echo e($s->id); ?>/certificate?lang=<?php echo e($lang); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="draft">
              <button type="submit" class="btn ghost"><?php echo e($L('draftc')); ?></button>
            </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </div>

      
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($cert): ?>
        <div style="margin-top:10px">
          <a class="btn ghost" style="text-decoration:none;display:inline-block"
             href="/projects/<?php echo e($project->code); ?>/subsystems/<?php echo e($s->id); ?>/certificate.pdf?lang=<?php echo e($lang); ?>">
            <?php echo e($L('pdf')); ?>

          </a>
        </div>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
    <p class="sub"><?php echo e($L('nosub')); ?></p>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canRaise && $subsystems->isNotEmpty()): ?>
  <fieldset>
    <legend><?php echo e($L('new')); ?></legend>
    <form method="post" action="/projects/<?php echo e($project->code); ?>/punch?lang=<?php echo e($lang); ?>">
      <?php echo csrf_field(); ?>
      <div class="frow">
        <div>
          <label for="pi_sub"><?php echo e($L('subsys')); ?></label>
          <select id="pi_sub" name="subsystem_id" required>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $subsystems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <option value="<?php echo e($s->id); ?>"><?php echo e($s->code); ?> — <?php echo e($s->name($lang)); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        </div>
        <div>
          <label for="pi_cat"><?php echo e($L('cat')); ?></label>
          <select id="pi_cat" name="category" required>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <option value="<?php echo e($c); ?>"><?php echo e(\App\Support\PunchCategory::label($c)[$lang]); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        </div>
        <div>
          <label for="pi_resp"><?php echo e($L('resp')); ?></label>
          <select id="pi_resp" name="responsible_company_id">
            <option value="">—</option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $companies; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <option value="<?php echo e($c->id); ?>"><?php echo e($c->code); ?> — <?php echo e($c->name($lang)); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </select>
        </div>
        <div>
          <label for="pi_loc"><?php echo e($L('loc')); ?></label>
          <input id="pi_loc" type="text" name="location" maxlength="255">
        </div>
      </div>
      <div class="frow">
        <div>
          <label for="pi_den"><?php echo e($L('den')); ?></label>
          <textarea id="pi_den" name="description_en" rows="2" required></textarea>
        </div>
        <div>
          <label for="pi_dfa"><?php echo e($L('dfa')); ?></label>
          <textarea id="pi_dfa" name="description_fa" rows="2" required></textarea>
        </div>
      </div>
      <div class="btnrow">
        <button type="submit" class="btn"><?php echo e($L('raise')); ?></button>
      </div>
    </form>
  </fieldset>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($items) === 0): ?>
    <p class="sub"><?php echo e($L('empty')); ?></p>
  <?php else: ?>
  
  <table data-tt>
    <thead>
      <tr>
        <th data-sort="text"><?php echo e($L('number')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('subsys')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('cat')); ?></th>
        <th data-sort="text"><?php echo e($L('desc')); ?></th>
        <th data-sort="text" data-facet><?php echo e($L('resp')); ?></th>
        <th data-sort="num" data-facet><?php echo e($L('state')); ?></th>
        <th data-sort="none"><?php echo e($L('actions')); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <tr>
        <td class="mono"><?php echo e($i->number); ?></td>
        <td class="mono"><?php echo e($i->subsystem->code); ?></td>
        <td data-facet-value="<?php echo e($i->category); ?>">
          <span class="cat <?php echo e($i->category); ?>"><?php echo e($i->categoryLabel($lang)); ?></span>
        </td>
        <td class="desc">
          <?php echo e($i->description($lang)); ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i->location): ?><span class="loc"><?php echo e($i->location); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td><?php echo e($i->responsibleCompany?->code ?? '—'); ?></td>
        
        <td data-sortkey="<?php echo e(\App\Support\PunchState::ordinalOf($i->state)); ?>"
            data-facet-value="<?php echo e($i->stateLabel($lang)); ?>">
          <span class="st <?php echo e($i->state); ?>"><?php echo e($i->stateLabel($lang)); ?></span>
        </td>
        <td>
          <div class="acts">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i->state === \App\Support\PunchState::RAISED || $i->state === \App\Support\PunchState::REJECTED): ?>
              <form method="post" action="/projects/<?php echo e($project->code); ?>/punch/<?php echo e($i->id); ?>?lang=<?php echo e($lang); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="assign">
                <select name="responsible_company_id" required>
                  <option value="">—</option>
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $companies; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($c->id); ?>"><?php echo e($c->code); ?></option>
                  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
                <button type="submit" class="btn ghost"><?php echo e($L('assign')); ?></button>
              </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i->state === \App\Support\PunchState::ASSIGNED && $i->responsible_company_id === $acting->id): ?>
              <form method="post" action="/projects/<?php echo e($project->code); ?>/punch/<?php echo e($i->id); ?>?lang=<?php echo e($lang); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="fix">
                <input type="text" name="note" required placeholder="<?php echo e($L('note')); ?>">
                <button type="submit" class="btn"><?php echo e($L('fix')); ?></button>
              </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i->state === \App\Support\PunchState::FIXED && $i->responsible_company_id !== $acting->id): ?>
              <form method="post" action="/projects/<?php echo e($project->code); ?>/punch/<?php echo e($i->id); ?>?lang=<?php echo e($lang); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="verify">
                <input type="text" name="note" placeholder="<?php echo e($L('note')); ?>">
                <button type="submit" class="btn"><?php echo e($L('verify')); ?></button>
              </form>
              <form method="post" action="/projects/<?php echo e($project->code); ?>/punch/<?php echo e($i->id); ?>?lang=<?php echo e($lang); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="reject">
                <input type="text" name="note" required placeholder="<?php echo e($L('note')); ?>">
                <button type="submit" class="btn danger"><?php echo e($L('reject')); ?></button>
              </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i->state === \App\Support\PunchState::VERIFIED && $isEmployer): ?>
              <form method="post" action="/projects/<?php echo e($project->code); ?>/punch/<?php echo e($i->id); ?>?lang=<?php echo e($lang); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="close">
                <button type="submit" class="btn"><?php echo e($L('close')); ?></button>
              </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/punch/index.blade.php ENDPATH**/ ?>