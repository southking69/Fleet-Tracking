<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Controllers\CrudController;
use Jalal\ServiceTracker\Http\Requests\ServiceCatalogItemRequest;
use Jalal\ServiceTracker\Http\Resources\ServiceCatalogItemResource;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;

class CatalogController extends CrudController
{
    protected string $model = ServiceCatalogItem::class;
    protected string $resource = ServiceCatalogItemResource::class;
    protected string $requestClass = ServiceCatalogItemRequest::class;
    protected string $plural = 'service_catalog_items';
    protected string $singular = 'service_catalog_item';
    protected array $with = ['lane'];
    protected array $searchColumns = ['name', 'category'];
    protected array $filterColumns = ['category' => 'category', 'lane_uuid' => 'lane_uuid'];
    protected array $sortable = ['name', 'category', 'interval_km', 'created_at'];
    protected bool $writesNeedSupervisor = true;

    /** Set from the validated payload in prepare() (the flag may arrive wrapped in the Ember root key). */
    protected bool $renameCascade = false;

    /** `rename_cascade` (console, when `name` changed): the service name on every job using this item follows. */
    protected function saved(Model $model, bool $creating, Request $request): void
    {
        if (!$creating && $this->renameCascade) {
            \Jalal\ServiceTracker\Models\ServiceJob::where('company_uuid', $model->company_uuid)
                ->where('catalog_item_uuid', $model->uuid)
                ->update(['service_name' => $model->name]);
        }
    }

    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $data = parent::prepare($data, $existing, $request);
        $this->renameCascade = (bool) ($data['rename_cascade'] ?? false);
        unset($data['rename_cascade']);
        if (!empty($data['suggested_vendor_uuid']) && empty($data['suggested_vendor_name'])) {
            $data['suggested_vendor_name'] = $this->vendorName($data['suggested_vendor_uuid']);
        }
        if (!empty($data['lane_uuid'])) {
            $lane = \Jalal\ServiceTracker\Models\ServiceLane::where('company_uuid', $this->companyUuid())
                ->where(fn ($q) => $q->where('uuid', $data['lane_uuid'])->orWhere('public_id', $data['lane_uuid']))->first();
            abort_unless($lane, 422, 'Unknown lane.');
            $data['lane_uuid'] = $lane->uuid;
        }
        return $data;
    }
}
