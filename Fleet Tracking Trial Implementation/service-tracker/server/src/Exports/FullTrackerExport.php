<?php

namespace Jalal\ServiceTracker\Exports;

use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Services\VisitMapper;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Whole tracker: Vehicles, Visits, Vendor Stops, Services, Damage Log, Petty Cash, Service Catalog.
 */
class FullTrackerExport implements WithMultipleSheets
{
    protected string $companyUuid;

    public function __construct(string $companyUuid)
    {
        $this->companyUuid = $companyUuid;
    }

    public function filename(): string
    {
        return 'Fleet_Service_Tracker_' . date('Y-m-d') . '.xlsx';
    }

    public function sheets(): array
    {
        $settings = VisitMapper::settings();
        $visits = ServiceVisit::where('company_uuid', $this->companyUuid)->with(['technician', 'stops', 'jobs', 'damages.files'])->orderBy('arrived_at_lsa')->orderBy('id')->get();
        VisitMapper::attachFiles($visits);
        $ctx = VisitMapper::context($this->companyUuid, $settings, $visits);
        $calcs = VisitMapper::calcMany($visits, $this->companyUuid, $settings, $ctx);
        $petty = PettyCashEntry::where('company_uuid', $this->companyUuid)->with('files')->orderBy('entry_date')->orderBy('id')->get();

        $b = new TrackerSheets($visits, $calcs, $ctx, $settings);
        return [$b->vehicles($petty), $b->visits(), $b->stops(), $b->services(), $b->damages(), $b->petty($petty), $b->catalog()];
    }
}
