#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "s2_wonlex_command_roundtrip"
trap scenario_cleanup EXIT

start_stack
restart_runtime
migrate_seed
wait_for_mosquitto
start_mqtt_subscriber
start_wonlex_listener

sleep 3

curl -s -X POST http://127.0.0.1:8081/devices/865028000000306/command \
  -H 'Content-Type: application/json' \
  -d '{"type":"dnHeartRate","data":{}}' > "$API_RESPONSE_FILE"

if ! grep -q '"status":"sent"' "$API_RESPONSE_FILE"; then
  scenario_fail "routing_failure" "API command was not accepted as sent"
fi

sleep 5

TOPIC="devices/865028000000306/command/state"
assert_topic_present "$TOPIC"
capture_mqtt_log
if ! grep -q '^devices/865028000000306/command/state .*"state":"dispatched"' "$MQTT_LOG_FILE"; then
  scenario_fail "routing_failure" "missing dispatched command state"
fi
if ! grep -q '^devices/865028000000306/command/state .*"state":"ack"' "$MQTT_LOG_FILE"; then
  scenario_fail "routing_failure" "missing ack command state"
fi

scenario_pass
