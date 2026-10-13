<?php
    $fa = $lang === 'fa';
    $t = [
        'title'  => ['fa' => 'انبار و متریال', 'en' => 'Warehouse & materials'],
        'sub'    => ['fa' => 'ورود به انبار، خروج از انبار، ورود به سایت و ردیابی تا شمارهٔ ذوب',
                     'en' => 'Goods receipt, issue, site delivery and traceability to heat number'],
        'lots'   => ['fa' => 'اقلام انبار', 'en' => 'Stock lots'],
        'lot'    => ['fa' => 'شمارهٔ پارت', 'en' => 'Lot'],
        'item'   => ['fa' => 'کالا', 'en' => 'Item'],
        'heat'   => ['fa' => 'ذوب / سریال', 'en' => 'Heat / serial'],
        'bal'    => ['fa' => 'موجودی انبار', 'en' => 'In store'],
        'onsite' => ['fa' => 'تحویل سایت', 'en' => 'On site'],
        'transit'=> ['fa' => 'در راه', 'en' => 'In transit'],
        'insp'   => ['fa' => 'بازرسی', 'en' => 'Inspection'],
        'mtc'    => ['fa' => 'گواهی مواد', 'en' => 'MTC'],
        'acts'   => ['fa' => 'اقدام', 'en' => 'Actions'],
        'moves'  => ['fa' => 'دفتر گردش متریال', 'en' => 'Movement ledger'],
        'number' => ['fa' => 'شماره', 'en' => 'Number'],
        'type'   => ['fa' => 'نوع', 'en' => 'Type'],
        'qty'    => ['fa' => 'مقدار', 'en' => 'Qty'],
        'when'   => ['fa' => 'تاریخ', 'en' => 'Date'],
        'who'    => ['fa' => 'ثبت‌کننده', 'en' => 'By'],
        'subsys' => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'mcgate' => ['fa' => 'کسری متریال در دروازهٔ تکمیل مکانیکی', 'en' => 'Material shortage at the MC gate'],
        'ordered'=> ['fa' => 'سفارش', 'en' => 'Ordered'],
        'deliv'  => ['fa' => 'تحویل سایت', 'en' => 'Delivered'],
        'short'  => ['fa' => 'کسری', 'en' => 'Short'],
        'ok'     => ['fa' => 'کامل', 'en' => 'Complete'],
        'nomat'  => ['fa' => 'بدون متریال', 'en' => 'No material'],
        'attn'   => ['fa' => 'اقلام نیازمند توجه بازرسی', 'en' => 'Lots needing inspection attention'],
        'noattn' => ['fa' => 'همهٔ اقلام بازرسی و قبول شده‌اند.', 'en' => 'Every lot has passed inspection.'],
        'issue'  => ['fa' => 'خروج از انبار', 'en' => 'Issue'],
        'deliver'=> ['fa' => 'ورود به سایت', 'en' => 'Deliver to site'],
        'empty'  => ['fa' => 'هنوز کالایی وارد انبار نشده است.', 'en' => 'Nothing has been received yet.'],
        'noMove' => ['fa' => 'گردشی ثبت نشده است.', 'en' => 'No movements recorded.'],
        'reverse'=> ['fa' => 'ابطال', 'en' => 'Reverse'],
        'reason' => ['fa' => 'دلیل', 'en' => 'Reason'],
        'ledger' => ['fa' => 'این دفتر فقط افزودنی است: اشتباه با سند ابطال اصلاح می‌شود، نه با ویرایش.',
                     'en' => 'This ledger is append-only: a mistake is corrected with a reversing entry, never an edit.'],
        'advisory' => ['fa' => 'بازرسی در این سامانه بازدارنده نیست؛ نتیجه ثبت و نمایش داده می‌شود ولی جلوی ورود کالا را نمی‌گیرد.',
                       'en' => 'Inspection is advisory here: the result is recorded and shown, but it does not block a receipt.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $qty = fn ($n) => \App\Support\JalaliDate::digits(rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0', $lang);

    $totalOnSite = $lots->sum(fn ($l) => $l->deliveredToSite($l->movements));
    $shortSubs = $subsystems->filter(fn ($s) => ! ($materialStatus[$s->id]['complete'] ?? true));
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
  .dstat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:118px; }
  .dstat .k { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .dstat .v { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:2px; }
  .dstat.warn .v { color:#fca5a5; }
  .dstat.good .v { color:#6ee7b7; }
  .st { display:inline-block; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
  .st.PASS        { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.FAIL        { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .st.CONDITIONAL { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.NONE        { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.RECEIPT { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.ISSUE   { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.SITE    { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.RETURN  { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .mono { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; }
  .muted { color:var(--muted); font-size:11.5px; }
  .num { font-variant-numeric:tabular-nums; text-align:end; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:5px 11px;
    font:inherit; font-size:11.5px; font-weight:600; cursor:pointer; }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .acts form { display:flex; gap:4px; align-items:center; flex-wrap:wrap; margin-bottom:4px; }
  .acts input, .acts select { padding:4px 6px; font-size:11px; border-radius:6px; max-width:92px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .note { background:rgba(139,92,246,.10); border:1px solid rgba(139,92,246,.35); color:#c4b5fd;
    border-radius:9px; padding:9px 13px; font-size:12px; margin-bottom:14px; line-height:1.7; }
  .gate { border-radius:9px; padding:9px 12px; font-size:12.5px; margin-bottom:8px; line-height:1.8; }
  .gate.short { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.35); color:#fca5a5; }
  .gate.fine  { background:rgba(16,185,129,.10); border:1px solid rgba(16,185,129,.35); color:#6ee7b7; }
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

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?><div class="ok"><?php echo e(session('status')); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div class="err"><ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($e); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="docstats">
    <div class="dstat"><div class="k"><?php echo e($L('lots')); ?></div><div class="v"><?php echo e($num($lots->count())); ?></div></div>
    <div class="dstat good"><div class="k"><?php echo e($L('onsite')); ?></div><div class="v"><?php echo e($qty($totalOnSite)); ?></div></div>
    <div class="dstat <?php echo e($flagged->count() ? 'warn' : 'good'); ?>">
      <div class="k"><?php echo e($L('insp')); ?></div><div class="v"><?php echo e($num($flagged->count())); ?></div>
    </div>
    <div class="dstat <?php echo e($shortSubs->count() ? 'warn' : 'good'); ?>">
      <div class="k"><?php echo e($L('short')); ?></div><div class="v"><?php echo e($num($shortSubs->count())); ?></div>
    </div>
  </div>

  <div class="note"><?php echo e($L('advisory')); ?></div>

  
  <fieldset>
    <legend><?php echo e($L('mcgate')); ?></legend>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $subsystems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php $st = $materialStatus[$s->id] ?? null; ?>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($st && $st['ordered'] > 0): ?>
        <div class="gate <?php echo e($st['complete'] ? 'fine' : 'short'); ?>">
          <span class="mono"><?php echo e($s->code); ?></span> — <?php echo e($s->name($lang)); ?><br>
          <?php echo e($L('ordered')); ?>: <?php echo e($qty($st['ordered'])); ?> ·
          <?php echo e($L('deliv')); ?>: <?php echo e($qty($st['delivered'])); ?> ·
          <?php echo e($L('short')); ?>: <strong><?php echo e($qty($st['shortfall'])); ?></strong>
        </div>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($subsystems->every(fn($s) => ($materialStatus[$s->id]['ordered'] ?? 0) <= 0)): ?>
      <div class="muted"><?php echo e($L('nomat')); ?></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </fieldset>

  
  <h2 style="font-size:15px;margin:18px 0 8px"><?php echo e($L('lots')); ?></h2>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($lots->isEmpty()): ?>
    <p class="muted"><?php echo e($L('empty')); ?></p>
  <?php else: ?>
  <table data-tt>
    <thead><tr>
      <th><?php echo e($L('lot')); ?></th><th><?php echo e($L('item')); ?></th><th><?php echo e($L('heat')); ?></th>
      <th class="num"><?php echo e($L('bal')); ?></th><th class="num"><?php echo e($L('onsite')); ?></th>
      <th><?php echo e($L('insp')); ?></th><th><?php echo e($L('mtc')); ?></th><th><?php echo e($L('acts')); ?></th>
    </tr></thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $lots; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $lot): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php
        $bal = $lot->balance($lot->movements);
        $site = $lot->deliveredToSite($lot->movements);
        $res = $lot->inspection_result ?? 'NONE';
      ?>
      <tr>
        <td class="mono"><?php echo e($lot->lot_code); ?></td>
        <td>
          <div class="mono"><?php echo e($lot->item_code); ?></div>
          <div class="muted"><?php echo e($lot->description($lang)); ?></div>
        </td>
        <td class="mono">
          <?php echo e($lot->heat_number ?? $lot->serial_number ?? '—'); ?>

        </td>
        <td class="num"><?php echo e($qty($bal)); ?> <span class="muted"><?php echo e($lot->unit); ?></span></td>
        <td class="num"><?php echo e($qty($site)); ?></td>
        <td><span class="st <?php echo e($res); ?>"><?php echo e(\App\Support\ProcurementState::inspectionLabel($lot->inspection_result)[$lang]); ?></span></td>
        <td>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($lot->mtc_document_id): ?>
            <span class="mono" style="color:#6ee7b7"><?php echo e($lot->mtcDocument?->number ?? '✓'); ?></span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td class="acts">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($bal > 0): ?>
          <form method="POST" action="/projects/<?php echo e($project->code); ?>/materials/issue?lang=<?php echo e($lang); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="lot_id" value="<?php echo e($lot->id); ?>">
            <input type="number" step="0.001" min="0.001" name="quantity" placeholder="<?php echo e($L('qty')); ?>" required>
            <select name="subsystem_id">
              <option value="">—</option>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $subsystems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($s->id); ?>"><?php echo e($s->code); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
            <button class="btn"><?php echo e($L('issue')); ?></button>
          </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <form method="POST" action="/projects/<?php echo e($project->code); ?>/materials/deliver?lang=<?php echo e($lang); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="lot_id" value="<?php echo e($lot->id); ?>">
            <input type="number" step="0.001" min="0.001" name="quantity" placeholder="<?php echo e($L('qty')); ?>" required>
            <select name="subsystem_id" required>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $subsystems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($s->id); ?>"><?php echo e($s->code); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
            <button class="btn ghost"><?php echo e($L('deliver')); ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <fieldset style="margin-top:18px">
    <legend><?php echo e($L('attn')); ?></legend>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $flagged; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $l): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
      <div style="font-size:12.5px;margin-bottom:5px">
        <span class="mono"><?php echo e($l->lot_code); ?></span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($l->heat_number): ?><span class="muted"> · <?php echo e($L('heat')); ?> <?php echo e($l->heat_number); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        — <span class="st <?php echo e($l->inspection_result ?? 'NONE'); ?>"><?php echo e(\App\Support\ProcurementState::inspectionLabel($l->inspection_result)[$lang]); ?></span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($l->mtc_document_id)): ?>
          <span class="muted"> · <?php echo e($L('mtc')); ?> —</span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
      <div class="muted"><?php echo e($L('noattn')); ?></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </fieldset>

  
  <h2 style="font-size:15px;margin:18px 0 8px"><?php echo e($L('moves')); ?></h2>
  <div class="note"><?php echo e($L('ledger')); ?></div>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($movements->isEmpty()): ?>
    <p class="muted"><?php echo e($L('noMove')); ?></p>
  <?php else: ?>
  <table data-tt>
    <thead><tr>
      <th><?php echo e($L('number')); ?></th><th><?php echo e($L('type')); ?></th><th><?php echo e($L('lot')); ?></th>
      <th class="num"><?php echo e($L('qty')); ?></th><th><?php echo e($L('subsys')); ?></th>
      <th><?php echo e($L('when')); ?></th><th><?php echo e($L('who')); ?></th><th><?php echo e($L('acts')); ?></th>
    </tr></thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $movements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <tr>
        <td class="mono"><?php echo e($m->number); ?></td>
        <td><span class="st <?php echo e($m->type); ?>"><?php echo e($m->typeName($lang)); ?></span></td>
        <td class="mono"><?php echo e($m->lot?->lot_code); ?></td>
        <td class="num"><?php echo e($qty($m->quantity)); ?></td>
        <td class="mono"><?php echo e($m->subsystem?->code ?? '—'); ?></td>
        <td class="mono"><?php echo e(\App\Support\JalaliDate::format($m->performed_at, $lang)); ?></td>
        <td class="muted"><?php echo e($m->performer?->name); ?></td>
        <td class="acts">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($m->isReversal())): ?>
          <form method="POST" action="/projects/<?php echo e($project->code); ?>/materials/reverse?lang=<?php echo e($lang); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="movement_id" value="<?php echo e($m->id); ?>">
            <input type="text" name="reason" placeholder="<?php echo e($L('reason')); ?>" required style="max-width:110px">
            <button class="btn ghost"><?php echo e($L('reverse')); ?></button>
          </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php /**PATH /home/user/epcc-platform/app/resources/views/materials/warehouse.blade.php ENDPATH**/ ?>