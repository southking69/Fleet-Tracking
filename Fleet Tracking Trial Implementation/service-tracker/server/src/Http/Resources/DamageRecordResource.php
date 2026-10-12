<?php

namespace Jalal\ServiceTracker\Http\Resources;

use Jalal\ServiceTracker\Services\VisitMapper;

class DamageRecordResource extends ServiceTrackerResource
{
    public function toArray($request): array
    {
        return $this->ident($request) + [
            'service_visit_uuid' => $this->service_visit_uuid,
            'service_visit_public_id' => $this->visitRef()['public_id'],
            'plate' => $this->visitRef()['plate'],
            'vehicle_uuid' => $this->vehicle_uuid,
            'stage' => $this->stage,
            'location' => $this->location,
            'damage_type' => $this->damage_type,
            'severity' => $this->severity,
            'condition' => $this->condition,
            'occurred_at' => $this->iso($this->occurred_at),
            'recorded_by_uuid' => $this->recorded_by_uuid,
            'recorded_by_name' => $this->userName($this->recorded_by_uuid),
            'client_notified' => $this->client_notified,
            'issue_uuid' => $this->issue_uuid,
            'notes' => $this->notes,
            'photos' => $this->photos(VisitMapper::PHOTO_DAMAGE),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
