
<?php
    $fa = ($lang ?? 'fa') === 'fa';
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang ?? 'fa'); ?>" dir="<?php echo e($fa ? 'rtl' : 'ltr'); ?>">
<head>
<meta charset="utf-8">
<title><?php echo e($title ?? 'Document'); ?></title>
<style>
  @page { size: A4; margin: 16mm 15mm 18mm 15mm; }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    background: #fff;
    color: #111;
    font-family: <?php echo e($fa ? "Vazirmatn, Tahoma, sans-serif" : "'DejaVu Sans', Arial, sans-serif"); ?>;
    font-size: 10.5pt;
    line-height: 1.85;
  }

  /* Latin identifiers inside Persian sentences. `unicode-bidi: embed` keeps
     PH-14-MCC-0001 from being reordered by the bidi algorithm. */
  .mono {
    font-family: 'DejaVu Sans Mono', monospace;
    direction: ltr;
    unicode-bidi: embed;
    font-size: 9.5pt;
  }

  header.doc {
    border-bottom: 2px solid #111;
    padding-bottom: 7mm;
    margin-bottom: 7mm;
  }
  header.doc .org { font-size: 9pt; color: #444; letter-spacing: .02em; }
  header.doc h1 { font-size: 17pt; margin: 2mm 0 1mm; font-weight: 700; }
  header.doc .strap { font-size: 9.5pt; color: #444; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
  .meta td { padding: 1.6mm 0; vertical-align: top; font-size: 10pt; }
  .meta td.k { width: 34mm; color: #555; }
  .meta td.k::after { content: ':'; }

  h2 { font-size: 11.5pt; margin: 6mm 0 2.5mm; border-bottom: 1px solid #bbb; padding-bottom: 1.5mm; }

  table.grid { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
  table.grid th, table.grid td {
    border: 1px solid #666;
    padding: 2mm 2.5mm;
    font-size: 9.5pt;
    text-align: <?php echo e($fa ? 'right' : 'left'); ?>;
    vertical-align: top;
  }
  table.grid th { background: #ececec; font-weight: 700; }
  /* Repeat headers when a long register spills onto page 2. */
  table.grid thead { display: table-header-group; }
  table.grid tr { page-break-inside: avoid; }

  .note {
    border: 1px solid #999;
    background: #f6f6f6;
    padding: 3mm 4mm;
    font-size: 9pt;
    margin-bottom: 5mm;
  }
  .warn { border-color: #a00; background: #fdf0f0; }
  .warn .t { font-weight: 700; color: #a00; }

  .sigs { width: 100%; border-collapse: collapse; margin-top: 12mm; page-break-inside: avoid; }
  .sigs td { width: 33.33%; padding: 0 3mm; vertical-align: top; }
  .sigs .box { border: 1px solid #666; height: 26mm; margin-bottom: 1.5mm; }
  .sigs .lbl { font-size: 8.5pt; color: #444; text-align: center; }

  /*
   * Chrome's print path does NOT reliably pin position:fixed to the bottom of
   * every printed page - it laid the footer over the top of page 2, straight
   * across the table header. Rather than fight it, the footer flows as normal
   * content at the end of the document.
   *
   * Per-page furniture (page numbers, a running document number) belongs to
   * Chrome's own header/footer templates, which we disable with
   * --no-pdf-header-footer because they cannot render Persian.
   */
  footer.doc {
    margin-top: 8mm;
    padding-top: 1.5mm;
    border-top: 1px solid #bbb;
    font-size: 8pt;
    color: #555;
    display: flex;
    justify-content: space-between;
    page-break-inside: avoid;
  }
</style>
</head>
<body>
<?php echo $__env->yieldContent('document'); ?>
</body>
</html>
<?php /**PATH /home/user/epcc-platform/app/resources/views/print/layout.blade.php ENDPATH**/ ?>