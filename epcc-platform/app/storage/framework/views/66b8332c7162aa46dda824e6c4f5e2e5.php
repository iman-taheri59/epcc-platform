<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'نامه‌های ارسال مدارک', 'en' => 'Transmittals'],
        'sub'     => ['fa' => 'ثبت رسمی ارسال مدارک و پیگیری تأیید دریافت', 'en' => 'Formal record of issue, with acknowledgement tracking'],
        'number'  => ['fa' => 'شماره', 'en' => 'Number'],
        'subject' => ['fa' => 'موضوع', 'en' => 'Subject'],
        'purpose' => ['fa' => 'هدف', 'en' => 'Purpose'],
        'from'    => ['fa' => 'فرستنده', 'en' => 'From'],
        'to'      => ['fa' => 'گیرندگان', 'en' => 'Recipients'],
        'docs'    => ['fa' => 'مدارک', 'en' => 'Documents'],
        'status'  => ['fa' => 'وضعیت', 'en' => 'Status'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'new'     => ['fa' => 'نامهٔ جدید', 'en' => 'New transmittal'],
        'sen'     => ['fa' => 'موضوع (انگلیسی)', 'en' => 'Subject (English)'],
        'sfa'     => ['fa' => 'موضوع (فارسی)', 'en' => 'Subject (Farsi)'],
        'notes'   => ['fa' => 'توضیحات', 'en' => 'Notes'],
        'pick'    => ['fa' => 'مدارک (چند مورد قابل انتخاب)', 'en' => 'Documents (select several)'],
        'pickto'  => ['fa' => 'گیرندگان (چند مورد قابل انتخاب)', 'en' => 'Recipients (select several)'],
        'issue'   => ['fa' => 'صدور', 'en' => 'Issue'],
        'draftit' => ['fa' => 'ذخیره به‌صورت پیش‌نویس', 'en' => 'Save as draft'],
        'issuenow'=> ['fa' => 'صدور فوری', 'en' => 'Issue immediately'],
        'ack'     => ['fa' => 'تأیید دریافت', 'en' => 'Acknowledge'],
        'zip'     => ['fa' => 'دریافت بستهٔ ZIP', 'en' => 'Download ZIP'],
        'sheet'   => ['fa' => 'برگهٔ ارسال (PDF)', 'en' => 'Transmittal sheet (PDF)'],
        'vtitle'  => ['fa' => 'ترانسمیتال وندورها', 'en' => 'Vendor transmittals'],
        'vsub'    => ['fa' => 'مدارک دریافتی از وندورها، ثبت‌شده توسط پیمانکار',
                      'en' => 'Documents received from vendors, recorded by the contractor'],
        'vnew'    => ['fa' => 'ثبت نامهٔ وندور', 'en' => 'Record a vendor transmittal'],
        'vendor'  => ['fa' => 'وندور', 'en' => 'Vendor'],
        'vref'    => ['fa' => 'شمارهٔ نامهٔ وندور', 'en' => "Vendor's own reference"],
        'vnone'   => ['fa' => 'هنوز نامه‌ای از وندورها ثبت نشده است.', 'en' => 'No vendor transmittals recorded yet.'],
        'novend'  => ['fa' => 'وندوری در این پروژه تعریف نشده است؛ ابتدا از صفحهٔ تیم پروژه اضافه کنید.',
                      'en' => 'No vendor is defined on this project; add one from the project team page first.'],
        'rollup'  => ['fa' => 'انتقال به نامهٔ پیمانکار', 'en' => 'Roll up into a contractor letter'],
        'rollupgo'=> ['fa' => 'انتقال', 'en' => 'Roll up'],
        'nodraft' => ['fa' => 'پیش‌نویس پیمانکاری باز ندارید', 'en' => 'no open contractor draft'],
        'goven'   => ['fa' => 'ترانسمیتال وندورها ←', 'en' => 'Vendor transmittals →'],
        'gocon'   => ['fa' => '← ترانسمیتال پیمانکار', 'en' => '← Contractor transmittals'],
        'srcfrom' => ['fa' => 'برگرفته از', 'en' => 'from'],
        'empty'   => ['fa' => 'هنوز نامه‌ای ثبت نشده است.', 'en' => 'No transmittals yet.'],
        'nodocs'  => ['fa' => 'مدرک قابل ارسالی ندارید؛ ابتدا مدرکی را برای بررسی ارسال کنید.',
                      'en' => 'You have no transmittable documents; share one for review first.'],
        'draft'   => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
        'issued'  => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'overdue' => ['fa' => 'سررسید گذشته', 'en' => 'Overdue'],
        'acked'   => ['fa' => 'تأیید شد', 'en' => 'Acknowledged'],
        'waiting' => ['fa' => 'در انتظار', 'en' => 'Awaiting'],
        'due'     => ['fa' => 'مهلت', 'en' => 'Due'],
        'total'   => ['fa' => 'نامه', 'en' => 'transmittals'],
        'openack' => ['fa' => 'در انتظار تأیید', 'en' => 'Awaiting acknowledgement'],
        'od'      => ['fa' => 'سررسید گذشته', 'en' => 'Overdue'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $jdate = fn ($d) => \App\Support\JalaliDate::format($d, $lang);

    $issued   = $transmittals->where('status', \App\Models\Transmittal::ISSUED);
    $overdue  = $issued->filter(fn ($x) => $x->isOverdue());
    $awaiting = $issued->filter(fn ($x) => $x->recipients->contains(fn ($r) => $r->acknowledged_at === null));
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
  .st.DRAFT   { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.ISSUED  { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.OVERDUE { background:rgba(239,68,68,.16); color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
  .rcp { display:flex; flex-direction:column; gap:3px; }
  .rcp .r { display:flex; align-items:center; gap:6px; font-size:11.5px; white-space:nowrap; }
  .rcp .dot { width:7px; height:7px; border-radius:50%; flex:none; }
  .rcp .dot.yes { background:#6ee7b7; }
  .rcp .dot.no  { background:#64748b; }
  .rcp .when { color:var(--muted); font-size:10.5px; }
  .docl { font-size:11.5px; color:var(--muted); line-height:1.7; max-width:230px; }
  .docl .mono { color:#cbd5e1; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  .acts { display:flex; flex-direction:column; gap:6px; align-items:flex-start; }
  .acts a.btn { text-decoration:none; display:inline-block; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select, .frow textarea { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .frow select[multiple] { min-height:118px; padding:6px; }
  .frow select[multiple] option { padding:4px 6px; border-radius:4px; }
  .hint { font-size:11px; color:#64748b; margin-top:4px; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
</style>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($isVendorRegister ? $L('vtitle') : $L('title')); ?></h1>
  <div class="sub">
    <?php echo e($project->name($lang)); ?> · <span class="mono"><?php echo e($project->code); ?></span> ·
    <?php echo e($isVendorRegister ? $L('vsub') : $L('sub')); ?> · <?php echo e($jalaliDate); ?>

  </div>

  
  <div class="bar" style="margin-bottom:14px">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isVendorRegister): ?>
      <a class="btn ghost" href="/projects/<?php echo e($project->code); ?>/transmittals?lang=<?php echo e($lang); ?>"><?php echo e($L('gocon')); ?></a>
    <?php else: ?>
      <a class="btn ghost" href="/projects/<?php echo e($project->code); ?>/vendor-transmittals?lang=<?php echo e($lang); ?>"><?php echo e($L('goven')); ?></a>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
      <div class="v"><?php echo e($num(count($transmittals))); ?></div>
    </div>
    <div class="dstat">
      <div class="k"><?php echo e($L('issued')); ?></div>
      <div class="v"><?php echo e($num($issued->count())); ?></div>
    </div>
    <div class="dstat <?php echo e($awaiting->count() > 0 ? 'warn' : 'good'); ?>">
      <div class="k"><?php echo e($L('openack')); ?></div>
      <div class="v"><?php echo e($num($awaiting->count())); ?></div>
    </div>
    <div class="dstat <?php echo e($overdue->count() > 0 ? 'warn' : 'good'); ?>">
      <div class="k"><?php echo e($L('od')); ?></div>
      <div class="v"><?php echo e($num($overdue->count())); ?></div>
    </div>
  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canIssue): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($documents->isEmpty()): ?>
      <div class="err" style="background:rgba(148,163,184,.09);border-color:var(--line);color:var(--muted);">
        <?php echo e($L('nodocs')); ?>

      </div>
    <?php else: ?>
      <fieldset>
        <legend><?php echo e($isVendorRegister ? $L('vnew') : $L('new')); ?></legend>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isVendorRegister && $vendors->isEmpty()): ?>
          <div class="err" style="background:rgba(148,163,184,.09);border-color:var(--line);color:var(--muted);">
            <?php echo e($L('novend')); ?>

          </div>
        <?php else: ?>
        <form method="post" action="/projects/<?php echo e($project->code); ?>/transmittals?lang=<?php echo e($lang); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="kind" value="<?php echo e($kind); ?>">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isVendorRegister): ?>
            <div class="frow">
              <div>
                <label for="vendor_company_id"><?php echo e($L('vendor')); ?></label>
                <select id="vendor_company_id" name="vendor_company_id" required>
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $vendors; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($v->id); ?>"><?php echo e($v->code); ?> — <?php echo e($v->name($lang)); ?></option>
                  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
              </div>
              <div>
                <label for="vendor_reference"><?php echo e($L('vref')); ?></label>
                <input id="vendor_reference" name="vendor_reference" maxlength="128"
                       placeholder="AZ-2026-114">
              </div>
            </div>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <div class="frow">
            <div>
              <label for="subject_en"><?php echo e($L('sen')); ?></label>
              <input id="subject_en" name="subject_en" required maxlength="512">
            </div>
            <div>
              <label for="subject_fa"><?php echo e($L('sfa')); ?></label>
              <input id="subject_fa" name="subject_fa" required maxlength="512">
            </div>
            <div>
              <label for="purpose"><?php echo e($L('purpose')); ?></label>
              <select id="purpose" name="purpose" required>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $purposes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($p); ?>"><?php echo e(\App\Support\TransmittalPurpose::label($p, $lang)); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
              <div class="hint">
                <?php echo e($fa
                  ? 'مهلت تأیید دریافت از روی هدف تعیین می‌شود؛ «جهت اطلاع» نیازی به تأیید ندارد.'
                  : 'The acknowledgement deadline follows the purpose; "for information" needs none.'); ?>

              </div>
            </div>
          </div>
          <div class="frow">
            <div>
              <label for="documents"><?php echo e($L('pick')); ?></label>
              <select id="documents" name="documents[]" multiple required>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $documents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($d->id); ?>">
                    <?php echo e($d->number); ?> · <?php echo e($d->currentRevision->revision); ?> · <?php echo e($d->title($lang)); ?>

                  </option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
            </div>
            <div>
              <label for="recipients"><?php echo e($L('pickto')); ?></label>
              <select id="recipients" name="recipients[]" multiple required>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $companies; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <option value="<?php echo e($c->id); ?>"><?php echo e($c->code); ?> · <?php echo e($c->name($lang)); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </select>
            </div>
          </div>
          <div class="frow" style="grid-template-columns:1fr;">
            <div>
              <label for="notes"><?php echo e($L('notes')); ?></label>
              <textarea id="notes" name="notes" maxlength="4000" rows="2"></textarea>
            </div>
          </div>
          <div class="btnrow">
            <button type="submit" name="issue_now" value="1" class="btn"><?php echo e($L('issuenow')); ?></button>
            <button type="submit" class="btn ghost"><?php echo e($L('draftit')); ?></button>
          </div>
        </form>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </fieldset>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($transmittals->isEmpty()): ?>
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;">
      <?php echo e($isVendorRegister ? $L('vnone') : $L('empty')); ?>

    </div>
  <?php else: ?>
    
    <table data-tt>
      <thead>
        <tr>
          <th data-sort="text"><?php echo e($L('number')); ?></th>
          <th data-sort="text"><?php echo e($L('subject')); ?></th>
          <th data-sort="text" data-facet><?php echo e($L('purpose')); ?></th>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isVendorRegister): ?>
            <th data-sort="text" data-facet><?php echo e($L('vendor')); ?></th>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <th data-sort="text" data-facet><?php echo e($L('from')); ?></th>
          <th data-sort="text"><?php echo e($L('to')); ?></th>
          <th data-sort="num"><?php echo e($L('docs')); ?></th>
          <th data-sort="text" data-facet><?php echo e($L('status')); ?></th>
          <th><?php echo e($L('actions')); ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $transmittals; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tr): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php
          $isSender = $tr->company_id === $acting->id;
          $mine = $tr->recipients->firstWhere('recipient_company_id', $acting->id);
          $od = $tr->isOverdue();
          $statusKey = $tr->isDraft() ? 'draft' : ($od ? 'overdue' : 'issued');
        ?>
        <tr>
          <td><span class="mono"><?php echo e($tr->number); ?></span></td>
          <td>
            <?php echo e($tr->subject($lang)); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tr->issued_at): ?>
              <div class="hint"><?php echo e($jdate($tr->issued_at)); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tr->due_at): ?> · <?php echo e($L('due')); ?> <?php echo e($jdate($tr->due_at)); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td><?php echo e($tr->purposeLabel($lang)); ?></td>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isVendorRegister): ?>
            <td>
              <span class="pill pack"><?php echo e($tr->vendor?->code ?? '—'); ?></span>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tr->vendor_reference): ?>
                <div class="hint mono"><?php echo e($tr->vendor_reference); ?></div>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </td>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          <td><span class="pill pack"><?php echo e($tr->sender->code); ?></span></td>
          <td>
            
            <div class="rcp">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tr->recipients; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="r">
                  <span class="dot <?php echo e($r->hasAcknowledged() ? 'yes' : 'no'); ?>"
                        title="<?php echo e($r->hasAcknowledged() ? $L('acked') : $L('waiting')); ?>"></span>
                  <span class="mono"><?php echo e($r->company->code); ?></span>
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($r->hasAcknowledged()): ?>
                    <span class="when"><?php echo e($jdate($r->acknowledged_at)); ?></span>
                  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
              <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
          </td>
          <td data-sortkey="<?php echo e($tr->items->count()); ?>">
            <div class="docl">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tr->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $it): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div>
                  <span class="mono"><?php echo e($it->number_at_issue); ?></span> · <?php echo e($it->revision_at_issue); ?>

                  
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($it->source_item_id && $it->source?->transmittal): ?>
                    <span class="hint">(<?php echo e($L('srcfrom')); ?>

                      <span class="mono"><?php echo e($it->source->transmittal->number); ?></span>)</span>
                  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
              <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
          </td>
          
          <td data-sortkey="<?php echo e($tr->isDraft() ? 0 : ($od ? 1 : 2)); ?>" data-facet-value="<?php echo e($L($statusKey)); ?>">
            <span class="st <?php echo e($od ? 'OVERDUE' : $tr->status); ?>"><?php echo e($L($statusKey)); ?></span>
          </td>
          <td>
            <div class="acts">
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isVendorRegister && $tr->isIssued() && $isSender && $canIssue): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rollUpTargets->isEmpty()): ?>
                  <span class="hint"><?php echo e($L('nodraft')); ?></span>
                <?php else: ?>
                  <form method="post"
                        action="/projects/<?php echo e($project->code); ?>/vendor-transmittals/<?php echo e($tr->id); ?>/rollup?lang=<?php echo e($lang); ?>">
                    <?php echo csrf_field(); ?>
                    <label class="hint" for="rt_<?php echo e($tr->id); ?>"><?php echo e($L('rollup')); ?></label>
                    <select id="rt_<?php echo e($tr->id); ?>" name="target_id" required>
                      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $rollUpTargets; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($t->id); ?>"><?php echo e($t->number); ?></option>
                      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </select>
                    <button type="submit" class="btn ghost"><?php echo e($L('rollupgo')); ?></button>
                  </form>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tr->isDraft() && $isSender && $canIssue): ?>
                <form method="post" action="/projects/<?php echo e($project->code); ?>/transmittals/<?php echo e($tr->id); ?>?lang=<?php echo e($lang); ?>">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="issue">
                  <button type="submit" class="btn"><?php echo e($L('issue')); ?></button>
                </form>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tr->isIssued()): ?>
                <a class="btn ghost" href="/projects/<?php echo e($project->code); ?>/transmittals/<?php echo e($tr->id); ?>/download?lang=<?php echo e($lang); ?>">
                  <?php echo e($L('zip')); ?>

                </a>
                <a class="btn ghost" href="/projects/<?php echo e($project->code); ?>/transmittals/<?php echo e($tr->id); ?>/sheet.pdf?lang=<?php echo e($lang); ?>">
                  <?php echo e($L('sheet')); ?>

                </a>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

              
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tr->isIssued() && $mine && ! $mine->hasAcknowledged()): ?>
                <form method="post" action="/projects/<?php echo e($project->code); ?>/transmittals/<?php echo e($tr->id); ?>?lang=<?php echo e($lang); ?>">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="acknowledge">
                  <button type="submit" class="btn"><?php echo e($L('ack')); ?></button>
                </form>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="foot">
    <?php echo e($fa
      ? 'نامهٔ صادرشده قابل تغییر نیست و آنچه را در لحظهٔ صدور ارسال شده ثبت می‌کند. هر گیرنده جداگانه دریافت را تأیید می‌کند و بستهٔ ZIP شامل فایل MANIFEST با اثر انگشت SHA-256 هر فایل است.'
      : 'An issued transmittal cannot be changed and records what was sent at the moment of issue. Each recipient acknowledges separately, and the ZIP carries a MANIFEST with the SHA-256 of every file.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/transmittals/index.blade.php ENDPATH**/ ?>