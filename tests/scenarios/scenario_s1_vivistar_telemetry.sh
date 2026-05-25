#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "s1_vivistar_telemetry"
trap scenario_cleanup EXIT

start_stack
restart_runtime
migrate_seed
wait_for_mosquitto
start_mqtt_subscriber

# Trigger Vivistar telemetry through native TCP ingress.
sent=0
for _ in $(seq 1 20); do
  if docker compose exec -T ws php simulator/simulate.php \
    --server tcp://127.0.0.1:9000 \
    --model VIVISTAR-CARE \
    --imei 865028000000308 \
    --command AP49 \
    --data '{"heartRate":72}' >/dev/null; then
    sent=1
    break
  fi
  sleep 1
done
if [ "$sent" -ne 1 ]; then
  scenario_fail "routing_failure" "failed to send Vivistar AP49 fixture event after retries"
fi

sleep 4

TOPIC="devices/865028000000308/telemetry"
PAYLOAD_FILE="$SCENARIO_DIR/${SCENARIO_NAME}-telemetry.json"

assert_topic_present "$TOPIC"
latest_topic_payload_to_file "$TOPIC" "$PAYLOAD_FILE"
assert_json_path_equals "$PAYLOAD_FILE" "event.type" "telemetry.received"
assert_json_path_equals "$PAYLOAD_FILE" "device.imei" "865028000000308"
assert_json_path_equals "$PAYLOAD_FILE" "data.heartRateBpm" "72"

if ! wait_for_db_event 35 "865028000000308" "AP49" "heart_rate"; then
  scenario_fail "persistence_failure" "DB persistence did not include expected IMEI/native_type/feature"
fi

if ! wait_for_api_recent_entry 35 "AP49" "heart_rate" "heartRateBpm" "72"; then
  scenario_fail "persistence_failure" "API recent events did not include expected normalized AP49 heart-rate event"
fi

scenario_pass
