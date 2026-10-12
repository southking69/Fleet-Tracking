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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Fleetbase\FleetOps\Models\Vendor;

class ServiceCatalogItem extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'service_catalog_items';
    protected $publicIdType = 'svc';
    protected $with = [];
    protected $hidden = [];
    protected $searchableColumns = ['name', 'category'];
    protected $filterParams = ['uuid', 'public_id', 'company_uuid', 'category', 'lane_uuid', 'suggested_vendor_uuid'];

    protected $fillable = [
        'company_uuid',
        'legacy_ref',
        'name',
        'category',
        'lane_uuid',
        'suggested_vendor_uuid',
        'suggested_vendor_name',
        'interval_km',
    ];

    protected $casts = [
        'interval_km' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('service_catalog_items');
    }

    public function lane(): BelongsTo
    {
        return $this->belongsTo(ServiceLane::class, 'lane_uuid', 'uuid');
    }

    public function suggestedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'suggested_vendor_uuid', 'uuid');
    }
}
