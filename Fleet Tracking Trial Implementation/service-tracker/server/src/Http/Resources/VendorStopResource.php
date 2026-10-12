<?php

namespace Jalal\ServiceTracker\Http\Resources;

use Jalal\ServiceTracker\Services\StopTimer;
use Jalal\ServiceTracker\Services\VisitMapper;

class VendorStopResource extends ServiceTrackerResource
{
    public function toArray($request): array
    {
        $timing = StopTimer::info(VisitMapper::stopArray($this->resource));
        return $this->ident($request) + [
            'service_visit_uuid' => $this->service_visit_uuid,
            'service_visit_public_id' => $this->visitRef()['public_id'],
            'vendor_uuid' => $this->vendor_uuid,
            'vendor_name' => $this->vendor_name,
            'arrived_at' => $this->iso($this->arrived_at),
            'approval_requested_at' => $this->iso($this->approval_requested_at),
            'approval_received_at' => $this->iso($this->approval_received_at),
            'left_at' => $this->iso($this->left_at),
            'notes' => $this->notes,
            'hours_at_vendor' => $timing['atVendor'] ?? null,
            'approval_wait_hours' => $timing['appr'] ?? null,
            'net_work_hours' => $timing['net'] ?? null,
            'photos' => $this->photos(VisitMapper::PHOTO_STOP),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
