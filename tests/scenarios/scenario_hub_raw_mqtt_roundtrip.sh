#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "hub_raw_mqtt_roundtrip"
trap scenario_cleanup EXIT


# Um dispositivo do inventário semeado por `bin/seed-inventory.php`, e não um de fixture: o
# hub resolve a empresa e a licença do tópico pela whitelist da base de dados.
IMEI="861265062542599"
MODEL="VL17"
DEVICE_TOPIC_PREFIX="havicare/1/watch/$IMEI"

export DASHBOARD_API_AUTH_REQUIRED="true"

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
docker compose exec -T hub sh -lc "php simulator/simulate.php --server tcp://127.0.0.1:9000 --model $MODEL --imei $IMEI --listen > /tmp/hub-vivistar-listener.log 2>&1 & echo \$! > /tmp/hub-vivistar-listener.pid"

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep -q "^$DEVICE_TOPIC_PREFIX/raw " "$MQTT_LOG_FILE" && grep -q '"payload":"IWAP00' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q "^$DEVICE_TOPIC_PREFIX/raw " "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "missing raw login topic"
fi
if ! grep -q '"debug":{"protocol":"vivistar-iw","transport":"tcp","encoding":"text","payload":"IWAP00' "$MQTT_LOG_FILE"; then
  scenario_fail "contract_failure" "raw login did not include text debug payload"
fi

wait_for_dashboard
api_token="$(scenario_api_token)"
if [ -z "$api_token" ]; then
  scenario_fail "auth_failure" "dashboard API login did not issue bearer token"
fi

# Um pedido de frequência cardíaca desce como `BPXL`.
command_response="$(curl -s -H "Authorization: Bearer $api_token" -H 'Content-Type: application/json' \
  -d '{"feature":"heart_rate"}' "$DASHBOARD_BASE_URL/api/devices/$IMEI/requests")"
printf '%s' "$command_response" > "$SCENARIO_DIR/api-command.json"
# Ligado, o comando desce logo e fica `waiting`. O `sentAt` é a prova de que saiu.
if printf '%s' "$command_response" | grep -q '"status":"queued"'; then
  scenario_fail "command_failure" "API command was queued although the device was connected"
fi
if ! printf '%s' "$command_response" | grep -q '"sentAt"'; then
  scenario_fail "command_failure" "online API command was not sent to the connected device"
fi

for _ in $(seq 1 20); do
  if docker compose exec -T hub sh -lc "grep -q '\\[COMMAND\\] BPXL' /tmp/hub-vivistar-listener.log"; then
    break
  fi
  sleep 1
done

docker compose exec -T hub sh -lc 'cat /tmp/hub-vivistar-listener.log 2>/dev/null || true' > "$SCENARIO_DIR/device-listener.log" || true
if ! grep -q '\[COMMAND\] BPXL' "$SCENARIO_DIR/device-listener.log"; then
  scenario_fail "routing_failure" "device listener did not receive the API command"
fi

# O identificador da transação é gerado pelo hub: prende-se só o prefixo.
for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep -q "^$DEVICE_TOPIC_PREFIX/raw " "$MQTT_LOG_FILE" && grep -q '"payload":"IWAPXL,' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
if ! grep -q '"payload":"IWAPXL,' "$MQTT_LOG_FILE"; then
  scenario_fail "routing_failure" "missing raw device reply after the API command"
fi

scenario_pass
