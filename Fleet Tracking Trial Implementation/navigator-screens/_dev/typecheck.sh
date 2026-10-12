#!/usr/bin/env bash
# tsc --noEmit over the shipped src/ against stubs of the existing navigator-app files + ambient module stubs.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(dirname "$HERE")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/src"
cp -r "$HERE/stubs/app/src/." "$TMP/src/"      # stubs first ...
cp -r "$ROOT/src/." "$TMP/src/"                 # ... shipped files win
cp "$HERE/stubs/ambient.d.ts" "$TMP/ambient.d.ts"
ln -s "$HERE/node_modules" "$TMP/node_modules"
cat > "$TMP/tsconfig.json" <<JSON
{ "compilerOptions": { "target": "ES2020", "module": "esnext", "moduleResolution": "node", "jsx": "react-jsx", "strict": true, "noEmit": true,
  "skipLibCheck": true, "esModuleInterop": true, "resolveJsonModule": true, "lib": ["ES2020"], "types": ["react", "node"], "typeRoots": ["./node_modules/@types"] },
  "include": ["src", "ambient.d.ts"] }
JSON
cd "$TMP" && "$HERE/node_modules/.bin/tsc" -p tsconfig.json
echo "tsc OK"
