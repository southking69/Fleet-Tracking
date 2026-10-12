# Service Tracker console engine - notes

## Unverified here

This was written in an environment with **no Fleetbase install and no Ember build**. What was actually run:

* `@babel/parser` (decorators + class properties) over every `.js` file in `addon/`, `app/` and the root: 0 errors.
* `@glimmer/syntax` `preprocess` over every `.hbs` template: 0 errors.
* `ember-template-lint` (config `.template-lintrc.js`) over `addon/**/*.hbs`: 0 errors.
* `eslint` 8 + `eslint-plugin-ember` 11 + `@babel/eslint-parser` over `addon app index.js ember-cli-build.js`: 0 errors.
* `node --check` on `index.js`, `ember-cli-build.js`, `config/environment.js`, `.eslintrc.js`, `.template-lintrc.js`.
* A script verified that every `service-tracker.*` translation key referenced in code exists in both `en-us.yaml` and `ar-ae.yaml` (350 keys each).

Not verified (needs a full Fleetbase console build and a running API):

1. The engine compiles and mounts (ember-engines wiring, `@jalal/service-tracker-engine` module prefix, `dependencies.services` list).
2. `@fleetbase/ember-core` import paths: `adapters/application`, `serializers/application`; the `fetch` service API (`get/post/put/delete(path, data, { namespace })`), `notifications.serverError`, and `fetch.host` (used to build URLs for binary/multipart calls, with `config:environment` as fallback).
3. `addon/extension.js` menu registration (`universe.getService('menu').registerHeaderMenuItem` / `registerMenuItem`) against the 0.6.x universe API.
4. Cross-engine models `vendor`, `vehicle`, `user` being resolvable from this engine's store (they are wrapped in try/catch and degrade to empty lists).
5. Tailwind classes: the console's Tailwind `content` globs must include this engine's `addon/**/*.{hbs,js}` for the classes to be generated (Fleetbase extensions normally are; the arbitrary variant `[&.active]:` needs Tailwind >= 3.1).
6. ember-intl ICU plurals for Arabic (`one`/`two`/`other` only are provided; `few`/`many` fall through to `other`).
7. PDF preview: the report is fetched with the browser `fetch` (session bearer token) and shown in an `<iframe>` from a blob URL.
8. Ember Data version quirks: `AdapterPopulatedRecordArray#toArray()` and `.firstObject` are used (fine on 4.x, deprecated on 5.x).

## Server contract assumed (reconcile with `server/`)

Everything below is what the UI reads/writes. If the PHP side differs, adjust `addon/utils/resources.js`, the models, or the
`service-tracker-api` service. All paths are under `/int/v1/service-tracker/`.

**Ember Data resources** (model -> path -> JSON root key plural / singular), snake_case attributes, ids are `public_id`:

| model | path | roots |
|-------|------|-------|
| `service-visit` | `visits` | `visits` / `visit` |
| `vendor-stop` | `vendor-stops` | `vendor_stops` / `vendor_stop` |
| `service-job` | `service-jobs` | `service_jobs` / `service_job` |
| `damage-record` | `damage-records` | `damage_records` / `damage_record` |
| `petty-cash-entry` | `petty-cash` | `petty_cash` / `petty_cash_entry` |
| `service-catalog-item` | `catalog` | `catalog` / `catalog_item` |
| `service-lane` | `lanes` | `lanes` / `lane` |

* Attribute names follow `SPEC.md` table columns (`arrived_at_lsa`, `picked_up_at`, `returned_at`, `odometer`, `pickup_inspected`, `vendor_name`, `approval_needed`, `next_due_override_km`, `entry_date`, `amount` in minor units, ...). Extra read-only display fields the UI uses: visit `technician_name`, `calc`, `pickup_photos`, `return_photos`; damage/petty `service_visit_public_id`, `recorded_by_name`, `photos` / `receipts` (`[{ uuid, url, original_filename }]`); catalog `lane_name`, `suggested_vendor_name`.
* List filters sent as query params: visits `query`, `status`, `page`, `limit`, `sort`, `plate`; child resources `service_visit_uuid`; damage `plate`, `severity`, `condition`, `stage`; petty `from`, `to`, `plate`, `paid_by`. Pagination meta: `meta.last_page`.
* `visit.calc` is the TurnaroundCalculator output with the JS key names (`stages[6]`, `total`, `target`, `lane`, `status`, `big`, `bigName`, `owner`, `met`, `open`, `check`, `overdueN`, `worstOver`, `THV`, `perVendor`, `si[]`, `sinfo[]`). It must be present on both list and single responses and refreshed in every `PUT visits/{id}` response. `si[i]` = `{atVendor, appr, net}` per vendor stop and `sinfo[i]` = `{lane, target, nextDue, prev, kmOver, status, ...}` per job, in **`created_at` ascending** order (matched by `uuid` if the entries carry one). `perVendor` may be an array of numbers (with `calc.vendorNames`), an array of `{name, hours}` or a `{name: hours}` map.
* Catalog rename: the UI sends `rename_cascade: true` with the PUT when `name` changed; the server must update `service_name` of the jobs.
* Lanes deletion should fail with a validation error when catalog items still use the lane.

