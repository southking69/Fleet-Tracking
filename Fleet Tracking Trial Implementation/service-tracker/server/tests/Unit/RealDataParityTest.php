<?php

declare(strict_types=1);

namespace Jalal\ServiceTracker\Tests\Unit;

use Jalal\ServiceTracker\Services\AppsScriptMapper;
use Jalal\ServiceTracker\Tests\RealData\RealDataParity;
use PHPUnit\Framework\TestCase;

/**
 * Importer mapping + engine parity on the REAL production data (tests/realdata).
 * Expected values come from the real Index.html functions (node tests/realdata/oracle.js, committed JSON).
 */
final class RealDataParityTest extends TestCase
{
    private static function load(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['StopTimer', 'DueCalculator', 'TurnaroundCalculator', 'VisitMapper', 'AppsScriptMapper'] as $c) {
            require_once "$root/src/Services/$c.php";
        }
        require_once "$root/src/Reports/VehicleIndex.php";
        require_once "$root/src/Reports/TrackerStats.php";
        require_once "$root/tests/realdata/StandIns.php";
        require_once "$root/tests/realdata/RealDataParity.php";
    }

    private function parity(string $data, string $expected): RealDataParity
    {
        self::load();
        $dir = dirname(__DIR__) . '/realdata/';
        $h = (new RealDataParity($dir . $data, $dir . $expected))->run();
        self::assertSame([], $h->diffs, $h->report());
        self::assertTrue($h->checks > 600, 'ran the whole harness');

        return $h;
    }

    public function testProductionDriveDbMatchesTheOriginalJavaScript(): void
    {
        $h = $this->parity('fleet-data.json', 'expected.realdata.json');
        self::assertSame(9, count($h->map['visits']));
        self::assertSame(16, count($h->map['stops']));
        self::assertSame(23, count($h->map['jobs']));
        self::assertSame(45500, array_sum(array_column($h->map['petty'], 'amount')));
    }

    public function testAppBackupWithArrayCollectionsAndNumericTombstones(): void
    {
        $this->parity('backup-2026-10-11.stripped.json', 'expected.backup.json');
    }

    public function testStressVariantTombstonesOverdueOpenVisitsOddAmounts(): void
    {
        $h = $this->parity('fleet-data.stress.json', 'expected.stress.json');
        self::assertSame(['E-ABD0002', 'E-ABD0003', 'E-ABD0004'], $h->map['skipped']['expenses']);
        self::assertSame(['V-ABD0004'], $h->map['skipped']['visits']);
    }

    public function testMapperUnits(): void
    {
        self::load();
        self::assertSame(2000, AppsScriptMapper::cents(19.995));
        self::assertSame(101, AppsScriptMapper::cents('1.005'));
        self::assertSame(0, AppsScriptMapper::cents('12,50'));
        self::assertSame(0, AppsScriptMapper::cents(''));
        self::assertSame(1234, AppsScriptMapper::cents(12.34));
        self::assertSame(29, AppsScriptMapper::cents(0.1 + 0.2 - 0.01));
        self::assertSame(15.0, AppsScriptMapper::tombT(['t' => 15, 'r' => 2]));
        self::assertSame(15.0, AppsScriptMapper::tombT('15'));
        self::assertNull(AppsScriptMapper::tombT('x'));
        self::assertSame('4473 RKR', AppsScriptMapper::cleanPlate('4473 RKR '));
        self::assertSame('3877-EER', AppsScriptMapper::cleanPlate('3877-EER'));
        self::assertSame('3877EER', AppsScriptMapper::plateKey('3877-EER'));
        $m = new AppsScriptMapper('Asia/Riyadh', '2026-10-12');
        self::assertSame('2026-10-12T05:00:00Z', $m->utcIso('2026-10-12T08:00'));
        self::assertNull($m->utcIso(''));
        self::assertNull($m->utcIso('garbage'));

        // minimal db: object collections, {t,r} tombstone equal to _u => skipped, older => kept, free-text service => catalog item
        $raw = ['settings' => ['lanes' => [['name' => 'Express', 'target' => 12]], 'vendors' => ['A'], 'currency' => 'SAR'], 'catalog' => [['service' => 'Known', 'lane' => 'Express', 'interval' => 5000, 'vendor' => 'A', 'category' => 'C']],
            'tomb' => ['services:j1' => ['t' => 100, 'r' => 1], 'services:j2' => ['t' => 99, 'r' => 1]],
            'visits' => ['V1' => ['id' => 'V1', 'plate' => ' ab-1 ', 'odo' => '', 'arrived' => '2026-10-12T08:00', '_u' => 5]],
            'services' => ['j1' => ['id' => 'j1', 'visitId' => 'V1', 'service' => 'Known', '_u' => 100], 'j2' => ['id' => 'j2', 'visitId' => 'V1', 'service' => 'Free text', '_u' => 100], 'j3' => ['id' => 'j3', 'visitId' => 'NOPE', 'service' => 'Known', '_u' => 1]]];
        $r = $m->map($raw);
        self::assertSame(['j2'], array_keys($r['jobs']));
        self::assertSame(['j1'], $r['skipped']['services']);
        self::assertSame('AB-1', $r['visits']['V1']['plate']);
        self::assertNull($r['visits']['V1']['odometer']);
        self::assertSame('in_progress', $r['visits']['V1']['status']);
        self::assertSame(['Known', 'Free text'], array_column($r['catalog'], 'name'));
        self::assertSame(true, $r['catalog'][1]['auto']);
        self::assertSame('', $r['catalog'][1]['lane']);
        $codes = array_column($r['notes'], 'code');
        self::assertTrue(in_array('orphan', $codes, true) && in_array('unknown_service', $codes, true) && in_array('missing_odometer', $codes, true), 'notes: ' . implode(',', $codes));
    }
}
