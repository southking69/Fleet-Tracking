<?php

declare(strict_types=1);

namespace Jalal\ServiceTracker\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Port of the tracker's low-level helpers (ms, diffH, num, norm, sum) and stopInfo().
 * Pure PHP, plain arrays in / out. All JS null semantics are preserved on purpose.
 */
final class StopTimer
{
    /** Naive ISO datetimes are interpreted in this single zone (no DST, so differences are exact). */
    public const ZONE = 'Asia/Riyadh';

    private static ?DateTimeZone $tz = null;

    private static function tz(): DateTimeZone
    {
        return self::$tz ??= new DateTimeZone(self::ZONE);
    }

    /** JS truthiness for scalars ('0' is truthy in JS, falsy in PHP). */
    public static function truthy(mixed $x): bool
    {
        if ($x === null || $x === false || $x === '' || $x === 0 || $x === 0.0) {
            return false;
        }
        if (is_float($x) && is_nan($x)) {
            return false;
        }

        return true;
    }

    /**
     * JS: const ms = s => { if (!s) return null; const t = new Date(s).getTime(); return isNaN(t) ? null : t; }
     * Returns epoch milliseconds or null. Date-only ISO strings are UTC (as in JS); date-time strings
     * without an offset are local (Asia/Riyadh); explicit Z/offset honoured.
     */
    public static function ms(mixed $s): ?int
    {
        if (!self::truthy($s)) {
            return null;
        }
        if ($s instanceof \DateTimeInterface) {
            return $s->getTimestamp() * 1000 + (int) ((int) $s->format('u') / 1000);
        }
        if (is_int($s) || is_float($s)) {
            return is_finite((float) $s) ? (int) $s : null;
        }
        if (!is_string($s)) {
            return null;
        }
        $s = trim($s);

        if (preg_match('/^(\d{4})-(\d\d)-(\d\d)$/', $s, $m)) {
            if (!self::validYmd((int) $m[2], (int) $m[3])) {
                return null;
            }

            return self::utcMs((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 0);
        }

        if (preg_match('/^(\d{4})-(\d\d)-(\d\d)[T ](\d\d):(\d\d)(?::(\d\d)(?:[.,](\d+))?)?\s*(Z|[+-]\d\d(?::?\d\d)?)?$/i', $s, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            $h = (int) $m[4];
            $mi = (int) $m[5];
            $se = (int) ($m[6] ?? 0);
            $frac = isset($m[7]) && $m[7] !== '' ? (int) str_pad(substr($m[7], 0, 3), 3, '0') : 0;
            $zone = $m[8] ?? '';
            if (!self::validYmd($mo, $d) || $h > 24 || $mi > 59 || $se > 59 || ($h === 24 && ($mi || $se || $frac))) {
                return null;
            }
            if ($zone === '') {
                $dt = (new DateTimeImmutable('now', self::tz()))->setDate($y, $mo, $d)->setTime($h, $mi, $se);

                return $dt->getTimestamp() * 1000 + $frac;
            }
            $utc = self::utcMs($y, $mo, $d, $h, $mi, $se, $frac);
            if (strtoupper($zone) === 'Z') {
                return $utc;
            }
            $sign = $zone[0] === '-' ? -1 : 1;
            $digits = str_replace(':', '', substr($zone, 1));
            $oh = (int) substr($digits, 0, 2);
            $om = (int) (substr($digits, 2, 2) ?: 0);

            return $utc - $sign * ($oh * 60 + $om) * 60000;
        }

        // Anything else: best effort, like V8's legacy parser (naive => local zone).
        try {
            $dt = new DateTimeImmutable($s, self::tz());

            return $dt->getTimestamp() * 1000 + (int) ((int) $dt->format('u') / 1000);
        } catch (Throwable) {
            return null;
        }
    }

    /** V8's ISO parser accepts day 1..31 in any month and rolls the overflow (2026-02-30 => Mar 2). */
    private static function validYmd(int $mo, int $d): bool
    {
        return $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31;
    }

    private static function utcMs(int $y, int $mo, int $d, int $h, int $mi, int $s, int $frac): int
    {
        $dt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($y, $mo, $d)->setTime($h, $mi, $s);

        return $dt->getTimestamp() * 1000 + $frac;
    }

    /** JS: diffH = (a,b) => either missing ? null : (y - x) / 36e5 */
    public static function diffH(mixed $a, mixed $b): ?float
    {
        $x = self::ms($a);
        $y = self::ms($b);

        return ($x === null || $y === null) ? null : ($y - $x) / 3600000.0;
    }

    /** JS: num = x => (x === '' || x == null || isNaN(Number(x))) ? null : Number(x) */
    public static function num(mixed $x): int|float|null
    {
        if ($x === null || $x === '') {
            return null;
        }
        if (is_bool($x)) {
            return $x ? 1 : 0;
        }
        if (is_int($x)) {
            return $x;
        }
        if (is_float($x)) {
            return is_nan($x) ? null : $x;
        }
        if (is_string($x)) {
            $t = trim($x);
            if ($t === '') {
                return 0; // Number('  ') === 0 in JS
            }
            if (preg_match('/^[+-]?Infinity$/', $t)) {
                return $t[0] === '-' ? -INF : INF;
            }
            if (preg_match('/^0[xX][0-9a-fA-F]+$/', $t)) {
                return (float) hexdec(substr($t, 2));
            }
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $t)) {
                return $t + 0;
            }

            return null;
        }

        return null; // arrays / objects: Number([...]) is NaN for our purposes
    }

    /** JS: norm = s => String(s || '').toUpperCase().replace(/[\s\-_.]/g, '') */
    public static function norm(mixed $s): string
    {
        $str = self::truthy($s) ? (is_scalar($s) ? (string) $s : '') : '';

        return (string) preg_replace('/[\s\-_.\x{00A0}\x{FEFF}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', '', mb_strtoupper($str));
    }

    /** JS: sum = a => a.reduce((p, c) => p + (c || 0), 0) */
    public static function sum(array $a): int|float
    {
        $t = 0;
        foreach ($a as $c) {
            $t += ($c === null || $c === false || (is_float($c) && is_nan($c))) ? 0 : $c;
        }

        return $t;
    }

    /**
     * JS stopInfo(st): atVendor = diffH(arrived,left), appr = diffH(apprReq,apprRec), net = atVendor - (appr||0).
     *
     * @param array<string,mixed> $stop keys arrived, apprReq, apprRec, left
     * @return array{atVendor: ?float, appr: ?float, net: ?float}
     */
    public static function info(array $stop): array
    {
        $atVendor = self::diffH($stop['arrived'] ?? null, $stop['left'] ?? null);
        $appr = self::diffH($stop['apprReq'] ?? null, $stop['apprRec'] ?? null);

        return [
            'atVendor' => $atVendor,
            'appr' => $appr,
            'net' => $atVendor === null ? null : $atVendor - ($appr ?? 0.0),
        ];
    }
}
