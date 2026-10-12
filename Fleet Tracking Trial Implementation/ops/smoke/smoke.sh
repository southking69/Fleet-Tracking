#!/usr/bin/env bash
# Service Tracker end-to-end smoke test (curl + jq). Runs against a LIVE Fleetbase with the extension installed.
#
#   ops/smoke/smoke.sh [--env smoke.env] [--selftest]
#
# Configuration (environment variables, or a KEY=VALUE file passed with --env; ops/smoke/bootstrap.sh writes one):
#   BASE_URL             API origin, default http://localhost:8000
#   SMOKE_API_KEY_A/B    live API credentials of two DIFFERENT companies (driver API + FleetOps public API)
#   SMOKE_USER_A_EMAIL / SMOKE_USER_A_PASSWORD   console user of company A (internal API: calc, PDF, xlsx, dashboard)
#   SMOKE_USER_B_EMAIL / SMOKE_USER_B_PASSWORD   console user of company B (tenant isolation)
#   SMOKE_FIXTURE        expected.json (default: service-tracker/server/tests/fixtures/expected.json of this repo)
#   TZ_OFFSET            offset appended to the fixture's local times, default +03:00 (Asia/Riyadh)
#   PDF_COMBOS           sample (default, 14 of the 2^6 toggle combinations) | all (64)
#   ORDER_SLEEP          seconds between sibling creates so created_at order is deterministic, default 1.1
#   DB_QUERY_CMD         optional, e.g. "docker compose exec -T database mysql -uroot -pXXX fleetbase -N -e": enables the
#                        FleetOps side-effect checks (Maintenance rows / Issue rows written by the close hook)
#   SMOKE_VERBOSE=1      print every request
#
# Needs a FRESH company A (no visits dated 2026-10-05..09): the dashboard figures are exact for the 4 sample visits and are
# SKIPPED (not failed) when the company already holds visits in that range. Plates carry a random run tag so re-runs
# do not collide, but earlier runs still pollute the dashboard range, hence the skip.
#
# Exit code: 0 all checks passed, 1 at least one FAIL, 2 usage / prerequisites. SKIP lines are not failures but ARE listed:
# read them. The script has never been run against a real Fleetbase (see ops/RISKS.md R-01).
# `cmd; check "name" $?` is the house pattern of this script ($? is deliberately the status of the preceding test)
# shellcheck disable=SC2319,SC1090
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE=""; SELFTEST=0
while [ $# -gt 0 ]; do
  case "$1" in
    --env) ENV_FILE="${2:?--env needs a file}"; shift 2 ;;
    --selftest) SELFTEST=1; shift ;;
    -h|--help) sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument $1" >&2; exit 2 ;;
  esac
done
if [ -n "$ENV_FILE" ]; then
  [ -f "$ENV_FILE" ] || { echo "no such env file: $ENV_FILE" >&2; exit 2; }
  set -a; . "$ENV_FILE"; set +a
fi

BASE_URL="${BASE_URL:-http://localhost:8000}"; BASE_URL="${BASE_URL%/}"
SMOKE_FIXTURE="${SMOKE_FIXTURE:-$HERE/../../service-tracker/server/tests/fixtures/expected.json}"
TZ_OFFSET="${TZ_OFFSET:-+03:00}"
PDF_COMBOS="${PDF_COMBOS:-sample}"
ORDER_SLEEP="${ORDER_SLEEP:-1.1}"
TAG="$(LC_ALL=C tr -dc 'A-Z' </dev/urandom | head -c 3)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

for bin in curl jq unzip; do command -v "$bin" >/dev/null 2>&1 || { echo "missing prerequisite: $bin" >&2; exit 2; }; done
[ -f "$SMOKE_FIXTURE" ] || { echo "fixture not found: $SMOKE_FIXTURE" >&2; exit 2; }

PASS=0; FAILN=0; SKIPN=0
FAILS=(); SKIPS=()
pass() { PASS=$((PASS+1)); printf 'PASS  %s\n' "$1"; }
fail() { FAILN=$((FAILN+1)); FAILS+=("$1"); printf 'FAIL  %s%s\n' "$1" "${2:+  -> $2}"; }
skip() { SKIPN=$((SKIPN+1)); SKIPS+=("$1"); printf 'SKIP  %s%s\n' "$1" "${2:+  -> $2}"; }
section() { printf '\n== %s\n' "$1"; }
check() { # check "name" <exit-code> ["detail"]
  if [ "$2" -eq 0 ]; then pass "$1"; else fail "$1" "${3:-}"; fi
}
eq() { # eq "name" actual expected
  if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "got '$2', expected '$3'"; fi
}

# ---- JSON comparison: numbers within 0.011 (the engine rounds to 2 decimals), objects compared on the EXPECTED keys only
JQ_CMP='
def near(a;b): if (a|type)=="number" and (b|type)=="number" then ((a-b)|fabs) <= 0.011 else a==b end;
def cmp(a;b):
  if (a|type)=="array" and (b|type)=="array" then ((a|length)==(b|length)) and ([range(0;(b|length)) as $i | cmp(a[$i];b[$i])]|all)
  elif (a|type)=="object" and (b|type)=="object" then ([b|keys[] as $k | cmp(a[$k];b[$k])]|all)
  else near(a;b) end;
