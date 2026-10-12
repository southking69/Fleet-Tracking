<?php

namespace Jalal\ServiceTracker\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;
use Jalal\ServiceTracker\Models\ServiceJob;
use Jalal\ServiceTracker\Models\ServiceLane;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Models\VendorStop;

/**
 * Eloquent -> engine arrays. The only place that knows both the DB column names and the
 * camelCase keys the pure-PHP engine (TurnaroundCalculator & co.) expects.
 *
 * Context ($ctx) built by context() and sliced per visit by engineContext():
 *   catalog  : list<{service, category, lane, vendor, interval}>
 *   lanes    : list<{name, target}>
 *   vendors  : list<string>          (settings vendor list; drives perVendor[])
 *   plates   : array<plateKey, {visits: list<visitArray>, jobs: list<jobArray>}>
 *   now      : ISO-8601 string (UTC)
 * The engine itself receives catalog, lanes, vendors, now plus allVisits / allJobs, which engineContext()
 * limits to the visit's own plate: the previous-due lookup only ever compares the same car, and the
 * engine's lookups are linear scans, so slicing keeps batch calculation fast.
 */
class VisitMapper
{
    public const STAGE_NAMES = ['Wait at LSA for pickup', 'Transit to first vendor', 'Approval wait (Splendor)', 'Vendor work (excl. approval)', 'Between vendors', 'Return transit to LSA'];
    public const STAGE_SHORT = ['Pickup wait', 'Transit to 1st vendor', 'Approval wait', 'Vendor work', 'Between vendors', 'Return transit'];
    public const STAGE_OWNER = ['Our team', 'Our team', 'Splendor', 'Vendor', 'Our team', 'Our team'];

    public const PHOTO_PICKUP = 'service-tracker:pickup';
    public const PHOTO_RETURN = 'service-tracker:return';
    public const PHOTO_DAMAGE = 'service-tracker:damage';
    public const PHOTO_RECEIPT = 'service-tracker:receipt';
    public const PHOTO_STOP = 'service-tracker:stop';

    public const SETTINGS_KEY = 'service-tracker.settings';

    /** @var array<string,array<int,string>> */
    protected static array $columnCache = [];

    /* ------------------------------------------------------------ settings */

    public static function defaultSettings(): array
    {
        return [
            'company' => 'Jalal Holding',
            'client' => '',
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'lanes' => [['name' => 'Express', 'target' => 12], ['name' => 'Standard', 'target' => 24], ['name' => 'Heavy', 'target' => 48]],
            'vendors' => ['Local Mechanic', 'Petromin', 'Tyre Vendor', 'Other Specialist'],
            'staff' => ['Supervisor', 'Technician 1', 'Technician 2'],
            'categories' => ['Routine Maintenance', 'Tyres & Wheels', 'Brakes', 'Engine & Cooling', 'Transmission & Drivetrain', 'Suspension & Steering', 'Electrical & Electronics', 'A/C & Heating', 'Body, Glass & Interior', 'Inspection & Compliance', 'Recovery & Roadside', 'Other'],
            'damageLocations' => ['Front bumper', 'Rear bumper', 'Hood', 'Roof', 'Trunk', 'Windshield', 'Rear window', 'Left front door', 'Left rear door', 'Right front door', 'Right rear door', 'Left mirror', 'Right mirror', 'Wheel / Rim', 'Tyre', 'Interior', 'Other'],
            'expenseCats' => ['Fuel', 'Spare parts', 'Oil / fluids top-up', 'Towing / transport', 'Car wash', 'Parking / tolls', 'Tools / consumables', 'Other'],
            'damageTypes' => ['Scratch', 'Dent', 'Crack', 'Broken part', 'Missing item', 'Stain / interior damage', 'Other'],
            'severities' => ['Minor', 'Moderate', 'Major'],
            'stages' => ['Pickup inspection', 'Return inspection', 'Vendor reported'],
            'svcStatuses' => ['Pending', 'In progress', 'Done'],
            'reportOpts' => ['turnaround' => true, 'vendorTimes' => true, 'photos' => true, 'overdue' => true, 'condPhotos' => false, 'petty' => true],
            'excelTiming' => true,
            'excelPetty' => false,
            'excelVehicles' => true,
        ];
    }

