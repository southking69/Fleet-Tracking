<?php

namespace Jalal\ServiceTracker\Reports;

use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Per-plate roll-up (Index.html vehicleIndex / vehicleDetail): history, last odometer and the
 * "next services due" table with OK / Due soon (<= 1000 km) / OVERDUE.
 */
class VehicleIndex
{
    public const DUE_SOON_KM = 1000;

    public static function dueStatus(?float $left): string
    {
        return $left === null ? '' : ($left < 0 ? 'OVERDUE' : ($left <= self::DUE_SOON_KM ? 'Due soon' : 'OK'));
    }

    /**
     * @param iterable $visits ServiceVisit models with stops, jobs, damages loaded
     * @param array    $calcs  uuid => engine output
     * @param iterable $petty  PettyCashEntry models (any period)
     * @return array<string,array> keyed by plate key
     */
    public static function build(iterable $visits, array $calcs, iterable $petty = []): array
    {
        $m = [];
        $get = function (string $key, string $plate) use (&$m) {
            return $m[$key] ??= ['key' => $key, 'plate' => $plate, 'make' => '', 'vehicle_uuid' => null, 'visits' => [], 'expenses' => []];
        };

        $sorted = collect($visits)->sortBy(fn ($v) => $v->created_at)->values();
        foreach ($sorted as $v) {
            $k = VisitMapper::plateKey($v->plate);
            if ($k === '') {
                continue;
            }
            $m[$k] = $get($k, (string) $v->plate);
            $m[$k]['plate'] = (string) $v->plate;
            if ($v->make_model) {
                $m[$k]['make'] = (string) $v->make_model;
            }
            if ($v->vehicle_uuid) {
                $m[$k]['vehicle_uuid'] = $v->vehicle_uuid;
            }
            $m[$k]['visits'][] = $v;
        }
        foreach ($petty as $e) {
            $k = VisitMapper::plateKey($e->plate);
            if ($k === '') {
                continue;
            }
            $m[$k] = $get($k, (string) $e->plate);
            $m[$k]['expenses'][] = $e;
        }

        foreach ($m as $k => &$x) {
            $odos = [];
            $arrivals = [];
            $jobs = 0;
            $overdue = 0;
            $damages = 0;
            $latest = [];
            $history = [];
            foreach ($x['visits'] as $v) {
                $c = $calcs[$v->uuid] ?? null;
                $odo = VisitMapper::num($v->odometer);
                if ($odo !== null) {
                    $odos[] = $odo;
                }
                if ($v->arrived_at_lsa) {
                    $arrivals[] = VisitMapper::iso($v->arrived_at_lsa);
                }
                $damages += $v->relationLoaded('damages') ? $v->damages->count() : 0;
                foreach ($v->jobs as $i => $j) {
                    $jobs++;
                    $info = $c['sinfo'][$i] ?? null;
                    if (!$info) {
                        continue;
                    }
                    if (($info['status'] ?? '') === 'OVERDUE') {
                        $overdue++;
                    }
                    $history[] = [
                        'date' => VisitMapper::iso($v->arrived_at_lsa),
                        'visit_uuid' => $v->uuid,
                        'visit' => $v->public_id,
                        'service' => $j->service_name,
                        'vendor' => $j->vendor_name,
                        'odometer' => $info['odo'] ?? null,
                        'next_due_km' => $info['nextDue'] ?? null,
                        'prev_due_km' => $info['prev'] ?? null,
                        'km_over' => $info['kmOver'] ?? null,
                        'status_at_pickup' => $info['status'] ?? null,
                    ];
                    if (($info['nextDue'] ?? null) !== null && $j->service_name !== '' && $j->service_name !== null) {
                        $p = $latest[$j->service_name] ?? null;
                        if (!$p || ((float) ($info['odo'] ?? 0)) >= ((float) ($p['odo'] ?? 0))) {
                            $latest[$j->service_name] = ['name' => $j->service_name, 'odo' => $info['odo'] ?? null, 'next' => $info['nextDue'], 'date' => $j->next_due_date];
                        }
                    }
                }
            }
            sort($arrivals);
            $lastOdo = $odos ? max($odos) : null;

            $due = array_map(function ($d) use ($lastOdo) {
                $left = $lastOdo !== null ? (float) $d['next'] - $lastOdo : null;
                return [
                    'service' => $d['name'],
                    'last_done_km' => $d['odo'] !== null ? (int) round($d['odo']) : null,
                    'next_due_km' => (int) round($d['next']),
                    'next_due_date' => $d['date'] ? \Carbon\Carbon::parse($d['date'])->toDateString() : null,
                    'km_remaining' => $left !== null ? (int) round($left) : null,
                    'status' => self::dueStatus($left),
                ];
            }, array_values($latest));
            usort($due, fn ($a, $b) => ($a['km_remaining'] ?? 1e12) <=> ($b['km_remaining'] ?? 1e12));
            usort($history, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

            $x['closed'] = count(array_filter($x['visits'], fn ($v) => $v->returned_at));
            $x['open'] = count(array_filter($x['visits'], fn ($v) => $v->arrived_at_lsa && !$v->returned_at));
            $x['jobs'] = $jobs;
            $x['last_visit'] = $arrivals ? end($arrivals) : null;
            $x['last_odometer'] = $lastOdo;
            $x['damages'] = $damages;
            $x['overdue'] = $overdue;
            $x['cash_minor'] = array_sum(array_map(fn ($e) => (int) $e->amount, $x['expenses']));
            $x['due'] = $due;
            $x['history'] = $history;
            $x['due_worst'] = collect($due)->contains('status', 'OVERDUE') ? 'OVERDUE' : (collect($due)->contains('status', 'Due soon') ? 'Due soon' : ($due ? 'OK' : ''));
        }
        unset($x);
        return $m;
    }
}
