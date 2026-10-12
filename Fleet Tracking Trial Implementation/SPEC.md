# service-tracker — shared build contract (all agents must follow)

Vendor namespace: `Jalal\ServiceTracker`  Composer: `jalal/service-tracker-api`  npm: `@jalal/service-tracker-engine`
Ember engine name/mount: `service-tracker`. Targets Fleetbase FleetOps 0.6.71 (pinned). FleetOps is NEVER modified; reference its models
(`Fleetbase\FleetOps\Models\{Vehicle,Vendor,WorkOrder,Maintenance,MaintenanceSchedule,Issue,InspectionSubmission}`) and core `Fleetbase\Models\{File,User,Company}`.
Reference source of truth for logic: `src/Index.html` (calcVisit L448, stopInfo L441, svcInfo L424, nextDueOf L418). Port line-for-line.

## Layout (inside /home/claude/build/service-tracker)
server/src/{migrations,Models,Services,Observers,Http/Controllers/{Internal/v1,Api/v1},Http/Resources,Exports,Reports,Seeders,Console/Commands,Providers}, server/tests, server/composer.json, server/resources/views/report.blade.php, server/data/{catalog.json,...}
addon/ (Ember engine), package.json, index.js ; README.md

## Tables (all: id bigint, uuid, public_id, company_uuid, timestamps, softDeletes, legacy_ref nullable string idx; money in integer minor units/cents)
- service_lanes: name, target_hours decimal(6,2), sort
- service_catalog_items: name, category, lane_uuid, suggested_vendor_uuid null, interval_km unsigned int default 0
- service_visits: work_order_uuid (FleetOps WorkOrder, nullable), vehicle_uuid null, plate, make_model, technician_uuid null (user), odometer decimal null, arrived_at_lsa, picked_up_at, returned_at (all nullable datetime UTC), pickup_inspected bool, return_inspected bool, lane, target_hours null, notes text, status string (not_started|in_progress|closed, derived but cached)
- vendor_stops: service_visit_uuid, vendor_uuid (FleetOps vendor) null, vendor_name, arrived_at, approval_requested_at, approval_received_at, left_at, notes
- service_jobs: service_visit_uuid, catalog_item_uuid null, service_name, vendor_uuid null, vendor_name, approval_needed bool, status (Pending|In progress|Done), parts_replaced text, next_due_override_km null, next_due_date null date, notes
- damage_records: service_visit_uuid, vehicle_uuid null, stage (Pickup inspection|Return inspection|Vendor reported), location, damage_type, severity (Minor|Moderate|Major), condition (Pre-existing|New), occurred_at, recorded_by_uuid null, client_notified (Yes|No), issue_uuid null, notes; photos = core File rows via polymorphic subject
- petty_cash_entries: entry_date date, vehicle_uuid null, plate, service_visit_uuid null, category, amount int cents, currency char(3) default SAR, paid_by, description, journal_uuid null; receipts = core Files
- (settings stored with Fleetbase `Setting` key `service-tracker.settings`: company, client, currency, reportOpts{turnaround,vendorTimes,photos,overdue,condPhotos,petty}, dropdown lists)
Public id prefixes: visit `visit`, stop `vstop`, job `sjob`, damage `dmg`, petty `pcash`, lane `lane`, catalog `svc`.

## Engine contract (pure PHP, no framework deps, 100% unit-testable)
`Services\StopTimer::info(array $stop): array{atVendor,appr,net}`; `Services\DueCalculator::{nextDueOf,svcInfo}`; `Services\TurnaroundCalculator::calc(array $visit,array $stops,array $jobs,array $ctx): array`
with keys identical to JS calcVisit output: stages[6], total, target, lane, status, big, bigName, owner, met, open, check, overdueN, worstOver, THV, perVendor, si[], sinfo[].
Inputs are plain arrays (ISO datetimes, camelCase JS-equivalent keys: arrived, picked, returned, odo; stop: vendor, arrived, apprReq, apprRec, left; job: service,nextOverride). Context carries catalog, lanes, all-jobs/all-visits for previous-due lookup, `now`. A thin mapper converts Eloquent models -> arrays.

## REST (prefix /int/v1/service-tracker/ for console, /v1/service-tracker/ for driver API)
visits, visits/{id}/stops, visits/{id}/jobs, vendor-stops, service-jobs, catalog, lanes, damage-records, petty-cash, vehicles/due, dashboard, reports/visit/{id}.pdf, exports/{full|period}.xlsx
Driver endpoints add: POST visits/{id}/stops/{stop}/check-in|approval-requested|approval-received|left ; POST uploads (multipart photos, client-side already 1000px JPEG q0.72); every write accepts `client_uuid` (idempotency key for offline retries — duplicate => return existing).
Roles: Supervisor (all), Technician (own visits create/update, damage create, petty cash create; no delete/settings).

## Quality bar
No placeholders/TODO stubs in shipped code. `php -l` clean, PHPUnit for engine runs here. Anything that cannot be executed here (needs a Fleetbase install / Ember build / RN build) must be written carefully against the audited APIs and listed in that area's `NOTES.md` under "Unverified here".
