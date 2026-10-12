<?php

namespace Jalal\ServiceTracker\Observers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Jalal\ServiceTracker\Models\DamageRecord;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;
use Jalal\ServiceTracker\Models\ServiceJob;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Register with ServiceVisit::observe(ServiceVisitObserver::class) in the service provider.
 *
 * When a visit becomes closed (car back at LSA) this writes into FleetOps, all inside one DB
 * transaction and all idempotent (re-saving a closed visit, or running onClose() again, creates
 * nothing twice):
 *   - one Maintenance row per job that is not Pending            (marker: service-tracker:job:<uuid>)
 *   - the MaintenanceSchedule of (vehicle, service) is updated   (last_service_odometer, next_due_odometer = odometer + interval_km)
 *   - one Issue per damage record with condition "New"           (damage_records.issue_uuid)
 * Every FleetOps class is guarded with class_exists() and only columns that exist are written, so the
 * extension keeps working if FleetOps is absent or its schema differs slightly.
 */
class ServiceVisitObserver
{
    public const MARK = 'service-tracker:job:';
    public const VEHICLE = 'Fleetbase\\FleetOps\\Models\\Vehicle';
    public const VENDOR = 'Fleetbase\\FleetOps\\Models\\Vendor';

    public function saved(ServiceVisit $visit): void
    {
        if ($visit->status !== 'closed') {
            return;
        }
        if (!($visit->wasRecentlyCreated || $visit->wasChanged('status') || $visit->wasChanged('returned_at'))) {
            return;
        }
        $this->onClose($visit);
    }

