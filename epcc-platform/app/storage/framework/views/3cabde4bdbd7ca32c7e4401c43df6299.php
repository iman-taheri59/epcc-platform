<?php
    $fa = $lang === 'fa';
    $t = [
        'org'     => ['fa' => 'سامانهٔ مدیریت پروژه‌های صنعتی', 'en' => 'Industrial Project Management Platform'],
        'title'   => ['fa' => 'نامهٔ ارسال مدارک', 'en' => 'Document Transmittal'],
        'strap'   => ['fa' => 'سند رسمی ارسال و رسید مدارک', 'en' => 'Formal record of issue and receipt'],
        'number'  => ['fa' => 'شمارهٔ نامه', 'en' => 'Transmittal no.'],
        'project' => ['fa' => 'پروژه', 'en' => 'Project'],
        'subject' => ['fa' => 'موضوع', 'en' => 'Subject'],
        'purpose' => ['fa' => 'هدف ارسال', 'en' => 'Purpose'],
        'from'    => ['fa' => 'فرستنده', 'en' => 'From'],
        'vendor'  => ['fa' => 'وندور', 'en' => 'Vendor'],
        'vref'    => ['fa' => 'شمارهٔ نامهٔ وندور', 'en' => "Vendor's reference"],
        'recby'   => ['fa' => 'ثبت‌شده توسط', 'en' => 'Recorded by'],
        'issued'  => ['fa' => 'تاریخ صدور', 'en' => 'Issued'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'Status'],
        'draft'   => ['fa' => 'پیش‌نویس — ارسال نشده', 'en' => 'DRAFT — not issued'],
        'issuedS' => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'notes'   => ['fa' => 'توضیحات', 'en' => 'Notes'],
        'rcp'     => ['fa' => 'گیرندگان', 'en' => 'Recipients'],
        'rcompany'=> ['fa' => 'شرکت', 'en' => 'Company'],
        'rack'    => ['fa' => 'تأیید دریافت', 'en' => 'Acknowledged'],
        'rdue'    => ['fa' => 'مهلت پاسخ', 'en' => 'Response due'],
        'pending' => ['fa' => 'در انتظار', 'en' => 'Pending'],
        'overdue' => ['fa' => 'سررسید گذشته', 'en' => 'Overdue'],
        'none'    => ['fa' => 'ندارد', 'en' => 'None'],
        'docs'    => ['fa' => 'مدارک ارسالی', 'en' => 'Documents transmitted'],
        'dnum'    => ['fa' => 'شمارهٔ مدرک', 'en' => 'Document no.'],
        'drev'    => ['fa' => 'بازنگری', 'en' => 'Rev'],
        'dstate'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'dsuit'   => ['fa' => 'کد مناسبت', 'en' => 'Suitability'],
        'integ'   => ['fa' => 'اصالت محتوا', 'en' => 'Content integrity'],
        'integb'  => ['fa' => 'تمام فایل‌های این بسته به همراه اثر انگشت SHA-256 در فایل MANIFEST.csv فهرست شده‌اند. برای اطمینان از تغییرنکردن محتوا پس از صدور، اثر انگشت‌ها را بررسی کنید.',
                      'en' => 'Every file in this package is listed in MANIFEST.csv with its SHA-256 fingerprint as issued. Verify the fingerprints to confirm the contents have not changed since issue.'],
        'sigs'    => ['fa' => 'فرستنده', 'en' => 'Issued by'],
        'sigr'    => ['fa' => 'گیرنده', 'en' => 'Received by'],
        'signame' => ['fa' => 'نام، امضا و تاریخ', 'en' => 'Name, signature and date'],
        'foot'    => ['fa' => 'این سند به‌صورت الکترونیکی تولید شده است.', 'en' => 'This document was generated electronically.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $when = fn ($d) => \App\Support\JalaliDate::formatWithTime($d, $lang);
?>

<?php $__env->startSection('document'); ?>
<header class="doc">
  <div class="org"><?php echo e($L('org')); ?></div>
  <h1><?php echo e($L('title')); ?></h1>
  <div class="strap"><?php echo e($L('strap')); ?></div>
</header>

<table class="meta">
  <tr><td class="k"><?php echo e($L('number')); ?></td><td><span class="mono"><?php echo e($transmittal->number); ?></span></td></tr>
  <tr><td class="k"><?php echo e($L('project')); ?></td><td><?php echo e($project->name($lang)); ?> (<span class="mono"><?php echo e($project->code); ?></span>)</td></tr>
  <tr><td class="k"><?php echo e($L('subject')); ?></td><td><?php echo e($fa ? $transmittal->subject_fa : $transmittal->subject_en); ?></td></tr>
  <tr><td class="k"><?php echo e($L('purpose')); ?></td><td><?php echo e($transmittal->purposeLabel($lang)); ?></td></tr>
  
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($transmittal->isVendor()): ?>
    <tr><td class="k"><?php echo e($L('vendor')); ?></td>
        <td><?php echo e($transmittal->vendor?->name($lang) ?? '—'); ?>

            (<span class="mono"><?php echo e($transmittal->vendor?->code); ?></span>)</td></tr>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($transmittal->vendor_reference): ?>
      <tr><td class="k"><?php echo e($L('vref')); ?></td>
          <td><span class="mono"><?php echo e($transmittal->vendor_reference); ?></span></td></tr>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <tr><td class="k"><?php echo e($L('recby')); ?></td><td><?php echo e($sender?->name($lang) ?? '—'); ?></td></tr>
  <?php else: ?>
    <tr><td class="k"><?php echo e($L('from')); ?></td><td><?php echo e($sender?->name($lang) ?? '—'); ?></td></tr>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <tr><td class="k"><?php echo e($L('state')); ?></td><td><?php echo e($transmittal->issued_at ? $L('issuedS') : $L('draft')); ?></td></tr>
  <tr><td class="k"><?php echo e($L('issued')); ?></td><td><?php echo e($when($transmittal->issued_at)); ?></td></tr>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($transmittal->notes): ?>
    <tr><td class="k"><?php echo e($L('notes')); ?></td><td><?php echo e($transmittal->notes); ?></td></tr>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</table>

<h2><?php echo e($L('rcp')); ?></h2>
<table class="grid">
  <thead><tr>
    <th><?php echo e($L('rcompany')); ?></th>
    <th style="width:38mm"><?php echo e($L('rack')); ?></th>
    <th style="width:34mm"><?php echo e($L('rdue')); ?></th>
  </tr></thead>
  <tbody>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $transmittal->recipients; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
      <td><span class="mono"><?php echo e($r->company?->code); ?></span> — <?php echo e($r->company?->name($lang)); ?></td>
      <td><?php echo e($r->acknowledged_at ? $when($r->acknowledged_at) : $L('pending')); ?></td>
      <td><?php echo e($r->response_due_at ? $when($r->response_due_at) : $L('none')); ?></td>
    </tr>
  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </tbody>
</table>

<h2><?php echo e($L('docs')); ?></h2>

<table class="grid">
  <thead><tr>
    <th><?php echo e($L('dnum')); ?></th>
    <th style="width:20mm"><?php echo e($L('drev')); ?></th>
    <th style="width:30mm"><?php echo e($L('dstate')); ?></th>
    <th style="width:26mm"><?php echo e($L('dsuit')); ?></th>
  </tr></thead>
  <tbody>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $transmittal->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
      <td><span class="mono"><?php echo e($i->number_at_issue); ?></span></td>
      <td><span class="mono"><?php echo e($i->revision_at_issue); ?></span></td>
      <td><?php echo e($i->state_at_issue); ?></td>
      <td><span class="mono"><?php echo e($i->suitability_at_issue ?? '—'); ?></span></td>
    </tr>
  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </tbody>
</table>

<div class="note">
  <strong><?php echo e($L('integ')); ?></strong><br>
  <?php echo e($L('integb')); ?>

</div>

<table class="sigs">
  <tr>
    <td><div class="box"></div><div class="lbl"><?php echo e($L('sigs')); ?><br><?php echo e($L('signame')); ?></div></td>
    <td><div class="box"></div><div class="lbl"><?php echo e($L('sigr')); ?><br><?php echo e($L('signame')); ?></div></td>
    <td></td>
  </tr>
</table>

<footer class="doc">
  <span><span class="mono"><?php echo e($transmittal->number); ?></span> · <?php echo e($project->code); ?></span>
  <span><?php echo e($L('foot')); ?> <?php echo e($generatedAt); ?></span>
</footer>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('print.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/user/epcc-platform/app/resources/views/print/transmittal.blade.php ENDPATH**/ ?>