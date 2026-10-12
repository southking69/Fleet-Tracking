# service-tracker (server)

Fleetbase extension backend: `jalal/service-tracker-api`, namespace `Jalal\ServiceTracker`. Targets FleetOps 0.6.71.

## Install

1. Require the package in your Fleetbase `api/` app (path repository or VCS):
   ```
   composer require jalal/service-tracker-api
   ```
   The provider `Jalal\ServiceTracker\Providers\ServiceTrackerServiceProvider` is auto-discovered.
2. Run the migrations (7 tables: `service_lanes`, `service_catalog_items`, `service_visits`, `vendor_stops`, `service_jobs`, `damage_records`, `petty_cash_entries`):
   ```
   php artisan migrate
   ```
3. Create the permission policies/roles (`php artisan fleetbase:create-permissions`; schema in `src/Auth/Schemas/ServiceTracker.php`) and seed existing companies (lanes Express 12h / Standard 24h / Heavy 48h, the 145-row service catalog, default settings, Supervisor and Technician policies). New companies are seeded automatically on creation.
   ```
   php artisan db:seed --class="Jalal\ServiceTracker\Seeders\ServiceTrackerSeeder"
   ```
   Catalog rows keep the suggested vendor *name*; after creating the vendors in FleetOps (Local Mechanic, Petromin, Tyre Vendor, Other Specialist) re-run the seeder, or call `ServiceTrackerSeeder::relinkVendors($companyUuid)`, to link them.
4. Assign the `Supervisor` or `Technician` role to users in Console > IAM (the roles carry the `ServiceTrackerSupervisor` / `ServiceTrackerTechnician` policies; the controllers test the role names).
5. Clear caches: `php artisan optimize:clear`.

Run the data tests (no Laravel needed): `composer install && vendor/bin/phpunit tests/Unit`.
