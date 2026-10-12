# Residual-risk register

Honest list of what is not proven. Likelihood (L) and impact (I): H/M/L. IDs are cited from INSTALL.md, UAT-checklist.md and CUTOVER.md; do not renumber.

| ID | Risk | L | I | Mitigation / what closes it |
|----|------|---|---|-----------------------------|
| R-01 | The integration workflow and `ops/smoke/smoke.sh` have never run against a real Fleetbase. The smoke script was only exercised with `--selftest` and against `ops/smoke/mock_server.py`, which proves script control flow, not server behaviour. Route names, auth headers and response shapes are taken from reading code. | H | H | Run `integration.yml` (manual dispatch) and fix what breaks before any UAT. Treat first green run as the real milestone. |
| R-02 | Importer DB half is unexecuted: the parsing/calc half is tested, but writes to MySQL were never run. | M | H | Run `--dry-run` first, then a real run on a staging copy; compare with the reconciliation guide in CUTOVER.md. |
| R-03 | Composer may fail to resolve `fleetbase/fleetops-api` 0.6.71 (pinned by the package) against the Fleetbase image you deploy. | M | M | Pin the base image tag to the matching Fleetbase release; check `composer require` output at INSTALL step. |
| R-04 | The Ember engine depends on `@fleetbase/ember-core: latest`; a newer upstream can break the console build without any change on our side. | M | M | Pin versions in the console lockfile after the first good build; rebuild only deliberately. |
| R-05 | Imported history does not fire the FleetOps close hook, so FleetOps-side maintenance records/linkage for old visits are not created. | H | L | Accept for history; verify only new visits link (UAT FleetOps linkage). |
| R-06 | Supervisor/Technician role enforcement on the driver (Navigator, API-key) path is not proven; users with neither role are treated as full users. | M | H | Test each role through Navigator and the console (UAT role matrix); assign roles to every user before go-live. |
| R-07 | Photo URLs on public storage are unauthenticated links; local-disk storage also needs `storage:link` to work. | M | M | Prefer private S3/GCS with signed URLs; otherwise accept and document. See data-protection note in CUTOVER.md. |
| R-08 | `GITHUB_AUTH_KEY` build-arg can persist in image history of locally built images. | M | M | Never push the built images to a public registry; use BuildKit secrets if you adopt a registry. |
| R-09 | dompdf may render Arabic text (names, plates) incorrectly without a suitable font. | M | M | Test PDF with Arabic data (UAT PDF section); install/embed an Arabic font if broken. |
| R-10 | The PHPUnit job in ci.yml is advisory (non-blocking); the blocking checks are php -l, shim tests, realdata parity, compat and contract checks. Shim tests use stubs, not real Fleetbase. | M | M | Make PHPUnit blocking once it passes against real core-api. |
| R-11 | AGPL-3.0 (Fleetbase, Navigator) vs commercial licence (FCL) decision is not made by us. Obligations on a modified network service/app are not legal advice here. | M | H | Confirm with legal before go-live. |
| R-12 | Navigator release builds need a Transistorsoft background-geolocation licence key; none is supplied. iOS build was never attempted. | H | M | Obtain key; do a debug Android build first; iOS needs Mac/Xcode and Apple credentials. |
| R-13 | Offline/airplane-mode visit sync is untested on a real device; conflict behaviour relies on `client_uuid` idempotency and server 422 ordering rules. | M | H | Run the UAT airplane-mode test on Android before wider rollout. |
| R-14 | Timezone: Laravel runs UTC; Asia/Riyadh is applied only in the extension setting and importer `--tz`. Any other report using core timestamps shows UTC. | M | L | Documented in INSTALL; train users. |
| R-15 | Real-data quality (missing odometers, out-of-order timestamps, near-duplicate catalog entries, plate variants) will produce data-check warnings and imperfect history. | H | M | Clean per the 12-item table in CUTOVER.md; do not force-fix source silently. |
| R-16 | Backup/restore scripts were never run against a live DB; restore is destructive. | M | H | Rehearse restore on staging before cutover. |
| R-17 | Docker builds (api/console images), `pnpm add` of the engine, console build and Navigator builds were not executed in this environment. | H | M | Done in INSTALL on a real host; expect first-run fixes. |
| R-18 | The two InspectionForms (Pickup/Return, 8 angles) are optional and not used by the tracker flow; not created or tested. | L | L | Create manually if wanted. |
