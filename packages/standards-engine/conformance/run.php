<?php

declare(strict_types=1);

/**
 * Conformance corpus runner (PHP reference implementation).
 *
 * ADR-003 Annex A §4/§7: this corpus is the language-neutral contract for the
 * release-gate evaluator. Any second implementation (the offline PWA, or a
 * rewrite in another language) must run these same cases and produce the same
 * verdicts. Divergence is a build failure, not a field data conflict.
 *
 * Usage: php conformance/run.php
 */

require __DIR__.'/../src/Failure.php';
require __DIR__.'/../src/Verdict.php';
require __DIR__.'/../src/Predicates.php';
require __DIR__.'/../src/ReleaseGateEvaluator.php';

use Epcc\StandardsEngine\ReleaseGateEvaluator;

$packDir = __DIR__.'/../../../standards-packs';
$corpus  = json_decode((string) file_get_contents(__DIR__.'/cases.json'), true, 512, JSON_THROW_ON_ERROR);

$packs = [];
foreach (['ips-iran', 'awp', 'iso'] as $name) {
    $path = $packDir.'/'.$name.'.pack.json';
    if (is_file($path)) {
        $packs[$name] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}

$evaluator = new ReleaseGateEvaluator();
$passed = 0;
$failed = 0;
$problems = [];

foreach ($corpus['cases'] as $case) {
    $id = $case['id'];

    // A case may reference a shipped pack by name, or carry a minimal inline
    // pack. Inline packs let the corpus test engine-level safety properties
    // (e.g. an unimplemented check) without polluting the real packs.
    if (isset($case['inlinePack'])) {
        $pack = $case['inlinePack'];
    } else {
        $pack = $packs[$case['pack']] ?? null;
        if ($pack === null) {
            $failed++;
            $problems[] = "$id: pack '{$case['pack']}' not found";
            continue;
        }
    }

    $verdict = $evaluator->evaluate($pack, $case['objectType'], $case['facts']);
    $exp     = $case['expect'];
    $errs    = [];

    if ($verdict->passed !== $exp['passed']) {
        $errs[] = sprintf('passed: expected %s got %s', var_export($exp['passed'], true), var_export($verdict->passed, true));
    }
    if (array_key_exists('blocked', $exp) && $verdict->isBlocked() !== $exp['blocked']) {
        $errs[] = sprintf('blocked: expected %s got %s', var_export($exp['blocked'], true), var_export($verdict->isBlocked(), true));
    }
    if (array_key_exists('waiverAllowed', $exp) && $verdict->waiverAllowed !== $exp['waiverAllowed']) {
        $errs[] = sprintf('waiverAllowed: expected %s got %s', var_export($exp['waiverAllowed'], true), var_export($verdict->waiverAllowed, true));
    }
    if (array_key_exists('absolutelyBlocked', $exp) && $verdict->isAbsolutelyBlocked() !== $exp['absolutelyBlocked']) {
        $errs[] = sprintf('absolutelyBlocked: expected %s got %s', var_export($exp['absolutelyBlocked'], true), var_export($verdict->isAbsolutelyBlocked(), true));
    }
    if (array_key_exists('waivable', $exp) && $verdict->isWaivable() !== $exp['waivable']) {
        $errs[] = sprintf('waivable: expected %s got %s', var_export($exp['waivable'], true), var_export($verdict->isWaivable(), true));
    }
    if (array_key_exists('waiverCapability', $exp) && $verdict->waiverCapability !== $exp['waiverCapability']) {
        $errs[] = sprintf('waiverCapability: expected %s got %s', var_export($exp['waiverCapability'], true), var_export($verdict->waiverCapability, true));
    }
    if (array_key_exists('justificationRequired', $exp) && $verdict->justificationRequired !== $exp['justificationRequired']) {
        $errs[] = 'justificationRequired mismatch';
    }
    if (array_key_exists('failedChecks', $exp)) {
        $got = $verdict->failedChecks();
        $want = $exp['failedChecks'];
        sort($got);
        sort($want);
        if ($got !== $want) {
            $errs[] = sprintf('failedChecks: expected [%s] got [%s]', implode(', ', $want), implode(', ', $got));
        }
    }
    if (array_key_exists('ungated', $exp) && $verdict->isUngated() !== $exp['ungated']) {
        $errs[] = sprintf('ungated: expected %s got %s', var_export($exp['ungated'], true), var_export($verdict->isUngated(), true));
    }

    if (($exp['requireBilingualMessages'] ?? false) === true) {
        foreach ($verdict->failures as $f) {
            if (trim($f->messageEn) === '' || trim($f->messageFa) === '') {
                $errs[] = "bilingual: check '{$f->check}' missing en or fa text";
            }
        }
    }

    if ($errs === []) {
        $passed++;
        fwrite(STDOUT, "  \033[32mok\033[0m   $id\n");
    } else {
        $failed++;
        fwrite(STDOUT, "  \033[31mFAIL\033[0m $id\n");
        foreach ($errs as $e) {
            fwrite(STDOUT, "         $e\n");
            $problems[] = "$id: $e";
        }
    }
}

fwrite(STDOUT, "\n");
fwrite(STDOUT, sprintf(
    "Conformance corpus v%s: \033[%sm%d passed, %d failed\033[0m (%d cases)\n",
    $corpus['version'],
    $failed === 0 ? '32' : '31',
    $passed,
    $failed,
    count($corpus['cases'])
));

exit($failed === 0 ? 0 : 1);