cmp($actual[0]; $expected[0])'
json_matches() { # json_matches actual.json expected.json -> 0 when equal within tolerance
  [ "$(jq -n --slurpfile actual "$1" --slurpfile expected "$2" "$JQ_CMP")" = "true" ]
}
# which expected keys differ (for the failure message)
json_diff_keys() {
  jq -n -r --slurpfile a "$1" --slurpfile e "$2" '
    def near(a;b): if (a|type)=="number" and (b|type)=="number" then ((a-b)|fabs) <= 0.011 else a==b end;
    def cmp(a;b):
      if (a|type)=="array" and (b|type)=="array" then ((a|length)==(b|length)) and ([range(0;(b|length)) as $i | cmp(a[$i];b[$i])]|all)
      elif (a|type)=="object" and (b|type)=="object" then ([b|keys[] as $k | cmp(a[$k];b[$k])]|all)
      else near(a;b) end;
    [$e[0]|keys[] as $k | select((cmp($a[0][$k];$e[0][$k]))|not) | "\($k): got \($a[0][$k]|tojson) expected \($e[0][$k]|tojson)"] | join("; ")'
}

# ---- fixture extraction ----------------------------------------------------------------------------------------
# Single source of truth: the same expected.json the PHP engine tests and the JS oracle agree on.
jq -c '[.fixtures[] | select(.name=="sample")][0].cases' "$SMOKE_FIXTURE" > "$WORK/cases.json"
NCASES="$(jq 'length' "$WORK/cases.json")"

if [ "$SELFTEST" -eq 1 ]; then
  section "selftest (no network): fixture + comparison logic"
  eq "fixture has 4 sample visits" "$NCASES" "4"
  for i in 0 1 2 3; do
    jq ".[$i].expected" "$WORK/cases.json" > "$WORK/e.json"
    json_matches "$WORK/e.json" "$WORK/e.json"; check "case $i compares equal to itself" $?
    jq ".[$i].expected | .total = (.total + 0.5)" "$WORK/cases.json" > "$WORK/a.json"
    json_matches "$WORK/a.json" "$WORK/e.json"; [ $? -ne 0 ]; check "case $i detects a +0.5 h total drift" $?
    jq ".[$i].expected | .total = (.total + 0.005)" "$WORK/cases.json" > "$WORK/a.json"
    json_matches "$WORK/a.json" "$WORK/e.json"; check "case $i tolerates 0.005 h rounding" $?
  done
  eq "case 0 total" "$(jq '.[0].expected.total' "$WORK/cases.json")" "9"
  eq "case 3 worstOver" "$(jq '.[3].expected.worstOver' "$WORK/cases.json")" "1600"
  printf '\nselftest: %d passed, %d failed\n' "$PASS" "$FAILN"
  [ "$FAILN" -eq 0 ]; exit $?
fi

for v in SMOKE_API_KEY_A SMOKE_API_KEY_B SMOKE_USER_A_EMAIL SMOKE_USER_A_PASSWORD SMOKE_USER_B_EMAIL SMOKE_USER_B_PASSWORD; do
  [ -n "${!v:-}" ] || { echo "missing $v (run ops/smoke/bootstrap.sh or export it)" >&2; exit 2; }
done

# ---- HTTP helper ---------------------------------------------------------------------------------------------
HTTP=""; BODY="$WORK/body"; HDRS="$WORK/hdrs"
TOK_A=""; TOK_B=""
# req <who: keyA|keyB|tokA|tokB|none> <METHOD> <path> [json-body]   -> sets HTTP (status) and $BODY (file)
req() {
  local who="$1" method="$2" path="$3" data="${4:-}" auth=()
  case "$who" in
    keyA) auth=(-H "Authorization: Bearer $SMOKE_API_KEY_A") ;;
    keyB) auth=(-H "Authorization: Bearer $SMOKE_API_KEY_B") ;;
    tokA) auth=(-H "Authorization: Bearer $TOK_A") ;;
    tokB) auth=(-H "Authorization: Bearer $TOK_B") ;;
    none) auth=() ;;
  esac
  local args=(-sS -o "$BODY" -D "$HDRS" -w '%{http_code}' -X "$method" -H 'Accept: application/json' "${auth[@]}")
  [ -n "$data" ] && args+=(-H 'Content-Type: application/json' --data "$data")
  HTTP="$(curl "${args[@]}" "$BASE_URL$path" 2>"$WORK/curl.err")" || HTTP="000"
  [ "${SMOKE_VERBOSE:-0}" = 1 ] && printf '   %s %s -> %s\n' "$method" "$path" "$HTTP" >&2
  [ -s "$BODY" ] || : > "$BODY"
  return 0
}
j() { jq -r "$1" "$BODY" 2>/dev/null; }   # query the last response
body_head() { head -c 300 "$BODY" | tr '\n' ' '; }
expect_code() { # expect_code "name" code[|code...]
  if [[ "|$2|" == *"|$HTTP|"* ]]; then pass "$1 (HTTP $HTTP)"; return 0; fi
  fail "$1" "HTTP $HTTP, expected $2; body: $(body_head)"; return 1
}
sleep_order() { sleep "$ORDER_SLEEP"; }
iso() { printf '%s%s' "$1" "$TZ_OFFSET"; }     # 2026-10-05T08:00 -> 2026-10-05T08:00+03:00 ; empty stays empty
isoq() { [ -n "$1" ] && printf '%s%s' "$1" "$TZ_OFFSET" || printf ''; }

