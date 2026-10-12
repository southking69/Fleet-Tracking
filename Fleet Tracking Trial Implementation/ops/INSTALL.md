# INSTALL: stand up the Fleet Service Tracker trial on self-hosted Fleetbase

Audience: the person (or IT contractor) who will run the trial server. Target: Fleetbase (Docker, `fleetbase/fleetbase` main, FleetOps **0.6.71**, core-api **1.6.68**) with the `jalal/service-tracker-api` extension, the `@jalal/service-tracker-engine` console engine, and the Navigator fork.

## How to read the status tags

Every step carries one of these tags. Nothing in this document has been run end to end: the sandbox where it was written had no Docker daemon, no packagist and no registry access.

| Tag | Meaning |
|---|---|
| `[RUN]` | The command (or its logic) was actually executed during the build, on a copy or against reference data. What exactly is stated. |
| `[CHECKED]` | Statically validated (YAML parse, `bash -n`, shellcheck, `docker compose config`) but the real action was not performed. |
| `[NOT RUN]` | Written from reading the Fleetbase sources. Expect to adjust it on first contact. |

Conventions used below (set them once per shell):

```bash
export BUILD=~/build           # this repository (contains ops/, service-tracker/, navigator-screens/, src/)
export FB=~/fleetbase          # the Fleetbase checkout you will deploy from
```

---

## 0. Decisions and prerequisites

| Item | Recommendation | Why |
|---|---|---|
| Host | Linux VM, 4 vCPU, 8 GB RAM, 60 GB disk (photos grow it), Docker Engine 24+ and **Compose v2.24+** | the override uses the `!override` / `!reset` merge tags (Compose 2.24, Jan 2024) |
| Build machine | the same VM is fine; for the console image allow 6 GB RAM and 15 min | the console is an Ember build |
| Node / pnpm (only for step 2) | Node 22, pnpm **11.0.9** (`corepack enable`; Fleetbase pins it in `console/package.json`) | `pnpm add` must write a lockfile compatible with the upstream one |
| DNS + TLS | two names, e.g. `fleet.example.com` (console) and `api.fleet.example.com` (API + websocket), A records to the VM; Caddy gets certificates automatically | Navigator on phones must reach the API over HTTPS |
| Open ports | 80 and 443 only. Every Fleetbase port is bound to 127.0.0.1 by the override | MySQL and the stock console are otherwise exposed |
| Mail | an SMTP provider, or `MAIL_MAILER=log` for a closed trial | Fleetbase sends verification codes by mail; with `log` you read them from `storage/logs` |
| Hosting location | decide **where** (this is an open decision in `BUILD_REPORT.md`); photos of client vehicles leave your premises if the VM is a cloud VM. See CUTOVER.md section 7 | data protection |

How the extension gets into Fleetbase, and why it needs image builds: Fleetbase's `application`, `queue` and `scheduler` services run the prebuilt image `fleetbase/fleetbase-api:latest` with all PHP packages baked in, and the console is a **static Ember build**. A composer package or an Ember engine cannot be hot-added to those images, so we layer our own: `ops/docker/Dockerfile.api` (stock image + `composer require jalal/service-tracker-api` from a path repository) and `ops/docker/Dockerfile.console` (stock console build + the engine as a `file:` dependency). FleetOps and core are never modified.

### Submodule vs path repository (pick one before step 1)

