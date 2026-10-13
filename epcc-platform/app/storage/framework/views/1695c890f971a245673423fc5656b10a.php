<?php
    $fa = $lang === 'fa';
    $t = [
        'title'   => ['fa' => 'دفتر مدارک', 'en' => 'Document register'],
        'sub'     => ['fa' => 'حالت‌های ایزو ۱۹۶۵۰ و کنترل بازنگری', 'en' => 'ISO 19650 CDE states and revision control'],
        'number'  => ['fa' => 'شمارهٔ مدرک', 'en' => 'Document no.'],
        'dtitle'  => ['fa' => 'عنوان', 'en' => 'Title'],
        'disc'    => ['fa' => 'رشته', 'en' => 'Discipline'],
        'rev'     => ['fa' => 'بازنگری', 'en' => 'Rev'],
        'state'   => ['fa' => 'وضعیت', 'en' => 'CDE state'],
        'suit'    => ['fa' => 'کد تناسب', 'en' => 'Suitability'],
        'owner'   => ['fa' => 'تهیه‌کننده', 'en' => 'Originator'],
        'actions' => ['fa' => 'اقدام', 'en' => 'Actions'],
        'empty'   => ['fa' => 'هنوز مدرکی ثبت نشده است.', 'en' => 'No documents registered yet.'],
        'new'     => ['fa' => 'ثبت مدرک جدید', 'en' => 'Register a document'],
        'type'    => ['fa' => 'نوع', 'en' => 'Type'],
        'ten'     => ['fa' => 'عنوان (انگلیسی)', 'en' => 'Title (English)'],
        'tfa'     => ['fa' => 'عنوان (فارسی)', 'en' => 'Title (Farsi)'],
        'save'    => ['fa' => 'ثبت', 'en' => 'Register'],
        'move'    => ['fa' => 'انتقال به', 'en' => 'Move to'],
        'apply'   => ['fa' => 'اعمال', 'en' => 'Apply'],
        'revise'  => ['fa' => 'بازنگری جدید', 'en' => 'New revision'],
        'total'   => ['fa' => 'مدرک', 'en' => 'documents'],
        'noperm'  => ['fa' => 'شما اجازهٔ ثبت مدرک در این پروژه را ندارید.', 'en' => 'You may not register documents on this project.'],
        'files'   => ['fa' => 'فایل‌ها', 'en' => 'Files'],
        'attach'  => ['fa' => 'پیوست فایل', 'en' => 'Attach file'],
        'nofiles' => ['fa' => 'بدون فایل', 'en' => 'No files'],
        'view'    => ['fa' => 'نمایش', 'en' => 'View'],
        'dl'      => ['fa' => 'دریافت', 'en' => 'Download'],
        'rm'      => ['fa' => 'حذف', 'en' => 'Remove'],
        'locked'  => ['fa' => 'بازنگری بسته است', 'en' => 'Revision closed'],
        'maxsz'   => ['fa' => 'حداکثر', 'en' => 'Max'],
        'confirm' => ['fa' => 'این فایل حذف شود؟', 'en' => 'Remove this file?'],
        'review'  => ['fa' => 'نظرات', 'en' => 'Comments'],
        'blocking'=> ['fa' => 'نظر بازدارنده', 'en' => 'blocking'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
    $num = fn ($n) => \App\Support\JalaliDate::digits((string) $n, $lang);
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
  .st { display:inline-block; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }
  .st.WIP       { background:rgba(148,163,184,.16); color:#cbd5e1; border:1px solid rgba(148,163,184,.35); }
  .st.SHARED    { background:rgba(245,158,11,.16); color:#fcd34d; border:1px solid rgba(245,158,11,.35); }
  .st.PUBLISHED { background:rgba(16,185,129,.16); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
  .st.ARCHIVED  { background:rgba(100,116,139,.16); color:#94a3b8; border:1px solid rgba(100,116,139,.35); }
  .inline-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
  .inline-form select, .inline-form input { padding:5px 8px; font-size:12px; border-radius:6px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .btn { background:var(--accent); color:#fff; border:0; border-radius:7px; padding:6px 12px;
    font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
  .btn:hover { filter:brightness(1.1); }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:#cbd5e1; }
  fieldset { border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; background:var(--card); }
  legend { padding:0 8px; font-size:13px; font-weight:700; color:#e2e8f0; }
  .frow { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:12px; }
  .frow label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
  .frow input, .frow select { width:100%; padding:8px 10px; font-size:13.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); font-family:inherit; }
  .ok { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.4); color:#6ee7b7;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:10px 14px; font-size:13px; margin-bottom:14px; }
  .err ul { margin:0; padding-inline-start:18px; }
  /* --- attachments (FR-DOC-01/02) --- */
  .files { display:flex; flex-direction:column; gap:5px; }
  .f { display:flex; align-items:center; gap:7px; font-size:12px; min-width:0; }
  .f .ext { flex:none; font-size:9.5px; font-weight:800; letter-spacing:.05em; padding:2px 5px;
    border-radius:4px; background:rgba(56,132,255,.16); color:#93c5fd;
    border:1px solid rgba(56,132,255,.35); text-transform:uppercase; }
  .f .nm { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:210px;
    color:#cbd5e1; text-decoration:none; border-bottom:1px dotted rgba(203,213,225,.4); }
  .f .nm:hover { color:#fff; border-bottom-color:#fff; }
  .f .sz { flex:none; color:var(--muted); font-variant-numeric:tabular-nums; font-size:11px; }
  /* The hash is the integrity record (FR-AUD-02); shown so it can be quoted
     in a transmittal or a claim without opening the file. */
  .f .hash { flex:none; font-family:var(--mono,monospace); font-size:10px; color:#64748b; }
  .f .x { flex:none; background:none; border:0; color:#64748b; cursor:pointer; font-size:14px;
    line-height:1; padding:0 3px; font-family:inherit; }
  .f .x:hover { color:#fca5a5; }
  .nofiles { font-size:11.5px; color:var(--muted); }
  .up { display:flex; align-items:center; gap:6px; margin-top:7px; flex-wrap:wrap; }
  .up input[type=file] { font:inherit; font-size:11px; color:var(--muted); max-width:190px; }
  .up input[type=file]::file-selector-button { font:inherit; font-size:11px; margin-inline-end:6px;
    padding:4px 9px; border-radius:6px; border:1px solid var(--line); background:#0b1220;
    color:#cbd5e1; cursor:pointer; }
  .up .hint { font-size:10.5px; color:#64748b; }
</style>
</head>
<body>
<div class="app">
<?php echo $__env->make('partials.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<div class="wrap">
<?php echo $__env->make('partials.topbar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

  <h1><?php echo e($L('title')); ?></h1>
  <div class="sub">
    <?php echo e($project->name($lang)); ?> · <span class="mono"><?php echo e($project->code); ?></span>
    · <span class="pill pack"><?php echo e($project->standards_pack); ?></span> · <?php echo e($jalaliDate); ?>

  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
    <div class="ok"><?php echo e(session('status')); ?></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
    <div class="err"><ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($e); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul></div>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="docstats">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $states; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <div class="dstat">
        <div class="k"><?php echo e(\App\Support\CdeState::label($s, $lang)); ?></div>
        <div class="v"><?php echo e($num($counts[$s] ?? 0)); ?></div>
      </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div class="dstat">
      <div class="k"><?php echo e($L('total')); ?></div>
      <div class="v"><?php echo e($num(count($documents))); ?></div>
    </div>
  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canCreate): ?>
    <fieldset>
      <legend><?php echo e($L('new')); ?></legend>
      <form method="post" action="/projects/<?php echo e($project->code); ?>/documents?lang=<?php echo e($lang); ?>">
        <?php echo csrf_field(); ?>
        <div class="frow">
          <div>
            <label for="discipline"><?php echo e($L('disc')); ?></label>
            <select id="discipline" name="discipline" required>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $disciplines; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($d); ?>"><?php echo e($d); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
          </div>
          <div>
            <label for="doc_type"><?php echo e($L('type')); ?></label>
            <select id="doc_type" name="doc_type" required>
              <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $docTypes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dt): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($dt); ?>"><?php echo e($dt); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
          </div>
          <div>
            <label for="title_en"><?php echo e($L('ten')); ?></label>
            <input id="title_en" name="title_en" value="<?php echo e(old('title_en')); ?>" required dir="ltr">
          </div>
          <div>
            <label for="title_fa"><?php echo e($L('tfa')); ?></label>
            <input id="title_fa" name="title_fa" value="<?php echo e(old('title_fa')); ?>" required dir="rtl">
          </div>
        </div>
        <button type="submit" class="btn"><?php echo e($L('save')); ?></button>
      </form>
    </fieldset>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($documents) === 0): ?>
    <div class="empty" style="background:var(--card);border:1px solid var(--line);border-radius:12px;">
      <?php echo e($L('empty')); ?>

    </div>
  <?php else: ?>
    
    <table data-tt>
      <thead>
        <tr>
          <th data-sort="text"><?php echo e($L('number')); ?></th>
          <th data-sort="text"><?php echo e($L('dtitle')); ?></th>
          <th data-sort="text"><?php echo e($L('rev')); ?></th>
          
          <th data-sort="text" data-facet><?php echo e($L('state')); ?></th>
          <th data-sort="text"><?php echo e($L('suit')); ?></th>
          <th data-sort="text" data-facet><?php echo e($L('owner')); ?></th>
          <th data-sort="num"><?php echo e($L('files')); ?></th>
          <th><?php echo e($L('actions')); ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $documents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $rev = $doc->currentRevision; ?>
        <tr>
          
          <td data-sortkey="<?php echo e($doc->discipline); ?>-<?php echo e($doc->doc_type); ?>-<?php echo e(str_pad((string) $doc->sequence, 6, '0', STR_PAD_LEFT)); ?>"><span class="mono"><?php echo e($doc->number); ?></span></td>
          <td><?php echo e($doc->title($lang)); ?></td>
          <td><span class="mono"><?php echo e($rev?->revision ?? '—'); ?></span>
            <div><a href="<?php echo e(route('documents.standard-references', ['projectCode' => $project->code, 'documentId' => $doc->id, 'lang' => $lang])); ?>"><?php echo e($lang === 'fa' ? 'مبنای استانداردها' : 'Standard basis'); ?></a></div></td>
          
          <td data-sortkey="<?php echo e($rev ? \App\Support\CdeState::ordinalOf($rev->cde_state) : 9); ?>">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rev): ?>
              <span class="st <?php echo e($rev->cde_state); ?>"><?php echo e($rev->stateLabel($lang)); ?></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td><span class="mono"><?php echo e($rev?->suitability ?? '—'); ?></span></td>
          <td><span class="pill pack"><?php echo e($doc->company->code); ?></span></td>
          <?php $revFiles = $rev?->files ?? collect(); ?>
          <td data-sortkey="<?php echo e($revFiles->count()); ?>">
            <?php
              // Published/archived revisions are a matter of record: their
              // attachments stay readable but can no longer be changed.
              $openRev = $rev && in_array($rev->cde_state, [\App\Support\CdeState::WIP, \App\Support\CdeState::SHARED], true);
            ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($revFiles->isEmpty()): ?>
              <span class="nofiles"><?php echo e($L('nofiles')); ?></span>
            <?php else: ?>
              <div class="files">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $revFiles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $f): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                  <div class="f">
                    <span class="ext"><?php echo e($f->extension); ?></span>
                    <a class="nm"
                       href="/projects/<?php echo e($project->code); ?>/documents/<?php echo e($doc->id); ?>/files/<?php echo e($f->id); ?>?lang=<?php echo e($lang); ?><?php echo e($f->isInlineViewable() ? '' : '&download=1'); ?>"
                       target="_blank" rel="noopener"
                       title="<?php echo e($f->original_name); ?> · SHA-256 <?php echo e($f->sha256); ?>"><?php echo e($f->original_name); ?></a>
                    <span class="sz"><?php echo e($f->humanSize($lang)); ?></span>
                    <span class="hash" dir="ltr"><?php echo e($f->shortHash()); ?></span>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($openRev && $canIssue): ?>
                      <form method="post" style="display:inline"
                            action="/projects/<?php echo e($project->code); ?>/documents/<?php echo e($doc->id); ?>/files/<?php echo e($f->id); ?>/delete?lang=<?php echo e($lang); ?>"
                            onsubmit="return confirm('<?php echo e($L('confirm')); ?>')">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="x" title="<?php echo e($L('rm')); ?>" aria-label="<?php echo e($L('rm')); ?>">&times;</button>
                      </form>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                  </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($openRev && $canIssue): ?>
              <form class="up" method="post" enctype="multipart/form-data"
                    action="/projects/<?php echo e($project->code); ?>/documents/<?php echo e($doc->id); ?>/files?lang=<?php echo e($lang); ?>">
                <?php echo csrf_field(); ?>
                <input type="file" name="file" required
                       accept="<?php echo e(collect($allowedExtensions)->map(fn ($e) => '.'.$e)->implode(',')); ?>"
                       aria-label="<?php echo e($L('attach')); ?>">
                <button type="submit" class="btn ghost"><?php echo e($L('attach')); ?></button>
              </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
          <td>
            <?php
              $cmts = $rev?->comments ?? collect();
              $blockingCount = $cmts->filter(fn ($c) => $c->isBlocking())->count();
            ?>
            
            <div style="margin-bottom:7px;">
              <a href="/projects/<?php echo e($project->code); ?>/documents/<?php echo e($doc->id); ?>/review?lang=<?php echo e($lang); ?>"
                 style="font-size:12px;color:<?php echo e($blockingCount > 0 ? '#fca5a5' : 'var(--muted)'); ?>;text-decoration:none;border-bottom:1px dotted currentColor;">
                <?php echo e($L('review')); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($cmts->count() > 0): ?> (<?php echo e($num($cmts->count())); ?>)<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($blockingCount > 0): ?>
                  <strong>· <?php echo e($num($blockingCount)); ?> <?php echo e($L('blocking')); ?></strong>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
              </a>
            </div>
            <?php $next = $rev?->nextStates() ?? []; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rev && count($next) > 0): ?>
              
              <form class="inline-form" method="post"
                    action="/projects/<?php echo e($project->code); ?>/documents/<?php echo e($doc->id); ?>?lang=<?php echo e($lang); ?>">
                <?php echo csrf_field(); ?>
                <select name="cde_state" aria-label="<?php echo e($L('move')); ?>">
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $next; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $n): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($n); ?>"><?php echo e(\App\Support\CdeState::label($n, $lang)); ?></option>
                  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
                <select name="suitability" aria-label="<?php echo e($L('suit')); ?>">
                  <option value="">—</option>
                  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = array_unique(array_merge(...array_map(fn ($n) => \App\Support\CdeState::suitabilityFor($n), $next))); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $code): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($code); ?>"><?php echo e($code); ?></option>
                  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
                <button type="submit" class="btn"><?php echo e($L('apply')); ?></button>
              </form>
            <?php elseif($rev && $rev->cde_state === \App\Support\CdeState::PUBLISHED): ?>
              <span style="font-size:12px;color:var(--muted);">—</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

  <div class="foot">
    <?php echo e($fa
      ? 'حالت‌ها بر پایهٔ ایزو ۱۹۶۵۰: در دست تهیه ← ارسال برای بررسی ← منتشر شده ← بایگانی. گذارهای غیرمجاز در سرور رد می‌شوند.'
      : 'States follow ISO 19650: WIP → Shared → Published → Archived. Illegal transitions are refused server-side.'); ?>

    <br>
    <?php echo e($fa
      ? 'فایل‌ها فقط به بازنگری‌های باز افزوده می‌شوند و با اثر انگشت SHA-256 ذخیره می‌گردند. '.$L('maxsz').' '.$maxUploadLabel.'.'
      : 'Files attach to open revisions only and are stored with a SHA-256 fingerprint. '.$L('maxsz').' '.$maxUploadLabel.'.'); ?>

  </div>
</div>
</div>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/documents/index.blade.php ENDPATH**/ ?>