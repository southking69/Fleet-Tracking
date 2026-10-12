<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Jalal\ServiceTracker\Http\Controllers\ServiceTrackerController;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;
use Jalal\ServiceTracker\Models\ServiceLane;
use Jalal\ServiceTracker\Services\VisitMapper;

/** Read-only reference data the driver app caches for offline use (routes: GET v1/service-tracker/lookups and /settings). */
class LookupController extends ServiceTrackerController
{
    protected bool $internal = false;

    public function index(): JsonResponse
    {
        $company = $this->companyUuid();
        $s = VisitMapper::settings();
        $laneRows = ServiceLane::where('company_uuid', $company)->orderBy('sort')->get();
        $laneName = $laneRows->pluck('name', 'uuid');
        $lanes = $laneRows->map(fn ($l) => ['id' => $l->public_id, 'uuid' => $l->uuid, 'public_id' => $l->public_id, 'name' => $l->name, 'target_hours' => (float) $l->target_hours])->values();
        $catalog = ServiceCatalogItem::where('company_uuid', $company)->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->public_id, 'uuid' => $c->uuid, 'public_id' => $c->public_id, 'name' => $c->name, 'category' => $c->category,
            'lane_uuid' => $c->lane_uuid, 'lane' => $laneName[$c->lane_uuid] ?? null, 'suggested_vendor_uuid' => $c->suggested_vendor_uuid, 'suggested_vendor_name' => $c->suggested_vendor_name, 'interval_km' => (int) $c->interval_km,
        ])->values();

        $vendors = [];
        $class = '\\Fleetbase\\FleetOps\\Models\\Vendor';
        if (class_exists($class)) {
            $vendors = $class::where('company_uuid', $company)->orderBy('name')->get(['uuid', 'public_id', 'name'])->map(fn ($v) => ['id' => $v->public_id, 'uuid' => $v->uuid, 'public_id' => $v->public_id, 'name' => $v->name])->values();
        }
        // vendor picker names: FleetOps vendors, else the configured fallback list
        $vendorNames = count($vendors) ? collect($vendors)->pluck('name')->all() : array_values((array) ($s['vendors'] ?? []));

        return response()->json(['data' => [
            'lanes' => $lanes,
            'catalog' => $catalog,
            'vendors' => $vendors,
            'lists' => array_intersect_key($s, array_flip(['damageLocations', 'damageTypes', 'severities', 'stages', 'svcStatuses', 'categories', 'expenseCats', 'staff'])) + ['vendors' => $vendorNames],
            'currency' => $s['currency'] ?? 'SAR',
            'timezone' => VisitMapper::tz($s),
        ]]);
    }
}
