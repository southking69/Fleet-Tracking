<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jalal\ServiceTracker\Http\Controllers\CrudController;
use Jalal\ServiceTracker\Http\Requests\ServiceVisitRequest;
use Jalal\ServiceTracker\Http\Resources\ServiceVisitResource;
use Jalal\ServiceTracker\Http\Resources\ServiceJobResource;
use Jalal\ServiceTracker\Http\Resources\VendorStopResource;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Services\VisitMapper;

class VisitController extends CrudController
{
    protected string $model = ServiceVisit::class;
    protected string $resource = ServiceVisitResource::class;
    protected string $requestClass = ServiceVisitRequest::class;
    protected string $plural = 'service_visits';
    protected string $singular = 'service_visit';
    protected array $with = ['technician'];
    protected array $searchColumns = ['plate', 'make_model', 'public_id', 'notes'];
    protected array $filterColumns = ['status' => 'status', 'technician_uuid' => 'technician_uuid', 'vehicle_uuid' => 'vehicle_uuid', 'work_order_uuid' => 'work_order_uuid', 'lane' => 'lane'];
    protected array $sortable = ['created_at', 'updated_at', 'arrived_at_lsa', 'picked_up_at', 'returned_at', 'plate', 'status', 'odometer'];
    protected array $dateTimeFields = ['arrived_at_lsa', 'picked_up_at', 'returned_at'];
    protected array $booleanFields = ['pickup_inspected', 'return_inspected'];

    protected function scope($query, Request $request)
    {
        if ($this->isTechnician()) {
            $query->where('technician_uuid', optional($this->user())->uuid);
        }
        if ($request->filled('plate')) {
            $this->wherePlateLike($query, 'plate', $request->input('plate'));
        }
        $tz = VisitMapper::tz();
        if ($request->filled('from')) {
            $query->where('arrived_at_lsa', '>=', \Carbon\Carbon::parse($request->input('from'), $tz)->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('arrived_at_lsa', '<=', \Carbon\Carbon::parse($request->input('to'), $tz)->endOfDay()->utc());
        }
        return $query;
    }

    protected function canWrite(?Model $existing, Request $request): void
    {
        if ($existing) {
            $this->authorizeVisit($existing);
        }
    }

    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $data = parent::prepare($data, $existing, $request);
        if (isset($data['plate'])) {
            $data['plate'] = VisitMapper::cleanPlate($data['plate']);
        }
        if (!$existing && empty($data['technician_uuid']) && $this->isTechnician()) {
            $data['technician_uuid'] = optional($this->user())->uuid;
        }
        if ($this->isTechnician() && $existing) {
            unset($data['technician_uuid']); // a technician cannot reassign a visit
        }
        if (!$existing || array_key_exists('plate', $data) || array_key_exists('vehicle_uuid', $data)) {
            $data = $this->linkVehicle($data, $existing);
        }
        return $data;
    }

