<?php

declare(strict_types=1);

// Fallback runner without PHPUnit (see EngineShim.php). Usage: php tests/Unit/RealDataShimRunner.php
require __DIR__ . '/EngineBootstrap.php';
require __DIR__ . '/RealDataParityTest.php';

use PHPUnit\Framework\TestCase;

$t0 = microtime(true);
$ran = 0;
$fail = [];
$cls = \Jalal\ServiceTracker\Tests\Unit\RealDataParityTest::class;
foreach (get_class_methods($cls) as $m) {
    if (!str_starts_with($m, 'test')) {
        continue;
    }
    $ran++;
    try {
        (new $cls())->$m();
        echo '.';
    } catch (Throwable $e) {
        echo 'F';
        $fail[] = "$cls::$m\n  " . $e->getMessage();
    }
}
printf("\n\nTime: %.2fs\n", microtime(true) - $t0);
if ($fail) {
    echo "\nFAILURES:\n" . implode("\n", $fail) . "\n";
    exit(1);
}
printf("\nOK (%d tests, %d assertions)\n", $ran, TestCase::$assertions);
