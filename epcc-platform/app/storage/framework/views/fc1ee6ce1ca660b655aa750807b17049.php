<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'level' => 2,
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'level' => 2,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $level = max(1, (int) $level);
    $headingTag = ($level > 6) ? 'p' : "h{$level}";
?>

<<?php echo e($headingTag); ?>

    <?php echo e($attributes->class(['fi-section-header-heading'])); ?>

>
    <?php echo e($slot); ?>

</<?php echo e($headingTag); ?>>
<?php /**PATH /home/user/.cache/epcc-vendor/filament/support/resources/views/components/section/heading.blade.php ENDPATH**/ ?>