<?php

namespace Jalal\ServiceTracker\Http\Requests;

class ServiceVisitRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        return $this->common() + [
            'plate' => ['nullable', 'string', 'max:32'],
            'make_model' => ['nullable', 'string', 'max:120'],
            'vehicle_uuid' => ['nullable', 'string', 'max:64'],
            'work_order_uuid' => ['nullable', 'string', 'max:64'],
            'technician_uuid' => ['nullable', 'string', 'max:64'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'arrived_at_lsa' => ['nullable', 'date'],
            'picked_up_at' => ['nullable', 'date'],
            'returned_at' => ['nullable', 'date'],
            'pickup_inspected' => ['nullable', 'boolean'],
            'return_inspected' => ['nullable', 'boolean'],
            'lane' => ['nullable', 'string', 'max:60'],
            'target_hours' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