# =====================================================================================================================
section "1. availability and authentication"
req none GET /health; expect_code "GET /health" 200
req none GET /v1/service-tracker/visits; expect_code "driver API without credentials is refused" "401|403"
req keyA GET /v1/service-tracker/visits; expect_code "driver API with company A key" 200
PRE_EXISTING="$(j '(.meta.total // (.data|length) // 0)')"

login() { # login email password -> token on stdout
  req none POST /int/v1/auth/login "$(jq -n --arg i "$1" --arg p "$2" '{identity:$i,password:$p}')"
  [ "$HTTP" = 200 ] && j '.token' || { echo ""; }
}
TOK_A="$(login "$SMOKE_USER_A_EMAIL" "$SMOKE_USER_A_PASSWORD")"
[ -n "$TOK_A" ] && [ "$TOK_A" != null ]; check "console login user A returns a token" $? "$(body_head)"
TOK_B="$(login "$SMOKE_USER_B_EMAIL" "$SMOKE_USER_B_PASSWORD")"
[ -n "$TOK_B" ] && [ "$TOK_B" != null ]; check "console login user B returns a token" $? "$(body_head)"
if [ -z "$TOK_A" ] || [ "$TOK_A" = null ]; then echo "cannot continue without a console token for company A" >&2; exit 1; fi

req tokA GET /int/v1/service-tracker/settings; expect_code "GET settings (internal)" 200
eq "settings.timezone" "$(j '.settings.timezone // .timezone')" "Asia/Riyadh"
req tokA GET /int/v1/service-tracker/lanes; expect_code "GET lanes" 200
LANES="$(jq -c '[(.lanes // .data // .)[] | {name, h:(.target_hours|tonumber|round)}] | sort_by(.name)' "$BODY" 2>/dev/null)"
eq "seeded lanes Express 12 / Heavy 48 / Standard 24" "$LANES" '[{"name":"Express","h":12},{"name":"Heavy","h":48},{"name":"Standard","h":24}]'
req tokA GET "/int/v1/service-tracker/catalog?limit=1"; expect_code "GET catalog" 200
CAT_N="$(j '.meta.total // 0')"
[ "${CAT_N:-0}" -ge 145 ] 2>/dev/null; check "catalog seeded with >= 145 services (got ${CAT_N:-?})" $?

# =====================================================================================================================
section "2. FleetOps vendors and vehicles (public API, company A)"
for vn in "Petromin" "Local Mechanic" "Tyre Vendor"; do
  req keyA POST /v1/vendors "$(jq -n --arg n "$vn" '{name:$n,type:"workshop"}')"
  expect_code "create vendor $vn" "200|201"
