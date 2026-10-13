<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'length' => 6,
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
    'length' => 6,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    use Filament\Support\View\ComponentAttributeBag;

    $inputAttributes = $input?->attributes ?? new ComponentAttributeBag;
    $isDisabled = filter_var($inputAttributes->get('disabled'), FILTER_VALIDATE_BOOLEAN);
    $isReadOnly = filter_var($inputAttributes->get('readonly'), FILTER_VALIDATE_BOOLEAN);
?>

<div
    x-data="filamentOneTimeCodeInput"
    x-modelable="state"
    role="group"
    <?php echo e($attributes->class(['fi-one-time-code-input-ctn'])); ?>

>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = range(0, $length - 1); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($index === 0): ?>
            <input
                autocomplete="one-time-code"
                inputmode="numeric"
                type="text"
                <?php echo e($inputAttributes->class(['fi-one-time-code-input-digit'])); ?>

            />
        <?php else: ?>
            <input
                aria-label="<?php echo e(__('filament::components/input/one-time-code.aria_label', ['position' => $index + 1, 'count' => $length])); ?>"
                autocomplete="off"
                inputmode="numeric"
                type="text"
                <?php if($isDisabled): echo 'disabled'; endif; ?>
                <?php if($isReadOnly): echo 'readonly'; endif; ?>
                class="fi-one-time-code-input-digit"
            />
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/user/.cache/epcc-vendor/filament/support/resources/views/components/input/one-time-code.blade.php ENDPATH**/ ?>