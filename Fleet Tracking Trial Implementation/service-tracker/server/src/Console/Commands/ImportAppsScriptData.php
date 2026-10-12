<?php

namespace Jalal\ServiceTracker\Console\Commands;

use Carbon\Carbon;
use Fleetbase\Models\File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jalal\ServiceTracker\Models\DamageRecord;
use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;
use Jalal\ServiceTracker\Models\ServiceJob;
use Jalal\ServiceTracker\Models\ServiceLane;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Models\VendorStop;
use Jalal\ServiceTracker\Services\AppsScriptMapper;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Imports the Apps Script tracker's Drive data (fleet-data.json + photos/ folder) into Service Tracker.
 *
 *   php artisan service-tracker:import --company=<uuid> --data=/path/fleet-data.json --photos=/path/photos [--dry-run]
 *
 * - accepts the Drive db ({visits:{id:rec},..., tomb:{'coll:id':{t,r}}, settings, catalog}) and the app's backup
 *   file ({state:{collections as arrays}, photos}); ALL shape handling lives in Services/AppsScriptMapper (pure, checked
 *   against the real production data by tests/realdata) - this command only resolves uuids and writes rows
 * - tombstoned records are skipped (tomb.t >= record._u); records without a visit are skipped and reported
 * - plates keep their display form (trimmed, upper case); matching uses the plate key (no spaces / dashes)
 * - vendors used by the data are created as FleetOps vendors when missing (--no-create-vendors to only match by name);
 *   lanes come from settings; a catalog item is created for every service name the jobs use; staff are matched to users by name
 * - petty cash is stored in minor units (rounded half up); odometer '' / non numeric => NULL
 * - local Asia/Riyadh timestamps are converted to UTC (--tz to override)
 * - every record keeps its old id in legacy_ref, so re-running updates in place and never duplicates
 * - --dry-run runs the whole import inside a transaction that is rolled back (no files written)
 * - ends with a reconciliation: counts, and per visit turnaround / stage hours / overdue count / petty total
 *   computed by the engine on the mapped source rows and on the imported database rows
 * FleetOps side effects (maintenance, schedules, issues) are NOT triggered for imported visits.
 */
class ImportAppsScriptData extends Command
{
    protected $signature = 'service-tracker:import
        {--company= : Company uuid or public id (required)}
        {--data= : Path to fleet-data.json (or an app backup .json)}
        {--photos= : Folder with <photoId>.jpg files}
        {--tz=Asia/Riyadh : Timezone of the legacy local timestamps}
        {--dry-run : Do everything, then roll back and write nothing}
        {--skip-settings : Do not touch settings / lanes / catalog}
        {--no-create-vendors : Do not create missing FleetOps vendors (names are still kept on stops / jobs)}
        {--report= : Write the reconciliation as JSON to this file}';

    protected $description = 'Import Apps Script tracker data (fleet-data.json + photos) into Service Tracker, idempotently.';

    protected const TOL = 0.011;

    protected string $company = '';
    protected string $tz = 'Asia/Riyadh';
    protected bool $dry = false;
    protected ?string $photoDir = null;
    protected array $inlinePhotos = [];
    protected array $warnings = [];
    protected array $stats = [];

