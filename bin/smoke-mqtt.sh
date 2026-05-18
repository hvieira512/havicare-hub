#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

MQTT_LOG_HOST="/tmp/mqtt-smoke.log"
LISTENER_LOG_HOST="/tmp/sim-listener.log"
TELEMETRY_LOG_HOST="/tmp/sim-telemetry.log"
ERROR_LOG_HOST="/tmp/sim-error.log"
API_COMMAND_HOST="/tmp/api-command.json"
MQTT_SMOKE_USER="${MQTT_SMOKE_USER:-integration_smoke}"
MQTT_SMOKE_PASS="${MQTT_SMOKE_PASS:-integration_smoke_dev}"

cleanup() {
  docker compose exec -T ws sh -lc 'test -f /tmp/sim-listener.pid && kill "$(cat /tmp/sim-listener.pid)" 2>/dev/null || true' >/dev/null 2>&1 || true
  docker compose exec -T mosquitto sh -lc 'test -f /tmp/mqtt-sub.pid && kill "$(cat /tmp/mqtt-sub.pid)" 2>/dev/null || true' >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[smoke] starting required services..."
docker compose up -d mysql redis ws api worker mosquitto mqtt-publisher >/dev/null

echo "[smoke] ensuring latest code is running..."
docker compose restart ws api mqtt-publisher >/dev/null

echo "[smoke] migrating/seed database..."
docker compose exec -T api php bin/migrate.php --seed >/dev/null

echo "[smoke] waiting for mosquitto to be ready..."
for _ in $(seq 1 30); do
  if docker compose exec -T mosquitto sh -lc "mosquitto_sub --help >/dev/null 2>&1"; then
    break
  fi
  sleep 1
done

echo "[smoke] preparing logs..."
docker compose exec -T mosquitto sh -lc 'rm -f /tmp/mqtt-smoke.log /tmp/mqtt-sub.pid'
docker compose exec -T ws sh -lc 'rm -f /tmp/sim-listener.log /tmp/sim-listener.pid /tmp/sim-telemetry.log /tmp/sim-error.log'
rm -f "$MQTT_LOG_HOST" "$LISTENER_LOG_HOST" "$TELEMETRY_LOG_HOST" "$ERROR_LOG_HOST" "$API_COMMAND_HOST"

echo "[smoke] subscribing to devices/# ..."
docker compose exec -T mosquitto sh -lc "mosquitto_sub -h 127.0.0.1 -p 1883 -u '$MQTT_SMOKE_USER' -P '$MQTT_SMOKE_PASS' -v -t 'devices/#' > /tmp/mqtt-smoke.log 2>&1 & echo \$! > /tmp/mqtt-sub.pid"

echo "[smoke] starting device listener for command ack..."
docker compose exec -T ws sh -lc "php simulator/simulate.php --server ws://127.0.0.1:8080 --model WONLEX-PRO --imei 865028000000306 --listen > /tmp/sim-listener.log 2>&1 & echo \$! > /tmp/sim-listener.pid"

sleep 3

echo "[smoke] trigger telemetry..."
docker compose exec -T ws sh -lc "php simulator/simulate.php --server ws://127.0.0.1:8080 --model WONLEX-HEALTH --imei 865028000000307 --command upBattery > /tmp/sim-telemetry.log 2>&1 || true"

sleep 2

echo "[smoke] trigger command dispatch + ack..."
curl -s -X POST http://127.0.0.1:8081/devices/865028000000306/command \
  -H "Content-Type: application/json" \
  -d '{"type":"dnHeartRate","data":{}}' > "$API_COMMAND_HOST" || true

sleep 2

echo "[smoke] trigger integration error..."
docker compose exec -T ws sh -lc "php simulator/simulate.php --server ws://127.0.0.1:8080 --model VIVISTAR-CARE --imei 865028000000306 --command AP49 > /tmp/sim-error.log 2>&1 || true"

sleep 3

echo "[smoke] stopping background helpers..."
cleanup
sleep 1

echo "[smoke] exporting logs..."
docker compose exec -T mosquitto sh -lc 'cat /tmp/mqtt-smoke.log' > "$MQTT_LOG_HOST" || true
docker compose exec -T ws sh -lc 'cat /tmp/sim-listener.log' > "$LISTENER_LOG_HOST" || true
docker compose exec -T ws sh -lc 'cat /tmp/sim-telemetry.log' > "$TELEMETRY_LOG_HOST" || true
docker compose exec -T ws sh -lc 'cat /tmp/sim-error.log' > "$ERROR_LOG_HOST" || true

telemetry_count=$(grep -c '^devices/865028000000307/telemetry ' "$MQTT_LOG_HOST" || true)
status_count=$(grep -c '^devices/865028000000306/status\|^devices/865028000000307/status' "$MQTT_LOG_HOST" || true)
error_count=$(grep -c '^devices/865028000000306/error ' "$MQTT_LOG_HOST" || true)
command_state_count=$(grep -c '^devices/865028000000306/command/state ' "$MQTT_LOG_HOST" || true)

echo
echo "[smoke] topic counts"
echo "  devices/865028000000307/telemetry   => $telemetry_count"
echo "  devices/*/status                   => $status_count"
echo "  devices/865028000000306/error      => $error_count"
echo "  devices/865028000000306/command/state => $command_state_count"
echo
echo "[smoke] API command response"
cat "$API_COMMAND_HOST" || true
echo

echo "[smoke] unique topics captured"
awk '{print $1}' "$MQTT_LOG_HOST" | sort -u || true

missing=0
if [ "$telemetry_count" -lt 1 ]; then
  echo "[smoke][FAIL] missing telemetry topic message"
  missing=1
fi
if [ "$status_count" -lt 1 ]; then
  echo "[smoke][FAIL] missing status topic message"
  missing=1
fi
if [ "$error_count" -lt 1 ]; then
  echo "[smoke][FAIL] missing error topic message"
  missing=1
fi
if [ "$command_state_count" -lt 1 ]; then
  echo "[smoke][FAIL] missing command/state topic message"
  missing=1
fi

if [ "$missing" -ne 0 ]; then
  echo "[smoke] FAIL"
  exit 1
fi

echo "[smoke] PASS"
