#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "dashboard_api"
trap scenario_cleanup EXIT

export MQTT_PUBLISHER_USERNAME="${MQTT_PUBLISHER_USERNAME:-hub_publisher}"
export MQTT_PUBLISHER_PASSWORD="${MQTT_PUBLISHER_PASSWORD:-hub_publisher_pass}"
export MQTT_SMOKE_USERNAME="${MQTT_SMOKE_USERNAME:-hub_smoke}"
export MQTT_SMOKE_PASSWORD="${MQTT_SMOKE_PASSWORD:-hub_smoke_pass}"
export MQTT_TOPIC_PREFIX=""
export WHITELIST_FILE="config/whitelist.example.json"
export DASHBOARD_USERNAME="admin"
export DASHBOARD_PASSWORD="secret"

IMEI="868705080300697"

docker compose up -d --force-recreate --remove-orphans mosquitto redis hub >/dev/null

wait_for_mosquitto

for _ in $(seq 1 40); do
  if docker compose exec -T hub php -r '$s=@fsockopen("127.0.0.1", 8081, $e, $m, 1); if ($s) { fclose($s); exit(0); } exit(1);' >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if ! docker compose exec -T hub php -r '$s=@fsockopen("127.0.0.1", 8081, $e, $m, 1); if ($s) { fclose($s); exit(0); } exit(1);' >/dev/null 2>&1; then
  scenario_fail "dashboard_failure" "dashboard HTTP listener did not become ready"
fi

unauth_status="$(curl -s -o /tmp/dashboard-unauth.txt -w '%{http_code}' http://127.0.0.1:8081/api/dashboard/summary)"
if [ "$unauth_status" != "401" ]; then
  scenario_fail "auth_failure" "dashboard API did not require basic auth"
fi

html="$(curl -s -u admin:secret http://127.0.0.1:8081/dashboard)"
if ! printf '%s' "$html" | grep -q 'Devices Hub'; then
  scenario_fail "dashboard_failure" "dashboard HTML did not render expected page"
fi

summary="$(curl -s -u admin:secret http://127.0.0.1:8081/api/dashboard/summary)"
printf '%s' "$summary" > "$SCENARIO_DIR/dashboard-summary.json"
if ! printf '%s' "$summary" | grep -q "$IMEI"; then
  scenario_fail "dashboard_failure" "dashboard summary did not include whitelist device"
fi

command_response="$(curl -s -u admin:secret -H 'Content-Type: application/json' -d '{"command":"dnHeartRate"}' "http://127.0.0.1:8081/api/devices/$IMEI/commands")"
printf '%s' "$command_response" > "$SCENARIO_DIR/dashboard-command.json"
if ! printf '%s' "$command_response" | grep -q '"status":"queued"'; then
  scenario_fail "command_failure" "offline dashboard command was not queued"
fi

device="$(curl -s -u admin:secret "http://127.0.0.1:8081/api/devices/$IMEI")"
printf '%s' "$device" > "$SCENARIO_DIR/dashboard-device.json"
if ! printf '%s' "$device" | grep -q '"dnHeartRate"'; then
  scenario_fail "dashboard_failure" "device detail did not include command or pending queue state"
fi

scenario_pass
