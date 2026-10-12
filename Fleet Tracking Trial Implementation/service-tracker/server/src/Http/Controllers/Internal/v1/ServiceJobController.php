<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Controllers\ChildCrudController;
use Jalal\ServiceTracker\Http\Requests\ServiceJobRequest;
use Jalal\ServiceTracker\Http\Resources\ServiceJobResource;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;
use Jalal\ServiceTracker\Models\ServiceJob;

class ServiceJobController extends ChildCrudController
{
    protected string $model = ServiceJob::class;
    protected string $resource = ServiceJobResource::class;
    protected string $requestClass = ServiceJobRequest::class;
    protected string $plural = 'service_jobs';
    protected string $singular = 'service_job';
    protected array $searchColumns = ['service_name', 'vendor_name', 'parts_replaced', 'notes'];
    protected array $filterColumns = ['status' => 'status', 'catalog_item_uuid' => 'catalog_item_uuid'];
    protected array $sortable = ['created_at', 'service_name', 'status'];
    protected array $booleanFields = ['approval_needed'];

    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $data = parent::prepare($data, $existing, $request);
        if (!empty($data['catalog_item_uuid'])) {
            $item = ServiceCatalogItem::where('company_uuid', $this->companyUuid())
                ->where(fn ($q) => $q->where('uuid', $data['catalog_item_uuid'])->orWhere('public_id', $data['catalog_item_uuid']))->first();
            abort_unless($item, 422, 'Unknown catalog item.');
            $data['catalog_item_uuid'] = $item->uuid;
            if (empty($data['service_name'])) {
                $data['service_name'] = $item->name;
            }
            if (!$existing && empty($data['vendor_uuid']) && $item->suggested_vendor_uuid && !array_key_exists('vendor_name', $data)) {
                $data['vendor_uuid'] = $item->suggested_vendor_uuid;
            }
        }
        if (!empty($data['vendor_uuid']) && empty($data['vendor_name'])) {
            $data['vendor_name'] = $this->vendorName($data['vendor_uuid']);
        }
        if (!$existing) {
            $data['status'] = $data['status'] ?? 'Pending';
            $data['service_name'] = $data['service_name'] ?? ''; // a blank row may be added first; the service is picked afterwards
        }
        if ($existing && array_key_exists('service_name', $data) && $data['service_name'] === null) {
            $data['service_name'] = '';
        }
        $data['approval_needed'] = (bool) ($data['approval_needed'] ?? ($existing->approval_needed ?? false));
        return $data;
    }
}
