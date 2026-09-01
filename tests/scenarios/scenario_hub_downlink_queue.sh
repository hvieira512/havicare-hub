#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "hub_downlink_queue"
trap scenario_cleanup EXIT


# Um dispositivo do inventário semeado, e distinto do dos outros cenários: uma fila que
# fique por drenar não segue para a corrida seguinte.
IMEI="861265062544868"
MODEL="VL16P"
DEVICE_TOPIC_PREFIX="havicare/1/watch/$IMEI"

export DASHBOARD_API_AUTH_REQUIRED="true"

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

wait_for_dashboard
api_token="$(scenario_api_token)"
if [ -z "$api_token" ]; then
  scenario_fail "auth_failure" "dashboard API login did not issue bearer token"
fi

# O dispositivo ainda não se ligou: o comando tem de ficar em fila, não ser descartado.
command_response="$(curl -s -H "Authorization: Bearer $api_token" -H 'Content-Type: application/json' \
  -d '{"feature":"heart_rate"}' "$DASHBOARD_BASE_URL/api/devices/$IMEI/requests")"
printf '%s' "$command_response" > "$SCENARIO_DIR/api-command.json"
if ! printf '%s' "$command_response" | grep -q '"status":"queued"'; then
  scenario_fail "command_failure" "offline API command was not queued"
fi

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
docker compose exec -T hub sh -lc "php simulator/simulate.php --server tcp://127.0.0.1:9000 --model $MODEL --imei $IMEI --listen > /tmp/hub-vivistar-listener.log 2>&1 & echo \$! > /tmp/hub-vivistar-listener.pid"

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
