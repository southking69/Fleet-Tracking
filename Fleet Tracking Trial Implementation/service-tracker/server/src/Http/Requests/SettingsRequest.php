<?php

namespace Jalal\ServiceTracker\Http\Requests;

class SettingsRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        $list = ['sometimes', 'array'];
        return [
            'company' => ['sometimes', 'nullable', 'string', 'max:160'],
            'client' => ['sometimes', 'nullable', 'string', 'max:160'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'vendors' => $list, 'staff' => $list, 'categories' => $list, 'damageLocations' => $list,
            'expenseCats' => $list, 'damageTypes' => $list, 'severities' => $list, 'stages' => $list, 'svcStatuses' => $list,
            'vendors.*' => ['nullable', 'string', 'max:160'],
            'reportOpts' => ['sometimes', 'array'],
            'reportOpts.turnaround' => ['boolean'], 'reportOpts.vendorTimes' => ['boolean'], 'reportOpts.overdue' => ['boolean'],
            'reportOpts.petty' => ['boolean'], 'reportOpts.photos' => ['boolean'], 'reportOpts.condPhotos' => ['boolean'],
            'excelTiming' => ['sometimes', 'boolean'], 'excelPetty' => ['sometimes', 'boolean'], 'excelVehicles' => ['sometimes', 'boolean'],
        ];
    }
}
