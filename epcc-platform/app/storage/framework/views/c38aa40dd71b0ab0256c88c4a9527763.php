<?php
    $fa = $lang === 'fa';
    $t = [
        'title'  => ['fa' => 'تهیه و خرید', 'en' => 'Procurement'],
        'sub'    => ['fa' => 'درخواست خرید، تأیید فنی و مالی، سفارش خرید و پیگیری تحویل',
                     'en' => 'Requisition, technical and commercial approval, purchase order and expediting'],
        'mrs'    => ['fa' => 'درخواست‌های خرید', 'en' => 'Material requisitions'],
        'pos'    => ['fa' => 'سفارش‌های خرید', 'en' => 'Purchase orders'],
        'insps'  => ['fa' => 'بازرسی‌ها', 'en' => 'Inspections'],
        'number' => ['fa' => 'شماره', 'en' => 'Number'],
        'titlec' => ['fa' => 'عنوان', 'en' => 'Title'],
        'disc'   => ['fa' => 'رشته', 'en' => 'Discipline'],
        'state'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'lines'  => ['fa' => 'اقلام', 'en' => 'Lines'],
        'vendor' => ['fa' => 'وندور', 'en' => 'Vendor'],
        'value'  => ['fa' => 'مبلغ', 'en' => 'Value'],
        'promise'=> ['fa' => 'تعهد قراردادی', 'en' => 'Promised'],
        'fcast'  => ['fa' => 'پیش‌بینی', 'en' => 'Forecast'],
        'slip'   => ['fa' => 'تأخیر (روز)', 'en' => 'Slip (days)'],
        'acts'   => ['fa' => 'اقدام', 'en' => 'Actions'],
        'submit' => ['fa' => 'ارسال', 'en' => 'Submit'],
        'tech'   => ['fa' => 'تأیید فنی', 'en' => 'Technical'],
        'comm'   => ['fa' => 'تأیید مالی', 'en' => 'Commercial'],
        'reject' => ['fa' => 'رد', 'en' => 'Reject'],
        'reason' => ['fa' => 'دلیل', 'en' => 'Reason'],
        'setfc'  => ['fa' => 'ثبت پیش‌بینی', 'en' => 'Set forecast'],
        'result' => ['fa' => 'نتیجه', 'en' => 'Result'],
        'stage'  => ['fa' => 'مرحله', 'en' => 'Stage'],
        'by'     => ['fa' => 'بازرس', 'en' => 'Inspector'],
        'find'   => ['fa' => 'یافته‌ها', 'en' => 'Findings'],
        'emptyMr'=> ['fa' => 'درخواست خریدی ثبت نشده است.', 'en' => 'No requisitions yet.'],
        'emptyPo'=> ['fa' => 'سفارش خریدی ثبت نشده است.', 'en' => 'No purchase orders yet.'],
        'emptyIn'=> ['fa' => 'بازرسی‌ای ثبت نشده است.', 'en' => 'No inspections recorded.'],
        'sod'    => ['fa' => 'تفکیک وظایف: درخواست‌کننده نمی‌تواند درخواست خود را تأیید کند، و تأیید فنی و مالی باید توسط دو نفر متفاوت امضا شود.',
                     'en' => 'Segregation of duties: the raiser cannot approve their own requisition, and the technical and commercial approvals must be signed by two different people.'],
        'exped'  => ['fa' => 'تاریخ تعهد قراردادی هرگز بازنویسی نمی‌شود؛ پیش‌بینی در ستون جداگانه ثبت می‌شود.',
                     'en' => 'The contractual promise is never overwritten; the forecast is kept in its own column.'],
        'rejected' => ['fa' => 'دلیل رد:', 'en' => 'Rejected because:'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $money = function ($v, $cur) use ($lang) {
        if ($v === null) { return '—'; }
        return \App\Support\JalaliDate::digits(number_format((float) $v), $lang).' '.($cur ?? '');
    };
    $late = $orders->filter(fn ($o) => $o->isLate());
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
  .st.DRAFT              { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.SUBMITTED          { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.TECHNICAL_APPROVED { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.APPROVED           { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.REJECTED           { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .st.ORDERED            { background:rgba(139,92,246,.16); color:#c4b5fd; border:1px solid rgba(139,92,246,.35); }
  .st.ISSUED             { background:rgba(59,130,246,.16); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
  .st.PARTIALLY_RECEIVED { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .st.RECEIVED           { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.CANCELLED          { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.PASS        { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.FAIL        { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .st.CONDITIONAL { background:rgba(234,179,8,.16); color:#fde68a; border:1px solid rgba(234,179,8,.35); }
  .mono { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; }
  .muted { color:var(--muted); font-size:11.5px; }
  .num { font-variant-numeric:tabular-nums; text-align:end; }
  .late { color:#fca5a5; font-weight:700; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:5px 11px;
    font:inherit; font-size:11.5px; font-weight:600; cursor:pointer; }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .btn.danger { background:rgba(239,68,68,.18); border:1px solid rgba(239,68,68,.45); color:#fca5a5; }
  .acts form { display:flex; gap:4px; align-items:center; flex-wrap:wrap; margin-bottom:4px; }
  .acts input { padding:4px 6px; font-size:11px; border-radius:6px; max-width:110px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .note { background:rgba(139,92,246,.10); border:1px solid rgba(139,92,246,.35); color:#c4b5fd;
    border-radius:9px; padding:9px 13px; font-size:12px; margin-bottom:14px; line-height:1.7; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; }
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

  <?php echo $__env->make('materials.mr-entry', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <div class="docstats">
    <div class="dstat"><div class="k"><?php echo e($L('mrs')); ?></div><div class="v"><?php echo e($num($requisitions->count())); ?></div></div>
    <div class="dstat"><div class="k"><?php echo e($L('pos')); ?></div><div class="v"><?php echo e($num($orders->count())); ?></div></div>
    <div class="dstat <?php echo e($late->count() ? 'warn' : 'good'); ?>">
      <div class="k"><?php echo e($L('slip')); ?></div><div class="v"><?php echo e($num($late->count())); ?></div>
    </div>
    <div class="dstat"><div class="k"><?php echo e($L('insps')); ?></div><div class="v"><?php echo e($num($inspections->count())); ?></div></div>
  </div>

  <div class="note"><?php echo e($L('sod')); ?></div>

  
  <h2 style="font-size:15px;margin:18px 0 8px"><?php echo e($L('mrs')); ?></h2>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($requisitions->isEmpty()): ?>
    <p class="muted"><?php echo e($L('emptyMr')); ?></p>
  <?php else: ?>
  <table data-tt>
    <thead><tr>
      <th><?php echo e($L('number')); ?></th><th><?php echo e($L('titlec')); ?></th><th><?php echo e($L('disc')); ?></th>
      <th class="num"><?php echo e($L('lines')); ?></th><th><?php echo e($L('state')); ?></th><th><?php echo e($L('acts')); ?></th>
    </tr></thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $requisitions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $mr): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <tr>
        <td class="mono"><?php echo e($mr->number); ?></td>
        <td>
          <?php echo e($mr->title($lang)); ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mr->isRejected() && $mr->rejection_reason): ?>
            <div class="muted" style="color:#fca5a5"><?php echo e($L('rejected')); ?> <?php echo e($mr->rejection_reason); ?></div>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td class="mono"><?php echo e($mr->discipline); ?></td>
        <td><details><summary><?php echo e($num($mr->items->count())); ?> · <?php echo e(__('mr.details')); ?></summary>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $mr->items->sortBy('line_no'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $line): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <p><span class="mono"><?php echo e($line->item_code); ?></span> · <?php echo e($line->description($lang)); ?><br>
            <span dir="ltr"><?php echo e($line->source_quantity ?? $line->quantity); ?> <?php echo e($line->unit); ?></span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($line->specification): ?><br><?php echo e($line->specification); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($line->subsystem): ?><br><?php echo e($line->subsystem->code); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></p>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mr->author_policy_id): ?><p class="muted"><?php echo e(__('mr.policy_basis')); ?>: <span class="mono"><?php echo e($mr->author_policy_id); ?></span></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </details></td>
        <td><span class="st <?php echo e($mr->state); ?>"><?php echo e(\App\Support\ProcurementState::mrLabel($mr->state)[$lang]); ?></span></td>
        <td class="acts">
          <?php $act = ['DRAFT'=>'submit','SUBMITTED'=>'technical','TECHNICAL_APPROVED'=>'commercial'][$mr->state] ?? null; ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($act): ?>
            <form method="POST" action="/projects/<?php echo e($project->code); ?>/procurement/requisition?lang=<?php echo e($lang); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="requisition_id" value="<?php echo e($mr->id); ?>">
              <input type="hidden" name="action" value="<?php echo e($act); ?>">
              <button class="btn"><?php echo e($L($act === 'submit' ? 'submit' : ($act === 'technical' ? 'tech' : 'comm'))); ?></button>
            </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(in_array($mr->state, ['SUBMITTED','TECHNICAL_APPROVED'], true)): ?>
            <form method="POST" action="/projects/<?php echo e($project->code); ?>/procurement/requisition?lang=<?php echo e($lang); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="requisition_id" value="<?php echo e($mr->id); ?>">
              <input type="hidden" name="action" value="reject">
              <input type="text" name="reason" placeholder="<?php echo e($L('reason')); ?>" required>
              <button class="btn danger"><?php echo e($L('reject')); ?></button>
            </form>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <h2 style="font-size:15px;margin:18px 0 8px"><?php echo e($L('pos')); ?></h2>
  <div class="note"><?php echo e($L('exped')); ?></div>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($orders->isEmpty()): ?>
    <p class="muted"><?php echo e($L('emptyPo')); ?></p>
  <?php else: ?>
  <table data-tt>
    <thead><tr>
      <th><?php echo e($L('number')); ?></th><th><?php echo e($L('titlec')); ?></th><th><?php echo e($L('vendor')); ?></th>
      <th class="num"><?php echo e($L('value')); ?></th><th><?php echo e($L('state')); ?></th>
      <th><?php echo e($L('promise')); ?></th><th><?php echo e($L('fcast')); ?></th><th class="num"><?php echo e($L('slip')); ?></th>
      <th><?php echo e($L('acts')); ?></th>
    </tr></thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $po): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <tr>
        <td class="mono"><?php echo e($po->number); ?></td>
        <td><?php echo e($po->title($lang)); ?></td>
        <td class="mono"><?php echo e($po->vendor?->code); ?></td>
        <td class="num"><?php echo e($money($po->total_value, $po->currency)); ?></td>
        <td><span class="st <?php echo e($po->state); ?>"><?php echo e(\App\Support\ProcurementState::poLabel($po->state)[$lang]); ?></span></td>
        <td class="mono"><?php echo e($po->promised_date ? \App\Support\JalaliDate::format($po->promised_date, $lang) : '—'); ?></td>
        <td class="mono"><?php echo e($po->forecast_date ? \App\Support\JalaliDate::format($po->forecast_date, $lang) : '—'); ?></td>
        <td class="num <?php echo e($po->isLate() ? 'late' : ''); ?>">
          <?php echo e($po->slipDays() === null ? '—' : $num($po->slipDays())); ?>

        </td>
        <td class="acts">
          <form method="POST" action="/projects/<?php echo e($project->code); ?>/procurement/forecast?lang=<?php echo e($lang); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="purchase_order_id" value="<?php echo e($po->id); ?>">
            <input type="date" name="forecast_date" required>
            <button class="btn ghost"><?php echo e($L('setfc')); ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <h2 style="font-size:15px;margin:18px 0 8px"><?php echo e($L('insps')); ?></h2>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($inspections->isEmpty()): ?>
    <p class="muted"><?php echo e($L('emptyIn')); ?></p>
  <?php else: ?>
  <table data-tt>
    <thead><tr>
      <th><?php echo e($L('number')); ?></th><th><?php echo e($L('result')); ?></th><th><?php echo e($L('stage')); ?></th>
      <th><?php echo e($L('by')); ?></th><th><?php echo e($L('find')); ?></th>
    </tr></thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $inspections; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <tr>
        <td class="mono"><?php echo e($i->number); ?></td>
        <td><span class="st <?php echo e($i->result); ?>"><?php echo e(\App\Support\ProcurementState::inspectionLabel($i->result)[$lang]); ?></span></td>
        <td><?php echo e(\App\Support\ProcurementState::stageLabel($i->stage)[$lang]); ?></td>
        <td class="mono"><?php echo e($i->company?->code); ?></td>
        <td class="muted" style="max-width:320px"><?php echo e($i->findings($lang) ?? '—'); ?></td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/materials/procurement.blade.php ENDPATH**/ ?>