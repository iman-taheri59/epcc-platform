#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../prototype/domain/MtoBackfillPlanner.php';

if ($argc !== 2 || $argv[1] === '--help') {
    fwrite($argc === 2 ? STDOUT : STDERR,
        "Usage: php scripts/plan-mto-backfill.php inventory-and-review.json\nOffline only. JSON to stdout; no DB access or writes.\nExit0=candidate for controlled review, NOT apply approval; exit1=blocked; exit2=invalid input.\n");
    exit($argc === 2 ? 0 : 2);
}
try {
    if (!is_file($argv[1]) || !is_readable($argv[1])) {
        throw new RuntimeException('Input must be a readable local JSON file.');
    }
    $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['project'] ?? null) || $data['project'] === ''
        || !is_array($data['batches'] ?? null) || !is_array($data['review'] ?? [])) {
        throw new RuntimeException('Expected project:string, batches:array, optional review:object.');
    }
    $r = (new Epcc\Prototype\MtoBackfillPlanner())->assess($data['project'], $data['batches'], $data['review'] ?? []);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit($r['status'] === 'BLOCKED' ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Invalid offline input: '.$e->getMessage()."\n");
    exit(2);
}
