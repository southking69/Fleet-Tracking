<?php

namespace Jalal\ServiceTracker\Http\Requests;

class PettyCashRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        return $this->common() + [
            'entry_date' => [$this->req(), 'date'],
            'vehicle_uuid' => ['nullable', 'string', 'max:64'],
            'plate' => ['nullable', 'string', 'max:32'],
            'service_visit_uuid' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:120'],
            // integer minor units (cents/halalas); amount_major (e.g. 12.50) is accepted as a convenience
            'amount' => ['nullable', 'integer', 'min:0'],
            'amount_major' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'paid_by' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

}
