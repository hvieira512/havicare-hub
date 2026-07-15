#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "hub_persistent_mqtt_downlink"
trap scenario_cleanup EXIT

export MQTT_PUBLISHER_USERNAME="${MQTT_PUBLISHER_USERNAME:-hub_publisher}"
export MQTT_PUBLISHER_PASSWORD="${MQTT_PUBLISHER_PASSWORD:-hub_publisher_pass}"
export MQTT_SMOKE_USERNAME="${MQTT_SMOKE_USERNAME:-hub_smoke}"
export MQTT_SMOKE_PASSWORD="${MQTT_SMOKE_PASSWORD:-hub_smoke_pass}"
export MQTT_HOST="mosquitto"
export MQTT_PORT="1883"
export MQTT_USERNAME="$MQTT_PUBLISHER_USERNAME"
export MQTT_PASSWORD="$MQTT_PUBLISHER_PASSWORD"
export MQTT_TOPIC_PREFIX=""
export WHITELIST_FILE="config/whitelist.example.json"

IMEI="865028000000308"
DOWNLINK='{"encoding":"text","payload":"IWBPXL,865028000000308,777777,1,2#"}'

docker compose up -d --force-recreate --remove-orphans mosquitto redis hub >/dev/null

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
docker compose stop hub >/dev/null

docker compose exec -T mosquitto sh -lc "printf '%s' '$DOWNLINK' >/tmp/hub-downlink.json && mosquitto_pub -q 1 -h 127.0.0.1 -p 1883 -u '$MQTT_PUBLISHER_USERNAME' -P '$MQTT_PUBLISHER_PASSWORD' -t 'null/42/watch/$IMEI/downlink' -f /tmp/hub-downlink.json"

docker compose up -d hub >/dev/null

for _ in $(seq 1 40); do
  capture_mqtt_log
  if grep -q "^null/42/watch/$IMEI/events " "$MQTT_LOG_FILE" && grep -q '"type":"device.downlink.queued"' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q '"type":"device.downlink.queued"' "$MQTT_LOG_FILE"; then
  scenario_fail "queue_failure" "QoS 1 downlink published while hub was stopped was not delivered after hub restart"
fi

scenario_pass
