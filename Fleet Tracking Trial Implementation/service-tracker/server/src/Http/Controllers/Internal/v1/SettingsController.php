<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Controllers\ServiceTrackerController;
use Jalal\ServiceTracker\Http\Requests\SettingsRequest;
use Jalal\ServiceTracker\Services\VisitMapper;

/** GET/PUT settings: company, client, currency, report options and the dropdown lists. */
class SettingsController extends ServiceTrackerController
{
    public function show(): JsonResponse
    {
        return response()->json(['settings' => VisitMapper::settings()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireSupervisor();
        $data = app(SettingsRequest::class)->validated();
        if (isset($data['timezone']) && !in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            abort(422, 'Unknown timezone.');
        }
        foreach (['vendors', 'staff', 'categories', 'damageLocations', 'expenseCats', 'damageTypes', 'severities', 'stages', 'svcStatuses'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = array_values(array_map(fn ($x) => is_string($x) ? trim($x) : $x, $data[$k]));
            }
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        return response()->json(['settings' => VisitMapper::saveSettings($data)]);
    }
}