done
mapfile -t PLATES < <(jq -r '[.[].visit.plate] | unique[]' "$WORK/cases.json")
for p in "${PLATES[@]}"; do
  tagged="$p $TAG"
  mm="$(jq -r --arg p "$p" '[.[].visit | select(.plate==$p)][0].make' "$WORK/cases.json")"
  req keyA POST /v1/vehicles "$(jq -n --arg pl "$tagged" --arg mk "${mm%% *}" --arg md "${mm#* }" '{plate_number:$pl,make:$mk,model:$md,status:"active"}')"
  expect_code "create vehicle $tagged" "200|201"
done

# =====================================================================================================================
section "3. the four sample visits: visit -> stops -> jobs -> close, then compare the engine output with expected.json"
declare -A VISIT_ID=()
mkdir -p "$WORK/calc"
for i in $(seq 0 $((NCASES-1))); do
  c="$WORK/case$i.json"; jq ".[$i]" "$WORK/cases.json" > "$c"
  plate="$(jq -r '.visit.plate' "$c") $TAG"
  cu="smoke-$TAG-visit-$i"
  body="$(jq -n --slurpfile c "$c" --arg plate "$plate" --arg cu "$cu" --arg off "$TZ_OFFSET" '
      $c[0].visit as $v | {client_uuid:$cu, plate:$plate, make_model:$v.make, odometer:$v.odo,
      arrived_at_lsa:($v.arrived+$off), picked_up_at:(if $v.picked=="" then null else $v.picked+$off end),
      pickup_inspected:$v.pins, return_inspected:$v.rins, notes:"smoke \($v.id)"}')"
  req keyA POST /v1/service-tracker/visits "$body"
  expect_code "visit $i: create (open, not yet returned)" 201 || continue
  vid="$(j '.id')"; VISIT_ID[$i]="$vid"
  # retry with the SAME client_uuid must return the same record (offline retry), not a duplicate
  req keyA POST /v1/service-tracker/visits "$body"
  expect_code "visit $i: idempotent retry" "200|201"
  eq "visit $i: retry returns the same id" "$(j '.id')" "$vid"

  nst="$(jq '.stops|length' "$c")"
  for s in $(seq 0 $((nst-1))); do
    sb="$(jq -n --slurpfile c "$c" --argjson s "$s" --arg off "$TZ_OFFSET" --arg cu "smoke-$TAG-stop-$i-$s" '
        $c[0].stops[$s] as $x | {client_uuid:$cu, vendor_name:$x.vendor,
        arrived_at:(if $x.arrived=="" then null else $x.arrived+$off end),
        approval_requested_at:(if $x.apprReq=="" then null else $x.apprReq+$off end),
        approval_received_at:(if $x.apprRec=="" then null else $x.apprRec+$off end),
        left_at:(if $x.left=="" then null else $x.left+$off end)}')"
    req keyA POST "/v1/service-tracker/visits/$vid/stops" "$sb"; expect_code "visit $i: create vendor stop $s" "200|201"
    sleep_order
  done
  njb="$(jq '.jobs|length' "$c")"
  for k in $(seq 0 $((njb-1))); do
    jb="$(jq -n --slurpfile c "$c" --argjson k "$k" --arg cu "smoke-$TAG-job-$i-$k" '
        $c[0].jobs[$k] as $x | {client_uuid:$cu, service_name:$x.service, vendor_name:$x.vendor,
        approval_needed:($x.apprNeeded=="Yes"), status:$x.status, parts_replaced:$x.parts}')"
    req keyA POST "/v1/service-tracker/visits/$vid/jobs" "$jb"; expect_code "visit $i: create job $k ($(jq -r ".jobs[$k].service" "$c"))" "200|201"
    sleep_order
  done
  ret="$(isoq "$(jq -r '.visit.returned' "$c")")"
  req keyA PUT "/v1/service-tracker/visits/$vid" "$(jq -n --arg r "$ret" '{returned_at:$r}')"
  expect_code "visit $i: close (set returned_at)" 200 && eq "visit $i: status is closed" "$(j '.status')" "closed"
done

for i in $(seq 0 $((NCASES-1))); do
  vid="${VISIT_ID[$i]:-}"; [ -n "$vid" ] || { skip "visit $i: calc comparison" "visit was not created"; continue; }
  req tokA GET "/int/v1/service-tracker/visits/$vid/calc"
  expect_code "visit $i: GET calc" 200 || continue
  cp "$BODY" "$WORK/calc/$i.json"
  jq ".[$i].expected" "$WORK/cases.json" > "$WORK/exp$i.json"
  if json_matches "$WORK/calc/$i.json" "$WORK/exp$i.json"; then
    pass "visit $i: calc matches expected.json (stages, total, target, lane, owner, met, check, overdue, per-stop, per-job)"
  else
    fail "visit $i: calc matches expected.json" "$(json_diff_keys "$WORK/calc/$i.json" "$WORK/exp$i.json" | head -c 700)"
  fi
done
# hand-written guards, independent of the fixture file
if [ -s "$WORK/calc/0.json" ]; then
  eq "V1 total turnaround 9.0 h" "$(jq '.total' "$WORK/calc/0.json")" "9"
  eq "V1 turnaround target 12 h (Express lane)" "$(jq '.target' "$WORK/calc/0.json")" "12"
fi
if [ -s "$WORK/calc/3.json" ]; then
  eq "V4 has exactly one overdue job" "$(jq '.overdueN' "$WORK/calc/3.json")" "1"
  eq "V4 worst overdue is 1600 km" "$(jq '.worstOver' "$WORK/calc/3.json")" "1600"
fi

# =====================================================================================================================
section "4. damage records and petty cash (incl. idempotent retry)"
dmg_post() { # dmg_post <visit idx> <cu> <stage> <location> <type> <severity> <condition> <notified>
  local vid="${VISIT_ID[$1]:-}"; [ -n "$vid" ] || { skip "damage on visit $1" "visit missing"; return; }
  local b; b="$(jq -n --arg v "$vid" --arg cu "$2" --arg st "$3" --arg lo "$4" --arg ty "$5" --arg sv "$6" --arg cd "$7" --arg n "$8" --arg at "2026-10-06T09:10:00$TZ_OFFSET" \
     '{client_uuid:$cu,service_visit_uuid:$v,stage:$st,location:$lo,damage_type:$ty,severity:$sv,condition:$cd,client_notified:$n,occurred_at:$at}')"
  req keyA POST /v1/service-tracker/damage-records "$b"; expect_code "damage on visit $1: $4 / $5 ($7)" "200|201" || return
  local id; id="$(j '.id')"
  req keyA POST /v1/service-tracker/damage-records "$b"
  expect_code "damage retry with same client_uuid" "200|201"; eq "damage retry returns the same record" "$(j '.id')" "$id"
}
dmg_post 1 "smoke-$TAG-dmg-1" "Pickup inspection" "Rear bumper" "Scratch" "Minor" "Pre-existing" "Yes"
dmg_post 2 "smoke-$TAG-dmg-2" "Return inspection" "Left front door" "Dent" "Moderate" "New" "No"
req keyA GET /v1/service-tracker/damage-records; expect_code "list damage records" 200
eq "exactly 2 damage records after the retries" "$(j '(.meta.total // (.data|length))')" "2"

petty_post() { # petty_post <cu> <visit idx|-> <category> <amount minor> <paid_by> <plate>
  local vlink=""; [ "$2" != "-" ] && vlink="${VISIT_ID[$2]:-}"
  local b; b="$(jq -n --arg cu "$1" --arg v "$vlink" --arg c "$3" --argjson a "$4" --arg p "$5" --arg pl "$6" \
     '{client_uuid:$cu,entry_date:"2026-10-07",category:$c,amount:$a,currency:"SAR",paid_by:$p,description:"smoke",plate:$pl} + (if $v=="" then {} else {service_visit_uuid:$v} end)')"
  req keyA POST /v1/service-tracker/petty-cash "$b"; expect_code "petty cash $3 $4" "200|201" || return
  local id; id="$(j '.id')"
  req keyA POST /v1/service-tracker/petty-cash "$b"; eq "petty cash retry returns the same record" "$(j '.id')" "$id"
}
petty_post "smoke-$TAG-pc-1" 2 "Fuel" 2550 "Technician 1" "DEF 9012 $TAG"
petty_post "smoke-$TAG-pc-2" - "Car wash" 1000 "Technician 2" "ABC 1234 $TAG"
req keyA GET "/v1/service-tracker/petty-cash"; expect_code "list petty cash" 200
eq "petty cash rows after retries" "$(j '(.meta.total // (.data|length))')" "2"
eq "petty cash total 35.50 SAR (3550 minor units)" "$(jq '[(.data // [])[] | .amount] | add' "$BODY")" "3550"

# =====================================================================================================================
section "5. stop events, retry safety and ordering (one extra visit, dated outside the sample range)"
EV_BODY="$(jq -n --arg cu "smoke-$TAG-visit-ev" --arg p "EVT 0001 $TAG" --arg a "2026-10-20T08:00:00$TZ_OFFSET" '{client_uuid:$cu,plate:$p,make_model:"Event Test",odometer:60000,arrived_at_lsa:$a}')"
req keyA POST /v1/service-tracker/visits "$EV_BODY"
if expect_code "event visit: create" "200|201"; then
  EV="$(j '.id')"
  req keyA POST "/v1/service-tracker/visits/$EV/stops" "$(jq -n --arg cu "smoke-$TAG-stop-ev" '{client_uuid:$cu,vendor_name:"Petromin"}')"
  if expect_code "event visit: create empty stop" "200|201"; then
    ST="$(j '.id')"
    req keyA POST "/v1/service-tracker/visits/$EV/stops/$ST/check-in" "{\"at\":\"2026-10-20T09:00:00$TZ_OFFSET\"}"; expect_code "stop check-in" "200|201"
    FIRST="$(j '.arrived_at')"
    req keyA POST "/v1/service-tracker/visits/$EV/stops/$ST/check-in" "{\"at\":\"2026-10-20T10:00:00$TZ_OFFSET\"}"; expect_code "stop check-in retried with a later time" "200|201"
    eq "retry did not move the original arrival" "$(j '.arrived_at')" "$FIRST"
    req keyA POST "/v1/service-tracker/visits/$EV/stops/$ST/approval-requested" "{\"at\":\"2026-10-20T09:05:00$TZ_OFFSET\"}"; expect_code "approval requested" "200|201"
    req keyA POST "/v1/service-tracker/visits/$EV/stops/$ST/approval-received" "{\"at\":\"2026-10-20T09:35:00$TZ_OFFSET\"}"; expect_code "approval received" "200|201"
    req keyA POST "/v1/service-tracker/visits/$EV/stops/$ST/left" "{\"at\":\"2026-10-20T08:00:00$TZ_OFFSET\"}"
    expect_code "left earlier than arrival is rejected" "422" 
    req keyA POST "/v1/service-tracker/visits/$EV/stops/$ST/left" "{\"at\":\"2026-10-20T11:00:00$TZ_OFFSET\"}"; expect_code "left at 11:00" "200|201"
  fi
  req keyA POST "/v1/service-tracker/visits/$EV/jobs" "$(jq -n --arg cu "smoke-$TAG-job-ev" '{client_uuid:$cu,service_name:"Oil change - engine oil & filter",vendor_name:"Petromin",status:"Done"}')"
  expect_code "event visit: job" "200|201"
  req keyA PUT "/v1/service-tracker/visits/$EV" "{\"returned_at\":\"2026-10-20T14:00:00$TZ_OFFSET\"}"
  expect_code "event visit: close" 200 && eq "event visit closed" "$(j '.status')" "closed"
fi

# =====================================================================================================================
section "6. photo upload (multipart) and idempotent retry"
base64 -d > "$WORK/t.jpg" <<'JPG'
/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA0JCgsKCA0LCgsODg0PEyAVExISEyccHhcgLikxMC4pLSwzOko+MzZGNywtQFdBRkxOUlNSMj5aYVpQYEpRUk//2wBDAQ4ODhMREyYVFSZPNS01T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT0//wAARCAAQABADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDGooorgPrj/9k=
JPG
upload() { # upload <subject> <subject_uuid> <client_uuid>
  HTTP="$(curl -sS -o "$BODY" -w '%{http_code}' -X POST -H 'Accept: application/json' -H "Authorization: Bearer $SMOKE_API_KEY_A" \
      -F "subject=$1" -F "subject_uuid=$2" -F "client_uuid=$3" -F "photos[]=@$WORK/t.jpg;type=image/jpeg" "$BASE_URL/v1/service-tracker/uploads" 2>/dev/null)" || HTTP=000
}
if [ -n "${VISIT_ID[0]:-}" ]; then
  upload visit_pickup "${VISIT_ID[0]}" "smoke-$TAG-photo-1"; expect_code "upload pickup photo" "200|201"
  upload visit_pickup "${VISIT_ID[0]}" "smoke-$TAG-photo-1"; expect_code "upload pickup photo again (same client_uuid)" "200|201"
  req tokA GET "/int/v1/service-tracker/visits/${VISIT_ID[0]}"
  eq "exactly one pickup photo after the duplicate upload" "$(j '(.pickup_photos // .visit.pickup_photos // []) | length')" "1"
  PURL="$(j '(.pickup_photos // .visit.pickup_photos // [])[0].url // empty')"
  if [ -n "$PURL" ]; then
    code="$(curl -sS -o "$WORK/photo.out" -w '%{http_code}|%{content_type}' "$PURL" 2>/dev/null || echo "000|")"
    [[ "$code" == 200\|image/* ]]; check "stored photo is downloadable from its URL ($code)" $? "check FILESYSTEM_DRIVER / storage:link / APP_URL"
  else
    skip "photo URL download" "visit payload has no photo url"
  fi
else
  skip "photo upload" "visit 0 missing"
fi

# =====================================================================================================================
section "7. dashboard figures for the four sample visits (2026-10-05 .. 2026-10-09)"
if [ "${PRE_EXISTING:-0}" != "0" ]; then
  skip "exact dashboard figures" "company A already held $PRE_EXISTING visit(s) before this run; use a fresh company"
else
  req tokA GET "/int/v1/service-tracker/dashboard?from=2026-10-05&to=2026-10-09"
  if expect_code "GET dashboard" 200; then
    jq 'if has("kpis") then . else (.dashboard // .data // .) end' "$BODY" > "$WORK/dash.json"
    eq "dashboard: visits" "$(jq '.kpis.visits' "$WORK/dash.json")" "4"
    eq "dashboard: closed" "$(jq '.kpis.closed' "$WORK/dash.json")" "4"
    eq "dashboard: jobs" "$(jq '.kpis.jobs' "$WORK/dash.json")" "8"
    eq "dashboard: vendor stops" "$(jq '.kpis.stops' "$WORK/dash.json")" "5"
    eq "dashboard: average turnaround 17.75 h" "$(jq '.kpis.avg_turnaround_hours' "$WORK/dash.json")" "17.75"
    eq "dashboard: within target (2 of 4) = 50 %" "$(jq '.kpis.pct_within_target' "$WORK/dash.json")" "50"
    eq "dashboard: jobs overdue at pickup" "$(jq '.due_compliance.jobs_overdue' "$WORK/dash.json")" "1"
    eq "dashboard: worst km overdue" "$(jq '.due_compliance.worst_km_overdue' "$WORK/dash.json")" "1600"
    eq "dashboard: damage records / new" "$(jq -r '"\(.damage_inspections.damage_records)/\(.damage_inspections.damage_new)"' "$WORK/dash.json")" "2/1"
    echo '[2.75,0.5,0.625,7.375,0.0833,6.4167]' > "$WORK/stages.exp"; jq '[.stages[].avg_hours]' "$WORK/dash.json" > "$WORK/stages.act"
    json_matches "$WORK/stages.act" "$WORK/stages.exp"; check "dashboard: average hours per stage" $? "$(cat "$WORK/stages.act" 2>/dev/null | tr -d '\n ')"
  fi
fi

# =====================================================================================================================
section "8. vehicles due"
req tokA GET /int/v1/service-tracker/vehicles/due; expect_code "GET vehicles/due" 200
NV="$(jq '[.. | objects | select(has("plate") or has("plate_key"))] | length' "$BODY" 2>/dev/null)"
[ "${NV:-0}" -ge 3 ] 2>/dev/null; check "at least 3 vehicles listed (got ${NV:-0})" $?
req tokA GET "/int/v1/service-tracker/vehicles/due?plate=ABC1234$TAG"
if [ "$HTTP" = 200 ]; then
  [ "$(jq '[.. | objects | select(.status? == "OVERDUE")] | length' "$BODY")" -ge 1 ]; check "plate ABC 1234 shows an OVERDUE service (oil change, 1600 km over)" $?
else
  skip "per-plate due detail" "HTTP $HTTP (plate key format may differ: see service-tracker/server/NOTES.md)"
fi

# =====================================================================================================================
section "9. client PDF report, including the six toggles"
is_pdf() { [ "$(head -c 4 "$WORK/out.pdf" 2>/dev/null)" = "%PDF" ]; }
fetch_pdf() { # fetch_pdf <visit id> <query>
  HTTP="$(curl -sS -o "$WORK/out.pdf" -w '%{http_code}|%{content_type}' -H "Authorization: Bearer $TOK_A" "$BASE_URL/int/v1/service-tracker/reports/visit/$1.pdf?$2" 2>/dev/null)" || HTTP="000|"
}
PDFV="${VISIT_ID[3]:-}"
if [ -n "$PDFV" ]; then
  fetch_pdf "$PDFV" ""
  PDF_BYTES="$(wc -c < "$WORK/out.pdf" 2>/dev/null)"
  [[ "$HTTP" == 200\|application/pdf* ]] && is_pdf; check "PDF with default toggles (HTTP/type: $HTTP, bytes: $PDF_BYTES)" $?
  names=(turnaround vendorTimes photos overdue condPhotos petty)
  combos=()
  if [ "$PDF_COMBOS" = all ]; then for m in $(seq 0 63); do combos+=("$m"); done
  else combos=(0 63 1 2 4 8 16 32 62 61 59 55 47 31); fi
  bad=0; sizes=""
  for m in "${combos[@]}"; do
    q=""; for b in 0 1 2 3 4 5; do q+="${names[$b]}=$(( (m >> b) & 1 ))&"; done
    fetch_pdf "$PDFV" "${q%&}"
    if [[ "$HTTP" == 200\|application/pdf* ]] && is_pdf; then sizes+="$m:$(wc -c < "$WORK/out.pdf") "; else bad=$((bad+1)); printf '      combo %s -> %s\n' "$m" "$HTTP"; fi
  done
  [ "$bad" -eq 0 ]; check "PDF renders for ${#combos[@]} toggle combinations ($PDF_COMBOS)" $?
  echo "      sizes (combo:bytes): $sizes"
  echo "      NOTE: this proves valid PDFs, not that each toggle shows/hides the right section: do that by eye (ops/UAT-checklist.md section 7)."
else
  skip "PDF report" "visit 3 missing"
fi

# =====================================================================================================================
section "10. Excel exports"
sheets_of() { unzip -p "$1" xl/workbook.xml 2>/dev/null | grep -o 'sheet name="[^"]*"' | sed 's/sheet name="//; s/"$//' | paste -sd, -; }
xlsx_text() { { unzip -p "$WORK/out.xlsx" xl/sharedStrings.xml; unzip -p "$WORK/out.xlsx" 'xl/worksheets/*.xml'; } 2>/dev/null; }
fetch_xlsx() { # fetch_xlsx <path+query>
  HTTP="$(curl -sS -o "$WORK/out.xlsx" -w '%{http_code}' -H "Authorization: Bearer $TOK_A" "$BASE_URL/int/v1/service-tracker/$1" 2>/dev/null)" || HTTP=000
}
fetch_xlsx "exports/full.xlsx"
[ "$HTTP" = 200 ] && [ "$(head -c 2 "$WORK/out.xlsx")" = "PK" ]; check "full workbook downloads as a zip (HTTP $HTTP)" $?
S="$(sheets_of "$WORK/out.xlsx")"
for want in Vehicles Visits "Vendor Stops" Services "Damage Log" "Petty Cash" "Service Catalog"; do
  [[ ",$S," == *",$want,"* ]]; check "full workbook has sheet '$want'" $? "sheets: $S"
done
fetch_xlsx "exports/period.xlsx?from=2026-10-05&to=2026-10-09&basis=arrived&timing=1&petty=1&vehicles=1"
if [ "$HTTP" = 200 ]; then
  S="$(sheets_of "$WORK/out.xlsx")"; [[ ",$S," == *",Summary,"* ]]; check "period workbook has a Summary sheet (sheets: $S)" $?
  XT="$(xlsx_text)"; grep -q 'Average total turnaround' <<<"$XT"
  check "period summary with timing=1 contains the turnaround block" $?
  fetch_xlsx "exports/period.xlsx?from=2026-10-05&to=2026-10-09&basis=arrived&timing=0&petty=0&vehicles=0"
  XT="$(xlsx_text)"; ! grep -q 'Average total turnaround' <<<"$XT"
  check "period summary with timing=0 drops the turnaround block" $?
  S="$(sheets_of "$WORK/out.xlsx")"; [[ ",$S," != *",Petty Cash,"* && ",$S," != *",Vehicles,"* ]]; check "period workbook with petty=0 vehicles=0 omits those sheets (sheets: $S)" $?
else
  fail "period workbook downloads" "HTTP $HTTP"
fi

# =====================================================================================================================
section "11. tenant isolation (company B must not see or touch company A)"
A_VISIT="${VISIT_ID[0]:-}"
if [ -n "$A_VISIT" ]; then
  req keyB GET /v1/service-tracker/visits; expect_code "B lists visits (driver API)" 200
  eq "B sees none of A's visits" "$(j '(.meta.total // (.data|length) // 0)')" "0"
  req keyB GET "/v1/service-tracker/visits/$A_VISIT"; expect_code "B reads A's visit by id" "403|404"
  req keyB PUT "/v1/service-tracker/visits/$A_VISIT" '{"notes":"hijack"}'; expect_code "B updates A's visit" "403|404"
  req keyB POST /v1/service-tracker/damage-records "$(jq -n --arg v "$A_VISIT" '{service_visit_uuid:$v,stage:"Vendor reported",client_uuid:"smoke-cross-tenant"}')"
  expect_code "B attaches a damage record to A's visit" "403|404|422"
  req tokB GET "/int/v1/service-tracker/visits/$A_VISIT"; expect_code "B (console token) reads A's visit" "403|404"
  req tokB GET "/int/v1/service-tracker/visits/$A_VISIT/calc"; expect_code "B reads A's calc" "403|404"
  fetch_pdf_b() { HTTP="$(curl -sS -o "$WORK/b.pdf" -w '%{http_code}' -H "Authorization: Bearer $TOK_B" "$BASE_URL/int/v1/service-tracker/reports/visit/$A_VISIT.pdf" 2>/dev/null)" || HTTP=000; }
  fetch_pdf_b; [[ "$HTTP" == 403 || "$HTTP" == 404 ]]; check "B downloads A's PDF is refused (HTTP $HTTP)" $?
  req tokB GET /int/v1/service-tracker/petty-cash; eq "B sees none of A's petty cash" "$(j '(.meta.total // (.petty_cash|length) // 0)')" "0"
  req keyB GET /v1/service-tracker/petty-cash; eq "B (driver API) sees none of A's petty cash" "$(j '(.meta.total // (.data|length) // 0)')" "0"
  req tokB GET /int/v1/service-tracker/catalog?limit=1; CATB="$(j '.meta.total // 0')"
  [ "${CATB:-0}" -ge 145 ] 2>/dev/null; check "B has its own seeded catalog ($CATB) rather than sharing A's" $?
  req none GET "/v1/service-tracker/visits/$A_VISIT"; expect_code "anonymous reads A's visit" "401|403"
else
  skip "tenant isolation" "company A has no visit"
fi

# =====================================================================================================================
section "12. optional: FleetOps side effects of the close hook (needs DB_QUERY_CMD)"
if [ -n "${DB_QUERY_CMD:-}" ]; then
  q() { eval "$DB_QUERY_CMD \"\$1\"" 2>/dev/null | tail -n 1; }
  M="$(q "SELECT COUNT(*) FROM maintenances WHERE notes LIKE '%service-tracker:job:%' OR summary LIKE '%service-tracker:job:%'")"
  [ "${M:-0}" -ge 1 ] 2>/dev/null; check "close hook wrote FleetOps maintenance rows (${M:-?}; sample visits have 8 jobs, all Done)" $?
  I="$(q "SELECT COUNT(*) FROM issues WHERE report LIKE '%Left front door%' OR report LIKE '%Dent%'")"
  [ "${I:-0}" -ge 1 ] 2>/dev/null; check "a NEW damage on a closed visit raised a FleetOps issue (${I:-?})" $?
  S2="$(q "SELECT COUNT(*) FROM maintenance_schedules WHERE next_due_odometer IS NOT NULL")"
  [ "${S2:-0}" -ge 1 ] 2>/dev/null; check "maintenance schedules carry next_due_odometer (${S2:-?})" $?
else
  skip "FleetOps side effects" "DB_QUERY_CMD not set"
fi

# ---------------------------------------------------------------------------------------------------------------------
printf '\n===== smoke result: %d passed, %d failed, %d skipped (run tag %s) =====\n' "$PASS" "$FAILN" "$SKIPN" "$TAG"
if [ "$FAILN" -gt 0 ]; then printf 'FAILED:\n'; printf '  - %s\n' "${FAILS[@]}"; fi
if [ "$SKIPN" -gt 0 ]; then printf 'SKIPPED (not verified):\n'; printf '  - %s\n' "${SKIPS[@]}"; fi
[ "$FAILN" -eq 0 ]