    /** Public so the import command / a repair job can replay it safely. */
    public function onClose(ServiceVisit $visit): void
    {
        try {
            DB::transaction(function () use ($visit) {
                $visit->loadMissing(['jobs', 'damages']);
                $this->recordMaintenance($visit);
                foreach ($visit->damages as $damage) {
                    if ($damage->condition === 'New') {
                        self::raiseIssue($damage, $visit);
                    }
                }
            });
        } catch (\Throwable $e) {
            // Rethrown so the surrounding request transaction rolls back and the close can be retried cleanly.
            Log::error('[service-tracker] close hook failed for visit ' . $visit->uuid . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /* ------------------------------------------------------------ maintenance + schedule */

    protected function recordMaintenance(ServiceVisit $visit): void
    {
        $maintenanceClass = '\\Fleetbase\\FleetOps\\Models\\Maintenance';
        if (!class_exists($maintenanceClass) || !$visit->vehicle_uuid) {
            return;
        }
        // FleetOps stores the FQCN (Vehicle::class, `->where('maintainable_type', static::class)`); never getMorphClass(),
        // which resolves to the legacy `Fleetbase\\Models\\Vehicle` alias from FleetOpsServiceProvider::registerMorphMap().
        $morph = self::VEHICLE;
        $vendorClass = self::VENDOR;
        $table = (new $maintenanceClass())->getTable();
        $noteCol = collect(['notes', 'summary', 'description'])->first(fn ($c) => Schema::hasColumn($table, $c));

        foreach ($visit->jobs as $job) {
            if ($job->status === 'Pending') {
                continue;
            }
            $item = $job->catalog_item_uuid ? ServiceCatalogItem::where('uuid', $job->catalog_item_uuid)->first() : null;
            $interval = $item ? (int) $item->interval_km : 0;
            $marker = self::MARK . $job->uuid;

            $exists = $noteCol && $maintenanceClass::where('company_uuid', $visit->company_uuid)->where($noteCol, 'like', '%' . $marker . '%')->exists();
            if (!$exists) {
                $text = trim($job->service_name . ($job->vendor_name ? ' (' . $job->vendor_name . ')' : '') . ($job->parts_replaced ? ' - parts: ' . $job->parts_replaced : '')) . ' [' . $marker . ']';
                $attrs = VisitMapper::filterColumns($table, [
                    'company_uuid' => $visit->company_uuid,
                    'work_order_uuid' => $visit->work_order_uuid,
                    'maintainable_type' => $morph,
                    'maintainable_uuid' => $visit->vehicle_uuid,
                    'type' => $interval > 0 ? 'preventive' : 'corrective',
                    'status' => 'completed',
                    'priority' => 'medium',
                    'scheduled_at' => $visit->arrived_at_lsa,
                    'started_at' => $visit->picked_up_at ?: $visit->arrived_at_lsa,
                    'completed_at' => $visit->returned_at,
                    'performed_by_type' => $job->vendor_uuid ? $vendorClass : null,
                    'performed_by_uuid' => $job->vendor_uuid ?: null,
                    // maintenances.odometer is UNSIGNED BIGINT
                    'odometer' => $visit->odometer !== null ? max(0, (int) round((float) $visit->odometer)) : null,
                    'summary' => $job->service_name . ' [' . $marker . ']',
                    'notes' => $text,
                    'meta' => [
                        'service_tracker' => true,
                        'service_job_uuid' => $job->uuid,
                        'service_visit_uuid' => $visit->uuid,
                        'vendor_name' => $job->vendor_name,
                        'parts_replaced' => $job->parts_replaced,
                    ],
                ]);
                // `summary` and `notes` both carry the marker; keep whichever columns exist
                $m = new $maintenanceClass();
                $m->forceFill($attrs)->save();
            }

            $this->updateSchedule($visit, $job, $item, $interval);
        }
    }

    protected function updateSchedule(ServiceVisit $visit, ServiceJob $job, ?ServiceCatalogItem $item, int $interval): void
    {
        $class = '\\Fleetbase\\FleetOps\\Models\\MaintenanceSchedule';
        if (!class_exists($class) || $visit->odometer === null) {
            return;
        }
        $override = $job->next_due_override_km !== null ? (int) $job->next_due_override_km : null;
        if ($interval <= 0 && $override === null) {
            return; // nothing recurring to schedule
        }
        $nextDue = $override ?? ((int) round((float) $visit->odometer) + $interval);
        $odo = max(0, (int) round((float) $visit->odometer)); // maintenance_schedules.*_odometer are UNSIGNED BIGINT
        $nextDue = max(0, $nextDue);

        $table = (new $class())->getTable();
        if (!Schema::hasColumn($table, 'name')) {
            return;
        }
        $morph = self::VEHICLE;
        $subjectCol = Schema::hasColumn($table, 'subject_uuid') ? 'subject_uuid' : (Schema::hasColumn($table, 'maintainable_uuid') ? 'maintainable_uuid' : null);
        if (!$subjectCol) {
            return;
        }

        $q = $class::where('company_uuid', $visit->company_uuid)->where($subjectCol, $visit->vehicle_uuid)->where('name', $job->service_name);
        $schedule = $q->first();

        $values = VisitMapper::filterColumns($table, [
            'last_service_odometer' => $odo,
            'last_service_date' => $visit->returned_at,
            'next_due_odometer' => $nextDue,
            'next_due_date' => $job->next_due_date,
            // FleetOps: interval_method time|distance|engine_hours. interval_value/interval_unit are the DATE interval
            // (MaintenanceSchedule::resetAfterCompletion does `add("$interval_value $interval_unit")`), so km must go to interval_distance.
            'interval_method' => 'distance',
            'interval_type' => 'distance',
            'interval_distance' => $interval > 0 ? $interval : null,
            'status' => 'active',
        ]);

        if ($schedule) {
            // never move a schedule backwards because an older visit is replayed
            if ($schedule->last_service_odometer !== null && (float) $schedule->last_service_odometer > $odo) {
                return;
            }
            $schedule->forceFill($values)->save();
            return;
        }

        $schedule = new $class();
        $schedule->forceFill($values + VisitMapper::filterColumns($table, [
            'company_uuid' => $visit->company_uuid,
            'name' => $job->service_name,
            'subject_type' => $morph,
            'subject_uuid' => $visit->vehicle_uuid,
            'type' => 'preventive',
            'default_priority' => 'normal',
            'meta' => ['service_tracker' => true, 'catalog_item_uuid' => $item ? $item->uuid : null],
        ]))->save();
    }

    /* ------------------------------------------------------------ issues */

    /**
     * Raise a FleetOps Issue for a damage record found after pickup (condition New). Idempotent:
     * a record that already has issue_uuid is left alone. Returns the issue uuid or null.
     */
    public static function raiseIssue(DamageRecord $damage, ServiceVisit $visit): ?string
    {
        $class = '\\Fleetbase\\FleetOps\\Models\\Issue';
        if (!class_exists($class) || $damage->condition !== 'New') {
            return $damage->issue_uuid;
        }
        if ($damage->issue_uuid) {
            return $damage->issue_uuid;
        }
        $table = (new $class())->getTable();
        $marker = 'service-tracker:damage:' . $damage->uuid;
        if (Schema::hasColumn($table, 'report') && ($found = $class::where('company_uuid', $visit->company_uuid)->where('report', 'like', '%' . $marker . '%')->first())) {
            $damage->forceFill(['issue_uuid' => $found->uuid])->saveQuietly();
            return $found->uuid;
        }

        $report = trim(sprintf(
            'New damage found during service visit %s (plate %s): %s - %s, severity %s, stage %s.%s [%s]',
            $visit->public_id,
            $visit->plate,
            $damage->location ?: 'location n/a',
            $damage->damage_type ?: 'type n/a',
            $damage->severity ?: 'n/a',
            $damage->stage ?: 'n/a',
            $damage->notes ? ' Notes: ' . $damage->notes : '',
            $marker
        ));
        $priority = ['Major' => 'high', 'Moderate' => 'medium'][$damage->severity] ?? 'low';

        $attrs = VisitMapper::filterColumns($table, [
            'company_uuid' => $visit->company_uuid,
            // issues.location is a NOT NULL spatial column without default (MySQL 1364 when omitted); mirrors
            // InspectionSubmission::failureLocation(): vehicle position, else an empty point.
            'location' => self::issueLocation($damage->vehicle_uuid ?: $visit->vehicle_uuid),
            'title' => 'Damage found: ' . trim(($damage->location ?: 'vehicle') . ' - ' . ($damage->damage_type ?: 'damage'), ' -'),
            'vehicle_uuid' => $damage->vehicle_uuid ?: $visit->vehicle_uuid,
            'reported_by_uuid' => $damage->recorded_by_uuid,
            'category' => 'Vehicle',
            'type' => 'Damage',
            'report' => $report,
            'priority' => $priority,
            'status' => 'pending',
            'meta' => ['service_tracker' => true, 'damage_record_uuid' => $damage->uuid, 'service_visit_uuid' => $visit->uuid],
        ]);

        $issue = new $class();
        $issue->forceFill($attrs)->save();
        $damage->forceFill(['issue_uuid' => $issue->uuid])->saveQuietly();
        return $issue->uuid;
    }

    /** Point for issues.location: the vehicle's last position when it has one, else (0,0). */
    protected static function issueLocation(?string $vehicleUuid)
    {
        $pointClass = '\\Fleetbase\\LaravelMysqlSpatial\\Types\\Point';
        if (!class_exists($pointClass)) {
            return null;
        }
        $vehicle = null;
        if ($vehicleUuid) {
            try {
                $vehicle = (self::VEHICLE)::withoutGlobalScopes()->where('uuid', $vehicleUuid)->first();
            } catch (\Throwable $e) {
                $vehicle = null;
            }
        }
        $loc = $vehicle ? $vehicle->location : null;
        if ($loc instanceof $pointClass) {
            return $loc;
        }

        return new $pointClass(0, 0);
    }
}
