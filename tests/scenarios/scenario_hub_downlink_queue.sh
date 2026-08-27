#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "hub_downlink_queue"
trap scenario_cleanup EXIT


IMEI="865028000000308"
DEVICE_TOPIC_PREFIX="null/42/watch/$IMEI"
DOWNLINK='{"encoding":"text","payload":"IWBPXL,865028000000308,654321,1,2#"}'

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

docker compose exec -T mosquitto sh -lc "printf '%s' '$DOWNLINK' >/tmp/hub-downlink.json && mosquitto_pub -h 127.0.0.1 -p 1883 -u '$MQTT_PUBLISHER_USERNAME' -P '$MQTT_PUBLISHER_PASSWORD' -t 'null/42/watch/$IMEI/downlink' -f /tmp/hub-downlink.json"

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep -q "^$DEVICE_TOPIC_PREFIX/events " "$MQTT_LOG_FILE" && grep -q '"type":"device.downlink.queued"' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q '"type":"device.downlink.queued"' "$MQTT_LOG_FILE"; then
  scenario_fail "queue_failure" "offline downlink was not queued"
fi

docker compose exec -T hub sh -lc 'rm -f /tmp/hub-vivistar-listener.log /tmp/hub-vivistar-listener.pid'
docker compose exec -T hub sh -lc "php simulator/simulate.php --server tcp://127.0.0.1:9000 --model VIVISTAR-CARE --imei $IMEI --listen > /tmp/hub-vivistar-listener.log 2>&1 & echo \$! > /tmp/hub-vivistar-listener.pid"

for _ in $(seq 1 20); do
  if docker compose exec -T hub sh -lc "grep -q '\\[COMMAND\\] BPXL' /tmp/hub-vivistar-listener.log"; then
    break
  fi
  sleep 1
done

docker compose exec -T hub sh -lc 'cat /tmp/hub-vivistar-listener.log 2>/dev/null || true' > "$SCENARIO_DIR/device-listener.log" || true
if ! grep -q '\[COMMAND\] BPXL' "$SCENARIO_DIR/device-listener.log"; then
  scenario_fail "routing_failure" "device listener did not receive queued downlink after reconnect"
fi

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep -q "^$DEVICE_TOPIC_PREFIX/events " "$MQTT_LOG_FILE" && grep -q '"type":"device.downlink.sent"' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q '"type":"device.downlink.sent"' "$MQTT_LOG_FILE"; then
  scenario_fail "queue_failure" "queued downlink did not publish sent event after reconnect"
fi

scenario_pass