| | git submodule at `packages/service-tracker` | plain copy (`prepare-fleetbase.sh` default) |
|---|---|---|
| Fits Fleetbase's own convention | yes (`.gitmodules` already lists fleetops, core-api, ... under `packages/`) | no |
| Update flow | `git submodule update --remote`, commit the pointer in your Fleetbase fork | re-run the script after every change |
| Needs an extension repo | yes: publish `service-tracker/` (the directory with `package.json` and `server/`) as its own repo, e.g. `https://github.com/jalal-holding/service-tracker` (the URL in the engine's `package.json`). **Use an `https://` URL**; upstream's `.gitmodules` uses `git@github.com:` SSH URLs | no |
| Composer side | identical in both: a **path repository** to `packages/service-tracker/server` (copied, `symlink:false`, inside the API image) | same |
| Console side | identical in both: `file:./vendor/service-tracker` copy (the script copies the engine without `server/` and `tests/`) | same |
| When to prefer | anything beyond a throw-away trial; also the place where AGPL source obligations are easiest to meet (CUTOVER.md section 6) | a quick trial from this build directory |

Both are handled by `prepare-fleetbase.sh`: with a submodule it detects that `packages/service-tracker` already is the source and does not copy.

---

## 1. Get Fleetbase and pin it   `[NOT RUN]`

```bash
git clone https://github.com/fleetbase/fleetbase.git "$FB"
cd "$FB"
git log -1 --format='%H %cd'            # write this SHA down: it is what you tested
# optional but recommended: fork first (AGPL, CUTOVER.md section 6) and commit your changes to the fork
# git remote add jalal git@github.com:jalal-holding/fleetbase.git
```

Reference facts read from `fleetbase/fleetbase` main on 12 Oct 2026 (`[RUN]`: cloned and read; nothing built): API image runs PHP 8.2 on FrankenPHP/Octane; services are `database` (mysql:8.0-oracle), `cache` (redis 4), `socket` (SocketCluster), `application`, `queue`, `scheduler` (go-crond running `php artisan schedule:run` every minute), `console` (nginx, port 4200), `httpd` (nginx, port 8000 to `application:8000`). `api/deploy.sh` runs `mysql:createdb`, `migrate`, `sandbox:migrate`, `fleetbase:seed`, `fleetbase:create-permissions`, `registry:init`.

If you used the interactive `scripts/docker-install.sh` before: it **overwrites** `docker-compose.override.yml`. Use either that installer or this guide, not both on the same checkout.

## 2. Add the extension, the Docker files and the override   `[CHECKED]`

Optional submodule variant (do this instead of the plain copy):

```bash
cd "$FB"
git submodule add https://github.com/jalal-holding/service-tracker.git packages/service-tracker
```

Then:

```bash
corepack enable && corepack prepare pnpm@11.0.9 --activate     # skip with --no-pnpm below if you prefer to run pnpm yourself
bash "$BUILD/ops/scripts/prepare-fleetbase.sh" "$FB" --src "$BUILD/service-tracker"
```

What it does (all idempotent): copies the extension to `$FB/packages/service-tracker`; copies `Dockerfile.api`, `Dockerfile.console`, `Caddyfile.example` to `$FB/jalal-ops/`; installs `docker-compose.override.yml` and `.env` (from `ops/.env.example`) **only if absent**; copies the engine to `$FB/console/vendor/service-tracker`; runs `pnpm add "@jalal/service-tracker-engine@file:./vendor/service-tracker"` in `console/` (updates `console/package.json` and `console/pnpm-lock.yaml`; **commit both**); makes sure `api/.env` is a file (Docker otherwise creates a directory there and Laravel cannot boot).

Executed here: the script ran with `--no-pnpm` against a copy of the Fleetbase tree (it created the files above and was idempotent on a second run). The `pnpm add` branch was **not** run (no pnpm registry access), so check that `console/package.json` now lists `"@jalal/service-tracker-engine": "file:./vendor/service-tracker"` and that `console/pnpm-lock.yaml` changed:

```bash
cd "$FB" && git status --short | head -20
grep -n 'service-tracker-engine' console/package.json
```

Keep Docker's build context small (the repo's `.dockerignore` does not exclude the MySQL data directory that some checkouts contain):

```bash
printf 'docker/database/mysql\nbackups\n' >> "$FB/.dockerignore"
```

## 3. Secrets and environment   `[CHECKED]` (the compose file renders; secrets are yours)

