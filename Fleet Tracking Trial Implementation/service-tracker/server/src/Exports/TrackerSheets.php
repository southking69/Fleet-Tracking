<?php

namespace Jalal\ServiceTracker\Exports;

use Jalal\ServiceTracker\Reports\TrackerStats;
use Jalal\ServiceTracker\Reports\VehicleIndex;
use Jalal\ServiceTracker\Services\StopTimer;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Builds the worksheets of the tracker's Excel exports (Index.html exportSheets / summarySheet /
 * vehiclesSheet / pettySheet), column for column. Amounts are written in major units (SAR), not cents.
 */
class TrackerSheets
{
    public const BASIS = ['arrived' => 'Arrived at LSA', 'returned' => 'Returned to LSA'];

    protected $visits;
    protected array $calcs;
    protected array $ctx;
    protected array $settings;
    protected string $tz;
    protected array $users = [];
    protected array $visitRef = [];

    /**
     * @param iterable $visits ServiceVisit models with technician, stops, jobs, damages.files, files loaded
     */
    public function __construct(iterable $visits, array $calcs, array $ctx, array $settings)
    {
        $this->visits = collect($visits)->values();
        $this->calcs = $calcs;
        $this->ctx = $ctx;
        $this->settings = $settings;
        $this->tz = VisitMapper::tz($settings);
        foreach ($this->visits as $v) {
            $this->visitRef[$v->uuid] = $v;
        }
        $ids = $this->visits->flatMap(fn ($v) => $v->damages->pluck('recorded_by_uuid'))->filter()->unique()->values()->all();
        if ($ids && class_exists(\Fleetbase\Models\User::class)) {
            $this->users = \Fleetbase\Models\User::whereIn('uuid', $ids)->pluck('name', 'uuid')->all();
        }
    }

    protected function sheet(string $name, array $cols, array $rows): ArraySheet
    {
        return new ArraySheet($name, $cols, $rows, $this->tz);
    }

    protected function currency(): string
    {
        return $this->settings['currency'] ?? 'SAR';
    }

    protected static function r1(?float $n): ?float
    {
        return $n === null ? null : round($n * 100) / 100;
    }

    protected function filesOf($model, ?string $type = null): int
    {
        return $model->relationLoaded('files') ? $model->files->filter(fn ($f) => $type === null || $f->type === $type)->count() : 0;
    }

    /* ------------------------------------------------------------ sheets */

    public function visits(): ArraySheet
    {
        $vendors = $this->ctx['vendors'];
        $cols = [
            ['h' => 'Visit ID', 'w' => 9], ['h' => 'Car Plate', 'w' => 12], ['h' => 'Make / Model', 'w' => 16], ['h' => 'Technician', 'w' => 14], ['h' => 'Odometer at Pickup (km)', 'w' => 12, 't' => 'int'],
            ['h' => 'Arrived at LSA', 'w' => 16, 't' => 'dt'], ['h' => 'Picked Up from LSA', 'w' => 16, 't' => 'dt'], ['h' => 'Returned to LSA', 'w' => 16, 't' => 'dt'],
            ['h' => 'Pickup Inspection Done?', 'w' => 11], ['h' => 'Return Inspection Done?', 'w' => 11], ['h' => 'Pickup Photos', 'w' => 9], ['h' => 'Return Photos', 'w' => 9], ['h' => 'Notes', 'w' => 28],
            ['h' => 'Status', 'w' => 11], ['h' => '# Services', 'w' => 9], ['h' => '# Vendor Stops', 'w' => 9], ['h' => 'Lane (highest job)', 'w' => 11, 'tm' => 1], ['h' => 'Target (hrs)', 'w' => 8, 'tm' => 1],
        ];
        foreach ($vendors as $i => $n) {
            $cols[] = ['h' => $n ? 'Hrs at ' . $n : 'Hrs at vendor ' . ($i + 1), 'w' => 12, 't' => 'h1', 'tm' => 1];
        }
        array_push($cols,
            ['h' => 'TOTAL Hrs at Vendors', 'w' => 11, 't' => 'h1', 'tm' => 1], ['h' => 'Wait for Pickup (hrs)', 'w' => 10, 't' => 'h1', 'tm' => 1], ['h' => 'Transit to 1st Vendor (hrs)', 'w' => 10, 't' => 'h1', 'tm' => 1],
            ['h' => 'Approval Wait (hrs)', 'w' => 10, 't' => 'h1', 'tm' => 1], ['h' => 'Vendor Work excl. Approval (hrs)', 'w' => 12, 't' => 'h1', 'tm' => 1], ['h' => 'Between Vendors (hrs)', 'w' => 10, 't' => 'h1', 'tm' => 1],
            ['h' => 'Return Transit (hrs)', 'w' => 10, 't' => 'h1', 'tm' => 1], ['h' => 'TOTAL Turnaround (hrs)', 'w' => 11, 't' => 'h1', 'tm' => 1], ['h' => 'Met Target?', 'w' => 9, 'tm' => 1],
            ['h' => 'Biggest Delay', 'w' => 18, 'tm' => 1], ['h' => 'Delay Owner', 'w' => 12, 'tm' => 1], ['h' => 'Data Check', 'w' => 24], ['h' => 'Overdue Services at Pickup', 'w' => 11], ['h' => 'Worst Overdue (km)', 'w' => 11, 't' => 'int']);

        $rows = [];
        foreach ($this->visits as $v) {
            $c = $this->calcs[$v->uuid];
            $row = [
                $v->public_id, $v->plate, $v->make_model, optional($v->technician)->name, VisitMapper::num($v->odometer),
                $v->arrived_at_lsa, $v->picked_up_at, $v->returned_at, $v->pickup_inspected ? 'Yes' : 'No', $v->return_inspected ? 'Yes' : 'No',
                $this->filesOf($v, VisitMapper::PHOTO_PICKUP), $this->filesOf($v, VisitMapper::PHOTO_RETURN), $v->notes,
                $c['status'], count($c['jobs']), count($c['stops']), $c['lane'], $c['target'],
            ];
            foreach ($vendors as $i => $_) {
                $row[] = $this->r1($c['perVendor'][$i] ?? null);
            }
            array_push($row,
                $this->r1($c['THV']), $this->r1($c['stages'][0]), $this->r1($c['stages'][1]), $this->r1($c['stages'][2]), $this->r1($c['stages'][3]), $this->r1($c['stages'][4]), $this->r1($c['stages'][5]),
                $this->r1($c['total']), $c['met'] === null ? '' : ($c['met'] ? 'Yes' : 'No'), $c['bigName'], $c['owner'], $c['check'], $c['overdueN'], $c['worstOver']);
            $rows[] = $row;
        }
        return $this->sheet('Visits', $cols, $rows);
    }

