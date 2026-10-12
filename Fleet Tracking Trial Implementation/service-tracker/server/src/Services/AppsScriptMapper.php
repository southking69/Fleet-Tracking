<?php

namespace Jalal\ServiceTracker\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure mapping layer of the Apps Script -> Service Tracker import (no Laravel, no database, no Carbon).
 *
 * Input : the decoded fleet-data.json (Drive db) or an app backup ({format, state:{...}, photos}).
 * Output: rows named after the Service Tracker columns, relations expressed through legacy ids; the importer
 *         (Console/Commands/ImportAppsScriptData) only has to resolve uuids and write them, and the parity
 *         harness (tests/realdata) can run the same rows through the calculation engine without a database.
 *
 * Source shape handled (verified on the production Drive db, rev 107):
 *   - collections are OBJECTS keyed by id ({"V-ABD0001": {...}}) in the Drive db, ARRAYS in the app backup;
 *   - tomb values are {t, r} objects (server side) or bare numbers (client side / backup);
 *   - a record is dropped when tomb[coll:id].t >= record._u (Code.gs applyChanges_ / Index.html mergeRemote);
 *   - odo is '' or a number (or a numeric string); plates carry trailing spaces / dashes ("4473 RKR ", "3877-EER");
 *   - catalog may hold services outside the 150 seeds, and jobs may use services that are not in the catalog at all.
 */
final class AppsScriptMapper
{
    public const COLLECTIONS = ['visits', 'stops', 'services', 'damages', 'expenses'];

    public const SVC_STATUSES = ['Pending', 'In progress', 'Done'];
    public const DAMAGE_STAGES = ['Pickup inspection', 'Return inspection', 'Vendor reported'];
    public const SEVERITIES = ['Minor', 'Moderate', 'Major'];
    public const CONDITIONS = ['Pre-existing', 'New'];

    /** Settings keys carried over verbatim into the Service Tracker settings document. */
    public const SETTINGS_KEEP = ['company', 'client', 'staff', 'categories', 'damageLocations', 'expenseCats', 'currency', 'damageTypes', 'severities', 'stages', 'svcStatuses', 'reportOpts', 'excelTiming', 'excelPetty', 'excelVehicles'];

    /** @var list<array{code:string,where:string,message:string}> data quality / mapping notes of the last map() */
    private array $dq = [];

    public function __construct(private string $tz = 'Asia/Riyadh', private ?string $today = null)
    {
        new DateTimeZone($tz); // throws on an unknown zone
    }

    /* ------------------------------------------------------------------ scalars */

    /** JS truthiness for the "Yes"/"No"/'' / bool flags the tracker stores. */
    public static function truthy(mixed $v): bool
    {
        return is_bool($v) ? $v : in_array(strtolower(trim((string) $v)), ['yes', 'y', 'true', '1', 'x'], true);
    }

    /** JS num(): '' / null / non numeric => null. */
    public static function num(mixed $v): int|float|null
    {
        return StopTimer::num($v);
    }

    /** Major units (number or numeric string) -> minor units. Non numeric counts as 0 (the JS sum() ignores it too). */
    public static function cents(mixed $v): int
    {
        $n = self::num($v);
        if ($n === null || !is_finite((float) $n)) {
            return 0;
        }

        // round() to 2 places first: PHP pre-rounds, so 1.005 -> 1.01 and 19.995 -> 20.00 instead of 100.49999999999999
        return (int) round(round((float) $n, 2) * 100);
    }

    public static function plateKey(?string $plate): string
    {
        return VisitMapper::plateKey($plate);
    }

