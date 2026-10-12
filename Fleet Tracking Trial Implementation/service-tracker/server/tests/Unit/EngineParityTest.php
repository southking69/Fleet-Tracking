<?php

declare(strict_types=1);

namespace Jalal\ServiceTracker\Tests\Unit;

use Jalal\ServiceTracker\Services\DueCalculator;
use Jalal\ServiceTracker\Services\StopTimer;
use Jalal\ServiceTracker\Services\TurnaroundCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Compares the PHP engine against tests/fixtures/expected.json, which tests/oracle/gen.js produced by
 * running the REAL functions extracted from src/Index.html. Numbers must agree to 1e-9 (the spec asks for
 * 0.1 h; we are stricter), strings / booleans / nulls / key sets exactly.
 */
final class EngineParityTest extends TestCase
{
    private const DELTA = 1e-9;

    /** @var array<string,mixed> */
    private static array $data = [];

    private static function data(): array
    {
        if (!self::$data) {
            $raw = file_get_contents(dirname(__DIR__) . '/fixtures/expected.json');
            self::$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$data;
    }

    private function same(mixed $exp, mixed $act, string $path): void
    {
        if ($exp === null) {
            self::assertNull($act, $path);
        } elseif (is_bool($exp) || is_string($exp)) {
            self::assertSame($exp, $act, $path);
        } elseif (is_int($exp) || is_float($exp)) {
            self::assertTrue(is_int($act) || is_float($act), "$path not numeric: " . var_export($act, true));
            self::assertEqualsWithDelta($exp, $act, self::DELTA, $path);
        } elseif (is_array($exp)) {
            self::assertTrue(is_array($act), "$path not array");
            if (array_is_list($exp)) {
                self::assertSame(array_is_list($act), true, "$path list-ness");
                self::assertCount(count($exp), $act, "$path count");
                foreach ($exp as $i => $e) {
                    $this->same($e, $act[$i], "$path[$i]");
                }
            } else {
                $ek = array_keys($exp);
                $ak = array_keys($act);
                sort($ek);
                sort($ak);
                self::assertSame($ek, $ak, "$path key set");
                foreach ($exp as $k => $e) {
                    $this->same($e, $act[$k], "$path.$k");
                }
            }
        } else {
            self::assertSame($exp, $act, $path);
        }
    }

    public function testHelpers(): void
    {
        $h = self::data()['helpers'];
        foreach ($h['num'] as $c) {
            $this->same($c['expected'], StopTimer::num($c['input']), 'num(' . json_encode($c['input']) . ')');
        }
        foreach ($h['norm'] as $c) {
            self::assertSame($c['expected'], StopTimer::norm($c['input']), 'norm(' . json_encode($c['input']) . ')');
        }
        foreach ($h['ms'] as $c) {
            $this->same($c['expected'], StopTimer::ms($c['input']), 'ms(' . json_encode($c['input']) . ')');
        }
        foreach ($h['diffH'] as $c) {
            $this->same($c['expected'], StopTimer::diffH($c['input'][0], $c['input'][1]), 'diffH(' . json_encode($c['input']) . ')');
        }
    }

    public function testStopInfo(): void
    {
        $i = StopTimer::info(['arrived' => '2026-10-05T11:30', 'apprReq' => '2026-10-05T11:35', 'apprRec' => '2026-10-05T13:35', 'left' => '2026-10-05T15:00']);
        $this->same(3.5, $i['atVendor'], 'atVendor');
        $this->same(2.0, $i['appr'], 'appr');
        $this->same(1.5, $i['net'], 'net');
        $j = StopTimer::info(['arrived' => '2026-10-05T11:30', 'apprReq' => '', 'apprRec' => '', 'left' => '']);
        self::assertNull($j['atVendor']);
        self::assertNull($j['net']);
    }

    public function testSampleVisitOneHandComputed(): void
    {
        // Independent of the oracle: ABC 1234, first visit, worked out by hand from the sample data.
        $f = self::data()['fixtures'][0];
        self::assertSame('sample', $f['name']);
        $c = $f['cases'][0];
        $r = TurnaroundCalculator::calc($c['visit'], $c['stops'], $c['jobs'], $f['ctx']);
        $this->same([3.0, 0.5, 2.0, 2.5, 0.33, 40.0 / 60.0], $r['stages'], 'stages');
        $this->same(9.0, $r['total'], 'total');
        $this->same(4.5, $r['THV'], 'THV');
        self::assertSame(0, $r['big']);
        self::assertSame('Pickup wait', $r['bigName']);
        self::assertSame('Our team', $r['owner']);
        self::assertSame('Express', $r['lane']);
        $this->same(12, $r['target'], 'target');
        self::assertSame(true, $r['met']);
        self::assertSame('OK', $r['check']);
        self::assertSame('Closed', $r['status']);
        self::assertNull($r['open']);
    }

    public function testCalcVisitParityEveryFixture(): void
    {
        $n = 0;
        foreach (self::data()['fixtures'] as $f) {
            foreach ($f['cases'] as $c) {
                $got = TurnaroundCalculator::calc($c['visit'], $c['stops'], $c['jobs'], $f['ctx']);
                $this->same($c['expected'], $got, $f['name'] . '/' . $c['visit']['id']);
                $n++;
            }
        }
        self::assertTrue($n >= 30, 'ran enough visits');
    }

    public function testSvcInfoAndNextDueParityEveryFixture(): void
    {
        foreach (self::data()['fixtures'] as $f) {
            $ctx = $f['ctx'];
            foreach ($f['svc'] as $s) {
                $name = $f['name'] . '/' . $s['job']['id'];
                $this->same($s['expected'], DueCalculator::svcInfo($s['job'], $ctx), $name);
                $this->same($s['nextDue'], DueCalculator::nextDueOf($s['job'], $ctx), $name . ' nextDue');
            }
        }
    }

    public function testSampleScenarioSpotChecks(): void
    {
        $f = self::data()['fixtures'][0];
        $by = [];
        foreach ($f['cases'] as $c) {
            $by[$c['visit']['id']] = TurnaroundCalculator::calc($c['visit'], $c['stops'], $c['jobs'], $f['ctx']);
        }
        $ids = array_keys($by);
        // ABC 1234 second visit (54,800 km) vs first visit's oil change next due 48,200 + 5,000 = 53,200
        $d = $by[$ids[3]];
        self::assertSame('OVERDUE', $d['sinfo'][0]['status']);
        $this->same(1600, $d['sinfo'][0]['kmOver'], 'kmOver');
        self::assertSame(1, $d['overdueN']);
        $this->same(1600, $d['worstOver'], 'worstOver');
        self::assertSame('Closed', $d['status']);
    }

    public function testDefaultNowIsAccepted(): void
    {
        $r = TurnaroundCalculator::calc(['id' => 'v', 'arrived' => '2026-10-05T08:00', 'odo' => 1], [], [], []);
        self::assertSame('In progress', $r['status']);
        self::assertTrue(is_float($r['open']) && $r['open'] > 0, 'open computed from the wall clock');
        $r2 = TurnaroundCalculator::calc(['id' => 'v', 'arrived' => '2026-10-05T08:00'], [], [], ['now' => '2026-10-05T10:30']);
        $this->same(2.5, $r2['open'], 'open with injected ISO now');
    }
}