    /** @var array<string,string> lower-case name => uuid */
    protected array $vendorByName = [];
    protected array $userByName = [];
    protected array $vehicleByKey = [];
    protected array $laneByName = [];
    protected array $catalogByName = [];   // exact service name => uuid
    protected array $visitUuid = [];       // legacy id => uuid
    /** @var array<string,array<string,Model>> kind (visits|damages|expenses) => legacy id => model */
    protected array $subjects = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $this->tz = (string) $this->option('tz');
        if (!in_array($this->tz, \DateTimeZone::listIdentifiers(), true)) {
            $this->error('Unknown timezone ' . $this->tz);

            return self::FAILURE;
        }
        $path = (string) $this->option('data');
        if (!$path || !is_file($path)) {
            $this->error('--data must point to fleet-data.json');

            return self::FAILURE;
        }
        $this->photoDir = $this->option('photos') ? rtrim((string) $this->option('photos'), '/\\') : null;
        if ($this->photoDir && !is_dir($this->photoDir)) {
            $this->error('--photos folder not found: ' . $this->photoDir);

            return self::FAILURE;
        }
        $company = $this->resolveCompany((string) $this->option('company'));
        if (!$company) {
            $this->error('--company must be an existing company uuid or public id.');

            return self::FAILURE;
        }
        $this->company = $company;
        Session::put('company', $company);

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            $this->error('Could not parse JSON: ' . json_last_error_msg());

