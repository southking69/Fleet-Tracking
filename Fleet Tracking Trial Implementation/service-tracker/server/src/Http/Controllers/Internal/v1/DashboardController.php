<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Controllers\ServiceTrackerController;
use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Reports\TrackerStats;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * GET dashboard?from=YYYY-MM-DD&to=YYYY-MM-DD[&preset=today|last7|last30|month|lastmonth][&basis=arrived|returned]
 * Every KPI of the tracker dashboard. Range is read in the company timezone and filters on the
 * LSA arrival date (basis=returned filters on the return date instead).
 */
class DashboardController extends ServiceTrackerController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'basis' => 'nullable|in:arrived,returned', 'preset' => 'nullable|string']);
        $company = $this->companyUuid();
        $settings = VisitMapper::settings();

        $from = $request->input('from');
        $to = $request->input('to');
        if ($request->filled('preset') && ($range = VisitMapper::periodPreset($request->input('preset'), VisitMapper::tz($settings)))) {
            [$from, $to] = $range;
        }
        $basis = $request->input('basis', 'arrived');

        $q = VisitMapper::visitsInRange($company, $from, $to, $basis, $settings, ['stops', 'jobs', 'damages']);
        VisitMapper::attachFiles($q);
        if ($this->isTechnician()) {
            $uid = optional($this->user())->uuid;
            $q = $q->filter(fn ($v) => $v->technician_uuid === $uid)->values();
        }

        $ctx = VisitMapper::context($company, $settings);
        $calcs = VisitMapper::calcMany($q, $company, $settings, $ctx);

        $petty = PettyCashEntry::where('company_uuid', $company);
        $from && $petty->whereDate('entry_date', '>=', $from);
        $to && $petty->whereDate('entry_date', '<=', $to);
        $stats = TrackerStats::compute($q, $calcs, $settings, $petty->get(), $ctx['lanes']);

        return response()->json($stats + [
            'range' => ['from' => $from, 'to' => $to, 'basis' => $basis, 'timezone' => VisitMapper::tz($settings), 'currency' => $settings['currency'] ?? 'SAR'],
            'generated_at' => $ctx['now'],
        ]);
    }
}
