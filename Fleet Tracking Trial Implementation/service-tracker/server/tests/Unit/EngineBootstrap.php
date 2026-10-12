<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require __DIR__ . '/EngineShim.php';
}
foreach (['StopTimer', 'DueCalculator', 'TurnaroundCalculator'] as $c) {
    require_once $root . "/src/Services/$c.php";
}