    /** Company settings merged over defaults. */
    public static function settings(): array
    {
        $stored = null;
        try {
            $class = \Fleetbase\Models\Setting::class;
            if (method_exists($class, 'lookupCompany')) {
                $stored = $class::lookupCompany(self::SETTINGS_KEY);
            } else {
                $stored = $class::lookup(self::SETTINGS_KEY);
            }
        } catch (\Throwable $e) {
            $stored = null;
        }
        if ($stored instanceof \Illuminate\Support\Collection) {
            $stored = $stored->toArray();
        } elseif (is_object($stored)) {
            $stored = json_decode(json_encode($stored), true);
        }
        $base = self::defaultSettings();
        if (!is_array($stored)) {
            return $base;
        }
        $merged = array_replace($base, $stored);
        $merged['reportOpts'] = array_replace($base['reportOpts'], (array) ($stored['reportOpts'] ?? []));
        return $merged;
    }

    public static function saveSettings(array $settings): array
    {
        $merged = array_replace(self::settings(), $settings);
        $merged['reportOpts'] = array_replace(self::defaultSettings()['reportOpts'], (array) ($settings['reportOpts'] ?? self::settings()['reportOpts']));
        $class = \Fleetbase\Models\Setting::class;
        if (method_exists($class, 'configureCompany')) {
            $class::configureCompany(self::SETTINGS_KEY, $merged);
        } else {
            $class::configure(self::SETTINGS_KEY, $merged);
        }
        return $merged;
    }

    public static function tz(?array $settings = null): string
    {
        $s = $settings ?? self::settings();
        $tz = $s['timezone'] ?? 'Asia/Riyadh';
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Asia/Riyadh';
    }

    /* ------------------------------------------------------------ scalars */

    /** Plate comparison key: upper case, no spaces, dashes, dots, underscores (JS pkey/norm). */
    public static function plateKey(?string $plate): string
    {
        return preg_replace('/[\s\-_.]+/u', '', mb_strtoupper((string) $plate));
    }

