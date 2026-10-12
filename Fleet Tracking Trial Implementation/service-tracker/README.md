# @jalal/service-tracker-engine

Fleetbase **console** extension (Ember engine) for the Jalal Service Tracker. It is the UI half of
`jalal/service-tracker-api` and talks to `/int/v1/service-tracker/*`. It targets Fleetbase / FleetOps 0.6.71
and never modifies FleetOps: vendors, vehicles and users are read from the console's existing Ember Data models.

## What you get

| Menu entry (tabs)  | Route                         | Content |
|--------------------|-------------------------------|---------|
| Visits             | `visits`, `visits.view`       | Searchable list with status filter. Detail page: visit details, condition photos, vendor stops with **Now** buttons, jobs with catalog type-ahead, petty cash, damage, and a live summary sidebar (6-stage bar chart, owner, biggest delay, met/missed, data check) |
| Vehicles           | `vehicles`, `vehicles.view`   | Due list and per-car history (visits, next services due, service history, damage, petty cash) |
| Petty Cash         | `petty-cash`                  | Period / plate / paid-by filters, category KPIs, receipts, Excel download |
| Damage Log         | `damage`                      | All damage records with filters, inline edit and photo management |
| Dashboard          | `dashboard`                   | All KPI widgets from the server `dashboard` document |
| Client Report      | `report`                      | Six toggles, server-rendered PDF preview, download, print |
| Service Catalog    | `catalog`                     | Editable catalog (rename cascades to jobs), lanes, vendors, intervals |
| Settings           | `settings`                    | Company / client / currency (SAR), lanes and targets, dropdown lists |
| Data & Export      | `export`                      | Full workbook and date-range workbook (`exports/full.xlsx`, `exports/period.xlsx`) |

Reusable components: `DatetimeNowField`, `CatalogPicker`, `PhotoUploader` (drag, paste, camera; canvas resize to
1000 px JPEG quality 0.72), `StageBar`, `StatusBadge`, `PeriodPicker` (Today, Last 7 days, Last 30 days, This month,
Last month), plus `SelectField`, `KpiCard`, `VisitSummary`, `VendorStopCard`, `JobCard`, `DamageEditor`, `PettyEditor`.

Design rule: **no business calculation in JavaScript.** Turnaround stages, totals, target, lane, owner, met/missed,
data check, due status and every dashboard figure are rendered from the server `calc` / dashboard payloads. The JS only
formats values and sizes bars.

## Install (self-hosted Fleetbase)

```bash
# in the Fleetbase console (ember) app
pnpm add @jalal/service-tracker-engine
# in the Fleetbase API
composer require jalal/service-tracker-api
php artisan migrate
```

Rebuild the console. The extension loader reads the `fleetbase-extension` keyword and `fleetbase.route`
(`service-tracker`) from `package.json`, calls `addon/extension.js` and mounts the engine at `/service-tracker`.

## Layout

```
addon/               engine source (routes, controllers, templates, components, models, adapters, serializers, services, helpers, utils)
app/                 re-exports so the host resolver can find models, adapters, serializers, services, components, helpers
translations/        en-us.yaml, ar-ae.yaml (ember-intl, namespace service-tracker.*)
config/environment.js, index.js, ember-cli-build.js   standard ember-engines scaffolding
NOTES.md             assumptions about the server contract and what has not been verified
```

## Translations

English and Arabic live in `translations/`. All UI strings use `service-tracker.*` keys; data-driven lists
(categories, locations, statuses ...) are edited in Settings and shown as typed.

## Lint

```bash
pnpm lint            # eslint + ember-template-lint
```
