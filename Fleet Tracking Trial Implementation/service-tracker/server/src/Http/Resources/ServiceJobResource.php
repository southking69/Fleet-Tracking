<?php

namespace Jalal\ServiceTracker\Http\Resources;

class ServiceJobResource extends ServiceTrackerResource
{
    public function toArray($request): array
    {
        return $this->ident($request) + [
            'service_visit_uuid' => $this->service_visit_uuid,
            'service_visit_public_id' => $this->visitRef()['public_id'],
            'catalog_item_uuid' => $this->catalog_item_uuid,
            'service_name' => $this->service_name,
            'vendor_uuid' => $this->vendor_uuid,
            'vendor_name' => $this->vendor_name,
            'approval_needed' => (bool) $this->approval_needed,
            'status' => $this->status,
            'parts_replaced' => $this->parts_replaced,
            'next_due_override_km' => $this->next_due_override_km !== null ? (int) $this->next_due_override_km : null,
            'next_due_date' => $this->next_due_date ? \Carbon\Carbon::parse($this->next_due_date)->toDateString() : null,
            'notes' => $this->notes,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
