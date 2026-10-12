<?php

namespace Jalal\ServiceTracker\Http\Requests;

class ServiceCatalogItemRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        return $this->common() + [
            'name' => [$this->req(), 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'lane_uuid' => ['nullable', 'string', 'max:64'],
            'suggested_vendor_uuid' => ['nullable', 'string', 'max:64'],
            'suggested_vendor_name' => ['nullable', 'string', 'max:255'],
            'interval_km' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // sent by the console when `name` changed: rename the service on every job that uses this item
            'rename_cascade' => ['nullable', 'boolean'],
        ];
    }
}