```bash
cd "$FB"
# fill the empty values in .env (it was created from ops/.env.example, mode 600)
sed -i "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" .env
sed -i "s#^DB_ROOT_PASSWORD=.*#DB_ROOT_PASSWORD=$(openssl rand -hex 20)#" .env
sed -i "s#^DB_PASSWORD=.*#DB_PASSWORD=$(openssl rand -hex 20)#" .env
# then edit the hostnames and anything else:
${EDITOR:-nano} .env
```

Fields to set by hand: `API_FQDN`, `CONSOLE_FQDN`, `SESSION_DOMAIN`, mail (`MAIL_*`), and storage (below). Use hex passwords: the database password is embedded in `DATABASE_URL`.

**File storage** (`FILESYSTEM_DRIVER`):

* `public` (default): local disk. Photos and receipts live in the Docker volume `fleetbase_storage_app` (mounted at `/fleetbase/api/storage/app`). Fine for the trial. Back it up (CUTOVER.md section 4). The volume survives `docker compose down`, not `down -v`.
* `s3`: set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` (and `AWS_URL` / `AWS_USE_PATH_STYLE_ENDPOINT=true` for S3-compatible stores). The extension writes photos through `Storage::disk(config('filesystems.default'))`, so it follows the setting; **unverified for the extension's PDF path with a remote disk** (the PDF reads photo bytes back through the same disk call).
* `gcs`: `GOOGLE_CLOUD_PROJECT_ID`, `GOOGLE_CLOUD_STORAGE_BUCKET`, `GOOGLE_CLOUD_KEY_FILE` (path **inside** the container: mount the key file in the override).
* Pick the driver **before** the first photo is uploaded. Files already stored are addressed by the disk name recorded on the `files` row; switching later strands them unless you copy them over.

**Timezone Asia/Riyadh: what is and is not possible.** Fleetbase hard-codes `config('app.timezone') = 'UTC'` (`api/config/app.php`), stores UTC, and `fleetops:process-maintenance-triggers` calls `date_default_timezone_set('UTC')`. There is no environment variable that changes that, and the override must not try (and must not put `TZ` on MySQL: server-side `NOW()` defaults would then disagree with the UTC values Laravel writes; the override pins `--default-time-zone=+00:00`). What the override does: `TZ=Asia/Riyadh` on the app containers so log lines and the system clock read Riyadh time. The business timezone lives in the extension: `settings.timezone` (seeded `Asia/Riyadh`) controls how local times typed without an offset are interpreted, how exports/PDF/dashboard ranges render, and the importer's `--tz`. Scheduled jobs run on UTC: "daily" = 00:00 UTC = 03:00 Riyadh.

**Validate the compose file** (`[RUN]` against a copy of the Fleetbase tree with dummy secrets: renders, all 4 published ports on 127.0.0.1):

```bash
cd "$FB" && docker compose config -q && echo "compose OK"
docker compose config | grep -E 'host_ip|published'        # every host_ip must be 127.0.0.1
```

Note: variables exported in your shell override `.env`. If `docker compose config` shows an unexpected value (for example a cloud `AWS_*`), `env | grep AWS` first.

## 4. Console runtime configuration   `[NOT RUN]`

The console reads the API host from build-time env **and** (because the override builds with `DISABLE_RUNTIME_CONFIG=false`) from the mounted `console/fleetbase.config.json`. Write both, replacing the hostnames:

```bash
cd "$FB"
API=api.fleet.example.com
cat > console/fleetbase.config.json <<EOF
{
  "API_HOST": "https://$API",
  "SOCKETCLUSTER_HOST": "$API",
  "SOCKETCLUSTER_PORT": "443",
  "SOCKETCLUSTER_SECURE": "true",
  "SOCKETCLUSTER_PATH": "/socketcluster/"
}
EOF
cat > console/environments/.env.production <<EOF
API_HOST=https://$API
API_NAMESPACE=int/v1
API_SECURE=true
SOCKETCLUSTER_PATH=/socketcluster/
SOCKETCLUSTER_HOST=$API
SOCKETCLUSTER_SECURE=true
SOCKETCLUSTER_PORT=443
OSRM_HOST=https://router.project-osrm.org
EOF
```

(`console/environments/.env.production` is a tracked template in Fleetbase; this overwrites it in your checkout. The socket goes through the reverse proxy on 443 at `/socketcluster/`; see `ops/docker/Caddyfile.example`.) Leave the `EXTENSIONS` runtime key unset: when set, it restricts which extensions the console loads.

## 5. Build the images   `[NOT RUN]`

```bash
cd "$FB"
docker compose build application console          # 10-25 min the first time
```

* The API build runs `composer require jalal/service-tracker-api:^0.1` against a path repository inside the stock image and then **fails the build** if the package or its provider is not discovered. Composer must resolve `fleetbase/fleetops-api` to exactly **0.6.71** (the package pins it; the stock `composer.json` allows `^0.6.71`). If the base image carries a newer FleetOps the build stops with a conflict: pin `FLEETBASE_API_BASE_IMAGE` to an older tag, or relax the pin in `service-tracker/server/composer.json` after re-running the compat check against that FleetOps version (CI job `fleetbase-compat` / `php tests/Verify/verify_fleetbase_compat.php <refdir>`).
* `GITHUB_AUTH_KEY` (in `.env`) is only needed if composer must download Fleetbase packages from GitHub. It is passed as a build arg and therefore **visible in `docker history`** of the image: never push this image to a registry with a token baked in.
* The console build prints `[Fleetbase] Discovered N extension(s)` followed by the list: `@jalal/service-tracker-engine` must be among them (it is found by the `fleetbase-extension` + `ember-engine` keywords in the engine's `package.json`). If it is missing the `file:` dependency did not install: re-run step 2's `pnpm add`.

## 6. Start the stack   `[NOT RUN]`

```bash
cd "$FB"
docker compose up -d database cache
# wait for the database
until [ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q database)")" = healthy ]; do sleep 3; done
# the bundled MySQL user needs server-level CREATE for the sandbox database (same step Fleetbase's installer performs)
docker compose exec -T database sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "GRANT ALL PRIVILEGES ON *.* TO \"$MYSQL_USER\"@\"%\"; FLUSH PRIVILEGES;"'
docker compose up -d
docker compose ps
```

## 7. Migrate, seed, permissions   `[NOT RUN]`

Fleetbase's own `deploy.sh` does `migrate`, `sandbox:migrate`, `fleetbase:seed` and `fleetbase:create-permissions`; because our package is installed in the image, its seven migrations and its permission schema are included. Run it, then the extension-specific commands explicitly (they are idempotent and make the extension steps visible):

```bash
cd "$FB"
docker compose exec -T application bash -c "./deploy.sh"
docker compose exec -T application php artisan migrate --force
docker compose exec -T application php artisan fleetbase:create-permissions
docker compose exec -T application php artisan storage:link || true     # photo URLs for the local disk; harmless if already linked
docker compose exec -T application php artisan optimize:clear
```

Verify:

```bash
docker compose exec -T application php artisan migrate:status | grep -E 'service_(lanes|catalog_items|visits)|vendor_stops|service_jobs|damage_records|petty_cash'
# expect 7 lines, all "Ran"
docker compose exec -T application php artisan route:list --path=service-tracker | tail -n 5     # routes registered
docker compose exec -T application php artisan list | grep -E 'service-tracker|fleetops:process-maintenance'
# expect: service-tracker:import and fleetops:process-maintenance-triggers
```

If `route:list` shows nothing: the provider was not discovered. `docker compose exec -T application composer show jalal/service-tracker-api` and `grep ServiceTrackerServiceProvider bootstrap/cache/packages.php`.

## 8. Reverse proxy and HTTPS   `[NOT RUN]`

On the host (not in compose):

```bash
sudo apt-get install -y caddy                    # or your distro's method
sudo cp "$FB/jalal-ops/Caddyfile.example" /etc/caddy/Caddyfile
sudo "${EDITOR:-nano}" /etc/caddy/Caddyfile       # set the two hostnames
sudo systemctl reload caddy
curl -fsS https://api.fleet.example.com/health      # {"status": ...}
```

nginx or Traefik work equally well if they provide TLS, the websocket upgrade for `/socketcluster/`, request bodies up to ~100 MB, and a read timeout of at least 120 s (PDF/Excel generation). The stock `httpd` container is plain HTTP on 127.0.0.1:8000.

## 9. Onboarding, existing companies, settings   `[NOT RUN]`

1. Open `https://fleet.example.com` and complete the onboarding wizard (organisation + administrator). The administrator is the company owner and passes the Supervisor test automatically.
2. Seed the Service Tracker defaults for every company that exists (lanes Express 12 h / Standard 24 h / Heavy 48 h, the 145-service catalog, default settings, and, again, the permission policies). New companies are seeded by a hook; whether that hook fires inside Fleetbase's sign-up transaction is unverified, so always run this once:

```bash
cd "$FB"
docker compose exec -T application php artisan db:seed --class="Jalal\ServiceTracker\Seeders\ServiceTrackerSeeder"
docker compose exec -T application php artisan tinker --execute='echo "catalog: ".\Jalal\ServiceTracker\Models\ServiceCatalogItem::withoutGlobalScopes()->count()." lanes: ".\Jalal\ServiceTracker\Models\ServiceLane::withoutGlobalScopes()->count().PHP_EOL;'
# expect 145 and 3 per company (so 145 / 3 for the first company)
```

3. In the console: **Service Tracker > Settings**: company name, client name, currency `SAR`, timezone `Asia/Riyadh`, confirm the three lanes and their targets, and the dropdown lists. The Service Tracker entry appears in the console menu when the engine is built in; if it does not, step 5's console build did not include the engine.

## 10. Roles and users   `[NOT RUN]`

`fleetbase:create-permissions` creates two policies and two roles from the extension's schema: roles **Supervisor** (`ServiceTrackerSupervisor`: everything) and **Technician** (`ServiceTrackerTechnician`: create/update visits, stops, jobs; create damage and petty cash; read catalog/lanes/dashboard/reports/due; no delete, no settings, no exports). The controllers test the role **names** `Supervisor` / `Technician`.