    /** Plate as stored: trimmed, upper case, single spaces. */
    public static function cleanPlate(?string $plate): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtoupper((string) $plate)));
    }

    public static function iso($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $utc = new \DateTimeZone('UTC');
            $d = $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : new \DateTimeImmutable((string) $value, $utc);

            return $d->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Parse any client datetime to UTC. Strings without offset are read in the company timezone. */
    public static function parseDt($value, ?string $tz = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->utc();
        }
        try {
            return Carbon::parse((string) $value, $tz ?: self::tz())->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function statusOf($arrived, $returned): string
    {
        return $returned ? 'closed' : ($arrived ? 'in_progress' : 'not_started');
    }

    public static function statusLabel(string $status): string
    {
        return ['closed' => 'Closed', 'in_progress' => 'In progress', 'not_started' => 'Not started'][$status] ?? $status;
    }

    public static function num($v): ?float
    {
        return ($v === '' || $v === null || !is_numeric($v)) ? null : (float) $v;
    }

    public static function r2(?float $n): ?float
    {
        return $n === null ? null : round($n * 100) / 100;
    }

    /** Keep only attributes whose column exists on $table (FleetOps schema drifts between releases). */
    public static function filterColumns(string $table, array $attrs): array
    {
        if (!isset(self::$columnCache[$table])) {
            try {
                self::$columnCache[$table] = Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                self::$columnCache[$table] = [];
            }
        }
        return array_intersect_key($attrs, array_flip(self::$columnCache[$table]));
    }

    /* ------------------------------------------------------------ models -> arrays */

    /**
     * Pure row -> engine array converters (no Eloquent, no Carbon). `$r` uses the DB column names, so the
     * same code serves the models (visitArray & co.) and the import parity harness (AppsScriptMapper rows
     * after a simulated database round trip).
     *
     * @param array<string,mixed> $r
     */
    public static function visitFromRow(array $r): array
    {
        return [
            'id' => $r['uuid'] ?? null,
            'public_id' => $r['public_id'] ?? null,
            'plate' => (string) ($r['plate'] ?? ''),
            'make' => (string) ($r['make_model'] ?? ''),
            'odo' => self::num($r['odometer'] ?? null),
            'arrived' => self::iso($r['arrived_at_lsa'] ?? null),
            'picked' => self::iso($r['picked_up_at'] ?? null),
            'returned' => self::iso($r['returned_at'] ?? null),
            'pins' => (bool) ($r['pickup_inspected'] ?? false),
            'rins' => (bool) ($r['return_inspected'] ?? false),
            'notes' => (string) ($r['notes'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $r */
    public static function stopFromRow(array $r): array
    {
        return [
            'id' => $r['uuid'] ?? null,
            'visitId' => $r['service_visit_uuid'] ?? null,
            'vendor' => (string) ($r['vendor_name'] ?? ''),
            'arrived' => self::iso($r['arrived_at'] ?? null),
            'apprReq' => self::iso($r['approval_requested_at'] ?? null),
            'apprRec' => self::iso($r['approval_received_at'] ?? null),
            'left' => self::iso($r['left_at'] ?? null),
            'notes' => (string) ($r['notes'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $r */
    public static function jobFromRow(array $r): array
    {
        $nd = $r['next_due_date'] ?? null;

        return [
            'id' => $r['uuid'] ?? null,
            'visitId' => $r['service_visit_uuid'] ?? null,
            'service' => (string) ($r['service_name'] ?? ''),
            'vendor' => (string) ($r['vendor_name'] ?? ''),
            'apprNeeded' => (bool) ($r['approval_needed'] ?? false),
            'status' => (string) ($r['status'] ?? ''),
            'parts' => (string) ($r['parts_replaced'] ?? ''),
            'nextOverride' => self::num($r['next_due_override_km'] ?? null),
            'nextDate' => $nd ? substr((string) ($nd instanceof \DateTimeInterface ? $nd->format('Y-m-d') : $nd), 0, 10) : null,
            'notes' => (string) ($r['notes'] ?? ''),
        ];
    }

    public static function visitArray(ServiceVisit $v): array
    {
        return self::visitFromRow([
            'uuid' => $v->uuid, 'public_id' => $v->public_id, 'plate' => $v->plate, 'make_model' => $v->make_model, 'odometer' => $v->odometer,
            'arrived_at_lsa' => $v->arrived_at_lsa, 'picked_up_at' => $v->picked_up_at, 'returned_at' => $v->returned_at,
            'pickup_inspected' => $v->pickup_inspected, 'return_inspected' => $v->return_inspected, 'notes' => $v->notes,
        ]);
    }

    public static function stopArray(VendorStop $s): array
    {
        return self::stopFromRow([
            'uuid' => $s->uuid, 'service_visit_uuid' => $s->service_visit_uuid, 'vendor_name' => $s->vendor_name, 'arrived_at' => $s->arrived_at,
            'approval_requested_at' => $s->approval_requested_at, 'approval_received_at' => $s->approval_received_at, 'left_at' => $s->left_at, 'notes' => $s->notes,
        ]);
    }

    public static function jobArray(ServiceJob $j): array
    {
        return self::jobFromRow([
            'uuid' => $j->uuid, 'service_visit_uuid' => $j->service_visit_uuid, 'service_name' => $j->service_name, 'vendor_name' => $j->vendor_name,
            'approval_needed' => $j->approval_needed, 'status' => $j->status, 'parts_replaced' => $j->parts_replaced,
            'next_due_override_km' => $j->next_due_override_km, 'next_due_date' => $j->next_due_date, 'notes' => $j->notes,
        ]);
    }

    /**
     * Pure context builder (shared by context() and the parity harness).
     *
     * @param list<array{name:string,category:?string,lane:string,vendor:string,interval_km:int|float|string|null}> $catalog
     * @param list<array{name:string,target:mixed}> $lanes
     * @param list<array> $visitArrays visitFromRow()
     * @param list<array> $jobArrays   jobFromRow()
     */
    public static function buildContext(array $catalog, array $lanes, array $settings, array $visitArrays, array $jobArrays, string $now, array $stopVendorNames = []): array
    {
        $cat = array_map(fn ($c) => [
            'service' => (string) $c['name'],
            'category' => (string) ($c['category'] ?? ''),
            'lane' => (string) ($c['lane'] ?? ''),
            'vendor' => (string) ($c['vendor'] ?? ''),
            'interval' => (int) ($c['interval_km'] ?? 0),
        ], $catalog);
        $laneList = array_map(fn ($l) => ['name' => (string) $l['name'], 'target' => self::num($l['target'] ?? null)], $lanes);
        if (!$laneList) {
            $laneList = array_map(fn ($l) => ['name' => $l['name'], 'target' => self::num($l['target'])], $settings['lanes'] ?? []);
        }
        $vendors = array_values(array_filter((array) ($settings['vendors'] ?? []), fn ($x) => $x !== null && $x !== ''));
        if (!$vendors) {
            $vendors = array_values(array_filter(array_unique($stopVendorNames)));
        }

        return [
            'catalog' => $cat,
            'lanes' => $laneList,
            'vendors' => $vendors,
            'plates' => self::plateIndex($visitArrays, $jobArrays),
            'now' => $now,
        ];
    }

    /**
     * Build the engine context for a company. $visits may be passed to avoid re-querying.
     */
    public static function context(string $companyUuid, ?array $settings = null, ?Collection $allVisits = null): array
    {
        $settings = $settings ?? self::settings();

        $lanes = ServiceLane::where('company_uuid', $companyUuid)->orderBy('sort')->get();
        $laneName = $lanes->pluck('name', 'uuid');

        $items = ServiceCatalogItem::where('company_uuid', $companyUuid)->get();
        $vendorIds = $items->pluck('suggested_vendor_uuid')->filter()->unique()->values()->all();
        $vendorNames = [];
        $vendorClass = '\\Fleetbase\\FleetOps\\Models\\Vendor';
        if ($vendorIds && class_exists($vendorClass)) {
            $vendorNames = $vendorClass::whereIn('uuid', $vendorIds)->pluck('name', 'uuid')->all();
        }

        $catalog = $items->map(fn ($c) => [
            'name' => (string) $c->name,
            'category' => (string) $c->category,
            'lane' => (string) ($laneName[$c->lane_uuid] ?? ''),
            'vendor' => (string) ($vendorNames[$c->suggested_vendor_uuid] ?? $c->suggested_vendor_name ?? ''),
            'interval_km' => (int) $c->interval_km,
        ])->values()->all();

        $laneRows = $lanes->map(fn ($l) => ['name' => (string) $l->name, 'target' => $l->target_hours])->values()->all();

        $visitArrays = ($allVisits ?? ServiceVisit::where('company_uuid', $companyUuid)->get())->map(fn ($v) => self::visitArray($v))->values()->all();
        $jobArrays = ServiceJob::where('company_uuid', $companyUuid)->get()->map(fn ($j) => self::jobArray($j))->values()->all();

        $stopVendors = [];
        if (!array_filter((array) ($settings['vendors'] ?? []), fn ($x) => $x !== null && $x !== '')) {
            $stopVendors = VendorStop::where('company_uuid', $companyUuid)->whereNotNull('vendor_name')->distinct()->pluck('vendor_name')->filter()->values()->all();
        }

        return self::buildContext($catalog, $laneRows, $settings, $visitArrays, $jobArrays, Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'), $stopVendors);
    }

    /**
     * Give every model a loaded `files` relation. Models that define files() (damage, petty cash) are
     * eager loaded; for the ones that do not (visit, vendor stop) the core File rows are fetched by
     * subject_uuid in one query and set as the relation.
     *
     * @param iterable<\Illuminate\Database\Eloquent\Model> $models
     */
    public static function attachFiles(iterable $models): void
    {
        $list = [];
        foreach ($models as $m) {
            if ($m instanceof \Illuminate\Database\Eloquent\Model && !$m->relationLoaded('files')) {
                $list[] = $m;
            }
        }
        if (!$list || !class_exists(\Fleetbase\Models\File::class)) {
            return;
        }
        $files = \Fleetbase\Models\File::whereIn('subject_uuid', array_map(fn ($m) => $m->uuid, $list))
            ->where('type', 'like', 'service-tracker:%')->orderBy('created_at')->get()->groupBy('subject_uuid');
        foreach ($list as $m) {
            $m->setRelation('files', $files->get($m->uuid, new EloquentCollection()));
        }
    }

    /**
     * Group visits and their jobs by plate key.
     *
     * @param list<array> $visits visitArray()
     * @param list<array> $jobs   jobArray()
     * @return array<string,array{visits:list<array>,jobs:list<array>}>
     */
    public static function plateIndex(array $visits, array $jobs): array
    {
        $plates = [];
        $plateOf = [];
        foreach ($visits as $v) {
            $k = self::plateKey($v['plate'] ?? '');
            $plateOf[$v['id']] = $k;
            $plates[$k]['visits'][] = $v;
            $plates[$k]['jobs'] ??= [];
        }
        foreach ($jobs as $j) {
            if (isset($plateOf[$j['visitId']])) {
                $plates[$plateOf[$j['visitId']]]['jobs'][] = $j;
            }
        }
        return $plates;
    }

    /** The context the engine sees for one visit. */
    public static function engineContext(array $ctx, array $visit): array
    {
        $slice = $ctx['plates'][self::plateKey($visit['plate'] ?? '')] ?? ['visits' => [], 'jobs' => []];
        return [
            'catalog' => $ctx['catalog'],
            'lanes' => $ctx['lanes'],
            'vendors' => $ctx['vendors'],
            'now' => $ctx['now'],
            'allVisits' => $slice['visits'],
            'allJobs' => $slice['jobs'],
        ];
    }

    /** created_at ascending, ties by primary key (insertion order). */
    public static function inCreationOrder(EloquentCollection|Collection $rows): Collection
    {
        return $rows->sort(function ($a, $b) {
            $ta = $a->created_at ? $a->created_at->getTimestamp() : 0;
            $tb = $b->created_at ? $b->created_at->getTimestamp() : 0;

            return [$ta, (int) $a->id] <=> [$tb, (int) $b->id];
        })->values();
    }

    /** Engine output for one visit (relations stops + jobs are loaded on demand). */
    public static function calc(ServiceVisit $visit, array $ctx): array
    {
        $visit->loadMissing(['stops', 'jobs']);
        // The relations have no ORDER BY; the JS engine works in creation order and sinfo[i] / si[i] are matched to rows by index.
        $stops = self::inCreationOrder($visit->stops)->map(fn ($s) => self::stopArray($s))->values()->all();
        $jobs = self::inCreationOrder($visit->jobs)->map(fn ($j) => self::jobArray($j))->values()->all();
        $va = self::visitArray($visit);
        $out = TurnaroundCalculator::calc($va, $stops, $jobs, self::engineContext($ctx, $va));
        $out['stops'] = $stops;
        $out['jobs'] = $jobs;
        return $out;
    }

    /**
     * Engine output for many visits, keyed by visit uuid. One context is built for the whole batch.
     *
     * @param EloquentCollection|Collection $visits
     */
    public static function calcMany($visits, string $companyUuid, ?array $settings = null, ?array $ctx = null): array
    {
        if ($visits instanceof EloquentCollection) {
            $visits->loadMissing(['stops', 'jobs']);
        }
        $ctx = $ctx ?? self::context($companyUuid, $settings);
        $out = [];
        foreach ($visits as $v) {
            $out[$v->uuid] = self::calc($v, $ctx);
        }
        return $out;
    }

    /**
     * Visits whose arrival (or return) date, read in the company timezone, falls in [from, to].
     */
    public static function visitsInRange(string $companyUuid, ?string $from, ?string $to, string $basis = 'arrived', ?array $settings = null, array $with = ['stops', 'jobs']): EloquentCollection
    {
        $tz = self::tz($settings);
        $col = $basis === 'returned' ? 'returned_at' : 'arrived_at_lsa';
        $q = ServiceVisit::where('company_uuid', $companyUuid)->with($with);
        if ($from || $to) {
            $q->whereNotNull($col);
            if ($from) {
                $q->where($col, '>=', Carbon::parse($from, $tz)->startOfDay()->utc());
            }
            if ($to) {
                $q->where($col, '<=', Carbon::parse($to, $tz)->endOfDay()->utc());
            }
        }
        return $q->orderBy($col)->orderBy('id')->get();
    }

    /** Resolve a preset (today|7|30|month|lastmonth) to [from, to] ISO dates in the company timezone. */
    public static function periodPreset(string $key, ?string $tz = null): ?array
    {
        $tz = $tz ?: self::tz();
        $now = Carbon::now($tz);
        return match ($key) {
            'today' => [$now->toDateString(), $now->toDateString()],
            '7', 'last7' => [$now->copy()->subDays(6)->toDateString(), $now->toDateString()],
            '30', 'last30' => [$now->copy()->subDays(29)->toDateString(), $now->toDateString()],
            'month' => [$now->copy()->startOfMonth()->toDateString(), $now->toDateString()],
            'lastmonth' => [$now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => null,
        };
    }
}
