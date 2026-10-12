<?php

declare(strict_types=1);

/**
 * Minimal stand-in for PHPUnit\Framework\TestCase, used ONLY when the real PHPUnit is not installed
 * (e.g. packagist is unreachable). The engine tests use nothing but the assertions below, so they run
 * unchanged under real PHPUnit (`composer install` in tests/, then `vendor/bin/phpunit -c phpunit.xml`)
 * and under this shim (`php tests/Unit/EngineShimRunner.php`).
 */

namespace PHPUnit\Framework {
    class AssertionFailedError extends \Exception
    {
    }

    abstract class TestCase
    {
        public static int $assertions = 0;

        private static function fail(string $m): never
        {
            throw new AssertionFailedError($m);
        }

        public static function assertSame(mixed $e, mixed $a, string $m = ''): void
        {
            self::$assertions++;
            if ($e !== $a) {
                self::fail($m . ' expected ' . var_export($e, true) . ' got ' . var_export($a, true));
            }
        }

        public static function assertNull(mixed $a, string $m = ''): void
        {
            self::$assertions++;
            if ($a !== null) {
                self::fail($m . ' expected null got ' . var_export($a, true));
            }
        }

        public static function assertTrue(mixed $a, string $m = ''): void
        {
            self::$assertions++;
            if ($a !== true) {
                self::fail($m . ' expected true');
            }
        }

        public static function assertCount(int $n, array $a, string $m = ''): void
        {
            self::$assertions++;
            if (count($a) !== $n) {
                self::fail($m . " expected count $n got " . count($a));
            }
        }

        public static function assertEqualsWithDelta(float|int $e, float|int $a, float $d, string $m = ''): void
        {
            self::$assertions++;
            if (abs($e - $a) > $d) {
                self::fail($m . " expected $e +/- $d got $a");
            }
        }
    }
}
