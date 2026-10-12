<?php

namespace Jalal\ServiceTracker\Http\Requests;

use Illuminate\Validation\Rule;

class ServiceJobRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        return $this->common() + [
            'service_visit_uuid' => ['sometimes', 'string', 'max:64'],
            'catalog_item_uuid' => ['nullable', 'string', 'max:64'],
            'service_name' => ['nullable', 'string', 'max:255'],
            'vendor_uuid' => ['nullable', 'string', 'max:64'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'approval_needed' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['Pending', 'In progress', 'Done'])],
            'parts_replaced' => ['nullable', 'string', 'max:2000'],
            'next_due_override_km' => ['nullable', 'numeric', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
