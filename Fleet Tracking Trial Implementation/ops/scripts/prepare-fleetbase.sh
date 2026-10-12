#!/usr/bin/env bash
# Prepare a Fleetbase checkout to build with the Service Tracker extension. Idempotent; never touches Fleetbase source files
# except console/package.json + console/pnpm-lock.yaml (via `pnpm add`) and files it creates.
#
#   ops/scripts/prepare-fleetbase.sh <fleetbase-checkout> [--src <path-to-service-tracker-dir>] [--no-pnpm]
#
# <path-to-service-tracker-dir> is the directory that contains package.json (engine) and server/ (composer package):
# /home/claude/build/service-tracker in this repo, or the root of the `service-tracker` repo when used as a submodule.
# Default: ../../service-tracker relative to this script.
#
# Steps:
#   1. copy the extension to <fleetbase>/packages/service-tracker (skipped when it already is that directory, e.g. a git submodule)
#   2. copy the Docker files to <fleetbase>/jalal-ops/ and the compose override + .env.example (existing files are NOT overwritten)
#   3. copy the engine (without server/ and tests/) to <fleetbase>/console/vendor/service-tracker and run
#      `pnpm add "@jalal/service-tracker-engine@file:./vendor/service-tracker"` in console/  (needs pnpm + network; see --no-pnpm)
#   4. make sure api/.env exists as a FILE (the stock compose bind-mounts it; if missing Docker creates a directory there)
set -euo pipefail

usage() { sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

FB=""; SRC=""; USE_PNPM=1
while [ $# -gt 0 ]; do
  case "$1" in
    --src) SRC="${2:?--src needs a path}"; shift 2 ;;
    --no-pnpm) USE_PNPM=0; shift ;;
    -h|--help) usage 0 ;;
    -*) echo "unknown option $1" >&2; usage 2 ;;
    *) if [ -z "$FB" ]; then FB="$1"; shift; else echo "unexpected argument $1" >&2; usage 2; fi ;;
  esac
done
[ -n "$FB" ] || usage 2

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPS="$(dirname "$HERE")"
SRC="${SRC:-$(cd "$OPS/../service-tracker" && pwd)}"
FB="$(cd "$FB" && pwd)"

[ -f "$FB/docker-compose.yml" ] && [ -d "$FB/console" ] && [ -d "$FB/api" ] || { echo "$FB does not look like a Fleetbase checkout (docker-compose.yml, api/, console/ expected)" >&2; exit 1; }
[ -f "$SRC/package.json" ] && [ -f "$SRC/server/composer.json" ] || { echo "$SRC is not the service-tracker directory (package.json + server/composer.json expected)" >&2; exit 1; }

say() { printf '==> %s\n' "$*"; }

# 1. extension -> packages/service-tracker
DEST="$FB/packages/service-tracker"
mkdir -p "$FB/packages"
if [ -e "$DEST/package.json" ] && [ "$(cd "$DEST" && pwd -P)" = "$(cd "$SRC" && pwd -P)" ]; then
  say "packages/service-tracker is already the source directory (submodule or symlink); not copying"
else
  say "copying extension to $DEST"
  mkdir -p "$DEST"
  # rsync is optional: tar works everywhere
  (cd "$SRC" && tar --exclude=node_modules --exclude=vendor --exclude=.git -cf - .) | (cd "$DEST" && tar -xf -)
fi

# 2. docker files, override, env example
say "installing Docker files into $FB/jalal-ops"
mkdir -p "$FB/jalal-ops"
cp "$OPS/docker/Dockerfile.api" "$OPS/docker/Dockerfile.console" "$OPS/docker/Caddyfile.example" "$FB/jalal-ops/"
if [ -e "$FB/docker-compose.override.yml" ]; then
  say "docker-compose.override.yml exists: left untouched (compare with $OPS/docker-compose.override.yml; the stock scripts/docker-install.sh writes one too)"
else
  cp "$OPS/docker-compose.override.yml" "$FB/docker-compose.override.yml"
  say "wrote docker-compose.override.yml"
fi
if [ -e "$FB/.env" ]; then
  say ".env exists: left untouched"
else
  cp "$OPS/.env.example" "$FB/.env"
  chmod 600 "$FB/.env"
  say "wrote .env from .env.example: FILL IN THE SECRETS before building"
fi

# 3. console engine
VENDOR="$FB/console/vendor/service-tracker"
say "copying engine to $VENDOR (without server/, tests/)"
rm -rf "$VENDOR"
mkdir -p "$VENDOR"
(cd "$SRC" && tar --exclude=node_modules --exclude=server --exclude=tests --exclude=.git -cf - .) | (cd "$VENDOR" && tar -xf -)

if grep -q '"@jalal/service-tracker-engine"' "$FB/console/package.json"; then
  say "console/package.json already lists the engine"
elif [ "$USE_PNPM" = 1 ]; then
  command -v pnpm >/dev/null 2>&1 || { echo "pnpm not found. Install it (npm i -g pnpm) or re-run with --no-pnpm and run the printed command yourself." >&2; exit 1; }
  say "pnpm add in console/ (updates package.json and pnpm-lock.yaml)"
  (cd "$FB/console" && pnpm add "@jalal/service-tracker-engine@file:./vendor/service-tracker")
else
  say "SKIPPED pnpm. Run this yourself, then commit console/package.json and console/pnpm-lock.yaml:"
  echo "    (cd $FB/console && pnpm add \"@jalal/service-tracker-engine@file:./vendor/service-tracker\")"
fi

# 4. api/.env must be a file
if [ -d "$FB/api/.env" ]; then
  echo "$FB/api/.env is a DIRECTORY (Docker created it). Remove it: rm -rf '$FB/api/.env' and re-run." >&2
  exit 1
fi
if [ ! -f "$FB/api/.env" ]; then
  printf '# Fleetbase API environment overrides. Values in docker-compose.override.yml take precedence.\n' > "$FB/api/.env"
  say "created empty api/.env"
fi

say "done. Next: edit $FB/.env, write console/fleetbase.config.json + console/environments/.env.production (ops/INSTALL.md step 4), then docker compose build"