            return self::FAILURE;
        }
        $mapper = new AppsScriptMapper($this->tz);
        $map = $mapper->map($raw);
        $this->inlinePhotos = $map['inline_photos'];
        foreach ($map['notes'] as $n) {
            $this->warnings[] = '[' . $n['code'] . '] ' . $n['where'] . ': ' . $n['message'];
        }
        $this->info(sprintf('Source (rev %s): %d visits, %d stops, %d jobs, %d damages, %d expenses; %d tombstoned records skipped, %d catalog items (%d to create from job names)',
            $map['rev'] ?? '?', count($map['visits']), count($map['stops']), count($map['jobs']), count($map['damages']), count($map['petty']),
            array_sum(array_map('count', $map['skipped'])), count($map['catalog']), count(array_filter($map['catalog'], fn ($c) => $c['auto']))));

        $this->stats = [];
        $report = null;

        try {
            DB::beginTransaction();
            $this->loadLookups();
            $this->importConfig($map);
            $this->importRecords($map);
            $report = $this->reconcile($map);
            if ($this->dry) {
                DB::rollBack();
                $this->warn('--dry-run: everything above was rolled back, nothing was written.');
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import failed and was rolled back: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report);
        if ($this->option('report')) {
            file_put_contents((string) $this->option('report'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('Reconciliation written to ' . $this->option('report'));
        }
        foreach (array_slice($this->warnings, 0, 60) as $w) {
            $this->warn(' - ' . $w);
        }
        if (count($this->warnings) > 60) {
            $this->warn(' ... and ' . (count($this->warnings) - 60) . ' more warnings');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /* ------------------------------------------------------------ helpers */

    protected function resolveCompany(string $id): ?string
    {
        if ($id === '' || !class_exists(\Fleetbase\Models\Company::class)) {
            return $id !== '' ? $id : null;
        }
        $c = \Fleetbase\Models\Company::where('uuid', $id)->orWhere('public_id', $id)->first();

        return $c ? $c->uuid : null;
    }

    /** mapper ISO-UTC string -> Carbon (UTC) */
    protected function at(?string $iso): ?Carbon
    {
        return $iso ? Carbon::parse($iso, 'UTC')->utc() : null;
    }

    protected function lc(?string $s): string
    {
        return mb_strtolower(trim((string) $s));
    }

    /* ------------------------------------------------------------ lookups */

    protected function loadLookups(): void
    {
        $vendor = '\\Fleetbase\\FleetOps\\Models\\Vendor';
        if (class_exists($vendor)) {
            foreach ($vendor::where('company_uuid', $this->company)->get(['uuid', 'name']) as $v) {
                $this->vendorByName[$this->lc($v->name)] = $v->uuid;
            }
        }
        if (class_exists(\Fleetbase\Models\User::class) && class_exists(\Fleetbase\Models\CompanyUser::class)) {
            $uids = \Fleetbase\Models\CompanyUser::where('company_uuid', $this->company)->pluck('user_uuid');
            foreach (\Fleetbase\Models\User::whereIn('uuid', $uids)->get(['uuid', 'name']) as $u) {
                $this->userByName[$this->lc($u->name)] = $u->uuid;
            }
        }
        $vehicle = '\\Fleetbase\\FleetOps\\Models\\Vehicle';
        if (class_exists($vehicle)) {
            foreach ($vehicle::where('company_uuid', $this->company)->get(['uuid', 'plate_number']) as $v) {
                $k = VisitMapper::plateKey($v->plate_number);
                if ($k !== '') {
                    $this->vehicleByKey[$k] = $v->uuid;
                }
            }
        }
    }

    /* ------------------------------------------------------------ write helpers */

    /** @return array{0:Model,1:bool} model and whether it was created */
    protected function upsert(string $class, string $legacy, array $attrs, string $stat, array $adopt = []): array
    {
        $q = $class::where('company_uuid', $this->company)->where('legacy_ref', $legacy);
        if (method_exists($class, 'withTrashed')) {
            $q = $class::withTrashed()->where('company_uuid', $this->company)->where('legacy_ref', $legacy);
        }
        $m = $q->first();
        if (!$m && $adopt) {
            // rows that ServiceTrackerSeeder created (lanes, catalog) carry no legacy_ref: adopt them by name instead of duplicating
            $m = $class::where('company_uuid', $this->company)->whereNull('legacy_ref')->where($adopt)->first();
            if ($m) {
                $m->forceFill(['legacy_ref' => $legacy]);
                $this->stats[$stat]['adopted'] = ($this->stats[$stat]['adopted'] ?? 0) + 1;
            }
        }
        $created = false;
        if (!$m) {
            $m = new $class();
            $m->forceFill(['company_uuid' => $this->company, 'legacy_ref' => $legacy]);
            $created = true;
        } elseif (method_exists($m, 'trashed') && $m->trashed()) {
            $this->stats[$stat]['skipped_deleted'] = ($this->stats[$stat]['skipped_deleted'] ?? 0) + 1;

            return [$m, false];
        }
        $m->forceFill($attrs);
        $m->save();
        $key = $created ? 'created' : 'updated';
        $this->stats[$stat][$key] = ($this->stats[$stat][$key] ?? 0) + 1;

        return [$m, $created];
    }

    /** created_at from the mapper (source `created`, or synthesised so that created_at order == source array order) */
    protected function createdAt(array $row): array
    {
        return !empty($row['created_ms']) ? ['created_at' => Carbon::createFromTimestampMs((int) $row['created_ms'])->utc()] : [];
    }

    protected function vendorUuid(?string $name, string $where): ?string
    {
        $k = $this->lc($name);
        if ($k === '') {
            return null;
        }
        if (!isset($this->vendorByName[$k])) {
            $this->warnings['vendor:' . $k] = 'vendor "' . $name . '" is not a FleetOps vendor (name kept, vendor_uuid empty) - ' . $where;

            return null;
        }

        return $this->vendorByName[$k];
    }

    /** Create FleetOps vendors that the data uses but the company does not have yet. */
    protected function ensureVendors(array $vendors): void
    {
        $class = '\\Fleetbase\\FleetOps\\Models\\Vendor';
        if ($this->option('no-create-vendors') || !class_exists($class)) {
            return;
        }
        foreach ($vendors as $v) {
            $k = $this->lc($v['name']);
            if ($k === '' || isset($this->vendorByName[$k])) {
                continue;
            }
            try {
                $m = new $class();
                $m->forceFill(VisitMapper::filterColumns($m->getTable(), ['company_uuid' => $this->company, 'name' => $v['name'], 'status' => 'active', 'type' => 'vendor']));
                $m->save();
                $this->vendorByName[$k] = $m->uuid;
                $this->stats['vendors']['created'] = ($this->stats['vendors']['created'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $this->warnings['vendorcreate:' . $k] = 'could not create FleetOps vendor "' . $v['name'] . '": ' . $e->getMessage();
            }
        }
    }

    /* ------------------------------------------------------------ config: settings, lanes, catalog */

    protected function importConfig(array $map): void
    {
        if ($this->option('skip-settings')) {
            foreach (ServiceLane::where('company_uuid', $this->company)->get() as $l) {
                $this->laneByName[$l->name] = $l->uuid;
            }
            foreach (ServiceCatalogItem::where('company_uuid', $this->company)->get() as $c) {
                $this->catalogByName[$c->name] ??= $c->uuid;
            }

            return;
        }
        $this->ensureVendors($map['vendors']);
        if ($map['settings']) {
            VisitMapper::saveSettings($map['settings']);
        }
        foreach ($map['lanes'] as $l) {
            [$m] = $this->upsert(ServiceLane::class, $l['legacy_ref'], ['name' => $l['name'], 'target_hours' => $l['target_hours'], 'sort' => $l['sort']], 'lanes', ['name' => $l['name']]);
            $this->laneByName[$l['name']] = $m->uuid;
        }
        foreach ($map['catalog'] as $c) {
            [$m] = $this->upsert(ServiceCatalogItem::class, $c['legacy_ref'], [
                'name' => $c['name'],
                'category' => $c['category'],
                'lane_uuid' => $c['lane'] !== '' ? ($this->laneByName[$c['lane']] ?? null) : null,
                'suggested_vendor_uuid' => $this->vendorUuid($c['vendor'], 'catalog "' . $c['name'] . '"'),
                'suggested_vendor_name' => $c['vendor'],
                'interval_km' => $c['interval_km'],
            ], $c['auto'] ? 'catalog_from_jobs' : 'catalog', ['name' => $c['name']]);
            $this->catalogByName[$c['name']] = $m->uuid;
        }
    }

    /* ------------------------------------------------------------ records */

    protected function importRecords(array $map): void
    {
        ServiceVisit::withoutEvents(function () use ($map) {
            foreach ($map['visits'] as $v) {
                $this->importVisit($v);
            }
        });

        foreach ($map['stops'] as $s) {
            $this->upsert(VendorStop::class, $s['legacy_ref'], [
                'service_visit_uuid' => $this->visitUuid[$s['visit_legacy']],
                'vendor_uuid' => $this->vendorUuid($s['vendor_name'], 'stop ' . $s['legacy_ref']),
                'vendor_name' => $s['vendor_name'],
                'arrived_at' => $this->at($s['arrived_at']),
                'approval_requested_at' => $this->at($s['approval_requested_at']),
                'approval_received_at' => $this->at($s['approval_received_at']),
                'left_at' => $this->at($s['left_at']),
                'notes' => $s['notes'],
            ] + $this->createdAt($s), 'stops');
        }

        foreach ($map['jobs'] as $j) {
            $this->upsert(ServiceJob::class, $j['legacy_ref'], [
                'service_visit_uuid' => $this->visitUuid[$j['visit_legacy']],
                'catalog_item_uuid' => $this->catalogByName[$j['service_name']] ?? null,
                'service_name' => $j['service_name'],
                'vendor_uuid' => $this->vendorUuid($j['vendor_name'], 'service ' . $j['legacy_ref']),
                'vendor_name' => $j['vendor_name'],
                'approval_needed' => $j['approval_needed'],
                'status' => $j['status'],
                'parts_replaced' => $j['parts_replaced'],
                'next_due_override_km' => $j['next_due_override_km'],
                'next_due_date' => $j['next_due_date'],
                'notes' => $j['notes'],
            ] + $this->createdAt($j), 'jobs');
        }

        foreach ($map['damages'] as $d) {
            $vu = $this->visitUuid[$d['visit_legacy']];
            $visit = $this->subjects['visits'][$d['visit_legacy']] ?? null;
            $notes = $d['notes'];
            if ($d['by'] !== '' && !isset($this->userByName[$this->lc($d['by'])])) {
                $notes = trim($notes . ' [recorded by: ' . $d['by'] . ']');
            }
            if ($d['photo_ref'] !== '' && !str_contains($notes, $d['photo_ref'])) {
                $notes = trim($notes . ' [photo ref: ' . $d['photo_ref'] . ']');
            }
            [$m] = $this->upsert(DamageRecord::class, $d['legacy_ref'], [
                'service_visit_uuid' => $vu,
                'vehicle_uuid' => optional($visit)->vehicle_uuid,
                'stage' => $d['stage'],
                'location' => $d['location'],
                'damage_type' => $d['damage_type'],
                'severity' => $d['severity'],
                'condition' => $d['condition'],
                'occurred_at' => $this->at($d['occurred_at']),
                'recorded_by_uuid' => $this->userByName[$this->lc($d['by'])] ?? null,
                'client_notified' => $d['client_notified'],
                'notes' => $notes,
            ] + $this->createdAt($d), 'damages');
            $this->subjects['damages'][$d['legacy_ref']] = $m;
        }

        foreach ($map['petty'] as $e) {
            $vu = $e['visit_legacy'] ? ($this->visitUuid[$e['visit_legacy']] ?? null) : null;
            $visit = $e['visit_legacy'] ? ($this->subjects['visits'][$e['visit_legacy']] ?? null) : null;
            [$m] = $this->upsert(PettyCashEntry::class, $e['legacy_ref'], [
                'entry_date' => $e['entry_date'],
                'vehicle_uuid' => $this->vehicleByKey[VisitMapper::plateKey($e['plate'])] ?? optional($visit)->vehicle_uuid,
                'plate' => $e['plate'],
                'service_visit_uuid' => $vu,
                'category' => $e['category'],
                'amount' => $e['amount'],
                'currency' => $e['currency'],
                'paid_by' => $e['paid_by'],
                'description' => $e['description'],
            ] + $this->createdAt($e), 'petty_cash');
            $this->subjects['expenses'][$e['legacy_ref']] = $m;
        }

        foreach ($map['photos'] as $p) {
            $subject = $this->subjects[$p['subject']][$p['id']] ?? null;
            if ($subject && !(method_exists($subject, 'trashed') && $subject->trashed())) {
                $this->importPhotos($subject, $p['type'], $p['ids']);
            }
        }
    }

    protected function importVisit(array $v): void
    {
        $techUuid = $v['tech'] !== '' ? ($this->userByName[$this->lc($v['tech'])] ?? null) : null;
        $notes = $v['notes'];
        if ($v['tech'] !== '' && !$techUuid) {
            $notes = trim($notes . ' [legacy technician: ' . $v['tech'] . ']');
        }

        [$m] = $this->upsert(ServiceVisit::class, $v['legacy_ref'], [
            'plate' => $v['plate'],
            'make_model' => $v['make_model'],
            'vehicle_uuid' => $this->vehicleByKey[VisitMapper::plateKey($v['plate'])] ?? null,
            'technician_uuid' => $techUuid,
            'odometer' => $v['odometer'],
            'arrived_at_lsa' => $this->at($v['arrived_at_lsa']),
            'picked_up_at' => $this->at($v['picked_up_at']),
            'returned_at' => $this->at($v['returned_at']),
            'pickup_inspected' => $v['pickup_inspected'],
            'return_inspected' => $v['return_inspected'],
            'notes' => $notes,
            'status' => $v['status'],
        ] + $this->createdAt($v), 'visits');
        $this->visitUuid[$v['legacy_ref']] = $m->uuid;
        $this->subjects['visits'][$v['legacy_ref']] = $m;
    }

    /** Attach legacy photo ids (files in --photos, or inline data URLs of a backup) as core File rows; idempotent per photo id. */
    protected function importPhotos(Model $subject, string $type, array $ids): void
    {
        foreach (array_values(array_filter($ids)) as $pid) {
            $pid = (string) $pid;
            $fname = $pid . '.jpg';
            $exists = File::where('subject_uuid', $subject->uuid)->where('type', $type)->where('original_filename', $fname)->exists();
            if ($exists) {
                $this->stats['photos']['existing'] = ($this->stats['photos']['existing'] ?? 0) + 1;
                continue;
            }
            $bytes = null;
            $path = $this->photoDir ? $this->photoDir . '/' . $fname : null;
            if ($path && is_file($path)) {
                $bytes = file_get_contents($path);
            } elseif (!empty($this->inlinePhotos[$pid]) && preg_match('#^data:([^;]+);base64,(.*)$#s', (string) $this->inlinePhotos[$pid], $mm)) {
                $bytes = base64_decode($mm[2], true) ?: null;
            }
            if ($bytes === null) {
                $this->stats['photos']['missing'] = ($this->stats['photos']['missing'] ?? 0) + 1;
                $this->warnings[] = '[photo_missing] photo ' . $pid . ' not found (' . $type . ')';
                continue;
            }
            if (!$this->dry) {
                $disk = config('filesystems.default', 'local');
                $rel = 'uploads/' . $this->company . '/service-tracker/' . Str::after($type, ':') . '/legacy/' . Str::uuid() . '.jpg';
                Storage::disk($disk)->put($rel, $bytes);
                $attrs = VisitMapper::filterColumns('files', [
                    'company_uuid' => $this->company, 'disk' => $disk, 'path' => $rel, 'folder' => dirname($rel),
                    'original_filename' => $fname, 'content_type' => 'image/jpeg', 'file_size' => strlen($bytes), 'type' => $type,
                    'subject_uuid' => $subject->uuid, 'subject_type' => $subject->getMorphClass(),
                ]);
                $f = new File();
                $f->forceFill($attrs)->save();
            }
            $this->stats['photos']['imported'] = ($this->stats['photos']['imported'] ?? 0) + 1;
        }
    }

    /* ------------------------------------------------------------ reconciliation */

    protected function metrics(array $c): array
    {
        $r = fn ($x) => $x === null ? null : round((float) $x, 2);

        return [
            'total' => $r($c['total'] ?? null),
            'stages' => array_map($r, array_slice((array) ($c['stages'] ?? []), 0, 6)),
            'overdue' => (int) ($c['overdueN'] ?? 0),
            'status' => $c['status'] ?? null,
        ];
    }

    protected function reconcile(array $map): array
    {
        $now = Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $srcCalcs = AppsScriptMapper::calcAll($map, $now);

        // petty totals per visit, source rows (minor units) vs db
        $srcPetty = [];
        foreach ($map['petty'] as $e) {
            if ($e['visit_legacy']) {
                $srcPetty[$e['visit_legacy']] = ($srcPetty[$e['visit_legacy']] ?? 0) + $e['amount'];
            }
        }

        $dbVisits = ServiceVisit::where('company_uuid', $this->company)->whereIn('uuid', array_values($this->visitUuid))->with(['stops', 'jobs'])->get()->keyBy('uuid');
        $ctx = VisitMapper::context($this->company, null);
        $ctx['now'] = $now;
        $dbPetty = PettyCashEntry::where('company_uuid', $this->company)->whereNotNull('service_visit_uuid')->get()->groupBy('service_visit_uuid')->map(fn ($g) => (int) $g->sum('amount'));

        $diffs = [];
        $perVisit = [];
        foreach ($map['visits'] as $legacy => $_) {
            $uuid = $this->visitUuid[$legacy] ?? null;
            $db = $uuid ? $dbVisits->get($uuid) : null;
            if (!$db) {
                $diffs[] = ['visit' => $legacy, 'field' => 'imported', 'source' => 'present', 'db' => 'missing'];
                continue;
            }
            $a = $this->metrics($srcCalcs[$legacy]);
            $b = $this->metrics(VisitMapper::calc($db, $ctx));
            $a['petty_minor'] = (int) ($srcPetty[$legacy] ?? 0);
            $b['petty_minor'] = (int) ($dbPetty[$uuid] ?? 0);
            $perVisit[$legacy] = ['public_id' => $db->public_id, 'source' => $a, 'db' => $b];

            foreach (['total', 'overdue', 'status', 'petty_minor'] as $f) {
                if (!$this->same($a[$f], $b[$f])) {
                    $diffs[] = ['visit' => $legacy, 'field' => $f, 'source' => $a[$f], 'db' => $b[$f]];
                }
            }
            foreach ($a['stages'] as $i => $x) {
                if (!$this->same($x, $b['stages'][$i] ?? null)) {
                    $diffs[] = ['visit' => $legacy, 'field' => 'stage:' . VisitMapper::STAGE_SHORT[$i], 'source' => $x, 'db' => $b['stages'][$i] ?? null];
                }
            }
        }

        $counts = [];
        $skip = (bool) $this->option('skip-settings');
        $pairs = ['visits' => [ServiceVisit::class, $map['visits']], 'stops' => [VendorStop::class, $map['stops']], 'services' => [ServiceJob::class, $map['jobs']],
            'damages' => [DamageRecord::class, $map['damages']], 'expenses' => [PettyCashEntry::class, $map['petty']],
            'catalog' => [ServiceCatalogItem::class, $skip ? [] : $map['catalog']], 'lanes' => [ServiceLane::class, $skip ? [] : $map['lanes']]];
        foreach ($pairs as $coll => [$class, $rows]) {
            $legacyIds = array_values(array_map(fn ($r) => $r['legacy_ref'], $rows));
            $inDb = $legacyIds ? $class::where('company_uuid', $this->company)->whereIn('legacy_ref', $legacyIds)->count() : 0;
            $counts[$coll] = ['source' => count($rows), 'db' => $inDb, 'match' => count($rows) === $inDb];
        }
        $counts['source_tombstones_skipped'] = array_sum(array_map('count', $map['skipped']));

        $ok = !$diffs && !array_filter($counts, fn ($c) => is_array($c) && !$c['match']);

        return ['ok' => $ok, 'dry_run' => $this->dry, 'counts' => $counts, 'actions' => $this->stats, 'differences' => $diffs, 'per_visit' => $perVisit, 'engine_now' => $now, 'data_quality' => $map['notes']];
    }

    protected function same($a, $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) <= self::TOL : $a === $b;
    }

    protected function printReport(array $r): void
    {
        $this->newLine();
        $this->info('Import actions' . ($r['dry_run'] ? ' (dry run)' : ''));
        $rows = [];
        foreach ($r['actions'] as $k => $a) {
            $rows[] = [$k, $a['created'] ?? 0, $a['updated'] ?? 0, implode(', ', array_map(fn ($kk, $vv) => "$kk=$vv", array_keys(array_diff_key($a, ['created' => 1, 'updated' => 1])), array_values(array_diff_key($a, ['created' => 1, 'updated' => 1]))))];
        }
        $this->table(['collection', 'created', 'updated', 'other'], $rows);

        $this->info('Reconciliation: counts');
        $this->table(['collection', 'source', 'database', 'match'], collect($r['counts'])->filter(fn ($c) => is_array($c))->map(fn ($c, $k) => [$k, $c['source'], $c['db'], $c['match'] ? 'yes' : 'NO'])->values()->all());

        $n = count($r['per_visit']);
        $this->info("Reconciliation: {$n} visits compared (turnaround, 6 stage hours, overdue jobs, petty total)");
        if ($r['differences']) {
            $this->error(count($r['differences']) . ' difference(s):');
            $this->table(['visit', 'field', 'source', 'database'], array_map(fn ($d) => [$d['visit'], $d['field'], json_encode($d['source']), json_encode($d['db'])], array_slice($r['differences'], 0, 40)));
        } else {
            $this->info('No differences.');
        }
    }
}
