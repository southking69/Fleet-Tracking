<?php

namespace Jalal\ServiceTracker\Http\Requests;

class VendorStopRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        return $this->common() + [
            'service_visit_uuid' => ['sometimes', 'string', 'max:64'],
            'vendor_uuid' => ['nullable', 'string', 'max:64'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'arrived_at' => ['nullable', 'date'],
            'approval_requested_at' => ['nullable', 'date'],
            'approval_received_at' => ['nullable', 'date'],
            'left_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
