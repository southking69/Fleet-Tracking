#!/usr/bin/env bash
# Restore a backup made by backup.sh into the running compose stack. DESTRUCTIVE: replaces the database contents and the
# storage/app directory. Run from the ROOT of the Fleetbase checkout:
#
#   /path/to/ops/scripts/restore.sh <backup-dir> --yes
#
# Practise this on a scratch stack before the cutover (ops/CUTOVER.md "Restore drill").
# NOT EXECUTED where it was written (needs a running compose stack).
set -euo pipefail

DIR="${1:-}"; CONFIRM="${2:-}"
[ -n "$DIR" ] && [ -f "$DIR/mysql.sql.gz" ] && [ -f "$DIR/storage-app.tar.gz" ] || { echo "usage: $0 <backup-dir containing mysql.sql.gz and storage-app.tar.gz> --yes" >&2; exit 2; }
[ "$CONFIRM" = "--yes" ] || { echo "refusing to overwrite data without --yes" >&2; exit 2; }
[ -f docker-compose.yml ] || { echo "run this from the Fleetbase checkout root" >&2; exit 1; }

( cd "$DIR" && sha256sum -c SHA256SUMS ) || { echo "checksum mismatch, aborting" >&2; exit 1; }

echo "==> stopping writers (queue, scheduler, application)"
docker compose stop application queue scheduler

echo "==> restoring MySQL (the dump contains CREATE DATABASE + DROP TABLE statements)"
gunzip -c "$DIR/mysql.sql.gz" | docker compose exec -T database sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'

echo "==> restoring storage/app"
docker compose up -d application
docker compose exec -T application sh -c 'rm -rf /fleetbase/api/storage/app/* /fleetbase/api/storage/app/.[!.]* 2>/dev/null || true'
docker compose exec -T application tar -C /fleetbase/api/storage -xzf - < "$DIR/storage-app.tar.gz"
docker compose exec -T application sh -c 'chown -R www-data:www-data /fleetbase/api/storage/app'

echo "==> starting everything and clearing caches"
docker compose up -d
docker compose exec -T application php artisan optimize:clear
docker compose exec -T application php artisan migrate:status | tail -n 15
echo "==> restore finished. Verify: log in, open a visit with photos, download a PDF."
