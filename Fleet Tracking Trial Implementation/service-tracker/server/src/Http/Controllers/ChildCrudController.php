<?php

namespace Jalal\ServiceTracker\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * CRUD for records that belong to a visit (stops, jobs, damage). The visit comes from the
 * nested route (`visits/{visit}/...`) or from `service_visit_uuid` in the body / query.
 */
abstract class ChildCrudController extends CrudController
{
    /** `service_visit_uuid` (uuid or public id) is resolved in scope(), not matched literally. */
    protected array $filterColumns = [];

    /** Parent visit id from a nested route (`visits/{id}/stops`, `visits/{id}/jobs`, `visits/{id}/stops/{stop}/...`). */
    protected ?string $nestedVisit = null;

    protected function nested(?string $id): void
    {
        $this->nestedVisit = $id ?: null;
    }

    protected function routeVisit(Request $request): ?ServiceVisit
    {
        return $this->nestedVisit ? $this->findModel(ServiceVisit::class, $this->nestedVisit) : null;
    }

    protected function scope($query, Request $request)
    {
        if ($visit = $this->routeVisit($request)) {
            $this->authorizeVisit($visit);
            $query->where('service_visit_uuid', $visit->uuid);
        }
        if ($request->filled('service_visit_uuid')) {
            $v = $this->findModel(ServiceVisit::class, (string) $request->input('service_visit_uuid'));
            $this->authorizeVisit($v);
            $query->where('service_visit_uuid', $v->uuid);
        }
        if ($this->isTechnician()) {
            $query->whereIn('service_visit_uuid', ServiceVisit::where('technician_uuid', optional($this->user())->uuid)->select('uuid'));
        }
        return $query;
    }

    /** Resolve + authorise the owning visit for a write. */
    protected function visitForWrite(array $data, ?Model $existing, Request $request): ServiceVisit
    {
        $visit = $this->routeVisit($request);
        if (!$visit) {
            $ref = $data['service_visit_uuid'] ?? optional($existing)->service_visit_uuid;
            abort_unless($ref, 422, 'service_visit_uuid is required.');
            $visit = $this->findModel(ServiceVisit::class, (string) $ref);
        }
        $this->authorizeVisit($visit);
        return $visit;
    }

    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $data = parent::prepare($data, $existing, $request);
        $visit = $this->visitForWrite($data, $existing, $request);
        if (!$existing) {
            $data['service_visit_uuid'] = $visit->uuid;
        } else {
            unset($data['service_visit_uuid']); // a child never moves between visits
        }
        return $data;
    }

    protected function canDelete(Model $model): void
    {
        // Supervisors delete anything; technicians may remove rows on their own visit only.
        $visit = ServiceVisit::where('uuid', $model->service_visit_uuid)->first();
        if ($visit) {
            $this->authorizeVisit($visit);
        }
    }

    /** FleetOps vendor display name, if the class is present. */
    protected function vendorName(?string $uuid): ?string
    {
        $class = '\\Fleetbase\\FleetOps\\Models\\Vendor';
        if (!$uuid || !class_exists($class)) {
            return null;
        }
        $v = $class::where('uuid', $uuid)->first();
        return $v ? $v->name : null;
    }
}