**Non-model endpoints** (JSON keys are camelised by the client except `settings`):

* `GET settings` -> `{ settings }`; `PUT settings` `{ settings }` (keys as in SPEC: `company, client, currency, reportOpts{turnaround,vendorTimes,photos,overdue,condPhotos,petty}`, lists `categories, damageLocations, damageTypes, severities, stages, svcStatuses, expenseCats`, flags `excelTiming, excelPetty, excelVehicles`). Vendors/staff lists of the original are replaced by FleetOps vendors and Fleetbase users.
* `GET vehicles/due[?q=]` -> `{ vehicles: [{ key, plate, makeModel, visits, jobs, lastVisit, lastOdometer, inService, overdue, dueSoon, damages, pettyCash }] }`; `GET vehicles/due?plate=KEY` -> `{ vehicle:{...same + closed}, due:[{service,lastDoneKm,nextDueKm,kmRemaining,status}], history:[{date,visitId,service,vendor,odometer,nextDueKm,status}] }`.
* `GET dashboard?from&to` -> `{ kpis{visits,closed,inProgress,jobs,stops,pettyCash,avgTurnaround,withinTargetPct,openOver24}, stages[6]{avg,share,owner}, owners[{owner,closed}], lanes[{name,target,closed,avg,pctWithin}], vendors[{name,stops,jobsDone,avgPerStop,avgApproval,avgNet}], categories[{category,jobs}], damage{records,newRecords,closedNoPickupInspection,closedNoReturnInspection,closedNoPickupPhotos,closedNoReturnPhotos,closedDataProblems}, compliance{comparable,overdue,pctOverdue,avgKmOver,worstKmOver,visitsWithOverdue,missingOdometer} }`.
* `GET reports/visit/{public_id}.pdf?turnaround&vendor_times&photos&overdue&cond_photos&petty` (0/1) -> `application/pdf`.
* `GET exports/full.xlsx`; `GET exports/period.xlsx?from&to&basis=arrived|returned&timing&petty&vehicles[&only=petty]`.
* `POST uploads` multipart: `subject_type` (`damage_record|petty_cash_entry|service_visit`), `subject_uuid`, `collection` (`photos|receipts|pickup_photos|return_photos`), `photos[]` -> `{ files:[{uuid,url,original_filename}] }`; `DELETE uploads/{file_uuid}`. (SPEC only lists `uploads` for the driver API; the console reuses the same shape under `/int/v1`.)
* Petty cash `meta.summary` `{total,count,cars,byCategory}` is used if present; otherwise KPIs are summed over the loaded rows (limit 200).

## Gaps versus the original `Index.html`

* No JSON backup download / import, no "Load sample data", no "Erase all data" (storage is now the Fleetbase database; seeding and wipe are server/CLI concerns).
* Data & Export does not show the "N visits in this period" counter.
* Vendors (4 fixed slots) and Staff lists are not configurable in Settings; FleetOps vendors and Fleetbase users are used instead.
* The on-screen paper preview of the report is replaced by the server PDF in an iframe.
* Visit numbering with device initials (V-JA0001) is replaced by server `public_id`.
* No offline/queued writes in the console (the driver app covers offline).
* No automated tests for the engine (no Ember toolchain here).
* Photo lightbox is a plain link opening the image in a new tab.
* Plate-vehicle linking only matches FleetOps `plate_number` exactly when typing a plate on a visit.

## Files outside the requested ownership list

Created because an Ember engine needs them: `config/environment.js`, `translations/en-us.yaml`, `translations/ar-ae.yaml`,
`.eslintrc.js`, `.template-lintrc.js`. `translations/` must sit at the package root for ember-intl to pick it up.