    /** Link the FleetOps vehicle by plate when the client did not send one. */
    protected function linkVehicle(array $data, ?Model $existing): array
    {
        $plate = $data['plate'] ?? optional($existing)->plate;
        $has = array_key_exists('vehicle_uuid', $data) ? $data['vehicle_uuid'] : optional($existing)->vehicle_uuid;
        $class = '\\Fleetbase\\FleetOps\\Models\\Vehicle';
        if ($has || !$plate || !class_exists($class)) {
            return $data;
        }
        try {
            $key = VisitMapper::plateKey($plate);
            $v = $class::where('company_uuid', $this->companyUuid())
                ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(plate_number,' ',''),'-',''),'.',''),'_','')) = ?", [$key])->first();
            if ($v) {
                $data['vehicle_uuid'] = $v->uuid;
                if (empty($data['make_model']) && empty(optional($existing)->make_model)) {
                    $data['make_model'] = trim(($v->make ?? '') . ' ' . ($v->model ?? '')) ?: null;
                }
            }
        } catch (\Throwable $e) {
            // vehicle linking is a convenience; never block a write
        }
        return $data;
    }

    protected function saving(Model $model, bool $creating, Request $request): void
    {
        $model->status = VisitMapper::statusOf($model->arrived_at_lsa, $model->returned_at);
    }

    /** Children in a stable order: the engine's si[i] / sinfo[i] are aligned with stops[i] / jobs[i] of the same response. */
    protected function orderedRelations(array $extra = []): array
    {
        $byCreated = fn ($q) => $q->orderBy('created_at')->orderBy('id');
        return ['stops' => $byCreated, 'jobs' => $byCreated] + $extra;
    }

    /** `with=stops,jobs,damage_records,petty_cash,photos` on list rows (driver app); `calc` stays the light summary. */
    protected function embedsFor(Request $request): array
    {
        $raw = $request->input('with', $request->input('expand', []));
        $want = array_map(fn ($x) => str_replace('-', '_', strtolower(trim($x))), is_array($raw) ? $raw : explode(',', (string) $raw));
        $map = ['stops' => 'stops', 'jobs' => 'jobs', 'damage_records' => 'damage_records', 'damages' => 'damage_records', 'petty_cash' => 'petty_cash', 'photos' => 'photos', 'pickup_photos' => 'photos', 'return_photos' => 'photos'];
        return array_values(array_unique(array_filter(array_map(fn ($w) => $map[$w] ?? null, $want))));
    }

    /** GET visits: each row carries the engine summary under `calc` (pass calc=0 to skip). */
    public function query(Request $request): JsonResponse
    {
        $embed = $this->embedsFor($request);
        $extra = [];
        if (in_array('damage_records', $embed, true)) {
            $extra['damages'] = fn ($q) => $q->orderBy('created_at')->orderBy('id');
            $extra['damages.files'] = fn ($q) => $q;
        }
        if (in_array('petty_cash', $embed, true)) {
            $extra['pettyCash'] = fn ($q) => $q->orderBy('created_at')->orderBy('id');
            $extra['pettyCash.files'] = fn ($q) => $q;
        }
        $q = ServiceVisit::where('company_uuid', $this->companyUuid())->with($this->eager())->with($this->orderedRelations($extra));
        $q = $this->scope($q, $request);
        foreach ($this->filterColumns as $param => $col) {
            if ($request->filled($param)) {
                $q->where($col, $param === 'status' ? $this->statusValue($request->input($param)) : $request->input($param));
            }
        }
        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->input('q')) . '%';
            $q->where(fn ($w) => $w->where('plate', 'like', $term)->orWhere('make_model', 'like', $term)->orWhere('public_id', 'like', $term)->orWhere('notes', 'like', $term));
        }
        $this->applySort($q, $request, $this->sortable, '-arrived_at_lsa');

        if ($single = $this->singleOrNull($q, $request)) {
            return $single;
        }

        $page = $this->paginate($q, $request);
        $items = $page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $page->getCollection() : $page;

        if (in_array('photos', $embed, true)) {
            VisitMapper::attachFiles($items);
        }
        $calcs = $request->boolean('calc', true) ? VisitMapper::calcMany($items, $this->companyUuid()) : [];
        $rows = $items->map(fn ($v) => (new ServiceVisitResource($v, $calcs[$v->uuid] ?? null, false, $embed))->resolve($request))->values()->all();
        $meta = $page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]
            : ['total' => count($rows)];
        return response()->json([$this->internal ? $this->plural : 'data' => $rows, 'meta' => $meta]);
    }

    protected function present(Model $model)
    {
        return $this->presentFull($model);
    }

    /** Visit with stops, jobs, damages, petty cash, photos and the full engine output embedded. */
    protected function presentFull(Model $visit): ServiceVisitResource
    {
        $order = fn ($q) => $q->orderBy('created_at')->orderBy('id');
        $visit->load(['technician'] + $this->orderedRelations(['damages' => $order, 'damages.files' => fn ($q) => $q, 'pettyCash' => $order, 'pettyCash.files' => fn ($q) => $q]));
        VisitMapper::attachFiles([$visit]);
        VisitMapper::attachFiles($visit->stops);
        $ctx = VisitMapper::context($this->companyUuid());
        $calc = VisitMapper::calc($visit, $ctx);
        $calc['vendorNames'] = array_values($ctx['vendors'] ?? []); // names for the index-aligned calc.perVendor[]
        return new ServiceVisitResource($visit, $calc, true);
    }

    public function find(Request $request, string $id): JsonResponse
    {
        $visit = $this->findModel(ServiceVisit::class, $id);
        $this->authorizeVisit($visit);
        return $this->respondOne($this->presentFull($visit));
    }

    /** GET visits/{id}/stops */
    public function stops(Request $request, string $id): JsonResponse
    {
        $visit = $this->findModel(ServiceVisit::class, $id, ['stops']);
        $this->authorizeVisit($visit);
        VisitMapper::attachFiles($visit->stops);
        return $this->respondList($visit->stops, VendorStopResource::class, 'vendor_stops');
    }

    /** POST visits/{id}/stops */
    public function createStop(Request $request, string $id): JsonResponse
    {
        return app(VendorStopController::class)->create($request, $id);
    }

    /** GET visits/{id}/jobs */
    public function jobs(Request $request, string $id): JsonResponse
    {
        $visit = $this->findModel(ServiceVisit::class, $id, ['jobs']);
        $this->authorizeVisit($visit);
        return $this->respondList($visit->jobs, ServiceJobResource::class, 'service_jobs');
    }

    /** POST visits/{id}/jobs */
    public function createJob(Request $request, string $id): JsonResponse
    {
        return app(ServiceJobController::class)->create($request, $id);
    }

    /** GET visits/{id}/calc: engine output only. */
    public function calc(Request $request, string $id): JsonResponse
    {
        $visit = $this->findModel(ServiceVisit::class, $id);
        $this->authorizeVisit($visit);
        return response()->json(VisitMapper::calc($visit, VisitMapper::context($this->companyUuid())));
    }

    /** Deleting a visit removes its stops, jobs and damage records (petty cash is kept, unlinked by the console). */
    protected function destroyModel(Model $visit): void
    {
        $visit->stops()->get()->each->delete();
        $visit->jobs()->get()->each->delete();
        $visit->damages()->get()->each->delete();
        $visit->delete();
    }
}
