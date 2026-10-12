#!/usr/bin/env bash
# Mint two throw-away companies + users + API keys inside a running Fleetbase compose stack and write ops/smoke/smoke.env
# (consumed by smoke.sh). Run from the ROOT of the Fleetbase checkout:
#
#   /path/to/ops/smoke/bootstrap.sh [output-file]        (default: ./smoke.env)
#
# ONLY for throw-away stacks (CI, a scratch VM): it creates users with passwords it prints.
# NOT EXECUTED where it was written (needs a running Fleetbase). Used by .github/workflows/integration.yml.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${1:-./smoke.env}"
[ -f docker-compose.yml ] || { echo "run this from the Fleetbase checkout root" >&2; exit 1; }

# drop the "<?php" line; eval() of the remainder, base64 so no quoting survives the shell/tinker layers
PAYLOAD="$(tail -n +2 "$HERE/mint-credentials.tinker.php" | base64 -w0)"
RAW="$(docker compose exec -T application php artisan tinker --execute="eval(base64_decode('${PAYLOAD}'));")"

if grep -q '^SMOKE_BOOTSTRAP_ERROR=' <<<"$RAW"; then grep '^SMOKE_BOOTSTRAP_ERROR=' <<<"$RAW" >&2; exit 1; fi
grep -E '^SMOKE_[A-Z_]+=' <<<"$RAW" > "$OUT" || true
for k in SMOKE_API_KEY_A SMOKE_API_KEY_B SMOKE_USER_A_EMAIL SMOKE_USER_A_PASSWORD SMOKE_USER_B_EMAIL SMOKE_USER_B_PASSWORD; do
  grep -q "^${k}=." "$OUT" || { echo "bootstrap did not produce $k; tinker output was:" >&2; echo "$RAW" >&2; exit 1; }
done
chmod 600 "$OUT"
echo "wrote $OUT (contains credentials; do not commit)"
