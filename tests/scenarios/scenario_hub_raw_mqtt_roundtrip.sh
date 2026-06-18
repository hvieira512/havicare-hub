#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "hub_raw_mqtt_roundtrip"
trap scenario_cleanup EXIT

export MQTT_PUBLISHER_USERNAME="${MQTT_PUBLISHER_USERNAME:-hub_publisher}"
export MQTT_PUBLISHER_PASSWORD="${MQTT_PUBLISHER_PASSWORD:-hub_publisher_pass}"
export MQTT_SMOKE_USERNAME="${MQTT_SMOKE_USERNAME:-hub_smoke}"
export MQTT_SMOKE_PASSWORD="${MQTT_SMOKE_PASSWORD:-hub_smoke_pass}"
export MQTT_TOPIC_PREFIX=""
export WHITELIST_FILE="config/whitelist.example.json"

IMEI="865028000000308"
DOWNLINK='{"encoding":"text","payload":"IWBPXL,865028000000308,123456,1,2#"}'

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

docker compose exec -T hub sh -lc 'rm -f /tmp/hub-vivistar-listener.log /tmp/hub-vivistar-listener.pid'
docker compose exec -T hub sh -lc "php simulator/simulate.php --server tcp://127.0.0.1:9000 --model VIVISTAR-CARE --imei $IMEI --listen > /tmp/hub-vivistar-listener.log 2>&1 & echo \$! > /tmp/hub-vivistar-listener.pid"

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep -q "^0/watch/$IMEI/raw " "$MQTT_LOG_FILE" && grep -q '"payload":"IWAP00' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q "^0/watch/$IMEI/raw " "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "missing raw login topic"
fi
if ! grep -q '"debug":{"protocol":"vivistar-iw","transport":"tcp","encoding":"text","payload":"IWAP00' "$MQTT_LOG_FILE"; then
  scenario_fail "contract_failure" "raw login did not include text debug payload"
fi

docker compose exec -T mosquitto sh -lc "printf '%s' '$DOWNLINK' >/tmp/hub-downlink.json && mosquitto_pub -h 127.0.0.1 -p 1883 -u '$MQTT_PUBLISHER_USERNAME' -P '$MQTT_PUBLISHER_PASSWORD' -t '0/watch/$IMEI/downlink' -f /tmp/hub-downlink.json"

for _ in $(seq 1 20); do
  if docker compose exec -T hub sh -lc "grep -q '\\[COMMAND\\] BPXL' /tmp/hub-vivistar-listener.log"; then
    break
  fi
  sleep 1
done

docker compose exec -T hub sh -lc 'cat /tmp/hub-vivistar-listener.log 2>/dev/null || true' > "$SCENARIO_DIR/device-listener.log" || true
if ! grep -q '\[COMMAND\] BPXL' "$SCENARIO_DIR/device-listener.log"; then
  scenario_fail "routing_failure" "device listener did not receive MQTT downlink"
fi

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep -q "^0/watch/$IMEI/raw " "$MQTT_LOG_FILE" && grep -q '"payload":"IWAPXL,123456#"' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q '"payload":"IWAPXL,123456#"' "$MQTT_LOG_FILE"; then
  scenario_fail "routing_failure" "missing raw device reply after MQTT downlink"
fi

scenario_pass
