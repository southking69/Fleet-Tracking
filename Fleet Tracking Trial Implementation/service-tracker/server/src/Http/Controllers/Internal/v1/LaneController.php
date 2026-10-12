<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Jalal\ServiceTracker\Http\Controllers\CrudController;
use Jalal\ServiceTracker\Http\Requests\ServiceLaneRequest;
use Jalal\ServiceTracker\Http\Resources\ServiceLaneResource;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;
use Jalal\ServiceTracker\Models\ServiceLane;

class LaneController extends CrudController
{
    protected string $model = ServiceLane::class;
    protected string $resource = ServiceLaneResource::class;
    protected string $requestClass = ServiceLaneRequest::class;
    protected string $plural = 'service_lanes';
    protected string $singular = 'service_lane';
    protected array $searchColumns = ['name'];
    protected array $sortable = ['sort', 'name', 'target_hours', 'created_at'];
    protected bool $writesNeedSupervisor = true;

    /** A lane that catalog items still use cannot be removed (422 with a message the console shows). */
    protected function destroyModel(\Illuminate\Database\Eloquent\Model $lane): void
    {
        $used = ServiceCatalogItem::where('company_uuid', $lane->company_uuid)->where('lane_uuid', $lane->uuid)->count();
        if ($used > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lane' => ["Lane \"{$lane->name}\" is used by {$used} catalog item(s). Move them to another lane first."],
            ]);
        }
        $lane->delete();
    }
}
