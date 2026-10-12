<?php

namespace Jalal\ServiceTracker\Http\Resources;

class ServiceLaneResource extends ServiceTrackerResource
{
    public function toArray($request): array
    {
        return $this->ident($request) + [
            'name' => $this->name,
            'target_hours' => (float) $this->target_hours,
            'sort' => (int) $this->sort,
        ];
    }
}
