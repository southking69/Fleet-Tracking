<?php

namespace Jalal\ServiceTracker\Reports;

use Jalal\ServiceTracker\Services\StopTimer;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Every KPI of the tracker dashboard (Index.html dashboardView) computed from engine output.
 * Shared by DashboardController and the period report Summary sheet.
 */
class TrackerStats
{
    public static function avg(array $values): ?float
    {
        $b = array_values(array_filter($values, fn ($x) => $x !== null && !is_nan((float) $x)));
        return $b ? array_sum($b) / count($b) : null;
    }

    public static function pct(int $a, int $b): ?int
    {
        return $b ? (int) round($a / $b * 100) : null;
    }

    /**
     * @param iterable $visits   ServiceVisit models (stops/jobs/damages loaded)
     * @param array    $calcs    uuid => engine output (VisitMapper::calc)
     * @param iterable $petty    PettyCashEntry rows in the period
     * @param array|null $lanes  engine ctx['lanes']
     */
    public static function compute(iterable $visits, array $calcs, array $settings, iterable $petty = [], ?array $lanes = null): array
    {
        $rows = [];
        foreach ($visits as $v) {
            $rows[] = ['v' => $v, 'c' => $calcs[$v->uuid]];
        }
        $cs = array_column($rows, 'c');
        $closed = array_values(array_filter($rows, fn ($r) => $r['c']['status'] === 'Closed'));
        $judged = array_values(array_filter($rows, fn ($r) => $r['c']['met'] !== null));
        $stops = [];
        $jobs = [];
        $damages = [];
        foreach ($rows as $r) {
            foreach ($r['v']->stops as $s) {
                $stops[] = $s;
            }
            foreach ($r['v']->jobs as $j) {
                $jobs[] = $j;
            }
            if ($r['v']->relationLoaded('damages')) {
                foreach ($r['v']->damages as $d) {
                    $damages[] = $d;
                }
            }
        }
        $pettyTotal = 0;
        $pettyCount = 0;
        $pettyByCat = [];
        foreach ($petty as $e) {
            $pettyTotal += (int) $e->amount;
            $pettyCount++;
            $k = $e->category ?: '(no category)';
            $pettyByCat[$k] = ($pettyByCat[$k] ?? 0) + (int) $e->amount;
        }
        arsort($pettyByCat);

        // stage averages
        $stageAvg = [];
        foreach (VisitMapper::STAGE_NAMES as $i => $_) {
            $stageAvg[$i] = self::avg(array_map(fn ($c) => $c['stages'][$i] ?? null, $cs));
        }
        $stageTotal = array_sum(array_filter($stageAvg, fn ($x) => $x !== null));
        $stages = [];
        foreach (VisitMapper::STAGE_NAMES as $i => $name) {
            $stages[] = [
                'name' => $name,
                'short' => VisitMapper::STAGE_SHORT[$i],
                'owner' => VisitMapper::STAGE_OWNER[$i],
                'avg_hours' => VisitMapper::r2($stageAvg[$i]),
                'share_pct' => ($stageTotal && $stageAvg[$i]) ? round($stageAvg[$i] / $stageTotal * 100, 1) : 0,
            ];
        }

        $byOwner = [];
        foreach (['Our team', 'Splendor', 'Vendor'] as $o) {
            $byOwner[] = ['owner' => $o, 'closed_visits' => count(array_filter($closed, fn ($r) => $r['c']['owner'] === $o))];
        }

        $byLane = [];
        foreach (self::lanes($settings, $lanes) as $l) {
            $m = array_values(array_filter($rows, fn ($r) => $r['c']['lane'] === $l['name']));
            $cl = array_filter($m, fn ($r) => $r['c']['status'] === 'Closed');
            $j = array_values(array_filter($m, fn ($r) => $r['c']['met'] !== null));
            $byLane[] = [
                'lane' => $l['name'],
                'target_hours' => $l['target'],
                'closed_visits' => count($cl),
                'avg_turnaround_hours' => VisitMapper::r2(self::avg(array_map(fn ($r) => $r['c']['total'], $m))),
                'pct_within_target' => self::pct(count(array_filter($j, fn ($r) => $r['c']['met'])), count($j)),
            ];
        }

        $byVendor = [];
        foreach (self::vendors($settings, $stops) as $name) {
            $ss = array_values(array_filter($stops, fn ($s) => $s->vendor_name === $name));
            $inf = array_map(fn ($s) => StopTimer::info(VisitMapper::stopArray($s)), $ss);
            $byVendor[] = [
                'vendor' => $name,
                'stops' => count($ss),
                'jobs_done' => count(array_filter($jobs, fn ($j) => $j->vendor_name === $name)),
                'avg_hours_per_stop' => VisitMapper::r2(self::avg(array_column($inf, 'atVendor'))),
                'avg_approval_wait_hours' => VisitMapper::r2(self::avg(array_column($inf, 'appr'))),
                'avg_net_work_hours' => VisitMapper::r2(self::avg(array_column($inf, 'net'))),
            ];
        }

        // jobs by category + due compliance (engine sinfo, aligned with the visit's jobs)
        $sinfo = [];
        foreach ($cs as $c) {
            foreach ($c['sinfo'] ?? [] as $x) {
                $sinfo[] = $x;
            }
        }
        $cats = [];
        foreach ((array) ($settings['categories'] ?? []) as $cn) {
            $cats[] = ['category' => $cn, 'jobs' => count(array_filter($sinfo, fn ($x) => ($x['category'] ?? '') === $cn))];
        }
        $od = array_values(array_filter($sinfo, fn ($x) => ($x['status'] ?? '') === 'OVERDUE'));
        $cmp = count($od) + count(array_filter($sinfo, fn ($x) => ($x['status'] ?? '') === 'On time'));
        $kmOver = array_map(fn ($x) => (float) $x['kmOver'], $od);

        $noPickPhotos = 0;
        $noRetPhotos = 0;
        foreach ($closed as $r) {
            if ($r['v']->relationLoaded('files')) {
                $noPickPhotos += $r['v']->files->where('type', VisitMapper::PHOTO_PICKUP)->count() ? 0 : 1;
                $noRetPhotos += $r['v']->files->where('type', VisitMapper::PHOTO_RETURN)->count() ? 0 : 1;
            }
        }

        $openOver24 = count(array_filter($cs, fn ($c) => $c['open'] !== null && $c['open'] > 24));

        return [
            'kpis' => [
                'visits' => count($rows),
                'closed' => count($closed),
                'in_progress' => count(array_filter($cs, fn ($c) => $c['status'] === 'In progress')),
                'jobs' => count($jobs),
                'stops' => count($stops),
                'petty_cash_total' => round($pettyTotal / 100, 2),
                'petty_cash_total_minor' => $pettyTotal,
                'petty_cash_entries' => $pettyCount,
                'avg_turnaround_hours' => VisitMapper::r2(self::avg(array_column($cs, 'total'))),
                'pct_within_target' => self::pct(count(array_filter($judged, fn ($r) => $r['c']['met'])), count($judged)),
                'open_over_24h' => $openOver24,
            ],
            'stages' => $stages,
            'by_owner' => $byOwner,
            'by_lane' => $byLane,
            'by_vendor' => $byVendor,
            'jobs_by_category' => $cats,
            'damage_inspections' => [
                'damage_records' => count($damages),
                'damage_new' => count(array_filter($damages, fn ($d) => $d->condition === 'New')),
                'closed_without_pickup_inspection' => count(array_filter($closed, fn ($r) => !$r['v']->pickup_inspected)),
                'closed_without_return_inspection' => count(array_filter($closed, fn ($r) => !$r['v']->return_inspected)),
                'closed_without_pickup_photos' => $noPickPhotos,
                'closed_without_return_photos' => $noRetPhotos,
                'closed_with_data_problems' => count(array_filter($closed, fn ($r) => $r['c']['check'] && $r['c']['check'] !== 'OK')),
            ],
            'due_compliance' => [
                'jobs_with_previous_due' => $cmp,
                'jobs_overdue' => count($od),
                'pct_overdue' => self::pct(count($od), $cmp),
                'avg_km_overdue' => $kmOver ? (int) round(array_sum($kmOver) / count($kmOver)) : null,
                'worst_km_overdue' => $kmOver ? (int) max($kmOver) : null,
                'visits_with_overdue_job' => count(array_filter($cs, fn ($c) => ($c['overdueN'] ?? 0) > 0)),
                'jobs_missing_odometer' => count(array_filter($sinfo, fn ($x) => ($x['status'] ?? '') === 'Odometer missing')),
            ],
            'petty_by_category' => array_map(fn ($k, $v) => ['category' => $k, 'amount' => round($v / 100, 2)], array_keys($pettyByCat), array_values($pettyByCat)),
            'delay_owner_visits' => count($closed),
        ];
    }

    /** Lanes for the by-lane table: engine ctx lanes ([{name,target}]), else the settings mirror. */
    protected static function lanes(array $settings, ?array $lanes): array
    {
        if ($lanes) {
            return array_map(fn ($l) => ['name' => $l['name'], 'target' => $l['target'] !== null ? (float) $l['target'] : null], $lanes);
        }
        return array_map(fn ($l) => ['name' => $l['name'], 'target' => (float) $l['target']], (array) ($settings['lanes'] ?? []));
    }

    protected static function vendors(array $settings, array $stops): array
    {
        $v = array_values(array_filter((array) ($settings['vendors'] ?? []), fn ($x) => $x !== null && $x !== ''));
        if ($v) {
            return $v;
        }
        return array_values(array_unique(array_filter(array_map(fn ($s) => $s->vendor_name, $stops))));
    }
}
