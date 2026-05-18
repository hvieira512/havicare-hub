#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "s3_status_and_error"
trap scenario_cleanup EXIT

start_stack
restart_runtime
migrate_seed
wait_for_mosquitto
start_mqtt_subscriber

# Bring a known device online for status messages.
start_wonlex_listener
sleep 2
stop_wonlex_listener
sleep 2

# Trigger an integration/protocol error path.
docker compose exec -T ws php simulator/simulate.php \
  --server ws://127.0.0.1:8080 \
  --model VIVISTAR-CARE \
  --imei 865028000000306 \
  --command AP49 >/dev/null || true

sleep 4

assert_topic_present "devices/865028000000306/status"
assert_topic_present "devices/865028000000306/error"

STATUS_FILE="$SCENARIO_DIR/${SCENARIO_NAME}-status.json"
ERROR_FILE="$SCENARIO_DIR/${SCENARIO_NAME}-error.json"
latest_topic_payload_to_file "devices/865028000000306/status" "$STATUS_FILE"
latest_topic_payload_to_file "devices/865028000000306/error" "$ERROR_FILE"
assert_json_path_equals "$STATUS_FILE" "event.type" "device.status.changed"
assert_json_path_equals "$ERROR_FILE" "event.type" "integration.error"

scenario_pass
