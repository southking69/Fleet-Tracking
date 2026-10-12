<?php

declare(strict_types=1);

namespace Jalal\ServiceTracker\Services;

/**
 * Port of calcVisit(). Inputs are plain arrays with the tracker's JS keys:
 *   visit: id, plate, odo, arrived, picked, returned
 *   stop:  vendor, arrived, apprReq, apprRec, left
 *   job:   id, visitId, service, nextOverride
 *   ctx:   catalog, lanes, vendors (list of names), allVisits, allJobs, now (epoch ms, ISO string or DateTime)
 */
final class TurnaroundCalculator
{
    public const STAGE_NAMES = ['Wait at LSA for pickup', 'Transit to first vendor', 'Approval wait (Splendor)', 'Vendor work (excl. approval)', 'Between vendors', 'Return transit to LSA'];
    public const STAGE_SHORT = ['Pickup wait', 'Transit to 1st vendor', 'Approval wait', 'Vendor work', 'Between vendors', 'Return transit'];
    public const STAGE_OWNER = ['Our team', 'Our team', 'Splendor', 'Vendor', 'Our team', 'Our team'];

    /**
     * @param array<string,mixed> $visit
     * @param list<array<string,mixed>> $stops stops of this visit
     * @param list<array<string,mixed>> $jobs jobs of this visit
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public static function calc(array $visit, array $stops, array $jobs, array $ctx): array
    {
        $stops = array_values($stops);
        $svcs = array_values($jobs);

        // Make sure lookups can see the visit and its jobs even if the caller passed only partial lists.
        $visits = $ctx['allVisits'] ?? [];
        $known = false;
        foreach ($visits as $x) {
            if (($x['id'] ?? null) === ($visit['id'] ?? null)) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $visits[] = $visit;
        }
        $lctx = $ctx;
        $lctx['allVisits'] = $visits;
        $lctx['allJobs'] = $ctx['allJobs'] ?? $svcs;

        $si = array_map([StopTimer::class, 'info'], $stops);
        $sinfo = array_map(fn (array $sv) => DueCalculator::svcInfo($sv, $lctx), $svcs);

        $targets = [];
        foreach ($sinfo as $x) {
            if ($x['target'] !== null && $x['target'] > 0) {
                $targets[] = $x['target'];
            }
        }
        $target = $targets ? max($targets) : null;

        $lane = '';
        if ($target !== null) {
            foreach ($ctx['lanes'] ?? [] as $l) {
                $lt = StopTimer::num($l['target'] ?? null);
                if ($lt !== null && $lt == $target) {
                    $n = $l['name'] ?? '';
                    $lane = StopTimer::truthy($n) ? (string) $n : '';
                    break;
                }
            }
        }

        $returned = StopTimer::truthy($visit['returned'] ?? null);
        $arrivedT = StopTimer::truthy($visit['arrived'] ?? null);
        $status = $returned ? 'Closed' : ($arrivedT ? 'In progress' : 'Not started');

        $perVendor = [];
        foreach ($ctx['vendors'] ?? [] as $name) {
            if (!StopTimer::truthy($name)) {
                $perVendor[] = null;
                continue;
            }
            $a = 0;
            foreach ($stops as $i => $s) {
                $a += (($s['vendor'] ?? null) === $name && $si[$i]['atVendor'] !== null) ? $si[$i]['atVendor'] : 0;
            }
            $perVendor[] = $a;
        }

        $doneN = count(array_filter($si, fn ($x) => $x['atVendor'] !== null));
        $THV = $stops ? StopTimer::sum(array_column($si, 'atVendor')) : null;
        $wPick = StopTimer::diffH($visit['arrived'] ?? null, $visit['picked'] ?? null);

        $arrs = [];
        $lefts = [];
        foreach ($stops as $s) {
            $a = StopTimer::ms($s['arrived'] ?? null);
            if ($a !== null) {
                $arrs[] = $a;
            }
            $l = StopTimer::ms($s['left'] ?? null);
            if ($l !== null) {
                $lefts[] = $l;
            }
        }
        $pickedMs = StopTimer::ms($visit['picked'] ?? null);
        $returnedMs = StopTimer::ms($visit['returned'] ?? null);
        $wTrans = ($pickedMs !== null && $arrs) ? (min($arrs) - $pickedMs) / 3600000.0 : null;
        $wAppr = $THV === null ? null : StopTimer::sum(array_column($si, 'appr'));
        $wWork = $THV === null ? null : $THV - $wAppr;
        $wRet = ($returnedMs !== null && $lefts) ? ($returnedMs - max($lefts)) / 3600000.0 : null;
        $total = StopTimer::diffH($visit['arrived'] ?? null, $visit['returned'] ?? null);

        $wBetw = null;
        if ($total !== null && $wPick !== null && $wTrans !== null && $THV !== null && $wRet !== null) {
            // Math.max(0, Math.round(x * 100) / 100): JS round is floor(x + 0.5)
            $wBetw = max(0.0, floor(($total - $wPick - $wTrans - $THV - $wRet) * 100 + 0.5) / 100);
        }

        $stages = [$wPick, $wTrans, $wAppr, $wWork, $wBetw, $wRet];
        $have = array_values(array_filter($stages, fn ($x) => $x !== null));
        $big = -1;
        if ($status === 'Closed' && $have) {
            $mx = max($have);
            foreach ($stages as $i => $x) {
                if ($x !== null && $x == $mx) {
                    $big = $i;
                    break;
                }
            }
        }

        $odo = StopTimer::num($visit['odo'] ?? null);
        $check = '';
        if ($status === 'Closed') {
            $check = !$svcs ? 'No services logged'
                : (!$stops ? 'No vendor stops logged'
                : (count($stops) > $doneN ? 'Vendor stop times missing'
                : (count($have) < 6 ? 'Timestamp missing'
                : ($odo === null ? 'Odometer missing' : 'OK'))));
        }

        $overdue = array_values(array_filter($sinfo, fn ($x) => $x['status'] === 'OVERDUE'));

        $open = null;
        if ($arrivedT && !$returned) {
            $now = $ctx['now'] ?? null;
            $nowMs = $now === null ? (int) round(microtime(true) * 1000) : (StopTimer::ms($now) ?? 0);
            // JS quirk kept: ms(arrived) === null (unparseable) gives (Date.now() - null) = Date.now().
            $open = ($nowMs - (StopTimer::ms($visit['arrived'] ?? null) ?? 0)) / 3600000.0;
        }

        return [
            'si' => $si,
            'sinfo' => $sinfo,
            'target' => $target,
            'lane' => $lane,
            'status' => $status,
            'perVendor' => $perVendor,
            'THV' => $THV,
            'stages' => $stages,
            'total' => $total,
            'big' => $big,
            'bigName' => $big >= 0 ? self::STAGE_SHORT[$big] : '',
            'owner' => $big >= 0 ? self::STAGE_OWNER[$big] : '',
            'met' => ($total !== null && $target !== null) ? $total <= $target : null,
            'open' => $open,
            'check' => $check,
            'overdueN' => count($overdue),
            'worstOver' => $overdue ? max(array_column($overdue, 'kmOver')) : null,
        ];
    }
}
