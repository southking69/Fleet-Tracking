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

class ServiceJob extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'service_jobs';
    protected $publicIdType = 'sjob';
    protected $with = [];
    protected $hidden = [];
    protected $searchableColumns = ['service_name', 'vendor_name', 'notes'];
    protected $filterParams = ['uuid', 'public_id', 'company_uuid', 'service_visit_uuid', 'catalog_item_uuid', 'vendor_uuid', 'status'];

    protected $fillable = [
        'company_uuid',
        'legacy_ref',
        'client_uuid',
        'service_visit_uuid',
        'catalog_item_uuid',
        'service_name',
        'vendor_uuid',
        'vendor_name',
        'approval_needed',
        'status',
        'parts_replaced',
        'next_due_override_km',
        'next_due_date',
        'notes',
    ];

    protected $casts = [
        'approval_needed' => 'boolean',
        'next_due_override_km' => 'integer',
        'next_due_date' => 'date:Y-m-d',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('service_jobs');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ServiceVisit::class, 'service_visit_uuid', 'uuid');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'catalog_item_uuid', 'uuid');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_uuid', 'uuid');
    }
}