1. Console > **IAM > Users > New user**: create the supervisors and technicians (e-mail invitation, or set a password).
2. Assign the role in IAM. Users with neither role (for example the company owner) are treated as full users.
3. Technicians who will use **Navigator** must also exist as FleetOps **Drivers** (Navigator is the driver app and logs in with a driver account). Create the driver from the user in FleetOps > Management > Drivers. **Unverified:** whether the Technician role attached to that user is honoured on the driver-API path (so the "own visits only" rule applies). Test it with UAT section 9 before relying on it (RISKS.md R-06).
4. Technicians see and edit only visits where they are the technician; supervisors see everything. Petty cash has no creator column, so a technician sees unlinked entries plus those on their own visits.

## 11. FleetOps vendors and vehicles   `[NOT RUN]`

Vendors and vehicles are FleetOps records; the extension only references them. Create them in the console (FleetOps > Management > Vendors / Vehicles) or through the FleetOps public API with an API key (Console > Developers > API keys):

```bash
KEY=flb_live_xxxxxxxx          # a LIVE API key of your company
API=https://api.fleet.example.com
for v in "Petromin" "Local Mechanic" "Tyre Vendor" "Other Specialist"; do
  curl -fsS -X POST "$API/v1/vendors" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
       -d "{\"name\":\"$v\",\"type\":\"workshop\"}" | head -c 200; echo
done
# vehicles (repeat per car): the plate is what links a visit to the car (spaces, dashes and case are ignored when matching)
curl -fsS -X POST "$API/v1/vehicles" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
     -d '{"plate_number":"3083 NER","make":"Toyota","model":"Hilux","status":"active","odometer":145559}' | head -c 200; echo
```

