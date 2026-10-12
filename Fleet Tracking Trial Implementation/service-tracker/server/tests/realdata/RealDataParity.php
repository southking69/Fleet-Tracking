<?php

declare(strict_types=1);

namespace Jalal\ServiceTracker\Tests\RealData;

use Jalal\ServiceTracker\Reports\TrackerStats;
use Jalal\ServiceTracker\Reports\VehicleIndex;
use Jalal\ServiceTracker\Services\AppsScriptMapper;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * PARITY HARNESS on the real production data.
 *
 *   node tests/realdata/oracle.js          # real Index.html functions -> tests/realdata/expected.realdata.json
 *   php  tests/realdata/run.php            # this class: importer mapping layer -> engine -> diff against the oracle
 *
 * Pipeline under test:  fleet-data.json -> AppsScriptMapper::map() (what the importer writes) -> simulated DB round trip
 * -> VisitMapper converters + TurnaroundCalculator / DueCalculator (calcVisit, svcInfo) -> VehicleIndex::build and
 * TrackerStats::compute (the real report classes, fed with stand-ins for the Eloquent models) -> petty cash totals.
 * Everything is compared with what the REAL JavaScript produced on the same file.
 */
final class RealDataParity
{
    private const EPS = 1e-9;

    /** @var list<array{section:string,path:string,expected:mixed,actual:mixed}> */
    public array $diffs = [];
    public int $checks = 0;
    /** @var array<string,int> checks per section */
    public array $perSection = [];
    /** @var list<string> quirks that were checked and accepted (documented deviations, not failures) */
    public array $accepted = [];
    /** @var array<string,mixed> */
    public array $map = [];
    /** @var array<string,mixed> */
    public array $expected = [];
    private string $section = '';
    /** @var list<float> numeric petty amounts of the source (JS num()) */
    private array $rawAmounts = [];

    public function __construct(private string $dataFile, private string $expectedFile)
    {
        StandIns::install();
    }

    /* ------------------------------------------------------------------ compare */

    private function cmp(mixed $exp, mixed $act, string $path, float $eps = self::EPS): void
    {
        $this->checks++;
        $this->perSection[$this->section] = ($this->perSection[$this->section] ?? 0) + 1;
        if (!$this->equal($exp, $act, $eps)) {
            $this->diffs[] = ['section' => $this->section, 'path' => $path, 'expected' => $exp, 'actual' => $act];
        }
    }

    private function equal(mixed $e, mixed $a, float $eps): bool
    {
        if ($e === null || $a === null) {
            return $e === $a;
        }
        if (is_bool($e) || is_string($e)) {
            return $e === $a;
        }
        if (is_int($e) || is_float($e)) {
            return (is_int($a) || is_float($a)) && abs($e - $a) <= $eps;
        }
        if (is_array($e)) {
            if (!is_array($a)) {
                return false;
            }
            if (array_is_list($e)) {
                if (!array_is_list($a) || count($e) !== count($a)) {
                    return false;
                }
                foreach ($e as $i => $x) {
                    if (!$this->equal($x, $a[$i], $eps)) {
                        return false;
                    }
                }

                return true;
            }
            $ek = array_keys($e);
            $ak = array_keys($a);
            sort($ek);
            sort($ak);
            if ($ek !== $ak) {
                return false;
            }
            foreach ($e as $k => $x) {
                if (!$this->equal($x, $a[$k], $eps)) {
                    return false;
                }
            }

            return true;
        }

        return $e === $a;
    }

    /** field-by-field so that a diff names the exact key */
    private function cmpMap(array $exp, array $act, string $path, array $skip = [], float $eps = self::EPS): void
    {
        foreach ($exp as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            $this->cmp($v, array_key_exists($k, $act) ? $act[$k] : '<<missing>>', $path . '.' . $k, $eps);
        }
    }

    /* ------------------------------------------------------------------ run */

