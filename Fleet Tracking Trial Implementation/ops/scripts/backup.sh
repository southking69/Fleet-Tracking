#!/usr/bin/env bash
# Backup the Fleetbase trial: MySQL dump (both databases) + file storage (photos, receipts, generated files) + config.
# Run from the ROOT of the Fleetbase checkout (where docker-compose.yml is):
#
#   /path/to/ops/scripts/backup.sh [output-dir]        (default: ./backups)
#
# Produces <output-dir>/<UTC timestamp>/{mysql.sql.gz,storage-app.tar.gz,config.tar.gz,SHA256SUMS}.
# config.tar.gz contains .env and api/.env (SECRETS: encrypt or store it separately from the other two files).
# NOT EXECUTED where it was written (needs a running compose stack).
set -euo pipefail

OUT_ROOT="${1:-./backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$OUT_ROOT/$STAMP"
mkdir -p "$OUT"

command -v docker >/dev/null || { echo "docker not found" >&2; exit 1; }
[ -f docker-compose.yml ] || { echo "run this from the Fleetbase checkout root" >&2; exit 1; }

echo "==> MySQL dump (fleetbase + fleetbase_sandbox if present)"
# The password comes from the container's own environment (MYSQL_ROOT_PASSWORD); it never appears on this host's command line.
docker compose exec -T database sh -c '
  dbs="fleetbase"
  if mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SHOW DATABASES LIKE \"fleetbase_sandbox\"" 2>/dev/null | grep -q fleetbase_sandbox; then dbs="fleetbase fleetbase_sandbox"; fi
  exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines --triggers --hex-blob \
       --default-character-set=utf8mb4 --set-gtid-purged=OFF --databases $dbs
' | gzip -9 > "$OUT/mysql.sql.gz"

echo "==> file storage (photos, receipts)"
docker compose exec -T application tar -C /fleetbase/api/storage -czf - app > "$OUT/storage-app.tar.gz"

echo "==> config (.env, api/.env): contains secrets"
tar -czf "$OUT/config.tar.gz" .env api/.env docker-compose.override.yml console/fleetbase.config.json 2>/dev/null || tar -czf "$OUT/config.tar.gz" .env docker-compose.override.yml

( cd "$OUT" && sha256sum ./*.gz > SHA256SUMS )
gzip -t "$OUT/mysql.sql.gz" "$OUT/storage-app.tar.gz"
echo "==> backup written to $OUT"
ls -l "$OUT"
