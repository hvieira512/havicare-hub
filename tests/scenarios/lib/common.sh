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
API_RESPONSE_FILE=""
LISTENER_PID_FILE="/tmp/sim-listener.pid"

scenario_begin() {
  SCENARIO_NAME="$1"
  local run_id
  run_id="$(date +%Y%m%d-%H%M%S)"
  SCENARIO_DIR="$ARTIFACTS_ROOT/$run_id/$SCENARIO_NAME"
  mkdir -p "$SCENARIO_DIR"
  MQTT_LOG_FILE="$SCENARIO_DIR/mqtt.log"
  API_RESPONSE_FILE="$SCENARIO_DIR/api-response.json"
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

start_stack() {
  docker compose up -d mysql redis ws api worker mosquitto mqtt-publisher >/dev/null
}

restart_runtime() {
  docker compose restart ws api worker mqtt-publisher >/dev/null
}

migrate_seed() {
  for _ in $(seq 1 40); do
    if docker compose exec -T api php bin/migrate.php --seed >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  scenario_fail "stream_failure" "database seed migration failed after retries"
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
    scenario_fail "stream_failure" "MQTT_SMOKE_USERNAME/MQTT_SMOKE_PASSWORD are required for scenario runs"
  fi
  docker compose exec -T mosquitto sh -lc 'rm -f /tmp/mqtt-scenario.log /tmp/mqtt-sub.pid'
  docker compose exec -T mosquitto sh -lc "mosquitto_sub -h 127.0.0.1 -p 1883 -u '${MQTT_SMOKE_USERNAME:-}' -P '${MQTT_SMOKE_PASSWORD:-}' -v -t 'devices/#' > /tmp/mqtt-scenario.log 2>&1 & echo \$! > /tmp/mqtt-sub.pid"
}

stop_mqtt_subscriber() {
  docker compose exec -T mosquitto sh -lc 'test -f /tmp/mqtt-sub.pid && kill "$(cat /tmp/mqtt-sub.pid)" 2>/dev/null || true' >/dev/null 2>&1 || true
}

start_wonlex_listener() {
  docker compose exec -T ws sh -lc 'rm -f /tmp/sim-listener.log /tmp/sim-listener.pid'
  docker compose exec -T ws sh -lc "php simulator/simulate.php --server ws://127.0.0.1:8080 --model WONLEX-PRO --imei 865028000000306 --listen > /tmp/sim-listener.log 2>&1 & echo \$! > $LISTENER_PID_FILE"
}

stop_wonlex_listener() {
  docker compose exec -T ws sh -lc "test -f $LISTENER_PID_FILE && kill \"\$(cat $LISTENER_PID_FILE)\" 2>/dev/null || true" >/dev/null 2>&1 || true
}

capture_mqtt_log() {
  docker compose exec -T mosquitto sh -lc 'cat /tmp/mqtt-scenario.log 2>/dev/null || true' > "$MQTT_LOG_FILE" || true
}

assert_topic_present() {
  local topic="$1"
  capture_mqtt_log
  if ! grep -q "^${topic} " "$MQTT_LOG_FILE"; then
    scenario_fail "publish_failure" "missing topic: $topic"
  fi
}

latest_topic_payload_to_file() {
  local topic="$1"
  local out_file="$2"
  capture_mqtt_log
  local line
  line="$(grep "^${topic} " "$MQTT_LOG_FILE" | tail -n 1 || true)"
  if [ -z "$line" ]; then
    scenario_fail "publish_failure" "no payload found for topic: $topic"
  fi
  echo "${line#* }" > "$out_file"
}

assert_json_path_equals() {
  local json_file="$1"
  local path="$2"
  local expected="$3"
  local actual
  actual="$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$path=explode(".",$argv[2]);
$v=$j;
foreach($path as $p){ if(!is_array($v)||!array_key_exists($p,$v)){ echo "__MISSING__"; exit(0);} $v=$v[$p]; }
if(is_bool($v)){ echo $v?"true":"false"; }
elseif($v===null){ echo "null"; }
elseif(is_scalar($v)){ echo (string)$v; }
else{ echo json_encode($v); }
' "$json_file" "$path")"
  if [ "$actual" != "$expected" ]; then
    scenario_fail "contract_failure" "json path mismatch for $path: expected '$expected' got '$actual'"
  fi
}

capture_artifacts() {
  mkdir -p "$SCENARIO_DIR"
  capture_mqtt_log

  docker compose logs --no-color ws > "$SCENARIO_DIR/ws.log" 2>&1 || true
  docker compose logs --no-color api > "$SCENARIO_DIR/api.log" 2>&1 || true
  docker compose logs --no-color worker > "$SCENARIO_DIR/worker.log" 2>&1 || true
  docker compose logs --no-color mqtt-publisher > "$SCENARIO_DIR/mqtt-publisher.log" 2>&1 || true
  docker compose logs --no-color redis > "$SCENARIO_DIR/redis.log" 2>&1 || true
  docker compose logs --no-color mosquitto > "$SCENARIO_DIR/mosquitto.log" 2>&1 || true

  docker compose exec -T redis redis-cli XLEN events > "$SCENARIO_DIR/redis-events-len.txt" 2>&1 || true
  docker compose exec -T redis redis-cli XLEN command_state > "$SCENARIO_DIR/redis-command-state-len.txt" 2>&1 || true
  docker compose exec -T redis redis-cli XRANGE command_state - + COUNT 20 > "$SCENARIO_DIR/redis-command-state-tail.txt" 2>&1 || true

  docker compose exec -T api php -r '
require "vendor/autoload.php";
$c=\App\Config::load()->all();
$db=\App\Database\Database::connect($c["database"])->pdo();
$q=$db->query(
    "SELECT e.imei, e.native_type, f.code AS feature, e.received_at
     FROM device_events e
     LEFT JOIN features f ON f.id = e.feature_id
     ORDER BY e.id DESC LIMIT 20"
);
echo json_encode($q->fetchAll(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;
' > "$SCENARIO_DIR/db-events-tail.json" 2>&1 || true
}

wait_for_api_recent_entry() {
  local timeout_seconds="${1:-30}"
  local native_type="$2"
  local feature="$3"
  local normalized_key="$4"
  local normalized_expected="$5"
  local start
  start="$(date +%s)"
  while true; do
    curl -s "http://127.0.0.1:8081/events/recent?limit=50" > "$API_RESPONSE_FILE" || true
    if php -r '
$j=json_decode(file_get_contents($argv[1]), true);
if (!is_array($j) || !isset($j["data"]) || !is_array($j["data"])) { exit(1); }
$nativeType=$argv[2];
$feature=$argv[3];
$normalizedKey=$argv[4];
$normalizedExpected=$argv[5];
foreach ($j["data"] as $event) {
  if (($event["nativeType"] ?? null) !== $nativeType) { continue; }
  if (($event["feature"] ?? null) !== $feature) { continue; }
  $value = $event["normalized"][$normalizedKey] ?? null;
  if ($value === null) { continue; }
  if ((string)$value === $normalizedExpected) { exit(0); }
}
exit(1);
' "$API_RESPONSE_FILE" "$native_type" "$feature" "$normalized_key" "$normalized_expected"; then
      return 0
    fi
    if [ $(( $(date +%s) - start )) -ge "$timeout_seconds" ]; then
      return 1
    fi
    sleep 1
  done
}

wait_for_db_event() {
  local timeout_seconds="${1:-30}"
  local imei="$2"
  local native_type="$3"
  local feature="$4"
  local start
  start="$(date +%s)"
  while true; do
    if docker compose exec -T api php -r '
require "vendor/autoload.php";
$config=\App\Config::load()->all();
$pdo=\App\Database\Database::connect($config["database"])->pdo();
$stmt=$pdo->prepare(
    "SELECT COUNT(*)
     FROM device_events e
     LEFT JOIN features f ON f.id = e.feature_id
     WHERE e.imei = :imei AND e.native_type = :native_type AND f.code = :feature"
);
$stmt->execute([
    "imei"=>$argv[1],
    "native_type"=>$argv[2],
    "feature"=>$argv[3],
]);
$count=(int)$stmt->fetchColumn();
echo $count, PHP_EOL;
' "$imei" "$native_type" "$feature" | grep -qE '^[1-9][0-9]*$'; then
      return 0
    fi
    if [ $(( $(date +%s) - start )) -ge "$timeout_seconds" ]; then
      return 1
    fi
    sleep 1
  done
}

scenario_cleanup() {
  stop_wonlex_listener
  stop_mqtt_subscriber
}
