# Service Tracker build

Fleetbase extension (server API + Ember engine), Navigator screens, and the deployment/CI/operations package.

## Layout

| Path | Contents |
|------|----------|
| `SPEC.md` | Requirements and rules the engine implements |
| `service-tracker/` | Laravel package `jalal/service-tracker-api` (server), Ember engine (addon), tests incl. realdata parity |
| `navigator-screens/` | Navigator fork screens and `PATCHES.md` |
| `src/` | Original single-file tool used as the reference/oracle |
| `ops/` | Deployment, smoke tests, UAT, cutover, risks |
| `.github/workflows/` | `ci.yml` (automatic), `integration.yml` (manual, never run) |
| `BUILD_REPORT.md` | Build report from the other work streams |

## Read in this order

1. `ops/INSTALL.md` - ordered install steps; each step marked run / checked / not run.
2. `ops/UAT-checklist.md` - expected numbers and acceptance tests.
3. `ops/CUTOVER.md` - parallel run, importer, rollback, backup, monitoring, licence and privacy notes.
4. `ops/RISKS.md` - what is still unproven.

## Run CI checks locally

- PHP syntax: `find service-tracker/server -name '*.php' -print0 | xargs -0 -n1 php -l`
- Other jobs (engine shim tests, realdata parity, compat, contract, navigator jest + tsc, ember lint): copy the commands from `.github/workflows/ci.yml`.
- Smoke script self-test (no network): `bash ops/smoke/smoke.sh --selftest`
- Compose validation: `docker compose -f <fleetbase>/docker-compose.yml -f ops/docker-compose.override.yml config`

`integration.yml` and a real smoke run against Fleetbase have never been executed (RISKS R-01).
