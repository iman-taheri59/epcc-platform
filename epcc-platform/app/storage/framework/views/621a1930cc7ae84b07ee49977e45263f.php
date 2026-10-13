<?php
    $fa = $lang === 'fa';
    $t = [
        'org'     => ['fa' => 'سامانهٔ مدیریت پروژه‌های صنعتی', 'en' => 'Industrial Project Management Platform'],
        'title'   => ['fa' => 'گواهی تکمیل مکانیکی', 'en' => 'Mechanical Completion Certificate'],
        'strap'   => ['fa' => 'صادرشده بر اساس رویهٔ راه‌اندازی و تحویل', 'en' => 'Issued under the commissioning and handover procedure'],
        'number'  => ['fa' => 'شمارهٔ گواهی', 'en' => 'Certificate no.'],
        'project' => ['fa' => 'پروژه', 'en' => 'Project'],
        'subsys'  => ['fa' => 'زیرسیستم', 'en' => 'Subsystem'],
        'disc'    => ['fa' => 'رشته', 'en' => 'Discipline'],
        'issued'  => ['fa' => 'تاریخ صدور', 'en' => 'Issued'],
        'issuer'  => ['fa' => 'صادرکننده', 'en' => 'Issued by'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'Status'],
        'draft'   => ['fa' => 'پیش‌نویس — فاقد اعتبار', 'en' => 'DRAFT — not valid'],
        'issuedS' => ['fa' => 'صادر شده', 'en' => 'Issued'],
        'decl'    => ['fa' => 'اعلامیه', 'en' => 'Declaration'],
        'declbody'=> ['fa' => 'بدین‌وسیله گواهی می‌شود که زیرسیستم یادشده مطابق مدارک طراحی و مشخصات فنی پروژه، نصب و آزمون شده و از نظر مکانیکی تکمیل است.',
                      'en' => 'This is to certify that the subsystem identified above has been installed and tested in accordance with the project design documents and specifications, and is mechanically complete.'],
        'punch'   => ['fa' => 'وضعیت نواقص', 'en' => 'Punch list status'],
        'ptotal'  => ['fa' => 'کل نواقص', 'en' => 'Total items'],
        'pclosed' => ['fa' => 'بسته‌شده', 'en' => 'Closed'],
        'popenA'  => ['fa' => 'باز — ردهٔ A', 'en' => 'Open — category A'],
        'clean'   => ['fa' => 'در زمان صدور، هیچ نقص باز ردهٔ A وجود نداشت.', 'en' => 'At issue there were no open category-A items.'],
        'excep'   => ['fa' => 'استثنای تأییدشدهٔ کارفرما', 'en' => 'Owner-approved exception'],
        'excbody' => ['fa' => 'این گواهی با وجود نواقص باز ردهٔ A و بر پایهٔ استثنای زیر صادر شده است:',
                      'en' => 'This certificate was issued over open category-A items on the basis of the following exception:'],
        'excby'   => ['fa' => 'تأییدکننده', 'en' => 'Approved by'],
        'excat'   => ['fa' => 'تاریخ تأیید', 'en' => 'Approved on'],
        'excwhy'  => ['fa' => 'دلیل', 'en' => 'Reason'],
        'excitems'=> ['fa' => 'نواقص مشمول استثنا در زمان صدور', 'en' => 'Items waived at issue'],
        'pnum'    => ['fa' => 'شماره', 'en' => 'Number'],
        'pcat'    => ['fa' => 'رده', 'en' => 'Cat.'],
        'pstate'  => ['fa' => 'وضعیت', 'en' => 'State'],
        'pdesc'   => ['fa' => 'شرح', 'en' => 'Description'],
        'sigc'    => ['fa' => 'پیمانکار', 'en' => 'Contractor'],
        'sigm'    => ['fa' => 'مدیریت پیمان', 'en' => 'Management contractor'],
        'sigo'    => ['fa' => 'کارفرما', 'en' => 'Employer'],
        'signame' => ['fa' => 'نام، امضا و تاریخ', 'en' => 'Name, signature and date'],
        'foot'    => ['fa' => 'این سند به‌صورت الکترونیکی تولید شده است.', 'en' => 'This document was generated electronically.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
    $when = fn ($d) => \App\Support\JalaliDate::formatWithTime($d, $lang);
?>

<?php $__env->startSection('document'); ?>
<header class="doc">
  <div class="org"><?php echo e($L('org')); ?></div>
  <h1><?php echo e($L('title')); ?></h1>
  <div class="strap"><?php echo e($L('strap')); ?></div>
</header>

<table class="meta">
  <tr><td class="k"><?php echo e($L('number')); ?></td><td><span class="mono"><?php echo e($certificate->number); ?></span></td></tr>
  <tr><td class="k"><?php echo e($L('project')); ?></td><td><?php echo e($project->name($lang)); ?> (<span class="mono"><?php echo e($project->code); ?></span>)</td></tr>
  <tr><td class="k"><?php echo e($L('subsys')); ?></td><td><?php echo e($subsystem->name($lang)); ?> (<span class="mono"><?php echo e($subsystem->code); ?></span>)</td></tr>
  <tr><td class="k"><?php echo e($L('disc')); ?></td><td><?php echo e($subsystem->discipline); ?></td></tr>
  <tr><td class="k"><?php echo e($L('state')); ?></td><td><?php echo e($certificate->isIssued() ? $L('issuedS') : $L('draft')); ?></td></tr>
  <tr><td class="k"><?php echo e($L('issued')); ?></td><td><?php echo e($when($certificate->issued_at)); ?></td></tr>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($issuer): ?>
    <tr><td class="k"><?php echo e($L('issuer')); ?></td><td><?php echo e($issuer->name); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($issuerCompany): ?> — <?php echo e($issuerCompany->name($lang)); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></td></tr>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</table>

<h2><?php echo e($L('decl')); ?></h2>
<p><?php echo e($L('declbody')); ?></p>

<h2><?php echo e($L('punch')); ?></h2>

<?php ($summary = $certificate->punchSummary($items, $openA)); ?>
<table class="meta">
  <tr><td class="k"><?php echo e($L('ptotal')); ?></td><td><?php echo e($num($summary['total'])); ?></td></tr>
  <tr><td class="k"><?php echo e($L('pclosed')); ?></td><td><?php echo e($num($summary['closed'])); ?></td></tr>
  <tr><td class="k"><?php echo e($L('popenA')); ?></td><td><?php echo e($num($summary['open_blocking'])); ?></td></tr>
</table>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($certificate->hasApprovedException()): ?>
  
  <div class="note warn">
    <div class="t"><?php echo e($L('excep')); ?></div>
    <p style="margin:2mm 0"><?php echo e($L('excbody')); ?></p>
    <table class="meta" style="margin-bottom:0">
      <tr><td class="k"><?php echo e($L('excby')); ?></td><td><?php echo e($exceptionApprover?->name ?? '—'); ?></td></tr>
      <tr><td class="k"><?php echo e($L('excat')); ?></td><td><?php echo e($when($certificate->exception_approved_at)); ?></td></tr>
      <tr><td class="k"><?php echo e($L('excwhy')); ?></td><td><?php echo e($certificate->exception_reason); ?></td></tr>
    </table>
  </div>

  <h2><?php echo e($L('excitems')); ?></h2>
  
  <table class="grid">
    <thead><tr>
      <th style="width:32mm"><?php echo e($L('pnum')); ?></th>
      <th style="width:14mm"><?php echo e($L('pcat')); ?></th>
      <th style="width:26mm"><?php echo e($L('pstate')); ?></th>
      <th><?php echo e($L('pdesc')); ?></th>
    </tr></thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $certificate->exception_items ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $w): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <tr>
        <td><span class="mono"><?php echo e($w['number'] ?? '—'); ?></span></td>
        <td><?php echo e(\App\Support\PunchCategory::shortLabel($w['category'] ?? '', $lang)); ?></td>
        <td><?php echo e(\App\Support\PunchState::label($w['state'] ?? '')[$lang] ?? ($w['state'] ?? '—')); ?></td>
        <td><?php echo e($fa ? ($w['description_fa'] ?? '') : ($w['description_en'] ?? '')); ?></td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
<?php else: ?>
  <div class="note"><?php echo e($L('clean')); ?></div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<table class="sigs">
  <tr>
    <td><div class="box"></div><div class="lbl"><?php echo e($L('sigc')); ?><br><?php echo e($L('signame')); ?></div></td>
    <td><div class="box"></div><div class="lbl"><?php echo e($L('sigm')); ?><br><?php echo e($L('signame')); ?></div></td>
    <td><div class="box"></div><div class="lbl"><?php echo e($L('sigo')); ?><br><?php echo e($L('signame')); ?></div></td>
  </tr>
</table>

<footer class="doc">
  <span><span class="mono"><?php echo e($certificate->number); ?></span> · <?php echo e($project->code); ?></span>
  <span><?php echo e($L('foot')); ?> <?php echo e($generatedAt); ?></span>
</footer>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('print.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/user/epcc-platform/app/resources/views/print/mc-certificate.blade.php ENDPATH**/ ?>