The four vendor names above are the ones the shipped catalog suggests. After creating them, link the catalog's suggested vendors:

```bash
CO=$(docker compose exec -T application php artisan tinker --execute='echo \Fleetbase\Models\Company::first()->uuid;')
docker compose exec -T application php artisan tinker --execute="echo \Jalal\ServiceTracker\Seeders\ServiceTrackerSeeder::relinkVendors('$CO');"
```

Why vehicles matter: a visit whose plate matches a FleetOps vehicle gets `vehicle_uuid` set automatically, and only then does the close hook write FleetOps `Maintenance` rows, update the vehicle's `MaintenanceSchedule` and raise an `Issue` for each NEW damage. The real plates are listed in CUTOVER.md section 5 (two need a decision first).

## 12. FleetOps InspectionForms for Pickup and Return (8 angles)   `[NOT RUN]`

Read this first: the Service Tracker visit flow does **not** use FleetOps InspectionForms. In Navigator the pickup/return inspection is the tracker's own screen with the eight suggested angles (`front, rear, left, right, front-left, front-right, rear-left, rear-right`); its photos are stored as Service Tracker files and drive `pickup_inspected` / `return_inspected`. The two FleetOps forms below are an **optional, parallel** artefact: they give you a DVIR-style checklist in the stock Navigator inspection feature and a FleetOps-side record. Skip this step if you do not want two inspection paths; nothing in the tracker depends on it.

FleetOps form builder (console: FleetOps, then the Inspections / forms area; exact menu label unverified). The model supports field types `pass-fail, input, textarea, number, select, radio-button, boolean, date-picker, date-time-input, file-upload, signature`, grouped into field groups, and a form must be **published** (`InspectionForm::publish()`, permission `publish inspection-form`) before drivers see it. Build two forms:

| Form name | Group | Fields |
|---|---|---|
| `LSA Pickup Inspection` | `Photos` | 8 x `file-upload`: Front, Rear, Left, Right, Front-left, Front-right, Rear-left, Rear-right |
| | `Condition` | `pass-fail` Body damage, `pass-fail` Interior, `number` Odometer (km), `signature` Handover |
| `LSA Return Inspection` | same as pickup | same as pickup |

Then **Publish** each. Check from the API: `curl -fsS "$API/v1/inspection-forms" -H "Authorization: Bearer $KEY"` lists published forms.

## 13. Queue, scheduler, maintenance triggers   `[NOT RUN]`

The stock compose already runs both workers, and the override gives them our image:

```bash
cd "$FB"
docker compose ps queue scheduler                                   # both "running"
docker compose exec -T queue php artisan queue:status || docker compose exec -T queue php artisan queue:monitor redis:default
docker compose exec -T scheduler php artisan schedule:list | grep -i maintenance
# expect: fleetops:process-maintenance-triggers  daily
```

* The scheduler container runs `schedule:run` every minute (go-crond). FleetOps registers `fleetops:process-maintenance-triggers` as **daily** (so 03:00 Riyadh). No extra worker is needed; if `schedule:list` does not show it, the FleetOps provider is not booting the schedule.
* What it does with our data: the close hook writes distance schedules (`next_due_odometer = visit odometer + catalog interval`), and when `vehicles.odometer >= next_due_odometer` the command opens a preventive-maintenance work order. That depends on the **FleetOps vehicle odometer being maintained**; the tracker does not update `vehicles.odometer` itself. Decide whether you want that automation during the trial. To see what it would do without creating anything:

```bash
docker compose exec -T scheduler php artisan fleetops:process-maintenance-triggers --dry-run
```

* The queue worker has no tracker-specific jobs today; it serves Fleetbase notifications and events.

## 14. Smoke test   `[NOT RUN]` against a real server (`[RUN]` against a mock)

On a **scratch** copy of the stack, never on production (it creates users with passwords):

```bash
cd "$FB"
bash "$BUILD/ops/smoke/bootstrap.sh" smoke.env             # mints 2 companies, users, API keys inside the stack
BASE_URL=http://localhost:8000 bash "$BUILD/ops/smoke/smoke.sh" --env smoke.env
```

