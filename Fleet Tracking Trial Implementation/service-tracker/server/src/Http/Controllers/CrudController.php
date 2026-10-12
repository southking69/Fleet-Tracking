<?php

namespace Jalal\ServiceTracker\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Generic idempotent CRUD using the action names Fleetbase's `fleetbaseRoutes()` macro dispatches to:
 * query, find, create, update, delete, bulkDelete. Subclasses declare the model / resource / request
 * classes and may override scope(), prepare(), saving(), saved(), canWrite(), canDelete().
 */
abstract class CrudController extends ServiceTrackerController
{
    /** @var class-string<Model> */
    protected string $model;
    protected string $resource;
    protected string $requestClass;
    protected string $plural;
    protected string $singular;
    protected array $with = [];
    protected array $searchColumns = [];
    protected array $filterColumns = [];
    protected array $sortable = ['created_at', 'updated_at'];
    protected array $dateTimeFields = [];
    protected array $booleanFields = [];
    protected bool $writesNeedSupervisor = false;

    /* ---- hooks ---- */

    protected function scope($query, Request $request)
    {
        return $query;
    }

    /** Relations to eager load (only those the model really defines). */
    protected function eager(): array
    {
        return array_values(array_filter($this->with, fn ($r) => method_exists($this->model, explode('.', $r)[0])));
    }

    /** Validated input -> model attributes. */
    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $tz = VisitMapper::tz();
        foreach ($this->dateTimeFields as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = VisitMapper::parseDt($data[$f], $tz);
            }
        }
        foreach ($this->booleanFields as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = (bool) $data[$f];
            }
        }
        return $data;
    }

    protected function canWrite(?Model $existing, Request $request): void
    {
        if ($this->writesNeedSupervisor) {
            $this->requireSupervisor();
        }
    }

    protected function canDelete(Model $model): void
    {
        $this->requireSupervisor();
    }

    protected function saving(Model $model, bool $creating, Request $request): void
    {
    }

    protected function saved(Model $model, bool $creating, Request $request): void
    {
    }

    protected function present(Model $model)
    {
        VisitMapper::attachFiles([$model]);
        return new $this->resource($model);
    }

    protected function supportsClientUuid(): bool
    {
        return in_array('client_uuid', (new $this->model())->getFillable(), true);
    }

    /* ---- Fleetbase `fleetbaseRoutes()` action names (RESTRegistrar dispatches to *Record) ---- */

    public function queryRecord(Request $request): JsonResponse
    {
        return $this->query($request);
    }

    public function findRecord(Request $request, string $id): JsonResponse
    {
        return $this->find($request, $id);
    }

    public function createRecord(Request $request): JsonResponse
    {
        return $this->create($request);
    }

    public function updateRecord(Request $request, string $id): JsonResponse
    {
        return $this->update($request, $id);
    }

    public function deleteRecord(Request $request, string $id): JsonResponse
    {
        return $this->delete($request, $id);
    }

    /**
     * `?public_id=` / `?uuid=` narrow a list to one record. With `?single=1` (Ember Data `queryRecord`)
     * the response is the single record `{<singular>: {...}}` (or `{<singular>: null}`), not a list.
     */
    protected function singleOrNull($query, Request $request): ?JsonResponse
    {
        foreach (['public_id', 'uuid'] as $col) {
            if ($request->filled($col)) {
                $query->where($col, $request->input($col));
            }
        }
        if (!$request->boolean('single')) {
            return null;
        }
        $model = $query->first();
        if (!$model) {
            return response()->json([$this->internal ? $this->singular : 'data' => null]);
        }
        return $this->respondOne($this->present($model));
    }

    /* ---- actions ---- */

    public function query(Request $request): JsonResponse
    {
        $q = $this->model::query()->where('company_uuid', $this->companyUuid())->with($this->eager());
        $q = $this->scope($q, $request);

        foreach ($this->filterColumns as $param => $col) {
            $param = is_int($param) ? $col : $param;
            if ($request->filled($param)) {
                $v = $request->input($param);
                is_array($v) ? $q->whereIn($col, $v) : $q->where($col, $v);
            }
        }
        if ($request->filled('q') && $this->searchColumns) {
            $term = '%' . trim((string) $request->input('q')) . '%';
            $q->where(function ($w) use ($term) {
                foreach ($this->searchColumns as $c) {
                    $w->orWhere($c, 'like', $term);
                }
            });
        }
        $this->applySort($q, $request, $this->sortable);

        if ($single = $this->singleOrNull($q, $request)) {
            return $single;
        }

        $page = $this->paginate($q, $request);
        VisitMapper::attachFiles($page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $page->getCollection() : $page);
        return $this->respondList($page, $this->resource, $this->plural);
    }

    public function find(Request $request, string $id): JsonResponse
    {
        $model = $this->scope($this->model::query()->where('company_uuid', $this->companyUuid()), $request)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->with($this->eager())->first();
        abort_unless($model, 404, 'Not found.');
        return $this->respondOne($this->present($model));
    }

    /** POST: create (idempotent on client_uuid). Child controllers may receive the parent visit id as $id. */
    public function create(Request $request, ?string $id = null): JsonResponse
    {
        $this->nested($id);
        $this->canWrite(null, $request);
        $data = app($this->requestClass)->validated();
        if (!$this->supportsClientUuid()) {
            unset($data['client_uuid']);
        }

        if ($dup = $this->byClientUuid($this->model, $data['client_uuid'] ?? null)) {
            return $this->respondOne($this->present($dup->load($this->eager())), 200);
        }

        $attrs = $this->prepare($data, null, $request);
        $model = DB::transaction(function () use ($attrs, $request) {
            $m = new $this->model();
            $m->forceFill($attrs + ['company_uuid' => $this->companyUuid()]);
            $this->saving($m, true, $request);
            $m->save();
            $this->saved($m, true, $request);
            return $m;
        });

        return $this->respondOne($this->present($model->fresh($this->eager())), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $model = $this->findModel($this->model, $id);
        $this->canWrite($model, $request);
        $data = app($this->requestClass)->validated();
        unset($data['client_uuid']);

        $attrs = $this->prepare($data, $model, $request);
        DB::transaction(function () use ($model, $attrs, $request) {
            $model->forceFill($attrs);
            $this->saving($model, false, $request);
            $model->save();
            $this->saved($model, false, $request);
        });

        return $this->respondOne($this->present($model->fresh($this->eager())));
    }

    public function delete(Request $request, string $id): JsonResponse
    {
        $model = $this->findModel($this->model, $id);
        $this->canDelete($model);
        DB::transaction(fn () => $this->destroyModel($model));
        return response()->json(['status' => 'OK', 'deleted' => true, 'uuid' => $model->uuid]);
    }

    /** DELETE bulk-delete {ids: []} (fleetbaseRoutes macro). */
    public function bulkDelete(Request $request): JsonResponse
    {
        $ids = (array) $request->input('ids', []);
        $n = 0;
        DB::transaction(function () use ($ids, &$n) {
            foreach ($ids as $id) {
                $m = $this->findModel($this->model, (string) $id);
                $this->canDelete($m);
                $this->destroyModel($m);
                $n++;
            }
        });
        return response()->json(['status' => 'OK', 'deleted' => $n]);
    }

    protected function destroyModel(Model $model): void
    {
        $model->delete();
    }

    /** Hook for child controllers: remember the parent id taken from a nested route. */
    protected function nested(?string $id): void
    {
    }
}
