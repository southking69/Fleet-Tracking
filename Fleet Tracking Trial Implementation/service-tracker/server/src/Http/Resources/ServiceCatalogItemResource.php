<?php

namespace Jalal\ServiceTracker\Http\Resources;

class ServiceCatalogItemResource extends ServiceTrackerResource
{
    public function toArray($request): array
    {
        return $this->ident($request) + [
            'name' => $this->name,
            'category' => $this->category,
            'lane_uuid' => $this->lane_uuid,
            'lane_name' => $this->resource->relationLoaded('lane') ? optional($this->lane)->name : null,
            'suggested_vendor_uuid' => $this->suggested_vendor_uuid,
            'suggested_vendor_name' => $this->suggested_vendor_name,
            'interval_km' => (int) $this->interval_km,
        ];
    }
}
