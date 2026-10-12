<?php

namespace Jalal\ServiceTracker\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Services\StopTimer;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Client report for one visit ("Vehicle Service & Damage Report"), mirroring Index.html reportPaper.
 * Six toggles (defaults from settings.reportOpts, overridable per call):
 *   turnaround, vendorTimes, overdue, petty, photos (damage photos), condPhotos (pickup/return photos)
 * Rendering uses barryvdh/laravel-dompdf (pure PHP, no headless browser) with a table-only layout.
 */
class VisitReportPdf
{
    public const OPTION_KEYS = ['turnaround', 'vendorTimes', 'overdue', 'petty', 'photos', 'condPhotos'];

    protected ServiceVisit $visit;
    protected array $opts;
    protected array $settings;
    protected string $tz;

    public function __construct(ServiceVisit $visit, array $overrides = [])
    {
        $this->settings = VisitMapper::settings();
        $this->tz = VisitMapper::tz($this->settings);
        $opts = $this->settings['reportOpts'];
        foreach (self::OPTION_KEYS as $k) {
            if (array_key_exists($k, $overrides) && $overrides[$k] !== null) {
                $opts[$k] = filter_var($overrides[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $this->opts = $opts;
        $this->visit = $visit;
        $this->visit->load(['technician', 'stops', 'jobs', 'damages.files']);
        VisitMapper::attachFiles([$this->visit]);
    }

    public function options(): array
    {
        return $this->opts;
    }

    public function filename(): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]+/', '_', $this->visit->public_id . '_report') . '.pdf';
    }

    protected function dt($v): string
    {
        return $v ? Carbon::parse($v)->setTimezone($this->tz)->format('d-M-y H:i') : '';
    }

    protected function d($v): string
    {
        return $v ? Carbon::parse($v)->format('d-M-y') : '';
    }

    protected static function f1($n): string
    {
        return ($n === null || !is_numeric($n)) ? "\u{2013}" : number_format(round($n * 10) / 10, 1, '.', '');
    }

    protected static function fint($n): string
    {
        return ($n === null || $n === '' || !is_numeric($n)) ? "\u{2013}" : number_format((float) $n, 0, '.', ',');
    }

    /** Petty cash that belongs on this visit's report (linked, or same car and dated within the visit). */
    protected function pettyEntries(): array
    {
        $v = $this->visit;
        $from = $v->arrived_at_lsa ? Carbon::parse($v->arrived_at_lsa)->setTimezone($this->tz)->toDateString() : null;
        $to = $v->returned_at ? Carbon::parse($v->returned_at)->setTimezone($this->tz)->toDateString() : $from;
        $key = VisitMapper::plateKey($v->plate);
        $rows = PettyCashEntry::where('company_uuid', $v->company_uuid)->where(function ($q) use ($v, $from, $to, $key) {
            $q->where('service_visit_uuid', $v->uuid);
            if ($from && $key !== '') {
                $q->orWhere(function ($w) use ($from, $to, $key) {
                    $w->whereNull('service_visit_uuid')->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)
                        ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(plate,' ',''),'-',''),'.','')) = ?", [$key]);
                });
            }
        })->orderBy('entry_date')->orderBy('id')->get();
        return $rows->all();
    }

    /** data: URI for a stored image, or null if it cannot be read. */
    protected function imageUri($file): ?string
    {
        try {
            $disk = Storage::disk($file->disk ?: config('filesystems.default', 'local'));
            if (!$file->path || !$disk->exists($file->path)) {
                return null;
            }
            $mime = $file->content_type ?: 'image/jpeg';
            return 'data:' . $mime . ';base64,' . base64_encode($disk->get($file->path));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function data(): array
    {
        $v = $this->visit;
        $o = $this->opts;
        $ctx = VisitMapper::context($v->company_uuid, $this->settings);
        $c = VisitMapper::calc($v, $ctx);

        $stops = [];
        foreach ($v->stops as $i => $s) {
            $t = StopTimer::info(VisitMapper::stopArray($s));
            $stops[] = [$i + 1, $s->vendor_name, $this->dt($s->arrived_at), $this->dt($s->left_at), self::f1($t['atVendor'] ?? null), self::f1($t['appr'] ?? null), self::f1($t['net'] ?? null)];
        }

        $jobs = [];
        $overdue = [];
        foreach ($v->jobs as $i => $j) {
            $x = $c['sinfo'][$i] ?? [];
            $jobs[] = [$i + 1, $j->service_name, $j->vendor_name, $j->parts_replaced, self::fint($x['odo'] ?? null), self::fint($x['nextDue'] ?? null), $x['status'] ?? ''];
            if (($x['status'] ?? '') === 'OVERDUE') {
                $overdue[] = $j->service_name . ' (' . self::fint($x['kmOver'] ?? null) . ' km over)';
            }
        }

        $damages = [];
        $damagePhotos = [];
        foreach ($v->damages as $i => $d) {
            $damages[] = [$i + 1, $d->public_id, $d->location, $d->damage_type, $d->severity, $d->condition, $d->stage, $this->dt($d->occurred_at), $d->files->count() ?: "\u{2013}"];
            if ($o['photos']) {
                $n = $d->files->count();
                foreach ($d->files->values() as $k => $f) {
                    if ($src = $this->imageUri($f)) {
                        $damagePhotos[] = ['src' => $src, 'caption' => implode(' · ', array_filter([$d->public_id . ($n > 1 ? ' (' . ($k + 1) . ')' : ''), $d->location, $d->damage_type, $d->condition]))];
                    }
                }
            }
        }

        $condPhotos = [];
        if ($o['condPhotos']) {
            foreach ([[VisitMapper::PHOTO_PICKUP, 'Pickup', $v->picked_up_at], [VisitMapper::PHOTO_RETURN, 'Return', $v->returned_at]] as [$type, $label, $when]) {
                foreach ($v->files->where('type', $type)->values() as $i => $f) {
                    if ($src = $this->imageUri($f)) {
                        $condPhotos[] = ['src' => $src, 'caption' => $label . ' photo ' . ($i + 1) . ($when ? ' · ' . $this->dt($when) : '')];
                    }
                }
            }
        }

        $petty = [];
        $pettyTotal = 0;
        if ($o['petty']) {
            foreach ($this->pettyEntries() as $i => $e) {
                $pettyTotal += (int) $e->amount;
                $petty[] = [$i + 1, $this->d($e->entry_date), $e->category, $e->description, $e->paid_by, number_format(((int) $e->amount) / 100, 2, '.', ',')];
            }
        }

        $target = $c['target'];
        $kv = [
            ['Car plate', $v->plate], ['Arrived at LSA', $this->dt($v->arrived_at_lsa)],
            ['Make / model', $v->make_model], ['Picked up from LSA', $this->dt($v->picked_up_at)],
            ['Odometer at pickup', $v->odometer !== null ? self::fint($v->odometer) . ' km' : ''], ['Returned to LSA', $this->dt($v->returned_at)],
            ['Handled by', optional($v->technician)->name], ['Pickup inspection', $v->pickup_inspected ? 'Done' : 'Not recorded'],
            ['Return inspection', $v->return_inspected ? 'Done' : 'Not recorded'], ['', ''],
        ];
        if ($o['turnaround']) {
            $kv[] = ['Total turnaround', $c['total'] !== null ? self::f1($c['total']) . ' hours' : ''];
            $kv[] = ['Target / result', $target !== null ? $target . ' h – ' . ($c['met'] === null ? 'in progress' : ($c['met'] ? 'within target' : 'over target')) : ''];
        }

        return [
            'company' => $this->settings['company'] ?? '',
            'client' => $this->settings['client'] ?? '',
            'currency' => $this->settings['currency'] ?? 'SAR',
            'visit' => $v,
            'visitRef' => $v->public_id,
            'reportDate' => Carbon::now($this->tz)->format('d-M-y'),
            'opts' => $o,
            'kvRows' => array_chunk($kv, 2),
            'stops' => $stops,
            'totalVendor' => $c['THV'] !== null ? self::f1($c['THV']) : null,
            'jobs' => $jobs,
            'overdue' => $overdue,
            'damages' => $damages,
            'damagePhotos' => $damagePhotos,
            'condPhotos' => $condPhotos,
            'petty' => $petty,
            'pettyTotal' => number_format($pettyTotal / 100, 2, '.', ','),
            'notes' => $v->notes,
        ];
    }

    public function html(): string
    {
        return view('service-tracker::report', $this->data())->render();
    }

    /** PDF bytes. */
    public function render(): string
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->html())->setPaper('a4', 'portrait');
        return $pdf->output();
    }
}