    public function run(): self
    {
        $raw = json_decode((string) file_get_contents($this->dataFile), true, 512, JSON_THROW_ON_ERROR);
        $this->expected = json_decode((string) file_get_contents($this->expectedFile), true, 512, JSON_THROW_ON_ERROR);
        $x = $this->expected;
        $dbRaw = $raw['state'] ?? $raw;
        foreach ((array) ($dbRaw['expenses'] ?? []) as $k => $e) {
            $id = (string) ($e['id'] ?? $k);
            if (is_numeric($e['amount'] ?? null) && !in_array($id, $x['tombSkipped']['expenses'], true)) {
                $this->rawAmounts[] = (float) $e['amount'];
            }
        }
        $mapper = new AppsScriptMapper('Asia/Riyadh', '2026-10-12');
        $this->map = $m = $mapper->map($raw);
        $nowIso = gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $x['nowMs'], 1000));

        /* 1. what the importer would write: counts, tombstones, ordering */
        $this->section = 'import-counts';
        $this->cmp($x['counts']['visits'], count($m['visits']), 'visits');
        // children of a deleted visit stay in the tracker's arrays but are not importable (no visit): the importer skips + reports them
        $orph = $x['orphans'] ?? ['stops' => [], 'services' => [], 'damages' => []];
        $this->cmp($x['counts']['stops'] - count($orph['stops']), count($m['stops']), 'stops (minus orphans)');
        $this->cmp($x['counts']['services'] - count($orph['services']), count($m['jobs']), 'jobs (minus orphans)');
        $this->cmp($x['counts']['damages'] - count($orph['damages']), count($m['damages']), 'damages (minus orphans)');
        foreach ($orph as $coll => $ids) {
            $notes = array_column(array_filter($m['notes'], fn ($n) => $n['code'] === 'orphan' && str_starts_with($n['where'], $coll . ':')), 'where');
            $this->cmp(array_map(fn ($i) => $coll . ':' . $i, $ids), array_values($notes), "orphans reported: $coll");
        }
        $this->cmp($x['counts']['expenses'], count($m['petty']), 'expenses');
        foreach ($x['tombSkipped'] as $coll => $ids) {
            $this->cmp($ids, $m['skipped'][$coll], 'tomb.' . $coll);
        }
        $this->cmp(array_column($x['visits'], 'id'), array_keys($m['visits']), 'visit order');
        $this->cmp(array_values(array_diff(array_column($x['jobs'], 'id'), $orph['services'])), array_keys($m['jobs']), 'job order');
        $this->cmp(array_map(fn ($l) => ['name' => $l['name'], 'target' => $l['target']], $x['lanes']), array_map(fn ($l) => ['name' => $l['name'], 'target' => $l['target_hours']], $m['lanes']), 'lanes');
        $this->checkRowFidelity($raw, $m);

        /* 2. calcVisit / svcInfo through mapper -> DB round trip -> engine */
        $calcs = AppsScriptMapper::calcAll($m, $nowIso);
        $this->section = 'calcVisit';
        foreach ($x['visits'] as $ev) {
            $id = $ev['id'];
            $act = $calcs[$id] ?? null;
            if ($act === null) {
                $this->diffs[] = ['section' => $this->section, 'path' => $id, 'expected' => 'present', 'actual' => 'missing'];
                continue;
            }
            $this->cmp(['Closed' => 'closed', 'In progress' => 'in_progress', 'Not started' => 'not_started'][$ev['expected']['status']], $m['visits'][$id]['status'], "$id.status column");
            $this->cmp($ev['stopIds'], array_column($act['stops'], 'id'), "$id.stopOrder");
            $this->cmp($ev['jobIds'], array_column($act['jobs'], 'id'), "$id.jobOrder");
            $this->cmpMap($ev['expected'], $act, $id);
        }
        $this->section = 'svcInfo';
        foreach ($x['jobs'] as $ej) {
            $vid = $ej['visitId'];
            if (!isset($calcs[$vid])) {
                continue; // orphan (visit deleted): not imported, checked above
            }
            $idx = array_search($ej['id'], array_column($calcs[$vid]['jobs'], 'id'), true);
            $info = $idx === false ? null : $calcs[$vid]['sinfo'][$idx];
            if ($info === null) {
                $this->diffs[] = ['section' => $this->section, 'path' => $ej['id'], 'expected' => 'present', 'actual' => 'missing'];
                continue;
            }
            $this->cmpMap($ej['expected'], $info, $ej['id']);
            $this->cmp($ej['nextDue'], $info['nextDue'], $ej['id'] . '.nextDue');
        }

        /* 3. models stand-ins for the report classes */
        $ctx = AppsScriptMapper::context($m, $nowIso);
        [$visitModels, $pettyModels] = StandIns::models($m);

        $this->vehicles($visitModels, $pettyModels, $calcs);
        $this->petty($m);
        $this->dashboard($visitModels, $pettyModels, $calcs, $ctx);

        return $this;
    }

    /** raw source value vs mapped row, for the fields a dashboard cannot see (odo '' / number, plates, minutes, cents). */
    private function checkRowFidelity(array $raw, array $m): void
    {
        $this->section = 'row-fidelity';
        $db = $raw['state'] ?? $raw;
        foreach (['visits', 'stops', 'services', 'damages', 'expenses'] as $c) { // objects keyed by id (Drive) or lists (backup)
            $byId = [];
            foreach ((array) ($db[$c] ?? []) as $k => $r) {
                $byId[(string) ($r['id'] ?? $k)] = $r;
            }
            $db[$c] = $byId;
        }
        $tz = new \DateTimeZone('Asia/Riyadh');
        $jsNum = fn ($v) => ($v === '' || $v === null || !is_numeric($v)) ? null : (float) $v; // Index.html num()
        foreach ($db['visits'] as $id => $v) {
            if (!isset($m['visits'][$id])) {
                continue;
            }
            $r = $m['visits'][$id];
            $this->cmp($jsNum($v['odo'] ?? ''), $r['odometer'], "$id.odometer");
            $this->cmp(VisitMapper::cleanPlate((string) $v['plate']), $r['plate'], "$id.plate trimmed / upper case, dashes kept");
            foreach (['arrived' => 'arrived_at_lsa', 'picked' => 'picked_up_at', 'returned' => 'returned_at'] as $src => $col) {
                $exp = ($v[$src] ?? '') === '' ? null : (new \DateTimeImmutable($v[$src], $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                $this->cmp($exp, $r[$col], "$id.$col Riyadh->UTC");
            }
        }
        $srcPhotoIds = [];
        foreach ($db['visits'] as $id => $v) {
            if (isset($m['visits'][$id])) {
                array_push($srcPhotoIds, ...($v['pickupPhotos'] ?? []), ...($v['returnPhotos'] ?? []));
            }
        }
        foreach ($db['expenses'] as $id => $e) {
            if (!isset($m['petty'][$id])) {
                $this->cmp(true, in_array($id, $m['skipped']['expenses'], true), "$id absent only because tombstoned");
                continue;
            }
            $this->cmp((int) round(round((float) ($jsNum($e['amount'] ?? null) ?? 0), 2) * 100), $m['petty'][$id]['amount'], "$id.amount minor units (non numeric = 0)");
            $this->cmp(VisitMapper::cleanPlate((string) $e['plate']), $m['petty'][$id]['plate'], "$id.plate");
            array_push($srcPhotoIds, ...($e['receipts'] ?? []));
        }
        foreach ($db['damages'] as $id => $d) {
            if (!isset($m['damages'][$id])) {
                $this->cmp(true, in_array($id, $m['skipped']['damages'], true) || !isset($m['visits'][$d['visitId']]), "$id absent only because tombstoned / orphan");
                continue;
            }
            $this->cmp($d['photos'] ?? [], $m['damages'][$id]['photos'], "$id.photos map");
            $this->cmp($d['photoRef'] ?? '', $m['damages'][$id]['photo_ref'], "$id.photoRef");
            array_push($srcPhotoIds, ...($d['photos'] ?? []));
        }
        $mapped = [];
        foreach ($m['photos'] as $p) {
            array_push($mapped, ...$p['ids']);
        }
        sort($srcPhotoIds);
        sort($mapped);
        $this->cmp($srcPhotoIds, $mapped, 'all photo ids reachable through the photos map');
        // every job's service name resolves to a catalog item (so no job is left without a catalog_item_uuid)
        $names = array_column($m['catalog'], 'name');
        foreach ($m['jobs'] as $id => $j) {
            $this->cmp(true, in_array($j['service_name'], $names, true), "$id.service '{$j['service_name']}' has a catalog item");
        }
    }

    private function vehicles(array $visitModels, array $pettyModels, array $calcs): void
    {
        $this->section = 'vehicles';
        $byKey = VehicleIndex::build($visitModels, $calcs, $pettyModels);
        $x = $this->expected['vehicles'];
        $this->cmp(array_column($x, 'key') === [] ? [] : $this->sorted(array_column($x, 'key')), $this->sorted(array_keys($byKey)), 'vehicle key set');
        $mapper = new AppsScriptMapper('Asia/Riyadh');
        foreach ($x as $ev) {
            $a = $byKey[$ev['key']] ?? null;
            if ($a === null) {
                continue;
            }
            $k = $ev['key'];
            // accepted deviation: the importer stores the display plate trimmed ("4473 RKR " -> "4473 RKR"); matching uses the plate key either way
            $this->cmp(VisitMapper::cleanPlate($ev['plate']), $a['plate'], "$k.plate (display, as last typed, trimmed / upper case)");
            if ($ev['plate'] !== trim($ev['plate'])) {
                $this->accepted[] = "plate '{$ev['plate']}' imported as '{$a['plate']}' (trailing whitespace trimmed on purpose)";
            }
            $this->cmp($ev['make'], $a['make'], "$k.make");
            $this->cmp($ev['visits'], count($a['visits']), "$k.visits");
            $this->cmp($ev['closed'], $a['closed'], "$k.closed");
            $this->cmp($ev['open'], $a['open'], "$k.open");
            $this->cmp($ev['jobs'], $a['jobs'], "$k.jobs");
            $this->cmp($ev['lastVisit'] === '' ? null : $mapper->utcIso($ev['lastVisit']), $a['last_visit'], "$k.lastVisit");
            $this->cmp($ev['lastOdo'], $a['last_odometer'], "$k.lastOdo");
            $this->cmp($ev['damages'], $a['damages'], "$k.damages");
            $this->cmp($ev['overdue'], $a['overdue'], "$k.overdue");
            $this->cmp((int) round($ev['cash'] * 100), $a['cash_minor'], "$k.cash");
            $this->cmp($ev['expenses'], count($a['expenses']), "$k.expenses");
            $due = array_map(fn ($d) => ['service' => $d['service'], 'last' => $d['last_done_km'], 'next' => $d['next_due_km'], 'left' => $d['km_remaining'], 'status' => $d['status']], $a['due']);
            $exp = array_map(fn ($d) => ['service' => $d['name'], 'last' => $d['done'] === null ? null : (int) round($d['done']), 'next' => (int) round($d['next']), 'left' => $d['left'] === null ? null : (int) round($d['left']), 'status' => $d['status']], $ev['due']);
            $this->cmp($exp, $due, "$k.due table");
        }
    }

    private function petty(array $m): void
    {
        $this->section = 'petty-cash';
        $x = $this->expected['petty'];
        $rows = array_values($m['petty']);
        // Money is stored in integer minor units, rounded per entry (half up). The tracker sums raw floats, so a sub-cent amount
        // (19.995) can shift a total by up to half a cent per such entry. Real data has none (tolerance stays 1e-9).
        $subCent = count(array_filter($this->rawAmounts, fn ($a) => abs($a * 100 - round($a * 100)) > 1e-7));
        $tol = 1e-9 + 0.005 * $subCent;
        if ($subCent) {
            $this->accepted[] = "$subCent petty amount(s) with more than 2 decimals: minor-unit rounding may move a total by up to " . (0.005 * $subCent);
        }
        $sumBy = function (callable $keyf, array $rs): array {
            $o = [];
            foreach ($rs as $e) {
                $o[$keyf($e)] = ($o[$keyf($e)] ?? 0) + $e['amount'];
            }

            return array_map(fn ($c) => $c / 100, $o);
        };
        $this->cmp($x['total'], array_sum(array_column($rows, 'amount')) / 100, 'total', $tol);
        $this->cmp($x['entries'], count($rows), 'entries');
        $this->cmp($this->sortedMap($x['byCategory']), $this->sortedMap($sumBy(fn ($e) => $e['category'] ?: '(no category)', $rows)), 'by category', $tol);
        $this->cmp($this->sortedMap($x['byCar']), $this->sortedMap($sumBy(fn ($e) => VisitMapper::plateKey($e['plate']) ?: '(none)', $rows)), 'by car', $tol);
        $this->cmp($this->sortedMap($x['byPaidBy']), $this->sortedMap($sumBy(fn ($e) => $e['paid_by'] ?: '(none)', $rows)), 'by payer', $tol);
        $this->cmp($this->sortedMap($x['byVisitLinked']), $this->sortedMap($sumBy(fn ($e) => $e['visit_legacy'], array_filter($rows, fn ($e) => $e['visit_legacy']))), 'by visit (linked)', $tol);
        $this->cmp($x['cars'], count(array_unique(array_filter(array_map(fn ($e) => VisitMapper::plateKey($e['plate']), $rows)))), 'distinct cars');
        // the visit report (VisitReportPdf::pettyEntries) mirrors Index.html visitExpenses(): linked OR same car dated inside the visit
        foreach ($m['visits'] as $id => $v) {
            $from = substr((string) $v['arrived_at_lsa'], 0, 10);
            $to = substr((string) ($v['returned_at'] ?: $v['arrived_at_lsa']), 0, 10);
            // legacy dates are local dates; the visit window must be read in local time too
            $tz = new \DateTimeZone('Asia/Riyadh');
            $loc = fn (?string $iso) => $iso ? (new \DateTimeImmutable($iso))->setTimezone($tz)->format('Y-m-d') : '';
            $from = $loc($v['arrived_at_lsa']);
            $to = $loc($v['returned_at'] ?: $v['arrived_at_lsa']);
            $tot = 0;
            foreach ($rows as $e) {
                if ($e['visit_legacy'] === $id || (!$e['visit_legacy'] && VisitMapper::plateKey($e['plate']) !== '' && VisitMapper::plateKey($e['plate']) === VisitMapper::plateKey($v['plate']) && $from !== '' && $e['entry_date'] >= $from && $e['entry_date'] <= ($to ?: $from))) {
                    $tot += $e['amount'];
                }
            }
            $this->cmp($x['byVisitReport'][$id], $tot / 100, "visit report petty total $id", $tol);
        }
    }

    private function dashboard(array $visitModels, array $pettyModels, array $calcs, array $ctx): void
    {
        $this->section = 'dashboard';
        $d = $this->expected['dashboard'];
        $s = TrackerStats::compute($visitModels, $calcs, $this->map['settings'], $pettyModels, $ctx['lanes']);
        $k = $s['kpis'];
        $r2 = fn ($v) => $v === null ? null : round($v, 2);
        $this->cmp($d['kpis']['visits'], $k['visits'], 'kpi.visits');
        $this->cmp($d['kpis']['closed'], $k['closed'], 'kpi.closed');
        $this->cmp($d['kpis']['in_progress'], $k['in_progress'], 'kpi.in_progress');
        $this->cmp($d['kpis']['jobs'], $k['jobs'], 'kpi.jobs');
        $this->cmp($d['kpis']['stops'], $k['stops'], 'kpi.stops');
        $this->cmp($d['kpis']['petty_cash_total'], $k['petty_cash_total'], 'kpi.petty', 1e-9 + 0.005 * count(array_filter($this->rawAmounts, fn ($a) => abs($a * 100 - round($a * 100)) > 1e-7)));
        $this->cmp($r2($d['kpis']['avg_turnaround_hours']), $k['avg_turnaround_hours'], 'kpi.avg_turnaround (2dp)', 0.0051);
        $this->cmp($d['kpis']['pct_within_target'], $k['pct_within_target'], 'kpi.pct_within_target');
        $this->cmp($d['kpis']['open_over_24h'], $k['open_over_24h'], 'kpi.open_over_24h');
        foreach ($d['stageAvg'] as $i => $v) {
            $this->cmp($r2($v), $s['stages'][$i]['avg_hours'], "stage[$i].avg", 0.0051);
            $this->cmp(round($d['stageShare'][$i], 1), $s['stages'][$i]['share_pct'], "stage[$i].share", 0.0501);
        }
        foreach ($d['byOwner'] as $o => $n) {
            $this->cmp($n, array_column($s['by_owner'], 'closed_visits', 'owner')[$o], "owner.$o");
        }
        foreach ($d['byLane'] as $i => $l) {
            $a = $s['by_lane'][$i];
            $this->cmp($l['lane'], $a['lane'], "lane[$i].name");
            $this->cmp($l['target'], $a['target_hours'], "lane[$i].target");
            $this->cmp($l['closed'], $a['closed_visits'], "lane[$i].closed");
            $this->cmp($r2($l['avg_turnaround']), $a['avg_turnaround_hours'], "lane[$i].avg", 0.0051);
            $this->cmp($l['pct_within_target'], $a['pct_within_target'], "lane[$i].pct");
        }
        foreach ($d['byVendor'] as $i => $v) {
            $a = $s['by_vendor'][$i];
            $this->cmp($v['vendor'], $a['vendor'], "vendor[$i].name");
            $this->cmp($v['stops'], $a['stops'], "vendor[$i].stops");
            $this->cmp($v['jobs_done'], $a['jobs_done'], "vendor[$i].jobs");
            $this->cmp($r2($v['avg_per_stop']), $a['avg_hours_per_stop'], "vendor[$i].avg_per_stop", 0.0051);
            $this->cmp($r2($v['avg_approval']), $a['avg_approval_wait_hours'], "vendor[$i].avg_approval", 0.0051);
            $this->cmp($r2($v['avg_net']), $a['avg_net_work_hours'], "vendor[$i].avg_net", 0.0051);
        }
        $cats = array_column($s['jobs_by_category'], 'jobs', 'category');
        foreach ($d['categories'] as $c => $n) {
            $this->cmp($n, $cats[$c] ?? null, "category.$c");
        }
        $dm = $s['damage_inspections'];
        foreach (['records' => 'damage_records', 'new_records' => 'damage_new', 'closed_no_pickup_inspection' => 'closed_without_pickup_inspection', 'closed_no_return_inspection' => 'closed_without_return_inspection',
            'closed_no_pickup_photos' => 'closed_without_pickup_photos', 'closed_no_return_photos' => 'closed_without_return_photos', 'closed_with_data_problems' => 'closed_with_data_problems'] as $js => $php) {
            $this->cmp($d['damage'][$js], $dm[$php], "damage.$js");
        }
        $c = $s['due_compliance'];
        foreach (['comparable' => 'jobs_with_previous_due', 'overdue' => 'jobs_overdue', 'pct_overdue' => 'pct_overdue', 'avg_km_over' => 'avg_km_overdue', 'worst_km_over' => 'worst_km_overdue',
            'visits_with_overdue' => 'visits_with_overdue_job', 'missing_odometer' => 'jobs_missing_odometer'] as $js => $php) {
            $this->cmp($d['compliance'][$js], $c[$php], "compliance.$js");
        }
    }

    private function sorted(array $a): array
    {
        sort($a);

        return $a;
    }

    private function sortedMap(array $a): array
    {
        ksort($a);

        return array_map(fn ($v) => round((float) $v, 2), $a);
    }

    /** Human readable result. */
    public function report(): string
    {
        $out = sprintf("Real-data parity: %d checks, %d difference(s)\n", $this->checks, count($this->diffs));
        foreach ($this->perSection as $sec => $n) {
            $bad = count(array_filter($this->diffs, fn ($d) => $d['section'] === $sec));
            $out .= sprintf("  %-14s %4d checks  %s\n", $sec, $n, $bad ? "$bad DIFF" : 'ok');
        }
        foreach ($this->accepted as $a) {
            $out .= "  accepted: $a\n";
        }
        foreach ($this->diffs as $d) {
            $out .= sprintf("  DIFF [%s] %s\n    expected %s\n    actual   %s\n", $d['section'], $d['path'], json_encode($d['expected']), json_encode($d['actual']));
        }

        return $out;
    }
}
