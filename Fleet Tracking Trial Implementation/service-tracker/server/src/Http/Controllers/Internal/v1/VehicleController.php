<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Controllers\ServiceTrackerController;
use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Reports\VehicleIndex;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * GET vehicles/due[?plate=ABC 1234 (exact, spaces/dashes ignored)][&q=substring of plate or make][&status=OVERDUE|Due soon|OK][&history=1]
 * Response: console `{vehicle_due: [row...], meta}` / driver API `{data: [row...], meta}`; money in `petty_cash_minor` (minor units) and `petty_cash_total` (major).
 * Per plate: last odometer, next services due with km remaining and status
 * (OK / Due soon <= 1000 km / OVERDUE), plus service history when a plate is asked for.
 */
class VehicleController extends ServiceTrackerController
{
    public function due(Request $request): JsonResponse
    {
        $company = $this->companyUuid();
        $q = ServiceVisit::where('company_uuid', $company)->with(['stops', 'jobs', 'damages']);
        if ($this->isTechnician()) {
            $q->where('technician_uuid', optional($this->user())->uuid);
        }
        $plateKey = $request->filled('plate') ? VisitMapper::plateKey($request->input('plate')) : null;
        if ($plateKey) {
            $q->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(plate,' ',''),'-',''),'.',''),'_','')) = ?", [$plateKey]);
        }
        if ($request->filled('vehicle_uuid')) {
            $q->where('vehicle_uuid', $request->input('vehicle_uuid'));
        }
        $visits = $q->get();

        $settings = VisitMapper::settings();
        $calcs = VisitMapper::calcMany($visits, $company, $settings);
        $petty = PettyCashEntry::where('company_uuid', $company)
            ->when($plateKey, fn ($p) => $p->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(plate,' ',''),'-',''),'.',''),'_','')) = ?", [$plateKey]))
            ->get();

        $index = VehicleIndex::build($visits, $calcs, $petty);
        $withHistory = $request->boolean('history', (bool) $plateKey);
        $wanted = $request->input('status');
        $search = VisitMapper::plateKey($request->input('q'));

        $rows = [];
        foreach ($index as $x) {
            $due = $wanted ? array_values(array_filter($x['due'], fn ($d) => $d['status'] === $wanted)) : $x['due'];
            if ($wanted && !$due) {
                continue;
            }
            if ($search !== '' && !str_contains($x['key'], $search) && !str_contains(VisitMapper::plateKey($x['make']), $search)) {
                continue;
            }
            $row = [
                'plate' => $x['plate'],
                'plate_key' => $x['key'],
                'vehicle_uuid' => $x['vehicle_uuid'],
                'make_model' => $x['make'],
                'visits' => count($x['visits']),
                'closed_visits' => $x['closed'],
                'open_visits' => $x['open'],
                'in_service' => $x['open'] > 0,
                'jobs' => $x['jobs'],
                'last_visit' => $x['last_visit'],
                'last_odometer' => $x['last_odometer'],
                'overdue_at_pickup' => $x['overdue'],
                'damage_records' => $x['damages'],
                'petty_cash_total' => round($x['cash_minor'] / 100, 2),
                'petty_cash_minor' => (int) $x['cash_minor'],
                'due_status' => $x['due_worst'],
                'due' => $due,
            ];
            if ($withHistory) {
                $row['history'] = $x['history'];
            }
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['last_visit'], (string) $a['last_visit']));

        return response()->json([$this->internal ? 'vehicle_due' : 'data' => $rows, 'meta' => [
            'total' => count($rows),
            'due_soon_km' => VehicleIndex::DUE_SOON_KM,
            'overdue' => count(array_filter($rows, fn ($r) => $r['due_status'] === 'OVERDUE')),
            'due_soon' => count(array_filter($rows, fn ($r) => $r['due_status'] === 'Due soon')),
        ]]);
    }
}
