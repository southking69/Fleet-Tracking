<?php

declare(strict_types=1);

/**
 * Real-data parity runner.
 *   node tests/realdata/oracle.js && php tests/realdata/run.php [--all] [--notes] [fleet-data.json] [expected.realdata.json]
 * Exit code 0 only when the PHP side agrees with the real Index.html functions on every check.
 */
$root = dirname(__DIR__, 2);
foreach (['StopTimer', 'DueCalculator', 'TurnaroundCalculator', 'VisitMapper', 'AppsScriptMapper'] as $c) {
    require_once "$root/src/Services/$c.php";
}
require_once "$root/src/Reports/VehicleIndex.php";
require_once "$root/src/Reports/TrackerStats.php";
require_once __DIR__ . '/StandIns.php';
require_once __DIR__ . '/RealDataParity.php';

if (in_array('--all', $argv, true)) { // production file, app backup (array shape), stress variant
    $code = 0;
    foreach ([['fleet-data.json', 'expected.realdata.json'], ['backup-2026-10-11.stripped.json', 'expected.backup.json'], ['fleet-data.stress.json', 'expected.stress.json']] as [$d, $e]) {
        echo "== $d\n";
        $h = (new Jalal\ServiceTracker\Tests\RealData\RealDataParity(__DIR__ . "/$d", __DIR__ . "/$e"))->run();
        echo $h->report();
        $code |= $h->diffs ? 1 : 0;
    }
    exit($code);
}
$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
$data = $args[0] ?? __DIR__ . '/fleet-data.json';
$expected = $args[1] ?? __DIR__ . '/expected.realdata.json';
$h = (new Jalal\ServiceTracker\Tests\RealData\RealDataParity($data, $expected))->run();
echo $h->report();

if (in_array('--notes', $argv, true)) {
    echo "\nData-quality notes from the importer mapping (" . count($h->map['notes']) . "):\n";
    foreach ($h->map['notes'] as $n) {
        printf("  [%s] %s: %s\n", $n['code'], $n['where'], $n['message']);
    }
    echo "\nData-quality findings from the engine (what the dashboard will show after cutover):\n";
    foreach ($h->expected['visits'] as $v) {
        $e = $v['expected'];
        $neg = [];
        foreach ($e['stages'] as $i => $x) {
            if ($x !== null && $x < 0) {
                $neg[] = Jalal\ServiceTracker\Services\VisitMapper::STAGE_SHORT[$i] . '=' . round($x, 2) . 'h';
            }
        }
        if (($e['check'] ?? '') !== 'OK' && ($e['check'] ?? '') !== '' || $neg) {
            printf("  %s: check='%s'%s\n", $v['id'], $e['check'], $neg ? ' NEGATIVE STAGE ' . implode(', ', $neg) . ' (timestamps out of order)' : '');
        }
    }
}
exit($h->diffs ? 1 : 0);
