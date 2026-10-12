<?php

namespace Jalal\ServiceTracker\Http\Requests;

use Illuminate\Validation\Rule;

class DamageRecordRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        return $this->common() + [
            'service_visit_uuid' => ['sometimes', 'string', 'max:64'],
            'vehicle_uuid' => ['nullable', 'string', 'max:64'],
            'stage' => [$this->req(), Rule::in(['Pickup inspection', 'Return inspection', 'Vendor reported'])],
            'location' => ['nullable', 'string', 'max:120'],
            'damage_type' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(['Minor', 'Moderate', 'Major'])],
            'condition' => ['nullable', Rule::in(['Pre-existing', 'New'])],
            'occurred_at' => ['nullable', 'date'],
            'recorded_by_uuid' => ['nullable', 'string', 'max:64'],
            'client_notified' => ['nullable', Rule::in(['Yes', 'No'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
