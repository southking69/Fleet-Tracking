<?php

namespace Jalal\ServiceTracker\Models\Concerns;

use Fleetbase\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Session;

/**
 * Company scoping for Service Tracker models.
 *
 * - stamps company_uuid from the session company when creating
 * - applies a global scope so console/API reads only ever see the active company
 *   (when a company is present in the session; CLI/queue/seeder contexts are unscoped).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {
            if (empty($model->company_uuid)) {
                $companyUuid = Session::get('company');
                if ($companyUuid) {
                    $model->company_uuid = $companyUuid;
                }
            }
        });

        static::addGlobalScope('service_tracker_company', function (Builder $builder) {
            $companyUuid = Session::get('company');
            if ($companyUuid) {
                $builder->where($builder->getModel()->getTable() . '.company_uuid', $companyUuid);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }
}
