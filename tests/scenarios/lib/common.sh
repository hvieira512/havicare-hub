#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
ARTIFACTS_ROOT="${ARTIFACTS_ROOT:-$ROOT_DIR/tests/artifacts}"

if [ -f "$ROOT_DIR/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  . "$ROOT_DIR/.env"
  set +a
fi

SCENARIO_NAME=""
SCENARIO_DIR=""
MQTT_LOG_FILE=""

scenario_begin() {
  SCENARIO_NAME="$1"
  local run_id
  run_id="$(date +%Y%m%d-%H%M%S)"
  SCENARIO_DIR="$ARTIFACTS_ROOT/$run_id/$SCENARIO_NAME"
  mkdir -p "$SCENARIO_DIR"
  MQTT_LOG_FILE="$SCENARIO_DIR/mqtt.log"
  echo "[scenario:$SCENARIO_NAME] artifacts -> $SCENARIO_DIR"
}

scenario_fail() {
  local class="$1"
  local message="$2"
  echo "[scenario:$SCENARIO_NAME][FAIL][$class] $message"
  echo "$class" > "$SCENARIO_DIR/failure-classification.txt"
  echo "$message" > "$SCENARIO_DIR/failure-message.txt"
  capture_artifacts || true
  exit 1
}

scenario_pass() {
  echo "[scenario:$SCENARIO_NAME][PASS]"
  capture_artifacts || true
}

scenario_cleanup() {
  docker compose exec -T hub sh -lc 'test -f /tmp/hub-vivistar-listener.pid && kill "$(cat /tmp/hub-vivistar-listener.pid)" 2>/dev/null || true' >/dev/null 2>&1 || true
  stop_mqtt_subscriber || true
}

wait_for_mosquitto() {
  for _ in $(seq 1 40); do
    if docker compose ps --status running --services 2>/dev/null | grep -q '^mosquitto$'; then
      return 0
    fi
    sleep 1
  done
  scenario_fail "stream_failure" "mosquitto did not become ready"
}

start_mqtt_subscriber() {
  if [ -z "${MQTT_SMOKE_USERNAME:-}" ] || [ -z "${MQTT_SMOKE_PASSWORD:-}" ]; then
    scenario_fail "stream_failure" "MQTT_SMOKE_USERNAME/MQTT_SMOKE_PASSWORD are required"
  fi
  docker compose exec -T mosquitto sh -lc 'rm -f /tmp/mqtt-scenario.log /tmp/mqtt-sub.pid'
  docker compose exec -T mosquitto sh -lc "mosquitto_sub -h 127.0.0.1 -p 1883 -u '${MQTT_SMOKE_USERNAME:-}' -P '${MQTT_SMOKE_PASSWORD:-}' -v -t '#' > /tmp/mqtt-scenario.log 2>&1 & echo \$! > /tmp/mqtt-sub.pid"
}

stop_mqtt_subscriber() {
  docker compose exec -T mosquitto sh -lc 'test -f /tmp/mqtt-sub.pid && kill "$(cat /tmp/mqtt-sub.pid)" 2>/dev/null || true' >/dev/null 2>&1 || true
}

capture_mqtt_log() {
  docker compose exec -T mosquitto sh -lc 'cat /tmp/mqtt-scenario.log 2>/dev/null || true' > "$MQTT_LOG_FILE" || true
}

capture_artifacts() {
  mkdir -p "$SCENARIO_DIR"
  capture_mqtt_log
  docker compose logs --no-color hub > "$SCENARIO_DIR/hub.log" 2>&1 || true
  docker compose logs --no-color mosquitto > "$SCENARIO_DIR/mosquitto.log" 2>&1 || true
  docker compose exec -T hub sh -lc 'cat /tmp/hub-vivistar-listener.log 2>/dev/null || true' > "$SCENARIO_DIR/device-listener.log" 2>&1 || true
}
