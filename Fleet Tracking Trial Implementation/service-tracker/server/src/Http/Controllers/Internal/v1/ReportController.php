<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Http\Request;
use Jalal\ServiceTracker\Exports\FullTrackerExport;
use Jalal\ServiceTracker\Exports\PeriodReportExport;
use Jalal\ServiceTracker\Http\Controllers\ServiceTrackerController;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Reports\VisitReportPdf;
use Maatwebsite\Excel\Facades\Excel;

/**
 * GET reports/visit/{id}.pdf?turnaround=1&vendorTimes=1&overdue=1&petty=0&photos=1&condPhotos=0
 * GET exports/full.xlsx
 * GET exports/period.xlsx?preset=today|last7|last30|month|lastmonth | from=&to=&basis=arrived|returned&timing=&petty=&vehicles=
 */
class ReportController extends ServiceTrackerController
{
    public function visitPdf(Request $request, string $id)
    {
        $visit = $this->findModel(ServiceVisit::class, preg_replace('/\.pdf$/i', '', $id));
        $this->authorizeVisit($visit);
        $report = new VisitReportPdf($visit, $request->only(VisitReportPdf::OPTION_KEYS));
        return response($report->render(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline') . '; filename="' . $report->filename() . '"',
        ]);
    }

    /** GET exports/{full|period}.xlsx */
    public function export(Request $request, string $type)
    {
        abort_unless(in_array($type, ['full', 'period'], true), 404);
        return $type === 'full' ? $this->fullExport($request) : $this->periodExport($request);
    }

    protected function fullExport(Request $request)
    {
        $this->requireSupervisor();
        $export = new FullTrackerExport($this->companyUuid());
        return Excel::download($export, $export->filename());
    }

    protected function periodExport(Request $request)
    {
        $this->requireSupervisor();
        $request->validate([
            'preset' => 'nullable|in:' . implode(',', PeriodReportExport::PRESETS),
            'from' => 'nullable|date', 'to' => 'nullable|date', 'basis' => 'nullable|in:arrived,returned',
        ]);
        $company = $this->companyUuid();
        $basis = $request->input('basis', 'arrived');
        $opt = fn (string $k) => $request->has($k) ? $this->flag($request->input($k)) : null;

        $export = $request->filled('preset')
            ? PeriodReportExport::fromPreset($company, $request->input('preset'), $basis, $opt('timing'), $opt('petty'), $opt('vehicles'))
            : new PeriodReportExport($company, $request->input('from'), $request->input('to'), $basis, $opt('timing'), $opt('petty'), $opt('vehicles'));

        return Excel::download($export, $export->filename());
    }
}
