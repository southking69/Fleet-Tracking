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
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceLane extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'service_lanes';
    protected $publicIdType = 'lane';
    protected $with = [];
    protected $hidden = [];
    protected $searchableColumns = ['name'];
    protected $filterParams = ['uuid', 'public_id', 'company_uuid', 'name'];

    protected $fillable = [
        'company_uuid',
        'legacy_ref',
        'name',
        'target_hours',
        'sort',
    ];

    protected $casts = [
        'target_hours' => 'float',
        'sort' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('service_lanes');
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(ServiceCatalogItem::class, 'lane_uuid', 'uuid');
    }
}
