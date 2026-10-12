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
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DamageRecord extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'damage_records';
    protected $publicIdType = 'dmg';
    protected $with = [];
    protected $hidden = [];
    protected $searchableColumns = ['location', 'damage_type', 'notes'];
    protected $filterParams = ['uuid', 'public_id', 'company_uuid', 'service_visit_uuid', 'vehicle_uuid', 'stage', 'severity', 'condition'];

    protected $fillable = [
        'company_uuid',
        'legacy_ref',
        'client_uuid',
        'service_visit_uuid',
        'vehicle_uuid',
        'stage',
        'location',
        'damage_type',
        'severity',
        'condition',
        'occurred_at',
        'recorded_by_uuid',
        'client_notified',
        'issue_uuid',
        'notes',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('damage_records');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ServiceVisit::class, 'service_visit_uuid', 'uuid');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_uuid', 'uuid');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_uuid', 'uuid');
    }

    /**
     * Photos / receipts: core File rows attached polymorphically (files.subject_uuid / subject_type).
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'subject', 'subject_type', 'subject_uuid', 'uuid');
    }
}