    public function stops(): ArraySheet
    {
        $cols = [['h' => 'Visit ID', 'w' => 9], ['h' => 'Car Plate', 'w' => 12], ['h' => 'Stop #', 'w' => 7], ['h' => 'Vendor', 'w' => 18], ['h' => 'Arrived at Vendor', 'w' => 16, 't' => 'dt'],
            ['h' => 'Approval Requested', 'w' => 16, 't' => 'dt'], ['h' => 'Approval Received', 'w' => 16, 't' => 'dt'], ['h' => 'Left Vendor (work done)', 'w' => 16, 't' => 'dt'], ['h' => 'Notes', 'w' => 28],
            ['h' => 'Time at Vendor (hrs)', 'w' => 11, 't' => 'h1', 'tm' => 1], ['h' => 'Approval Wait (hrs)', 'w' => 11, 't' => 'h1', 'tm' => 1], ['h' => 'Net Work Time (hrs)', 'w' => 11, 't' => 'h1', 'tm' => 1]];
        $rows = [];
        foreach ($this->visits as $v) {
            foreach ($v->stops as $i => $s) {
                $t = StopTimer::info(VisitMapper::stopArray($s));
                $rows[] = [$v->public_id, $v->plate, $i + 1, $s->vendor_name, $s->arrived_at, $s->approval_requested_at, $s->approval_received_at, $s->left_at, $s->notes,
                    $this->r1($t['atVendor'] ?? null), $this->r1($t['appr'] ?? null), $this->r1($t['net'] ?? null)];
            }
        }
        return $this->sheet('Vendor Stops', $cols, $rows);
    }

