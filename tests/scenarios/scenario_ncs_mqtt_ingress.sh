#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "ncs_mqtt_ingress"
trap scenario_cleanup EXIT

export MQTT_PUBLISHER_USERNAME="${MQTT_PUBLISHER_USERNAME:-hub_publisher}"
export MQTT_PUBLISHER_PASSWORD="${MQTT_PUBLISHER_PASSWORD:-hub_publisher_pass}"
export MQTT_SMOKE_USERNAME="${MQTT_SMOKE_USERNAME:-hub_smoke}"
export MQTT_SMOKE_PASSWORD="${MQTT_SMOKE_PASSWORD:-hub_smoke_pass}"
export MQTT_TOPIC_PREFIX=""
export WHITELIST_FILE="config/whitelist.example.json"

EVENT_PAYLOAD='{"from":"gw-001","type":6,"timestamp":1718700000,"payload":{"id":"button-07","key":"8","transparent":{"raw":"0A01"},"location":{"lat":41.1579,"lon":-8.6291,"accuracy":12}}}'
STATUS_PAYLOAD='{"from":"gw-001","payload":{"status":{"online":false}}}'

docker compose up -d --force-recreate --remove-orphans mosquitto hub >/dev/null

wait_for_mosquitto
start_mqtt_subscriber

for _ in $(seq 1 40); do
  if docker compose exec -T hub php -r '$s=@fsockopen("127.0.0.1", 9000, $e, $m, 1); if ($s) { fclose($s); exit(0); } exit(1);' >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if ! docker compose exec -T hub php -r '$s=@fsockopen("127.0.0.1", 9000, $e, $m, 1); if ($s) { fclose($s); exit(0); } exit(1);' >/dev/null 2>&1; then
  scenario_fail "routing_failure" "hub TCP listener did not become ready"
fi

sleep 2
docker compose exec -T mosquitto sh -lc "printf '%s' '$EVENT_PAYLOAD' >/tmp/ncs-event.json && mosquitto_pub -q 1 -h 127.0.0.1 -p 1883 -u '$MQTT_PUBLISHER_USERNAME' -P '$MQTT_PUBLISHER_PASSWORD' -t '/voerka/ncs/devices/gw-001/events' -f /tmp/ncs-event.json"
docker compose exec -T mosquitto sh -lc "printf '%s' '$STATUS_PAYLOAD' >/tmp/ncs-status.json && mosquitto_pub -q 1 -h 127.0.0.1 -p 1883 -u '$MQTT_PUBLISHER_USERNAME' -P '$MQTT_PUBLISHER_PASSWORD' -t '/voerka/default/devices/gw-001/status/online' -f /tmp/ncs-status.json"

for _ in $(seq 1 30); do
  capture_mqtt_log
  if grep -q "^1001/ncs/ncs-gateway-01/raw " "$MQTT_LOG_FILE" \
    && grep -q "^1001/ncs/ncs-gateway-01/events " "$MQTT_LOG_FILE" \
    && grep -q "^1001/ncs/ncs-gateway-01/telemetry " "$MQTT_LOG_FILE" \
    && grep -q "^1001/ncs/ncs-gateway-01/status " "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q "^1001/ncs/ncs-gateway-01/raw " "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "missing NCS raw topic"
fi
if ! grep -q '"sourceTopic":"/voerka/ncs/devices/gw-001/events"' "$MQTT_LOG_FILE"; then
  scenario_fail "contract_failure" "NCS raw payload did not preserve the upstream topic"
fi
if ! grep -q "^1001/ncs/ncs-gateway-01/events " "$MQTT_LOG_FILE" || ! grep -q '"type":"ncs.event"' "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "missing normalized NCS event topic"
fi
if ! grep -q '"transparent":{"raw":"0A01"}' "$MQTT_LOG_FILE"; then
  scenario_fail "contract_failure" "NCS event did not preserve transparent payload"
fi
if ! grep -q "^1001/ncs/ncs-gateway-01/telemetry " "$MQTT_LOG_FILE" || ! grep -q '"type":"location"' "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "missing NCS location telemetry topic"
fi
if ! grep -q "^1001/ncs/ncs-gateway-01/status " "$MQTT_LOG_FILE" || ! grep -q '"state":"offline"' "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "missing retained NCS offline status topic"
fi

scenario_pass
