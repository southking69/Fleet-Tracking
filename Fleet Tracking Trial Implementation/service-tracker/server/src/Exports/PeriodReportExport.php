<?php

namespace Jalal\ServiceTracker\Exports;

use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Services\VisitMapper;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Period report: Summary, optional Vehicles, Visits, Vendor Stops, Services, Damage Log, optional Petty Cash.
 * Presets: today | last7 | last30 | month | lastmonth (resolved in the company timezone).
 * `timing` false removes every timing column and the timing blocks of the Summary.
 */
class PeriodReportExport implements WithMultipleSheets
{
    public const PRESETS = ['today', 'last7', 'last30', 'month', 'lastmonth'];

    protected string $companyUuid;
    protected ?string $from;
    protected ?string $to;
    protected string $basis;
    protected ?bool $timing;
    protected ?bool $petty;
    protected ?bool $vehicles;
    protected int $visitCount = 0;

    public function __construct(string $companyUuid, ?string $from, ?string $to, string $basis = 'arrived', ?bool $timing = null, ?bool $petty = null, ?bool $vehicles = null)
    {
        $this->companyUuid = $companyUuid;
        $this->from = $from ?: null;
        $this->to = $to ?: null;
        $this->basis = $basis === 'returned' ? 'returned' : 'arrived';
        $this->timing = $timing;
        $this->petty = $petty;
        $this->vehicles = $vehicles;
    }

    public static function fromPreset(string $companyUuid, string $preset, string $basis = 'arrived', ?bool $timing = null, ?bool $petty = null, ?bool $vehicles = null): self
    {
        $range = VisitMapper::periodPreset($preset);
        abort_unless($range, 422, 'Unknown preset. Use one of: ' . implode(', ', self::PRESETS));
        return new self($companyUuid, $range[0], $range[1], $basis, $timing, $petty, $vehicles);
    }

    public function filename(): string
    {
        return 'Service_Report_' . ($this->from ?: 'start') . '_to_' . ($this->to ?: date('Y-m-d')) . '.xlsx';
    }

    public function sheets(): array
    {
        $settings = VisitMapper::settings();
        $timing = $this->timing ?? (bool) ($settings['excelTiming'] ?? true);
        $petty = $this->petty ?? (bool) ($settings['excelPetty'] ?? false);
        $vehicles = $this->vehicles ?? (bool) ($settings['excelVehicles'] ?? true);

        $visits = VisitMapper::visitsInRange($this->companyUuid, $this->from, $this->to, $this->basis, $settings, ['technician', 'stops', 'jobs', 'damages.files']);
        VisitMapper::attachFiles($visits);
        $this->visitCount = $visits->count();
        abort_if($visits->isEmpty(), 404, 'No visits in that period.');

        $ctx = VisitMapper::context($this->companyUuid, $settings);
        $calcs = VisitMapper::calcMany($visits, $this->companyUuid, $settings, $ctx);

        $pettyRows = null;
        if ($petty) {
            $q = PettyCashEntry::where('company_uuid', $this->companyUuid)->with('files');
            $this->from && $q->whereDate('entry_date', '>=', $this->from);
            $this->to && $q->whereDate('entry_date', '<=', $this->to);
            $pettyRows = $q->orderBy('entry_date')->orderBy('id')->get();
        }

        $b = new TrackerSheets($visits, $calcs, $ctx, $settings);
        $sheets = [$b->summary($this->from, $this->to, $this->basis, !$timing, $pettyRows)];
        if ($vehicles) {
            $sheets[] = $b->vehicles($pettyRows ?? []);
        }
        array_push($sheets, $b->visits(), $b->stops(), $b->services(), $b->damages());
        if ($petty) {
            $sheets[] = $b->petty($pettyRows);
        }
        if (!$timing) {
            $sheets = array_map(fn (ArraySheet $s) => $s->withoutTiming(), $sheets);
        }
        return $sheets;
    }
}