The smoke script has only ever been run against `ops/smoke/mock_server.py`, a mock that implements what the script assumes. See `.github/workflows/integration.yml` (manual) for the same flow in CI; it has never run either.

## 15. Navigator fork (Android APK, iOS notes)   `[NOT RUN]`

Navigator (`fleetbase/navigator-app`, AGPL-3.0-or-later, React Native, Yarn 3.6.4 via corepack, Node 20.19.x per its CI) is a **bare** React Native app (no Expo), with a `android/fastlane` and `ios/fastlane` already in the repo. Our additions are drop-in files plus five small edits listed in `navigator-screens/PATCHES.md`.

### 15.1 Fork and add the files

```bash
git clone https://github.com/fleetbase/navigator-app.git ~/navigator-jalal && cd ~/navigator-jalal
git checkout -b jalal/service-tracker
# new files (no overwrites). Skip __tests__: the app's own jest setup differs; the tests run in this repo's CI.
cp -r "$BUILD/navigator-screens/src/." src/
```

Apply the edits of `PATCHES.md` by hand (they are annotated diffs, not a `git apply` patch): `App.tsx` provider (section 1), the tab (section 3; or simply set `DRIVER_NAVIGATOR_TABS=DriverDashboardTab,DriverTaskTab,DriverServiceTab,DriverReportTab,DriverChatTab,DriverAccountTab` in `.env`, which `config/default.js` reads), and optionally the `IssueForm` and `FuelReportForm` hooks (sections 4 and 5). Section 2 is only for root-stack deep links. Fix the existing odometer bug (section 6) so the odometer shows (`[RUN]` against the cloned `navigator-app` file: it changes line 56 as intended):

```bash
sed -i 's/value = value ? 0 : value;/value = value > 0 ? value : 0;/' src/components/OdometerNumber.tsx
yarn add @react-native-community/netinfo        # optional, instant flush on reconnect (PATCHES.md section 0)
```

### 15.2 Configure the API host

```bash
cat > .env <<'EOF'
APP_NAME=Jalal Navigator
APP_IDENTIFIER=com.jalalholding.navigator
APP_LINK_PREFIX=jalalnavigator
FLEETBASE_HOST=https://api.fleet.example.com
FLEETBASE_KEY=flb_live_xxxxxxxx
GOOGLE_MAPS_API_KEY=
DEFAULT_COORDINATES=24.7136,46.6753
DRIVER_NAVIGATOR_TABS=DriverDashboardTab,DriverTaskTab,DriverServiceTab,DriverReportTab,DriverChatTab,DriverAccountTab
EOF
```

`FLEETBASE_HOST` is the API origin (the SDK adds `/v1`); `FLEETBASE_KEY` is a LIVE API key of the company (Console > Developers). Drivers can instead be pointed at an instance at runtime through the app's instance-link screen. Keep `.env` out of git. The Android release build needs `ANDROID_NAVIGATOR_APP_UPLOAD_STORE_FILE`, `..._STORE_PASSWORD`, `..._KEY_ALIAS`, `..._KEY_PASSWORD` in the environment or `.env` (`android/app/build.gradle` refuses to build a release without them).

**Licence check before any release build:** Navigator depends on `react-native-background-geolocation` (Transistorsoft). Release builds need `TRANSISTORSOFT_LICENSE_KEY_ANDROID` / `_IOS`; without a license the plugin is restricted in release builds. Confirm what Fleetbase's or your own license covers before distributing the APK (RISKS.md R-12).

### 15.3 Android APK

```bash
corepack enable && corepack prepare yarn@3.6.4 --activate
yarn install --immutable
cd android
./gradlew assembleRelease                       # app/build/outputs/apk/release/app-release.apk
# a debug APK for first device tests needs no signing:   ./gradlew assembleDebug
```

