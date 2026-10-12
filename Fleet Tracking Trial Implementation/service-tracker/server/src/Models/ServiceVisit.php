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
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceVisit extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'service_visits';
    protected $publicIdType = 'visit';
    protected $with = [];
    protected $hidden = [];
    protected $searchableColumns = ['plate', 'make_model', 'notes', 'public_id'];
    protected $filterParams = ['uuid', 'public_id', 'company_uuid', 'vehicle_uuid', 'technician_uuid', 'work_order_uuid', 'status', 'lane', 'plate'];

    protected $fillable = [
        'company_uuid',
        'legacy_ref',
        'client_uuid',
        'work_order_uuid',
        'vehicle_uuid',
        'plate',
        'make_model',
        'technician_uuid',
        'odometer',
        'arrived_at_lsa',
        'picked_up_at',
        'returned_at',
        'pickup_inspected',
        'return_inspected',
        'lane',
        'target_hours',
        'notes',
        'status',
    ];

    protected $casts = [
        'odometer' => 'float',
        'target_hours' => 'float',
        'arrived_at_lsa' => 'datetime',
        'picked_up_at' => 'datetime',
        'returned_at' => 'datetime',
        'pickup_inspected' => 'boolean',
        'return_inspected' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('service_visits');
    }

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_CLOSED = 'closed';

    public function stops(): HasMany
    {
        return $this->hasMany(VendorStop::class, 'service_visit_uuid', 'uuid');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'service_visit_uuid', 'uuid');
    }

    public function damages(): HasMany
    {
        return $this->hasMany(DamageRecord::class, 'service_visit_uuid', 'uuid');
    }

    public function pettyCash(): HasMany
    {
        return $this->hasMany(PettyCashEntry::class, 'service_visit_uuid', 'uuid');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_uuid', 'uuid');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_uuid', 'uuid');
    }
}
