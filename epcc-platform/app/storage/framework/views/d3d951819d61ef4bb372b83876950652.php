<?php
    use App\Support\Itp\InterventionCode;
    use App\Support\TenantType;

    $fa = $lang === 'fa';
    $t = [
        'back'   => ['fa' => 'بازگشت به فهرست ITP', 'en' => 'Back to the ITP register'],
        'clause' => ['fa' => 'بند', 'en' => 'Clause'],
        'act'    => ['fa' => 'فعالیت بازرسی', 'en' => 'Inspection activity'],
        'accept' => ['fa' => 'معیار پذیرش', 'en' => 'Acceptance criteria'],
        'method' => ['fa' => 'روش', 'en' => 'Method'],
        'freq'   => ['fa' => 'تناوب', 'en' => 'Frequency'],
        'rec'    => ['fa' => 'مدرک', 'en' => 'Record'],
        'notice' => ['fa' => 'اعلام', 'en' => 'Notice'],
        'legend' => ['fa' => 'راهنمای کدها', 'en' => 'Code legend'],
        'hours'  => ['fa' => 'ساعت', 'en' => 'h'],
        'not_registered'  => ['fa' => 'در مدارک ثبت نشده', 'en' => 'not in the register'],
        'register_action' => ['fa' => 'ثبت در مدارک پروژه', 'en' => 'Enter in the document register'],
        'registered_as'   => ['fa' => 'شمارهٔ ثبت', 'en' => 'Register entry'],
        'annexes'         => ['fa' => 'پیوست‌ها', 'en' => 'Annexes'],
        'col_standards'    => ['fa' => 'استانداردها', 'en' => 'Standards'],
        'col_project_refs' => ['fa' => 'مدارک پروژه', 'en' => 'Project references'],
        'col_form'         => ['fa' => 'فرم گزارش', 'en' => 'Report form'],
        'no_reference'     => ['fa' => '—', 'en' => '—'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $q = ['lang' => $lang];

    // The party columns. Fixed order so the grid reads like the printed ITP,
    // and so a stage where a party has no involvement shows a blank cell in a
    // known place rather than shifting the whole row.
    $parties = [TenantType::EPC, TenantType::VEN, TenantType::PMC, TenantType::MC, TenantType::TPI, TenantType::OWN];

    $colour = fn (string $c): string => match ($c) {
        InterventionCode::HOLD => '#f87171',
        InterventionCode::WITNESS => '#fbbf24',
        InterventionCode::MONITOR => '#60a5fa',
        InterventionCode::REVIEW => '#a78bfa',
        default => 'var(--muted)',
    };
?>
<!doctype html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo e($dir); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($itp->number); ?></title>
<?php echo $__env->make('partials.shell', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('partials.tabletools', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($itp->title($lang)); ?></h1>
  <div class="sub">
    <bdi class="mono"><?php echo e($itp->displayNumber()); ?></bdi> · <?php echo e($itp->discipline); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($itp->revision_label): ?> · <?php echo e($itp->revision_label); ?> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($itp->isRegistered()): ?>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($itp->cdeState()): ?>
        · <span style="color:var(--muted);"><?php echo e($itp->cdeState()); ?></span>
      <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php else: ?>
      · <span style="color:#fbbf24;"><?php echo e($L('not_registered')); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($fa ? $itp->scope_fa : $itp->scope_en): ?>
    <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;max-width:860px;">
      <?php echo e($fa ? $itp->scope_fa : $itp->scope_en); ?>

    </p>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <p style="margin:10px 0 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
    <a href="/projects/<?php echo e($project->code); ?>/itp?<?php echo e(http_build_query($q)); ?>"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; <?php echo e($L('back')); ?></a>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($itp->isRegistered())): ?>
      <form method="POST" action="<?php echo e(route('itp.register', ['projectCode' => $project->code, 'itpId' => $itp->id])); ?>"
            style="margin:0;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="lang" value="<?php echo e($lang); ?>">
        <button type="submit" style="font-size:12.5px;padding:5px 12px;cursor:pointer;">
          <?php echo e($L('register_action')); ?>

        </button>
      </form>
    <?php else: ?>
      <span style="font-size:12px;color:var(--muted);">
        <?php echo e($L('registered_as')); ?>:
        <a href="<?php echo e(route('documents', ['projectCode' => $project->code])); ?>#doc-<?php echo e($itp->document_id); ?>"
           style="color:#93c5fd;text-decoration:none;">
          <bdi class="mono"><?php echo e($itp->document?->number); ?></bdi></a>
      </span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </p>

  
  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($itp->annexes->isNotEmpty()): ?>
    <div style="margin-top:12px;font-size:12.5px;">
      <b style="color:var(--muted);"><?php echo e($L('annexes')); ?>:</b>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $itp->annexes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $annex): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <span style="margin-inline-end:14px;">
          <?php echo e($annex->caption($lang)); ?> —
          <a href="<?php echo e(route('documents', ['projectCode' => $project->code])); ?>#doc-<?php echo e($annex->document_id); ?>"
             style="color:#93c5fd;text-decoration:none;">
            <bdi class="mono"><?php echo e($annex->document?->number); ?></bdi></a>
          <span style="color:var(--muted);"><?php echo e($fa ? $annex->document?->title_fa : $annex->document?->title_en); ?></span>
        </span>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  
  <div style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;font-size:12px;">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = InterventionCode::ALL; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $code): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <span style="display:inline-flex;align-items:center;gap:6px;">
        <b style="color:<?php echo e($colour($code)); ?>;font-family:ui-monospace,monospace;"><?php echo e($code); ?></b>
        <span><?php echo e(InterventionCode::label($code)[$lang]); ?></span>
        <span style="color:var(--muted);">— <?php echo e(InterventionCode::description($code)[$lang]); ?></span>
      </span>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  </div>

  <div style="margin-top:16px;overflow-x:auto;">
  <table data-tt style="min-width:1180px;">
    <thead><tr>
      <th><?php echo e($L('clause')); ?></th>
      <th><?php echo e($L('act')); ?></th>
      <th><?php echo e($L('accept')); ?></th>
      <th><?php echo e($L('method')); ?></th>
      <th><?php echo e($L('freq')); ?></th>
      <th><?php echo e($L('col_standards')); ?></th>
      <th><?php echo e($L('col_project_refs')); ?></th>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $parties; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <th style="text-align:center;" title="<?php echo e(TenantType::label($p)[$lang]); ?>"><?php echo e($p); ?></th>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      <th><?php echo e($L('rec')); ?></th>
      <th><?php echo e($L('col_form')); ?></th>
    </tr></thead>
    <tbody>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $itp->stages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $stage): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php $grid = $stage->parties->keyBy('tenant_type'); ?>
      <tr>
        <td class="mono" style="font-size:12px;white-space:nowrap;">
          <?php echo e($num($stage->clause)); ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->isHoldPoint()): ?>
            <div style="color:#f87171;font-size:10px;"><?php echo e($fa ? 'نقطهٔ توقف' : 'HOLD'); ?></div>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <td style="font-size:13px;"><?php echo e($stage->title($lang)); ?></td>
        <td style="font-size:12px;color:var(--muted);line-height:1.7;min-width:260px;"><?php echo e($stage->acceptance($lang)); ?></td>
        <td style="font-size:12px;"><span class="mono"><?php echo e($stage->method); ?></span></td>
        <td style="font-size:12px;"><?php echo e($fa ? $stage->frequency_fa : $stage->frequency_en); ?></td>

        
        <td style="font-size:11.5px;min-width:130px;line-height:1.7;">
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->printed_standard_basis): ?>
            <bdi class="mono"><?php echo e($stage->printed_standard_basis); ?></bdi>
          <?php else: ?>
            <span style="color:var(--muted);"><?php echo e($L('no_reference')); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>

        
        <td style="font-size:11.5px;line-height:1.8;white-space:nowrap;">
          <?php $refs = $stage->documents->whereIn('usage', ['SPEC','DRAWING','PROCEDURE']); ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $refs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ref): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div>
              <a href="<?php echo e(route('documents', ['projectCode' => $itp->project->code])); ?>#doc-<?php echo e($ref->document_id); ?>"
                 style="color:#93c5fd;text-decoration:none;">
                <bdi class="mono"><?php echo e($ref->document?->number); ?></bdi></a><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ref->locator): ?><bdi
                 style="color:var(--muted);">&nbsp;·&nbsp;<?php echo e($ref->locator); ?></bdi><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ref->pinned_revision_id): ?>
                <div style="color:#fbbf24;font-size:10px;">
                  <?php echo e($fa ? 'تثبیت‌شده روی' : 'pinned at'); ?>

                  <bdi class="mono"><?php echo e($ref->pinnedRevision?->revision); ?></bdi>
                </div>
              <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <span style="color:var(--muted);"><?php echo e($L('no_reference')); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $parties; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <?php $cell = $grid->get($p); ?>
          <td style="text-align:center;font-family:ui-monospace,monospace;font-weight:700;color:<?php echo e($cell ? $colour($cell->intervention) : 'var(--line,#374151)'); ?>;"
              title="<?php echo e($cell ? InterventionCode::label($cell->intervention)[$lang] : ''); ?>">
            <?php echo e($cell?->intervention ?? '·'); ?>

          </td>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <td style="font-size:12px;">
          <?php echo e($fa ? $stage->record_fa : $stage->record_en); ?>

          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->notice_hours): ?>
            <div style="color:var(--muted);font-size:11px;"><?php echo e($L('notice')); ?> <?php echo e($num($stage->notice_hours)); ?><?php echo e($L('hours')); ?></div>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>

        
        <td style="font-size:11.5px;white-space:nowrap;">
          <?php $form = $stage->documents->firstWhere('usage', 'FORM'); ?>
          <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($form?->document): ?>
            <a href="<?php echo e(route('documents', ['projectCode' => $itp->project->code])); ?>#doc-<?php echo e($form->document_id); ?>"
               style="color:#93c5fd;text-decoration:none;">
              <bdi class="mono"><?php echo e($form->document->number); ?></bdi></a>
          <?php elseif($stage->form_number): ?>
            <bdi class="mono"><?php echo e($stage->form_number); ?></bdi>
          <?php else: ?>
            <span style="color:var(--muted);"><?php echo e($L('no_reference')); ?></span>
          <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="foot">
    <?php echo e($fa
        ? 'این جدول همان چیزی است که سر پروژه بر سرش بحث می‌شود: چه کسی کجا حق توقف کار را دارد. به همین دلیل شبکهٔ ارکان یک جدول واقعی است، نه یک فیلد JSON — تا بتوان پرسید «بازرس ثالث در این پروژه کجاها نقطهٔ توقف دارد؟».'
        : 'This grid is what people actually argue about on a project: who may stop the work, and where. That is why the party grid is a real table rather than a JSON blob — so the question "where does the TPI hold in this project?" has an answer.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/itp/show.blade.php ENDPATH**/ ?>