Gradle, JDK and Android SDK setup follow the React Native environment guide for the RN version in `package.json` (the app targets API level 36 per its `RELEASE.md`). Distribute the APK directly to the trial technicians (enable "install unknown apps") or through Play internal testing with the existing Fastlane lane: `cd android && bundle exec fastlane deploy_internal` (needs a Play service-account JSON, as configured in `android/fastlane`). Fleetbase's own CI says Play/App Store publishing jobs are disabled and store releases are a manual step.

**EAS Build:** not needed and not set up. Navigator is a bare React Native project; EAS Build can build such projects but this repo has no `eas.json`, and the Android signing logic above reads its own environment variables. Use Gradle + Fastlane, or add EAS only if your team already uses it (unverified for this app).

### 15.4 iOS (notes only; no iOS build was or could be attempted)

* Needs macOS, Xcode (project `ios/NavigatorApp.xcworkspace`), Ruby with bundler (`Gemfile` pins CocoaPods), an Apple Developer account, and the bundle id from `APP_IDENTIFIER` configured in Xcode (Signing & Capabilities) and `ios/Config.xcconfig`.
* `bundle install && yarn pod:install` then open the workspace; `yarn ios` for the simulator.
* TestFlight: `ios/fastlane` expects an App Store Connect API key (`APPSTORE_API_KEY_ID`, `APPSTORE_API_ISSUER_ID`, `~/.appstoreconnect/private_keys/AuthKey_<id>.p8`) and `match` signing (`MATCH_READONLY`, certificates repo). Treat it as a template to adapt.
* Background location and camera permission strings must be present in `Info.plist` (they are in the stock app); Apple review of the location purpose strings is on you.

### 15.5 What to test on the first device build

UAT sections 8 and 9: create a visit, capture 8 inspection photos, record a damage and a petty-cash entry in airplane mode, then reconnect and confirm exactly-once delivery (the queue is unit-tested; the device behaviour, camera and image resizing never were).

---

## 16. Day-2 operations

```bash
cd "$FB"
docker compose logs -f --tail=200 application queue scheduler     # service logs
docker compose exec -T application sh -c 'tail -n 200 storage/logs/laravel-$(date +%F).log'   # Laravel daily log (UTC date)
docker compose ps
docker stats --no-stream
# update the extension: re-run step 2 (copy or submodule update), then
docker compose build application console && docker compose up -d && docker compose exec -T application php artisan migrate --force && docker compose exec -T application php artisan optimize:clear
# backups (CUTOVER.md section 4): run from $FB, from cron nightly
bash "$BUILD/ops/scripts/backup.sh" ./backups
```

If the console shows the old UI after an update, the browser caches the runtime config and the app shell: hard-reload, and clear the `fleetbase_runtime_config` local-storage key.

## 17. Order of operations (one-page recap)

1. clone Fleetbase, pin SHA (1)  2. `prepare-fleetbase.sh` (2)  3. `.env` secrets (3)  4. console config files (4)  5. `docker compose build application console` (5)  6. `up -d database cache`, GRANT, `up -d` (6)  7. `deploy.sh`, `migrate`, `fleetbase:create-permissions`, `storage:link` (7)  8. Caddy (8)  9. onboarding, seeder, settings (9)  10. roles/users (10)  11. vendors, vehicles, relink (11)  12. optional InspectionForms (12)  13. verify workers (13)  14. smoke on a scratch stack (14)  15. Navigator (15)  16. UAT (`ops/UAT-checklist.md`), then CUTOVER.md.

## Steps I could not execute (summary)

Everything that needs Docker, a registry, packagist, a database or a device: steps 1 (cloning was done only to read), 4 to 13, 14 against a real server, and 15. What was executed: reading Fleetbase and Navigator sources, `prepare-fleetbase.sh` on a copy (without pnpm), `docker compose config` on the override with dummy secrets, shellcheck/`bash -n` on every script, the smoke script against its mock, the sed fix on Navigator's `OdometerNumber.tsx`, and all the test suites listed in `.github/workflows/ci.yml`.
