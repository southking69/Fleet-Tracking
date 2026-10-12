<?php

declare(strict_types=1);

namespace Jalal\ServiceTracker\Services;

/**
 * Port of nextDueOf() and svcInfo().
 *
 * Context keys (all optional, plain arrays):
 *   catalog   list of {service, category, lane, vendor, interval}
 *   lanes     list of {name, target}
 *   allVisits list of visits {id, plate, odo, ...}  (used for visitById and the previous-due lookup)
 *   allJobs   list of jobs {id, visitId, service, nextOverride}
 * A job is "the same job" as another when their `id` matches (JS used object identity).
 */
final class DueCalculator
{
    /** @param array<string,mixed> $ctx */
    private static function visitById(mixed $id, array $ctx): ?array
    {
        foreach ($ctx['allVisits'] ?? [] as $v) {
            if (($v['id'] ?? null) === $id) {
                return $v;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $ctx */
    private static function catEntry(mixed $name, array $ctx): ?array
    {
        foreach ($ctx['catalog'] ?? [] as $c) {
            if (($c['service'] ?? null) === $name) {
                return $c;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $ctx */
    private static function laneTarget(mixed $lane, array $ctx): int|float|null
    {
        foreach ($ctx['lanes'] ?? [] as $l) {
            if (($l['name'] ?? null) === $lane) {
                return StopTimer::num($l['target'] ?? null);
            }
        }

        return null;
    }

    private static function visitOdo(?array $v): int|float|null
    {
        return $v ? StopTimer::num($v['odo'] ?? null) : null;
    }

    /**
     * @param array<string,mixed> $sv job
     * @param array<string,mixed> $ctx
     */
    public static function nextDueOf(array $sv, array $ctx): int|float|null
    {
        $o = StopTimer::num($sv['nextOverride'] ?? null);
        if ($o !== null) {
            return $o;
        }
        $v = self::visitById($sv['visitId'] ?? null, $ctx);
        $odo = self::visitOdo($v);
        $ce = self::catEntry($sv['service'] ?? null, $ctx);
        $iv = $ce ? StopTimer::num($ce['interval'] ?? null) : null;

        return ($odo !== null && $iv !== null && $iv > 0) ? $odo + $iv : null;
    }

    private static function sameJob(array $a, array $b): bool
    {
        if (array_key_exists('id', $a) && array_key_exists('id', $b)) {
            return $a['id'] === $b['id'];
        }

        return $a === $b;
    }

    /**
     * @param array<string,mixed> $sv job
     * @param array<string,mixed> $ctx
     * @return array{odo:int|float|null,lane:string,target:int|float|null,category:string,suggested:string,nextDue:int|float|null,prev:int|float|null,kmOver:int|float|null,status:string}
     */
    public static function svcInfo(array $sv, array $ctx): array
    {
        $v = self::visitById($sv['visitId'] ?? null, $ctx);
        $odo = self::visitOdo($v);
        $ce = self::catEntry($sv['service'] ?? null, $ctx);
        $lane = $ce ? ($ce['lane'] ?? '') : '';
        $target = self::laneTarget($lane, $ctx);
        $nextDue = self::nextDueOf($sv, $ctx);
        $prev = null;
        if ($odo !== null && $v && StopTimer::norm($v['plate'] ?? null) !== '') {
            $plate = StopTimer::norm($v['plate'] ?? null);
            foreach ($ctx['allJobs'] ?? [] as $s2) {
                if (self::sameJob($s2, $sv) || ($s2['service'] ?? null) !== ($sv['service'] ?? null)) {
                    continue;
                }
                $v2 = self::visitById($s2['visitId'] ?? null, $ctx);
                if (!$v2 || StopTimer::norm($v2['plate'] ?? null) !== $plate) {
                    continue;
                }
                $o2 = self::visitOdo($v2);
                if ($o2 === null || !($o2 < $odo)) {
                    continue;
                }
                $nd = self::nextDueOf($s2, $ctx);
                if ($nd !== null && $nd > 0 && ($prev === null || $nd > $prev)) {
                    $prev = $nd;
                }
            }
        }
        $kmOver = ($prev !== null && $odo !== null) ? $odo - $prev : null;
        $status = $odo === null ? 'Odometer missing' : ($prev === null ? 'No previous due' : ($kmOver > 0 ? 'OVERDUE' : 'On time'));

        return [
            'odo' => $odo,
            'lane' => $lane,
            'target' => $target,
            'category' => $ce ? ($ce['category'] ?? '') : '',
            'suggested' => $ce ? ($ce['vendor'] ?? '') : '',
            'nextDue' => $nextDue,
            'prev' => $prev,
            'kmOver' => $kmOver,
            'status' => $status,
        ];
    }
}