    /** Display plate: upper case, whitespace collapsed, trimmed. Dashes are kept ("3877-EER"); matching uses plateKey(). */
    public static function cleanPlate(mixed $plate): string
    {
        return VisitMapper::cleanPlate(is_scalar($plate) ? (string) $plate : '');
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /** Local (company zone) legacy datetime -> UTC 'Y-m-d\TH:i:s\Z'. Explicit Z / offsets are honoured. */
    public function utcIso(mixed $local, string $where = ''): ?string
    {
        if ($local === null || $local === '' || $local === false) {
            return null;
        }
        $s = trim($this->str($local));
        if ($s === '') {
            return null;
        }
        $zone = new DateTimeZone($this->tz);
        try {
            if (preg_match('/^\d{4}-\d\d-\d\d$/', $s)) {
                $this->note('date_only', $where, 'date without time "' . $s . '" read as local midnight');
                $d = new DateTimeImmutable($s . ' 00:00:00', $zone);
            } elseif (preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d(:\d\d(\.\d+)?)?$/', $s)) {
                $d = new DateTimeImmutable($s, $zone);
            } else {
                $d = new DateTimeImmutable($s, $zone);
            }
            // DateTimeImmutable silently rolls 2026-02-30 / 25:00 over; the JS parser rejects hours > 24, mirror that
            if (preg_match('/[T ](\d\d):(\d\d)/', $s, $m) && ((int) $m[1] > 24 || (int) $m[2] > 59)) {
                throw new \InvalidArgumentException('bad time');
            }

            return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            $this->note('bad_datetime', $where, 'unparseable datetime "' . $s . '" ignored');

            return null;
        }
    }

    private function note(string $code, string $where, string $message): void
    {
        $this->dq[] = ['code' => $code, 'where' => $where, 'message' => $message];
    }

    /** @return list<array{code:string,where:string,message:string}> */
    public function notes(): array
    {
        return $this->dq;
    }

    /* ------------------------------------------------------------------ source normalisation */

    /** tomb value -> timestamp. Accepts {t,r}, a bare number, a numeric string. */
    public static function tombT(mixed $t): ?float
    {
        if (is_array($t)) {
            $t = $t['t'] ?? null;
        }

        return (is_int($t) || is_float($t) || (is_string($t) && is_numeric($t))) ? (float) $t : null;
    }

    /**
     * Drive db / app backup -> {settings, catalog, <collection> => [id => record], skipped, photos, rev}.
     * Collections may be objects keyed by id or lists of records; records are indexed by their own id.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function normaliseSource(array $raw): array
    {
        $db = isset($raw['state']) && is_array($raw['state']) ? $raw['state'] : $raw;
        $tomb = is_array($db['tomb'] ?? null) ? $db['tomb'] : [];
        $out = [
            'settings' => is_array($db['settings'] ?? null) ? $db['settings'] : [],
            'catalog' => $this->listOf($db['catalog'] ?? []),
            'photos' => (isset($raw['photos']) && is_array($raw['photos'])) ? $raw['photos'] : [],
            'rev' => $db['rev'] ?? null,
            'skipped' => [],
        ];
        foreach (self::COLLECTIONS as $coll) {
            $rows = [];
            $out['skipped'][$coll] = [];
            foreach ($this->listOf($db[$coll] ?? [], true) as $k => $r) {
                if (!is_array($r)) {
                    continue;
                }
                $id = $r['id'] ?? (is_string($k) ? $k : null);
                if ($id === null || $id === '') {
                    $this->note('no_id', $coll, 'record without an id skipped');
                    continue;
                }
                $r['id'] = $id = (string) $id;
                $t = self::tombT($tomb[$coll . ':' . $id] ?? null);
                $u = self::num($r['_u'] ?? null) ?? 0;
                if ($t !== null && $t >= $u) {
                    $out['skipped'][$coll][] = $id;
                    continue;
                }
                if (isset($rows[$id])) { // list form with a duplicate id: the newer _u wins, first wins ties (mergeRemote)
                    $this->note('duplicate_id', $coll . ':' . $id, 'duplicate record id, newest kept');
                    if ($u <= (self::num($rows[$id]['_u'] ?? null) ?? 0)) {
                        continue;
                    }
                }
                $rows[$id] = $r;
            }
            $out[$coll] = $rows;
        }

        return $out;
    }

    /** object-or-array -> array preserving keys of an object, 0..n of a list. */
    private function listOf(mixed $v, bool $keepKeys = false): array
    {
        if (!is_array($v)) {
            return [];
        }

        return $keepKeys ? $v : array_values($v);
    }

    /* ------------------------------------------------------------------ the mapping */

    /**
     * @param array<string,mixed> $raw decoded JSON
     * @return array<string,mixed>
     */
    public function map(array $raw): array
    {
        $this->dq = [];
        $src = $this->normaliseSource($raw);
        $settings = $src['settings'];
        $currency = strtoupper(substr(trim($this->str($settings['currency'] ?? '')) ?: 'SAR', 0, 3));
        $today = $this->today ?? (new DateTimeImmutable('now', new DateTimeZone($this->tz)))->format('Y-m-d');
        foreach ($src['skipped'] as $coll => $ids) {
            foreach ($ids as $id) {
                $this->note('tombstoned', $coll . ':' . $id, 'deleted in the tracker (tomb >= record) - not imported');
            }
        }

        /* ---- lanes (from settings; catalog lanes that are not in settings are NOT invented: a 0 target would differ from the tracker's null) */
        $lanes = [];
        $laneNames = [];
        foreach (array_values((array) ($settings['lanes'] ?? [])) as $i => $l) {
            $name = is_array($l) ? $this->str($l['name'] ?? '') : '';
            if ($name === '' || isset($laneNames[$name])) {
                continue;
            }
            $t = self::num($l['target'] ?? null);
            $laneNames[$name] = true;
            $lanes[] = ['legacy_ref' => 'lane:' . $name, 'name' => $name, 'target_hours' => $t === null ? 0.0 : (float) $t, 'sort' => count($lanes)];
            if ($t === null) {
                $this->note('lane_no_target', 'lane:' . $name, 'lane has no numeric target, stored as 0');
            }
        }

        /* ---- catalog: first entry per exact name wins (JS catalog.find), plus auto items for services used by jobs */
        $catalog = [];
        foreach ($src['catalog'] as $i => $c) {
            $name = is_array($c) ? $this->str($c['service'] ?? '') : '';
            if (trim($name) === '') {
                continue;
            }
            if (isset($catalog[$name])) {
                $this->note('duplicate_catalog', 'catalog:' . $name, 'duplicate catalog name, first entry kept (as the tracker does)');
                continue;
            }
            $lane = $this->str($c['lane'] ?? '');
            if ($lane !== '' && !isset($laneNames[$lane])) {
                $this->note('unknown_lane', 'catalog:' . $name, 'lane "' . $lane . '" is not in settings.lanes, catalog item imported without a lane');
                $lane = '';
            }
            $iv = self::num($c['interval'] ?? null);
            $catalog[$name] = [
                'legacy_ref' => 'cat:' . $name, 'name' => $name, 'category' => $this->str($c['category'] ?? ''), 'lane' => $lane,
                'vendor' => $this->str($c['vendor'] ?? ''), 'interval_km' => $iv !== null && $iv > 0 ? (int) round((float) $iv) : 0, 'auto' => false,
            ];
        }

        /* ---- visits */
        $visits = [];
        $plates = []; // plate key => [display => count]
        foreach ($src['visits'] as $id => $v) {
            $where = 'visits:' . $id;
            $plate = self::cleanPlate($v['plate'] ?? '');
            if (trim($this->str($v['plate'] ?? '')) !== $this->str($v['plate'] ?? '')) {
                $this->note('plate_whitespace', $where, 'plate "' . $this->str($v['plate']) . '" has leading/trailing whitespace, trimmed');
            }
            if ($plate === '') {
                $this->note('no_plate', $where, 'visit has no plate number');
            } else {
                $plates[self::plateKey($plate)][$plate][] = $id;
            }
            $arrived = $this->utcIso($v['arrived'] ?? null, $where . '.arrived');
            $returned = $this->utcIso($v['returned'] ?? null, $where . '.returned');
            $odo = self::num($v['odo'] ?? null);
            if ($odo === null) {
                $this->note('missing_odometer', $where, 'no odometer reading (' . ($returned ? 'closed' : ($arrived ? 'open' : 'not started')) . ' visit): service-due check cannot run');
            }
            if ($arrived && !$returned) {
                $this->note('open_visit', $where, 'visit still open (arrived, not returned)');
            }
            $visits[$id] = [
                'legacy_ref' => $id, 'plate' => $plate, 'make_model' => $this->str($v['make'] ?? ''), 'tech' => trim($this->str($v['tech'] ?? '')),
                'odometer' => $odo === null ? null : (float) $odo,
                'arrived_at_lsa' => $arrived, 'picked_up_at' => $this->utcIso($v['picked'] ?? null, $where . '.picked'), 'returned_at' => $returned,
                'pickup_inspected' => self::truthy($v['pins'] ?? false), 'return_inspected' => self::truthy($v['rins'] ?? false),
                'notes' => trim($this->str($v['notes'] ?? '')),
                'status' => $returned ? 'closed' : ($arrived ? 'in_progress' : 'not_started'),
                'created_ms' => $this->createdMs($v),
            ];
        }
        foreach ($plates as $key => $variants) {
            if (count($variants) > 1) {
                $this->note('plate_variants', 'plate:' . $key, 'plate written in different ways: ' . implode(' | ', array_map(fn ($d, $ids) => '"' . $d . '" (' . implode(',', $ids) . ')', array_keys($variants), $variants)) . ' - grouped as one car by the plate key');
            }
        }

        /* ---- stops / jobs (children keep source order; created_at is synthesised so DB order == tracker array order) */
        $stops = [];
        $prevCreated = [];
        foreach ($src['stops'] as $id => $s) {
            $vid = $this->str($s['visitId'] ?? '');
            if (!isset($visits[$vid])) {
                $this->note('orphan', 'stops:' . $id, 'vendor stop skipped: visit "' . $vid . '" not found');
                continue;
            }
            $stops[$id] = [
                'legacy_ref' => $id, 'visit_legacy' => $vid, 'vendor_name' => $this->str($s['vendor'] ?? ''),
                'arrived_at' => $this->utcIso($s['arrived'] ?? null, "stops:$id.arrived"), 'approval_requested_at' => $this->utcIso($s['apprReq'] ?? null, "stops:$id.apprReq"),
                'approval_received_at' => $this->utcIso($s['apprRec'] ?? null, "stops:$id.apprRec"), 'left_at' => $this->utcIso($s['left'] ?? null, "stops:$id.left"),
                'notes' => $this->str($s['notes'] ?? ''),
                'created_ms' => $this->childCreated($s, $visits[$vid], 'stops:' . $vid, $prevCreated),
            ];
        }

        $jobs = [];
        $prevCreated = [];
        foreach ($src['services'] as $id => $s) {
            $vid = $this->str($s['visitId'] ?? '');
            if (!isset($visits[$vid])) {
                $this->note('orphan', 'services:' . $id, 'job skipped: visit "' . $vid . '" not found');
                continue;
            }
            $name = $this->str($s['service'] ?? '');
            $status = $this->str($s['status'] ?? '');
            if (!in_array($status, self::SVC_STATUSES, true)) {
                if ($status !== '') {
                    $this->note('bad_status', "services:$id", 'job status "' . $status . '" is not Pending / In progress / Done, imported as Pending');
                }
                $status = 'Pending';
            }
            $ov = self::num($s['nextOverride'] ?? null);
            if ($ov !== null && ($ov < 0 || !is_finite((float) $ov))) {
                $this->note('bad_override', "services:$id", 'next-due override ' . $ov . ' is negative, ignored');
                $ov = null;
            }
            $nd = $this->str($s['nextDate'] ?? '');
            $jobs[$id] = [
                'legacy_ref' => $id, 'visit_legacy' => $vid, 'service_name' => $name, 'vendor_name' => $this->str($s['vendor'] ?? ''),
                'approval_needed' => self::truthy($s['apprNeeded'] ?? false), 'status' => $status, 'parts_replaced' => $this->str($s['parts'] ?? ''),
                'next_due_override_km' => $ov === null ? null : (int) round((float) $ov),
                'next_due_date' => preg_match('/^\d{4}-\d\d-\d\d/', $nd) ? substr($nd, 0, 10) : null,
                'notes' => $this->str($s['notes'] ?? ''),
                'created_ms' => $this->childCreated($s, $visits[$vid], 'jobs:' . $vid, $prevCreated),
            ];
            if (trim($name) === '') {
                $this->note('blank_service', "services:$id", 'job without a service name');
            } elseif (!isset($catalog[$name])) {
                // the tracker lets a job carry a free-text service; it behaves as "no catalog entry" (no lane/category/interval).
                // The catalog item is created so the console can pick it, but with empty lane / category / interval to keep the engine identical.
                $catalog[$name] = ['legacy_ref' => 'cat:' . $name, 'name' => $name, 'category' => '', 'lane' => '', 'vendor' => '', 'interval_km' => 0, 'auto' => true];
                $this->note('unknown_service', "services:$id", 'service "' . $name . '" is not in the catalog - catalog item created (no lane / category / interval)');
            }
        }

        /* ---- damages */
        $damages = [];
        foreach ($src['damages'] as $id => $d) {
            $vid = $this->str($d['visitId'] ?? '');
            if (!isset($visits[$vid])) {
                $this->note('orphan', 'damages:' . $id, 'damage skipped: visit "' . $vid . '" not found');
                continue;
            }
            $stage = $this->str($d['stage'] ?? '');
            if (!in_array($stage, self::DAMAGE_STAGES, true)) {
                $this->note('bad_stage', "damages:$id", 'stage "' . $stage . '" unknown, imported as Pickup inspection');
                $stage = 'Pickup inspection';
            }
            $sev = $this->str($d['severity'] ?? '');
            $cond = $this->str($d['condition'] ?? '');
            $ids = $this->idList($d['photos'] ?? []);
            if (!empty($d['photo']) && is_string($d['photo'])) { // pre-photos[] data (Index.html migrate())
                $ids[] = $d['photo'];
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) {
                $this->note('damage_no_photo', "damages:$id", 'damage record without a photo');
            }
            $notified = $this->str($d['notified'] ?? '');
            $damages[$id] = [
                'legacy_ref' => $id, 'visit_legacy' => $vid, 'stage' => $stage, 'location' => $this->str($d['location'] ?? ''), 'damage_type' => $this->str($d['type'] ?? ''),
                'severity' => in_array($sev, self::SEVERITIES, true) ? $sev : null, 'condition' => in_array($cond, self::CONDITIONS, true) ? $cond : null,
                'occurred_at' => $this->utcIso($d['time'] ?? null, "damages:$id.time"), 'by' => trim($this->str($d['by'] ?? '')),
                'client_notified' => self::truthy($notified) ? 'Yes' : ($notified === '' ? null : 'No'),
                'notes' => trim($this->str($d['notes'] ?? '')), 'photo_ref' => trim($this->str($d['photoRef'] ?? '')),
                'photos' => $ids, 'created_ms' => $this->createdMs($d),
            ];
        }

        /* ---- expenses -> petty cash */
        $petty = [];
        foreach ($src['expenses'] as $id => $e) {
            $vid = $this->str($e['visitId'] ?? '');
            $plate = self::cleanPlate($e['plate'] ?? '');
            $raw = $e['amount'] ?? null;
            if (self::num($raw) === null && $raw !== null && $raw !== '') {
                $this->note('bad_amount', "expenses:$id", 'amount "' . $this->str($raw) . '" is not a number, counts as 0 (as in the tracker)');
            }
            $date = $this->str($e['date'] ?? '');
            if (!preg_match('/^\d{4}-\d\d-\d\d/', $date)) {
                $this->note('expense_no_date', "expenses:$id", 'expense without a valid date, dated ' . $today);
                $date = $today;
            }
            if ($vid !== '' && !isset($visits[$vid])) {
                $this->note('orphan', 'expenses:' . $id, 'visit "' . $vid . '" not found, expense imported unlinked');
                $vid = '';
            }
            if ($vid !== '') {
                $v = $visits[$vid];
                if ($plate !== '' && self::plateKey($plate) !== self::plateKey($v['plate'])) {
                    $this->note('expense_plate_mismatch', "expenses:$id", 'expense plate "' . $plate . '" differs from visit ' . $vid . ' plate "' . $v['plate'] . '"');
                }
                $from = substr((string) ($v['arrived_at_lsa'] ?? ''), 0, 10);
                $to = substr((string) ($v['returned_at'] ?? $v['arrived_at_lsa'] ?? ''), 0, 10);
                $d10 = substr($date, 0, 10);
                if ($from !== '' && ($d10 < $from || $d10 > ($to ?: $from))) {
                    $this->note('expense_date_outside_visit', "expenses:$id", 'expense dated ' . $d10 . ' is outside visit ' . $vid . ' (' . $from . ' .. ' . ($to ?: $from) . ')');
                }
            }
            $ids = $this->idList($e['receipts'] ?? []);
            $petty[$id] = [
                'legacy_ref' => $id, 'visit_legacy' => $vid !== '' ? $vid : null, 'entry_date' => substr($date, 0, 10), 'plate' => $plate,
                'category' => $this->str($e['category'] ?? ''), 'amount' => self::cents($raw), 'currency' => $currency, 'paid_by' => trim($this->str($e['paidBy'] ?? '')),
                'description' => trim($this->str($e['description'] ?? '') . (trim($this->str($e['notes'] ?? '')) !== '' ? ' (' . trim($this->str($e['notes'])) . ')' : '')),
                'receipts' => $ids, 'created_ms' => $this->createdMs($e),
            ];
        }

        /* ---- vendors / staff (names used anywhere, settings first) */
        $vendorSeen = [];
        $vendors = [];
        $addVendor = function (string $n, bool $listed) use (&$vendors, &$vendorSeen) {
            $k = mb_strtolower(trim($n));
            if ($k === '') {
                return;
            }
            if (!isset($vendorSeen[$k])) {
                $vendorSeen[$k] = count($vendors);
                $vendors[] = ['name' => trim($n), 'in_settings' => $listed];
            }
        };
        foreach ((array) ($settings['vendors'] ?? []) as $n) {
            $addVendor($this->str($n), true);
        }
        foreach ($catalog as $c) {
            $addVendor($c['vendor'], false);
        }
        foreach ($stops as $s) {
            $addVendor($s['vendor_name'], false);
        }
        foreach ($jobs as $j) {
            $addVendor($j['vendor_name'], false);
        }
        foreach ($vendors as $v) {
            if (!$v['in_settings']) {
                $this->note('vendor_not_in_settings', 'vendor:' . $v['name'], 'vendor "' . $v['name'] . '" is used by the data but not listed in settings.vendors');
            }
        }

        $staff = [];
        foreach ((array) ($settings['staff'] ?? []) as $n) {
            $n = trim($this->str($n));
            if ($n !== '' && !in_array($n, $staff, true)) {
                $staff[] = $n;
            }
        }
        $staffListed = $staff;
        foreach ([array_column($visits, 'tech'), array_column($damages, 'by'), array_column($petty, 'paid_by')] as $names) {
            foreach ($names as $n) {
                if ($n !== '' && !in_array($n, $staff, true)) {
                    $staff[] = $n;
                    $this->note('staff_not_in_settings', 'staff:' . $n, 'person "' . $n . '" appears in the data but not in settings.staff');
                }
            }
        }

        /* ---- photos: per subject, legacy photo ids (files are <id>.jpg in the Drive photos folder, or data URLs in a backup) */
        $photos = [];
        $srcVisits = $src['visits'];
        foreach ($visits as $id => $_) {
            $pick = $this->idList($srcVisits[$id]['pickupPhotos'] ?? []);
            $ret = $this->idList($srcVisits[$id]['returnPhotos'] ?? []);
            if ($pick) {
                $photos[] = ['subject' => 'visits', 'id' => $id, 'type' => VisitMapper::PHOTO_PICKUP, 'ids' => $pick];
            }
            if ($ret) {
                $photos[] = ['subject' => 'visits', 'id' => $id, 'type' => VisitMapper::PHOTO_RETURN, 'ids' => $ret];
            }
        }
        foreach ($damages as $id => $d) {
            if ($d['photos']) {
                $photos[] = ['subject' => 'damages', 'id' => $id, 'type' => VisitMapper::PHOTO_DAMAGE, 'ids' => $d['photos']];
            }
        }
        foreach ($petty as $id => $e) {
            if ($e['receipts']) {
                $photos[] = ['subject' => 'expenses', 'id' => $id, 'type' => VisitMapper::PHOTO_RECEIPT, 'ids' => $e['receipts']];
            }
        }
        $photoIds = array_merge(...array_map(fn ($p) => $p['ids'], $photos ?: [['ids' => []]]));
        if (count($photoIds) !== count(array_unique($photoIds))) {
            $this->note('shared_photo_id', 'photos', 'the same photo id is referenced by more than one record');
        }

        /* ---- settings document for Service Tracker */
        $keep = array_intersect_key($settings, array_flip(self::SETTINGS_KEEP));
        // settings.vendors / staff stay exactly as the tracker had them: perVendor[] of the engine is indexed by settings.vendors, so adding
        // names here would change every visit's calc. Vendors used by the data but not listed are created in FleetOps (see 'vendors').
        $keep['vendors'] = array_values(array_filter(array_map(fn ($n) => trim($this->str($n)), (array) ($settings['vendors'] ?? [])), fn ($n) => $n !== ''));
        $keep['staff'] = $staffListed;
        $keep['lanes'] = array_map(fn ($l) => ['name' => $l['name'], 'target' => $l['target_hours']], $lanes);
        $keep['timezone'] = $this->tz;

        return [
            'rev' => $src['rev'],
            'settings' => $keep,
            'currency' => $currency,
            'lanes' => $lanes,
            'catalog' => array_values($catalog),
            'vendors' => $vendors,
            'staff' => $staff,
            'staff_listed' => $staffListed,
            'visits' => $visits,
            'stops' => $stops,
            'jobs' => $jobs,
            'damages' => $damages,
            'petty' => $petty,
            'photos' => $photos,
            'inline_photos' => $src['photos'],
            'skipped' => $src['skipped'],
            'notes' => $this->dq,
        ];
    }

    /**
     * Engine output per legacy visit id, computed on the MAPPED rows after a simulated database round trip
     * (odometer decimal(12,2), integer override km, children in created_at / id order). This is exactly what the
     * console would show for the imported data, minus Eloquent - used by the importer's reconciliation and by
     * tests/realdata.
     *
     * @param array<string,mixed> $m map() output
     * @return array<string,array<string,mixed>> legacy visit id => TurnaroundCalculator::calc() (+ stops, jobs)
     */
    public static function calcAll(array $m, string $nowIso): array
    {
        $db = self::dbRows($m);
        $visitArrays = array_map([VisitMapper::class, 'visitFromRow'], $db['visits']);
        $ctx = self::context($m, $nowIso);
        $stopsBy = [];
        foreach ($db['stops'] as $s) {
            $stopsBy[$s['service_visit_uuid']][] = VisitMapper::stopFromRow($s);
        }
        $jobsBy = [];
        foreach ($db['jobs'] as $j) {
            $jobsBy[$j['service_visit_uuid']][] = VisitMapper::jobFromRow($j);
        }
        $out = [];
        foreach ($visitArrays as $id => $va) {
            $stops = $stopsBy[$id] ?? [];
            $jobs = $jobsBy[$id] ?? [];
            $r = TurnaroundCalculator::calc($va, $stops, $jobs, VisitMapper::engineContext($ctx, $va));
            $r['stops'] = $stops;
            $r['jobs'] = $jobs;
            $out[$id] = $r;
        }

        return $out;
    }

    /**
     * The engine context VisitMapper::context() would build from the imported database (catalog, lanes, vendors, plate index).
     *
     * @param array<string,mixed> $m map() output
     */
    public static function context(array $m, string $nowIso): array
    {
        $db = self::dbRows($m);

        return VisitMapper::buildContext(
            array_map(fn ($c) => ['name' => $c['name'], 'category' => $c['category'], 'lane' => $c['lane'], 'vendor' => $c['vendor'], 'interval_km' => $c['interval_km']], $m['catalog']),
            array_map(fn ($l) => ['name' => $l['name'], 'target' => number_format((float) $l['target_hours'], 2, '.', '')], $m['lanes']),
            $m['settings'],
            array_values(array_map([VisitMapper::class, 'visitFromRow'], $db['visits'])),
            array_values(array_map([VisitMapper::class, 'jobFromRow'], $db['jobs'])),
            $nowIso,
            array_column($db['stops'], 'vendor_name'),
        );
    }

    /**
     * Mapped rows as the database would hand them back (uuid := legacy id), children in created_at / id order.
     *
     * @return array{visits:array,stops:array,jobs:array}
     */
    public static function dbRows(array $m): array
    {
        $visits = [];
        foreach ($m['visits'] as $id => $v) {
            $v['uuid'] = $id;
            $v['odometer'] = $v['odometer'] === null ? null : number_format((float) $v['odometer'], 2, '.', ''); // decimal(12,2) comes back as a string
            $visits[$id] = $v;
        }
        $order = static function (array $rows): array {
            $i = 0;
            $keyed = [];
            foreach ($rows as $r) {
                $keyed[] = [intdiv((int) ($r['created_ms'] ?? 0), 1000), $i++, $r]; // created_at has second precision, ties by insertion order
            }
            usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            return array_map(fn ($k) => $k[2], $keyed);
        };
        $stops = array_map(function ($s) {
            $s['uuid'] = $s['legacy_ref'];
            $s['service_visit_uuid'] = $s['visit_legacy'];

            return $s;
        }, $order($m['stops']));
        $jobs = array_map(function ($j) {
            $j['uuid'] = $j['legacy_ref'];
            $j['service_visit_uuid'] = $j['visit_legacy'];

            return $j;
        }, $order($m['jobs']));

        return ['visits' => $visits, 'stops' => $stops, 'jobs' => $jobs];
    }

    /** @return list<string> */
    private function idList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? (string) $x : '', $v), fn ($x) => $x !== ''));
    }

    /** `created` (epoch ms) of a record, or null. */
    private function createdMs(array $r): ?int
    {
        $c = self::num($r['created'] ?? null);

        return ($c !== null && $c > 1e11) ? (int) $c : null;
    }

    /**
     * Stops / jobs carry no `created` in the Drive db, but the tracker orders them by array position. created_at is
     * synthesised as (previous sibling + 1 s), the first one 1 s after the visit's own creation time, so that
     * ORDER BY created_at, id reproduces the source order on every re-run.
     *
     * @param array<string,int> $prev last created_ms per "<kind>:<visit>"
     */
    private function childCreated(array $r, array $visit, string $key, array &$prev): ?int
    {
        $own = $this->createdMs($r);
        $base = $prev[$key] ?? ($visit['created_ms'] ?? (($visit['arrived_at_lsa'] ?? null) ? (new DateTimeImmutable($visit['arrived_at_lsa']))->getTimestamp() * 1000 : null));
        $ms = $own ?? ($base === null ? null : $base + 1000);
        if ($ms !== null) {
            $prev[$key] = $ms;
        }

        return $ms;
    }
}
