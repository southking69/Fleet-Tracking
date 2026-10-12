<?php

namespace Jalal\ServiceTracker\Http\Resources;

/** A core Fleetbase File row as returned by the Service Tracker endpoints. */
class FileResource extends ServiceTrackerResource
{
    public function toArray($request): array
    {
        return self::photo($this->resource);
    }
}
