<?php

namespace Jalal\ServiceTracker\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jalal\ServiceTracker\Models\Concerns\BelongsToCompany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorStop extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'vendor_stops';
    protected $publicIdType = 'vstop';
    protected $with = [];
    protected $hidden = [];
    protected $searchableColumns = ['vendor_name', 'notes'];
    protected $filterParams = ['uuid', 'public_id', 'company_uuid', 'service_visit_uuid', 'vendor_uuid'];

    protected $fillable = [
        'company_uuid',
        'legacy_ref',
        'client_uuid',
        'service_visit_uuid',
        'vendor_uuid',
        'vendor_name',
        'arrived_at',
        'approval_requested_at',
        'approval_received_at',
        'left_at',
        'notes',
    ];

    protected $casts = [
        'arrived_at' => 'datetime',
        'approval_requested_at' => 'datetime',
        'approval_received_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('vendor_stops');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ServiceVisit::class, 'service_visit_uuid', 'uuid');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_uuid', 'uuid');
    }
}
