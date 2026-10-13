<?php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'برگهٔ نظرات', 'en' => 'Comment sheet'],
        'back'     => ['fa' => 'بازگشت به دفتر مدارک', 'en' => 'Back to register'],
        'seq'      => ['fa' => 'ردیف', 'en' => '#'],
        'cat'      => ['fa' => 'دسته', 'en' => 'Category'],
        'ref'      => ['fa' => 'ارجاع', 'en' => 'Reference'],
        'body'     => ['fa' => 'متن نظر', 'en' => 'Comment'],
        'by'       => ['fa' => 'بررسی‌کننده', 'en' => 'Reviewer'],
        'disp'     => ['fa' => 'پاسخ', 'en' => 'Disposition'],
        'act'      => ['fa' => 'اقدام', 'en' => 'Action'],
        'add'      => ['fa' => 'ثبت نظر جدید', 'en' => 'Raise a comment'],
        'save'     => ['fa' => 'ثبت', 'en' => 'Submit'],
        'apply'    => ['fa' => 'ثبت پاسخ', 'en' => 'Respond'],
        'note'     => ['fa' => 'توضیح', 'en' => 'Justification'],
        'empty'    => ['fa' => 'هنوز نظری ثبت نشده است.', 'en' => 'No comments raised yet.'],
        'total'    => ['fa' => 'کل نظرها', 'en' => 'Total'],
        'pending'  => ['fa' => 'بی‌پاسخ', 'en' => 'Pending'],
        'blocking' => ['fa' => 'بازدارنده', 'en' => 'Blocking'],
        'closure'  => ['fa' => 'درصد بسته‌شده', 'en' => 'Closure'],
        'blocked'  => ['fa' => 'تا پاسخ‌دادن به نظرهای بازدارنده، انتشار این مدرک ممکن نیست.',
                       'en' => 'This document cannot be published until the blocking comments are answered.'],
        'clear'    => ['fa' => 'هیچ نظر بازدارنده‌ای باز نیست؛ مدرک قابل انتشار است.',
                       'en' => 'No blocking comments are open; this document can be published.'],
        'notshared'=> ['fa' => 'تنها مدارکِ «ارسال برای بررسی» قابل اظهار نظر هستند.',
                       'en' => 'Only documents in the Shared state are open for review.'],
        'ownersay' => ['fa' => 'شما تهیه‌کنندهٔ این مدرک هستید؛ می‌توانید به نظرها پاسخ دهید.',
                       'en' => 'You are the originator of this document; you may respond to comments.'],
        'refhint'  => ['fa' => 'مثلاً شمارهٔ شیت یا تگ', 'en' => 'e.g. sheet number or tag'],
        'pendinglabel' => ['fa' => 'در انتظار پاسخ', 'en' => 'Pending'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($L('title')); ?> — <?php echo e($document->number); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<style>
  .docstats { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
  .dstat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:110px; }
  .dstat .k { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .dstat .v { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:2px; }
  .dstat.warn .v { color:#fca5a5; }
  .dstat.good .v { color:#6ee7b7; }
  .cat { display:inline-block; font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .cat.HOLD  { background:rgba(239,68,68,.18);  color:#fca5a5; border:1px solid rgba(239,68,68,.45); }
  .cat.CODE1 { background:rgba(239,68,68,.13);  color:#fca5a5; border:1px solid rgba(239,68,68,.32); }
  .cat.CODE2 { background:rgba(245,158,11,.16); color:#fcd34d; border:1px solid rgba(245,158,11,.38); }
  .cat.CODE3 { background:rgba(56,132,255,.16); color:#93c5fd; border:1px solid rgba(56,132,255,.35); }
  .cat.CODE4 { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .cat.INFO  { background:rgba(148,163,184,.16);color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .dsp { display:inline-block; font-size:11px; font-weight:600; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .dsp.PENDING  { background:rgba(148,163,184,.14); color:#94a3b8; border:1px solid rgba(148,163,184,.3); }
  .dsp.ACCEPTED { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .dsp.REJECTED { background:rgba(239,68,68,.14);  color:#fca5a5; border:1px solid rgba(239,68,68,.35); }
  .dsp.NOTED    { background:rgba(56,132,255,.14); color:#93c5fd; border:1px solid rgba(56,132,255,.32); }
  .cbody { max-width:420px; line-height:1.55; }
  .cnote { margin-top:6px; font-size:12px; color:var(--muted); border-inline-start:2px solid var(--line); padding-inline-start:8px; }
  .banner { border-radius:10px; padding:11px 15px; font-size:13px; margin-bottom:16px; line-height:1.6; }
  .banner.bad  { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.38); color:#fca5a5; }
  .banner.good { background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.35); color:#6ee7b7; }
  .banner.note { background:rgba(148,163,184,.09); border:1px solid var(--line); color:var(--muted); }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select, .frow textarea { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .frow textarea { min-height:74px; resize:vertical; line-height:1.6; }
  .dispose-form { display:flex; flex-direction:column; gap:5px; min-width:172px; }
  .dispose-form select, .dispose-form input { padding:5px 8px; font-size:12px; border-radius:6px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; width:100%; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .backlink { display:inline-block; font-size:12.5px; color:var(--muted); text-decoration:none; margin-bottom:12px; }
  .backlink:hover { color:#e2e8f0; }
</style>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <a class="backlink" href="/projects/<?php echo e($project->code); ?>/documents?lang=<?php echo e($lang); ?>">&larr; <?php echo e($L('back')); ?></a>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub">
    <span class="mono"><?php echo e($document->number); ?></span> · <?php echo e($document->title($lang)); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($revision): ?>
      · <?php echo e($fa ? 'بازنگری' : 'Rev'); ?> <span class="mono"><?php echo e($revision->revision); ?></span>
      · <span class="pill pack"><?php echo e($revision->stateLabel($lang)); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    · <?php echo e($jalaliDate); ?>

  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
    <div class="ok"><?php echo e(session('status')); ?></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div class="err"><ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($e); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stats): ?>
    <div class="docstats">
      <div class="dstat">
        <div class="k"><?php echo e($L('total')); ?></div>
        <div class="v"><?php echo e($num($stats['total'])); ?></div>
      </div>
      <div class="dstat <?php echo e($stats['pending'] > 0 ? 'warn' : 'good'); ?>">
        <div class="k"><?php echo e($L('pending')); ?></div>
        <div class="v"><?php echo e($num($stats['pending'])); ?></div>
      </div>
      <div class="dstat <?php echo e($stats['blocking'] > 0 ? 'warn' : 'good'); ?>">
        <div class="k"><?php echo e($L('blocking')); ?></div>
        <div class="v"><?php echo e($num($stats['blocking'])); ?></div>
      </div>
      <div class="dstat <?php echo e($stats['closure'] === 100 ? 'good' : ''); ?>">
        <div class="k"><?php echo e($L('closure')); ?></div>
        <div class="v"><?php echo e($num($stats['closure'])); ?>٪</div>
      </div>
    </div>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stats['blocking'] > 0): ?>
      <div class="banner bad"><?php echo e($L('blocked')); ?></div>
    <?php elseif($stats['total'] > 0): ?>
      <div class="banner good"><?php echo e($L('clear')); ?></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canComment): ?>
    <fieldset>
      <legend><?php echo e($L('add')); ?></legend>
      <form method="post" action="/projects/<?php echo e($project->code); ?>/documents/<?php echo e($document->id); ?>/review?lang=<?php echo e($lang); ?>">
        <?php echo csrf_field(); ?>
        <div class="frow">
          <div>
            <label for="category"><?php echo e($L('cat')); ?></label>
            <select id="category" name="category" required>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($c); ?>"><?php echo e(\App\Support\CommentCategory::label($c, $lang)); ?></option>
              <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
          </div>
          <div>
            <label for="reference"><?php echo e($L('ref')); ?></label>
            <input id="reference" name="reference" maxlength="128" placeholder="<?php echo e($L('refhint')); ?>">
          </div>
        </div>
        <div class="frow" style="grid-template-columns:1fr;">
          <div>
            <label for="body"><?php echo e($L('body')); ?></label>
            <textarea id="body" name="body" required maxlength="4000"></textarea>
          </div>
        </div>
        <button type="submit" class="btn"><?php echo e($L('save')); ?></button>
      </form>
    </fieldset>
  <?php elseif($isOriginator): ?>
    <div class="banner note"><?php echo e($L('ownersay')); ?></div>
  <?php elseif($revision && $revision->cde_state !== \App\Support\CdeState::SHARED): ?>
    <div class="banner note"><?php echo e($L('notshared')); ?></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($comments->isEmpty()): ?>
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;">
      <?php echo e($L('empty')); ?>

    </div>
  <?php else: ?>
    
    <table data-tt>
      <thead>
        <tr>
          <th data-sort="num"><?php echo e($L('seq')); ?></th>
          <th data-sort="text" data-facet><?php echo e($L('cat')); ?></th>
          <th data-sort="text"><?php echo e($L('ref')); ?></th>
          <th data-sort="text"><?php echo e($L('body')); ?></th>
          <th data-sort="text" data-facet><?php echo e($L('by')); ?></th>
          <th data-sort="text" data-facet><?php echo e($L('disp')); ?></th>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canDispose): ?><th><?php echo e($L('act')); ?></th><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $comments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
          <td class="mono"><?php echo e($num($c->seq)); ?></td>
          
          <td data-sortkey="<?php echo e(array_search($c->category, $categories, true)); ?>"
              data-facet-value="<?php echo e($c->categoryCode($lang)); ?>">
            <span class="cat <?php echo e($c->category); ?>" title="<?php echo e($c->categoryLabel($lang)); ?>"><?php echo e($c->categoryCode($lang)); ?></span>
          </td>
          <td class="mono"><?php echo e($c->reference ?: '—'); ?></td>
          <td class="cbody">
            <?php echo e($c->body); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($c->disposition_note): ?>
              <div class="cnote"><strong><?php echo e($L('note')); ?>:</strong> <?php echo e($c->disposition_note); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td><span class="pill pack"><?php echo e($c->reviewer->code); ?></span></td>
          <td data-facet-value="<?php echo e($c->dispositionLabel($lang)); ?>">
            <span class="dsp <?php echo e($c->disposition); ?>"><?php echo e($c->dispositionLabel($lang)); ?></span>
          </td>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canDispose): ?>
            <td>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($c->isPending()): ?>
                
                <form class="dispose-form" method="post"
                      action="/projects/<?php echo e($project->code); ?>/documents/<?php echo e($document->id); ?>/review/<?php echo e($c->id); ?>?lang=<?php echo e($lang); ?>">
                  <?php echo csrf_field(); ?>
                  <select name="disposition" aria-label="<?php echo e($L('disp')); ?>">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $dispositions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                      <option value="<?php echo e($d); ?>"><?php echo e(\App\Support\CommentDisposition::label($d, $lang)); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                  </select>
                  <input name="disposition_note" maxlength="4000" placeholder="<?php echo e($L('note')); ?>"
                         aria-label="<?php echo e($L('note')); ?>">
                  <button type="submit" class="btn"><?php echo e($L('apply')); ?></button>
                </form>
              <?php else: ?>
                <span style="font-size:12px;color:var(--muted);">—</span>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </td>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </tr>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="foot">
    <?php echo e($fa
      ? 'دسته‌های توقف، کد ۱ و کد ۲ تا زمانی که پاسخ داده نشوند مانع انتشار مدرک می‌شوند. رد کردن یک نظر بدون ذکر دلیل ممکن نیست.'
      : 'Hold, Code 1 and Code 2 comments block publication until they are answered. A comment cannot be rejected without a written justification.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/documents/review.blade.php ENDPATH**/ ?>