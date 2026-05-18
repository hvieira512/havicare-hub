#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "s4_redis_failure"
trap scenario_cleanup EXIT

start_stack
restart_runtime
migrate_seed
wait_for_mosquitto

# Force enqueue failure by stopping Redis.
docker compose stop redis >/dev/null
sleep 2

curl -s -X POST http://127.0.0.1:8081/devices/865028000000306/command \
  -H 'Content-Type: application/json' \
  -d '{"type":"dnHeartRate","data":{}}' > "$API_RESPONSE_FILE"

if ! grep -q '"code":"device_offline"' "$API_RESPONSE_FILE"; then
  scenario_fail "stream_failure" "API response did not expose command enqueue failure semantics"
fi

API_LOG_SNAPSHOT="$SCENARIO_DIR/${SCENARIO_NAME}-api-after-redis-stop.log"
docker compose logs --no-color api > "$API_LOG_SNAPSHOT" 2>&1 || true
if ! grep -Eq 'command enqueue failed|commandPublish:' "$API_LOG_SNAPSHOT"; then
  scenario_fail "stream_failure" "API logs did not include enqueue failure diagnostic"
fi

# Restore Redis for cleanup and future scenarios.
docker compose start redis >/dev/null
sleep 2

scenario_pass
