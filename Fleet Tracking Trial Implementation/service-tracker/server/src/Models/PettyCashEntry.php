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
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PettyCashEntry extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes, LogsActivity, BelongsToCompany;

    protected $table = 'petty_cash_entries';
    protected $publicIdType = 'pcash';
    protected $with = [];
    protected $hidden = [];
    protected $searchableColumns = ['plate', 'category', 'paid_by', 'description'];
    protected $filterParams = ['uuid', 'public_id', 'company_uuid', 'service_visit_uuid', 'vehicle_uuid', 'category', 'entry_date'];

    protected $fillable = [
        'company_uuid',
        'legacy_ref',
        'client_uuid',
        'entry_date',
        'vehicle_uuid',
        'plate',
        'service_visit_uuid',
        'category',
        'amount',
        'currency',
        'paid_by',
        'description',
        'journal_uuid',
    ];

    protected $casts = [
        'entry_date' => 'date:Y-m-d',
        'amount' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('petty_cash_entries');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ServiceVisit::class, 'service_visit_uuid', 'uuid');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    /**
     * Photos / receipts: core File rows attached polymorphically (files.subject_uuid / subject_type).
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'subject', 'subject_type', 'subject_uuid', 'uuid');
    }
}