    public function services(): ArraySheet
    {
        $cols = [['h' => 'Visit ID', 'w' => 9], ['h' => 'Car Plate', 'w' => 12], ['h' => 'Service', 'w' => 42], ['h' => 'Category', 'w' => 22], ['h' => 'Lane', 'w' => 10, 'tm' => 1], ['h' => 'Target (hrs)', 'w' => 9, 'tm' => 1],
            ['h' => 'Suggested Vendor', 'w' => 16], ['h' => 'Vendor Used', 'w' => 16], ['h' => 'Splendor Approval Needed?', 'w' => 11], ['h' => 'Status', 'w' => 11], ['h' => 'Odometer at Service (km)', 'w' => 12, 't' => 'int'],
            ['h' => 'Parts Replaced', 'w' => 24], ['h' => 'Next Due Override (km)', 'w' => 12, 't' => 'int'], ['h' => 'NEXT DUE (km)', 'w' => 12, 't' => 'int'], ['h' => 'Next Due (date)', 'w' => 12, 't' => 'date'],
            ['h' => 'Previous Due (km)', 'w' => 12, 't' => 'int'], ['h' => 'Km Over Previous Due', 'w' => 12, 't' => 'int'], ['h' => 'Due Status at Pickup', 'w' => 16], ['h' => 'Notes', 'w' => 26]];
        $rows = [];
        foreach ($this->visits as $v) {
            $c = $this->calcs[$v->uuid];
            foreach ($v->jobs as $i => $j) {
                $x = $c['sinfo'][$i] ?? [];
                $rows[] = [$v->public_id, $v->plate, $j->service_name, $x['category'] ?? '', $x['lane'] ?? '', $x['target'] ?? null, $x['suggested'] ?? '', $j->vendor_name,
                    $j->approval_needed ? 'Yes' : 'No', $j->status, $x['odo'] ?? null, $j->parts_replaced, VisitMapper::num($j->next_due_override_km), $x['nextDue'] ?? null,
                    $j->next_due_date ? \Carbon\Carbon::parse($j->next_due_date)->toDateString() : null, $x['prev'] ?? null, $x['kmOver'] ?? null, $x['status'] ?? '', $j->notes];
            }
        }
        return $this->sheet('Services', $cols, $rows);
    }

    public function damages(): ArraySheet
    {
        $cols = [['h' => 'Damage ID', 'w' => 10], ['h' => 'Visit ID', 'w' => 9], ['h' => 'Car Plate', 'w' => 12], ['h' => 'Date / Time Recorded', 'w' => 16, 't' => 'dt'], ['h' => 'Stage', 'w' => 18], ['h' => 'Location on Car', 'w' => 18],
            ['h' => 'Damage Type', 'w' => 22], ['h' => 'Severity', 'w' => 11], ['h' => 'Pre-existing or New', 'w' => 14], ['h' => 'Photo Ref', 'w' => 26], ['h' => 'Photos attached', 'w' => 10], ['h' => 'Recorded By', 'w' => 14],
            ['h' => 'Client Notified?', 'w' => 11], ['h' => 'Notes', 'w' => 28]];
        $rows = [];
        foreach ($this->visits as $v) {
            foreach ($v->damages as $d) {
                $first = $d->relationLoaded('files') ? $d->files->first() : null;
                $rows[] = [$d->public_id, $v->public_id, $v->plate, $d->occurred_at, $d->stage, $d->location, $d->damage_type, $d->severity, $d->condition,
                    $first ? $first->original_filename : '', $this->filesOf($d), $this->users[$d->recorded_by_uuid] ?? '', $d->client_notified, $d->notes];
            }
        }
        return $this->sheet('Damage Log', $cols, $rows);
    }

    /** @param iterable $petty PettyCashEntry rows (files loaded) */
    public function petty(iterable $petty): ArraySheet
    {
        $cols = [['h' => 'Date', 'w' => 12, 't' => 'date'], ['h' => 'Ref', 'w' => 10], ['h' => 'Car Plate', 'w' => 12], ['h' => 'Visit', 'w' => 10], ['h' => 'Category', 'w' => 18], ['h' => 'What for', 'w' => 34],
            ['h' => 'Paid by', 'w' => 14], ['h' => 'Amount (' . $this->currency() . ')', 'w' => 13, 't' => 'money'], ['h' => 'Receipt photos', 'w' => 9], ['h' => 'Notes', 'w' => 26]];
        $rows = [];
        $total = 0;
        foreach (collect($petty)->sortBy(fn ($e) => ($e->entry_date ? \Carbon\Carbon::parse($e->entry_date)->toDateString() : '') . str_pad((string) $e->id, 12, '0', STR_PAD_LEFT)) as $e) {
            $total += (int) $e->amount;
            $rows[] = [$e->entry_date ? \Carbon\Carbon::parse($e->entry_date)->toDateString() : null, $e->public_id, $e->plate,
                $e->service_visit_uuid && isset($this->visitRef[$e->service_visit_uuid]) ? $this->visitRef[$e->service_visit_uuid]->public_id : '',
                $e->category, $e->description, $e->paid_by, round(((int) $e->amount) / 100, 2), $this->filesOf($e), ''];
        }
        $rows[] = ['', '', '', '', '', '', 'TOTAL', round($total / 100, 2), '', ''];
        return $this->sheet('Petty Cash', $cols, $rows);
    }

    public function catalog(): ArraySheet
    {
        $cols = [['h' => 'Service', 'w' => 46], ['h' => 'Category', 'w' => 26], ['h' => 'Lane', 'w' => 12], ['h' => 'Suggested Vendor', 'w' => 20], ['h' => 'Service Interval (km)', 'w' => 14, 't' => 'int']];
        $rows = array_map(fn ($c) => [$c['service'], $c['category'], $c['lane'], $c['vendor'], $c['interval'] ?: null], $this->ctx['catalog']);
        return $this->sheet('Service Catalog', $cols, $rows);
    }

