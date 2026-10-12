<?php

namespace Jalal\ServiceTracker\Http\Resources;

use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * A visit plus (optionally) the engine output under `calc`. Pass the engine array as $calc
 * (VisitMapper::calc) and set $full = true to embed stops, jobs, damages, petty cash and photos.
 */
class ServiceVisitResource extends ServiceTrackerResource
{
    protected ?array $calc;
    protected bool $full;
    /** @var list<string> partial embeds on list rows: stops, jobs, damage_records, petty_cash, photos */
    protected array $embed;

    public function __construct($resource, ?array $calc = null, bool $full = false, array $embed = [])
    {
        parent::__construct($resource);
        $this->calc = $calc;
        $this->full = $full;
        $this->embed = $embed;
    }

    protected function wants(string $key): bool
    {
        return $this->full || in_array($key, $this->embed, true);
    }

    public function toArray($request): array
    {
        $data = $this->ident($request) + [
            'work_order_uuid' => $this->work_order_uuid,
            'vehicle_uuid' => $this->vehicle_uuid,
            'plate' => $this->plate,
            'plate_key' => VisitMapper::plateKey($this->plate),
            'make_model' => $this->make_model,
            'technician_uuid' => $this->technician_uuid,
            'technician_name' => $this->resource->relationLoaded('technician') ? optional($this->technician)->name : null,
            'odometer' => $this->odometer !== null ? (float) $this->odometer : null,
            'arrived_at_lsa' => $this->iso($this->arrived_at_lsa),
            'picked_up_at' => $this->iso($this->picked_up_at),
            'returned_at' => $this->iso($this->returned_at),
            'pickup_inspected' => (bool) $this->pickup_inspected,
            'return_inspected' => (bool) $this->return_inspected,
            'lane' => $this->lane,
            'target_hours' => $this->target_hours !== null ? (float) $this->target_hours : null,
            'notes' => $this->notes,
            'status' => $this->status,
            'status_label' => VisitMapper::statusLabel((string) $this->status),
            'stops_count' => $this->when($this->resource->relationLoaded('stops'), fn () => $this->stops->count()),
            'jobs_count' => $this->when($this->resource->relationLoaded('jobs'), fn () => $this->jobs->count()),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];

        if ($this->calc !== null) {
            $c = $this->calc;
            $data['calc'] = $this->full ? $c : array_diff_key($c, ['si' => 1, 'sinfo' => 1, 'stops' => 1, 'jobs' => 1]);
        }

        if ($this->wants('stops')) {
            $data['stops'] = $this->resource->relationLoaded('stops') ? VendorStopResource::collection($this->stops)->resolve($request) : [];
        }
        if ($this->wants('jobs')) {
            $data['jobs'] = $this->resource->relationLoaded('jobs') ? ServiceJobResource::collection($this->jobs)->resolve($request) : [];
        }
        if ($this->wants('damage_records')) {
            $damages = $this->resource->relationLoaded('damages') ? $this->damages : collect();
            self::primeRefs($damages);
            $data['damage_records'] = DamageRecordResource::collection($damages)->resolve($request);
        }
        if ($this->wants('petty_cash')) {
            $data['petty_cash'] = $this->resource->relationLoaded('pettyCash') ? PettyCashEntryResource::collection($this->pettyCash)->resolve($request) : [];
        }
        if ($this->wants('photos')) {
            $data['pickup_photos'] = $this->photos(VisitMapper::PHOTO_PICKUP);
            $data['return_photos'] = $this->photos(VisitMapper::PHOTO_RETURN);
        }

        return $data;
    }
}
