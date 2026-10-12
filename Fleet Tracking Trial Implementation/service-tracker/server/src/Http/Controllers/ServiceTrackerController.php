<?php

namespace Jalal\ServiceTracker\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Jalal\ServiceTracker\Http\Resources\ServiceTrackerResource;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Shared helpers for every Service Tracker controller (console and driver API).
 *
 * Role model: Supervisor (and Fleetbase admins) can do everything. A user holding the
 * Technician role (and not Supervisor) may create/update only their own visits, create damage
 * and petty cash, and cannot delete or change settings/catalog/lanes.
 */
abstract class ServiceTrackerController extends Controller
{
    /** Console (Ember) responses are keyed `{plural: [...], meta}`; the driver API returns `{data, meta}`. */
    protected bool $internal = true;

    public function __construct()
    {
        // per-request lookup caches used by the resources (visit public ids, user names)
        ServiceTrackerResource::flushRefs();
    }

    protected function companyUuid(): string
    {
        $uuid = Session::get('company');
        if (!$uuid && ($u = Auth::user())) {
            $uuid = $u->company_uuid ?? null;
        }
        abort_unless($uuid, 401, 'No company in session.');
        return (string) $uuid;
    }

    protected function user()
    {
        return Auth::user();
    }

    protected function hasRole($user, string $role): bool
    {
        try {
            return $user && method_exists($user, 'hasRole') && $user->hasRole($role);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isSupervisor(): bool
    {
        $u = $this->user();
        if (!$u) {
            // `fleetbase.api` routes authenticated with a company API credential (no user behind the token):
            // company-level access, there is no technician identity to restrict to.
            return Session::get('company') !== null;
        }
        if ($this->hasRole($u, 'Supervisor')) {
            return true;
        }
        if (method_exists($u, 'isAdmin') && $u->isAdmin()) {
            return true;
        }
        // Users with no Technician role are treated as full users (console owners / admins).
        return !$this->hasRole($u, 'Technician');
    }

    protected function isTechnician(): bool
    {
        return !$this->isSupervisor();
    }

    protected function requireSupervisor(): void
    {
        abort_unless($this->isSupervisor(), 403, 'Supervisor role required.');
    }

    /** Technicians may touch only visits assigned to them (or that they created, i.e. no technician yet). */
    protected function authorizeVisit(ServiceVisit $visit): void
    {
        if ($this->isSupervisor()) {
            return;
        }
        $uid = optional($this->user())->uuid;
        abort_unless($visit->technician_uuid === $uid, 403, 'This visit belongs to another technician.');
    }

    /** Find by uuid or public id inside the company; 404 otherwise. */
    protected function findModel(string $class, ?string $id, array $with = []): Model
    {
        abort_if($id === null || $id === '', 404);
        $model = $class::where('company_uuid', $this->companyUuid())
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->with($with)
            ->first();
        abort_unless($model, 404, 'Not found.');
        return $model;
    }

    /** Idempotency: the record already created for this client_uuid (unique per company), if any. */
    protected function byClientUuid(string $class, ?string $clientUuid): ?Model
    {
        if (!$clientUuid) {
            return null;
        }
        return $class::where('company_uuid', $this->companyUuid())->where('client_uuid', $clientUuid)->first();
    }

    /**
     * Single record. Console (Ember Data RESTSerializer) needs the root key `{service_visit: {...}}`;
     * the driver API returns the bare resource (`id` = public id).
     */
    protected function respondOne($resource, int $status = 200, ?string $key = null): JsonResponse
    {
        $payload = $resource->resolve(request());
        $key = $key ?? (isset($this->singular) ? $this->singular : null);
        if ($this->internal && $key) {
            $payload = [$key => $payload];
        }
        return response()->json($payload, $status);
    }

    protected function respondList($paginatorOrCollection, string $resourceClass, string $plural, array $extraMeta = []): JsonResponse
    {
        $isPage = $paginatorOrCollection instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
        $items = $isPage ? $paginatorOrCollection->getCollection() : $paginatorOrCollection;
        ServiceTrackerResource::primeRefs($items);
        $rows = $resourceClass::collection($items)->resolve(request());
        $meta = $isPage
            ? ['total' => $paginatorOrCollection->total(), 'per_page' => $paginatorOrCollection->perPage(), 'current_page' => $paginatorOrCollection->currentPage(), 'last_page' => $paginatorOrCollection->lastPage()]
            : ['total' => count($rows)];
        $meta += $extraMeta;
        return response()->json([$this->internal ? $plural : 'data' => $rows, 'meta' => $meta]);
    }

    /** SQL fragment: plate column without spaces, dashes, dots and underscores, upper case (VisitMapper::plateKey). */
    protected function plateExpr(string $col = 'plate'): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE({$col},' ',''),'-',''),'.',''),'_',''))";
    }

    /** Substring match on the normalised plate, so "abc 1234", "ABC-1234" and "ABC1234" all find "ABC 1234". */
    protected function wherePlateLike($query, string $col, ?string $input)
    {
        $key = VisitMapper::plateKey($input);
        return $key === '' ? $query : $query->whereRaw($this->plateExpr($col) . ' like ?', ['%' . $key . '%']);
    }

    /** Accept both the stored status and its label ("In progress") as a filter value. */
    protected function statusValue(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $k = strtolower(trim(str_replace(['-', ' '], '_', $v)));
        return in_array($k, ['not_started', 'in_progress', 'closed'], true) ? $k : $v;
    }

    protected function paginate(\Illuminate\Database\Eloquent\Builder $q, Request $request)
    {
        if ($request->boolean('all')) {
            return $q->get();
        }
        $limit = max(1, min((int) $request->input('limit', 30), 1000));
        return $q->paginate($limit, ['*'], 'page', max(1, (int) $request->input('page', 1)));
    }

    /** Apply `sort=-col,col2` limited to $allowed. */
    protected function applySort($q, Request $request, array $allowed, string $default = '-created_at')
    {
        $sorts = array_filter(explode(',', (string) $request->input('sort', $default)));
        foreach ($sorts as $s) {
            $dir = str_starts_with($s, '-') ? 'desc' : 'asc';
            $col = ltrim($s, '-+');
            if (in_array($col, $allowed, true)) {
                $q->orderBy($col, $dir);
            }
        }
        return $q;
    }

    protected function flag($v): ?bool
    {
        return $v === null ? null : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
