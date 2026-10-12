<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Controllers\ChildCrudController;
use Jalal\ServiceTracker\Http\Requests\DamageRecordRequest;
use Jalal\ServiceTracker\Http\Resources\DamageRecordResource;
use Jalal\ServiceTracker\Models\DamageRecord;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Observers\ServiceVisitObserver;

class DamageController extends ChildCrudController
{
    protected string $model = DamageRecord::class;
    protected string $resource = DamageRecordResource::class;
    protected string $requestClass = DamageRecordRequest::class;
    protected string $plural = 'damage_records';
    protected string $singular = 'damage_record';
    protected array $with = ['files'];
    protected array $searchColumns = ['location', 'damage_type', 'notes'];
    protected array $filterColumns = ['vehicle_uuid' => 'vehicle_uuid', 'stage' => 'stage', 'condition' => 'condition', 'severity' => 'severity'];
    protected array $sortable = ['created_at', 'occurred_at', 'severity', 'condition'];
    protected array $dateTimeFields = ['occurred_at'];

    /** Damage rows carry no plate of their own: `plate` filters on the plate of the visit they belong to. */
    protected function scope($query, Request $request)
    {
        $query = parent::scope($query, $request);
        if ($request->filled('plate')) {
            $plate = $request->input('plate');
            $query->whereIn('service_visit_uuid', $this->wherePlateLike(ServiceVisit::where('company_uuid', $this->companyUuid()), 'plate', $plate)->select('uuid'));
        }
        return $query;
    }

    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $data = parent::prepare($data, $existing, $request);
        if (!$existing) {
            $visit = ServiceVisit::where('uuid', $data['service_visit_uuid'])->first();
            $data['vehicle_uuid'] = $data['vehicle_uuid'] ?? optional($visit)->vehicle_uuid;
            $data['recorded_by_uuid'] = $data['recorded_by_uuid'] ?? optional($this->user())->uuid;
            $data['occurred_at'] = $data['occurred_at'] ?? Carbon::now('UTC');
        }
        return $data;
    }

    /** Damage added/changed after the visit is closed still raises its FleetOps Issue (idempotent). */
    protected function saved(Model $model, bool $creating, Request $request): void
    {
        $visit = ServiceVisit::where('uuid', $model->service_visit_uuid)->first();
        if ($visit && $visit->status === 'closed' && $model->condition === 'New') {
            ServiceVisitObserver::raiseIssue($model, $visit);
        }
    }
}
