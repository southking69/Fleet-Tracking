<?php

namespace Jalal\ServiceTracker\Http\Resources;

use Jalal\ServiceTracker\Services\VisitMapper;

class PettyCashEntryResource extends ServiceTrackerResource
{
    public function toArray($request): array
    {
        return $this->ident($request) + [
            'entry_date' => $this->entry_date ? \Carbon\Carbon::parse($this->entry_date)->toDateString() : null,
            'vehicle_uuid' => $this->vehicle_uuid,
            'plate' => $this->plate,
            'service_visit_uuid' => $this->service_visit_uuid,
            'service_visit_public_id' => $this->visitRef()['public_id'],
            'category' => $this->category,
            'amount' => (int) $this->amount,
            'amount_major' => round(((int) $this->amount) / 100, 2),
            'currency' => $this->currency,
            'paid_by' => $this->paid_by,
            'description' => $this->description,
            'journal_uuid' => $this->journal_uuid,
            'receipts' => $this->photos(VisitMapper::PHOTO_RECEIPT),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