    /** @param iterable $petty petty rows to count per car (period or all) */
    public function vehicles(iterable $petty = []): ArraySheet
    {
        $idx = VehicleIndex::build($this->visits, $this->calcs, $petty);
        $cols = [['h' => 'Car Plate', 'w' => 12], ['h' => 'Make / Model', 'w' => 16], ['h' => 'Times Serviced (visits)', 'w' => 11], ['h' => 'Completed Visits', 'w' => 10], ['h' => 'Jobs Done', 'w' => 9],
            ['h' => 'Last Visit', 'w' => 16, 't' => 'dt'], ['h' => 'Last Odometer (km)', 'w' => 12, 't' => 'int'], ['h' => 'Jobs Overdue at Pickup (all time)', 'w' => 12], ['h' => 'Damage Records', 'w' => 10],
            ['h' => 'Petty Cash (' . $this->currency() . ')', 'w' => 13, 't' => 'money']];
        ksort($idx);
        $rows = array_map(fn ($x) => [$x['plate'], $x['make'], count($x['visits']), $x['closed'], $x['jobs'], $x['last_visit'], $x['last_odometer'], $x['overdue'], $x['damages'], round($x['cash_minor'] / 100, 2)], array_values($idx));
        return $this->sheet('Vehicles', $cols, $rows);
    }

    /** Summary sheet of the period report. */
    public function summary(?string $from, ?string $to, string $basis, bool $hideTiming, ?iterable $petty): ArraySheet
    {
        $s = $this->settings;
        $stats = TrackerStats::compute($this->visits, $this->calcs, $s, $petty ?? [], $this->ctx['lanes']);
        $k = $stats['kpis'];
        $dash = "\u{2013}";
        $pct = fn ($p) => $p === null ? $dash : $p . '%';

        $rows = [
            ['Period', ($from ?: 'start') . '  to  ' . ($to ?: \Carbon\Carbon::now($this->tz)->toDateString())],
            ['Visits selected by', (self::BASIS[$basis] ?? self::BASIS['arrived']) . ' date'],
            ['Generated', \Carbon\Carbon::now($this->tz)->format('d-M-y H:i')],
            ['', ''],
            ['Visits', $k['visits']], ['Closed (back at LSA)', $k['closed']], ['Still in progress', $k['in_progress']],
            ['Jobs / services', $k['jobs']], ['Vendor stops', $k['stops']],
        ];
        if (!$hideTiming) {
            $rows[] = ['Average total turnaround (hrs)', $k['avg_turnaround_hours']];
            $rows[] = ['Closed visits within target', $pct($k['pct_within_target'])];
            $rows[] = ['', ''];
            $rows[] = ['AVERAGE HOURS PER STAGE', ''];
            foreach ($stats['stages'] as $st) {
                $rows[] = [$st['short'] . '  (' . $st['owner'] . ')', $st['avg_hours']];
            }
        }
        if ($petty !== null) {
            $rows[] = ['', ''];
            $rows[] = ['PETTY CASH (' . $this->currency() . ')', ''];
            $rows[] = ['Entries', $k['petty_cash_entries']];
            $rows[] = ['Total spent', $k['petty_cash_total']];
            foreach ($stats['petty_by_category'] as $p) {
                $rows[] = ['  ' . $p['category'], $p['amount']];
            }
        }
        $dc = $stats['due_compliance'];
        $di = $stats['damage_inspections'];
        array_push($rows,
            ['', ''], ['SERVICE INTERVALS', ''], ['Jobs overdue at pickup', $dc['jobs_overdue']], ['Visits with an overdue job', $dc['visits_with_overdue_job']],
            ['', ''], ['DAMAGE', ''], ['Damage records', $di['damage_records']], ["\u{2026}of which NEW (found after pickup)", $di['damage_new']],
            ['', ''], ['JOBS BY CATEGORY', '']);
        foreach ($stats['jobs_by_category'] as $c) {
            if ($c['jobs']) {
                $rows[] = [$c['category'], $c['jobs']];
            }
        }
        if (!$hideTiming) {
            $rows[] = ['', ''];
            $rows[] = ['TIME AT VENDORS', 'Avg hrs per stop'];
            foreach ($stats['by_vendor'] as $vd) {
                if ($vd['stops']) {
                    $rows[] = [$vd['vendor'] . '  (' . $vd['stops'] . ($vd['stops'] === 1 ? ' stop)' : ' stops)'), $vd['avg_hours_per_stop']];
                }
            }
        }
        return new ArraySheet('Summary', [['h' => 'Service report summary', 'w' => 46], ['h' => '', 'w' => 26]], $rows, $this->tz);
    }
}
