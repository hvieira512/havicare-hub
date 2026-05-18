#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ARTIFACT_DIR="${1:-$ROOT_DIR/tests/artifacts/replay}"
mkdir -p "$ARTIFACT_DIR"
REPORT_FILE="$ARTIFACT_DIR/replay-report.ndjson"
: > "$REPORT_FILE"

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
    echo "[replay][FAIL][contract_failure] $path expected=$expected actual=$actual"
    return 1
  fi
}

for fixture in "$ROOT_DIR"/tests/fixtures/replay/*.json; do
  name="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo $f["name"] ?? "unnamed";' "$fixture")"
  server="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo $f["ingress"]["server"];' "$fixture")"
  model="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo $f["ingress"]["model"];' "$fixture")"
  imei="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo $f["ingress"]["imei"];' "$fixture")"
  command="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo $f["ingress"]["command"];' "$fixture")"
  topic="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo $f["expect"]["topic"];' "$fixture")"

  echo "[replay] running fixture: $name"
  docker compose exec -T ws php simulator/simulate.php \
    --server "$server" \
    --model "$model" \
    --imei "$imei" \
    --command "$command" >/dev/null

  sleep 4

  log_file="$ARTIFACT_DIR/${name}-mqtt.log"
  payload_file="$ARTIFACT_DIR/${name}-payload.json"

  docker compose exec -T mosquitto sh -lc 'cat /tmp/mqtt-scenario.log 2>/dev/null || true' > "$log_file"
  line="$(grep "^${topic} " "$log_file" | tail -n 1 || true)"
  if [ -z "$line" ]; then
    echo "[replay][FAIL][publish_failure] missing topic for fixture $name: $topic"
    exit 1
  fi
  echo "${line#* }" > "$payload_file"

  while IFS=$'\t' read -r path expected; do
    [ -z "$path" ] && continue
    assert_json_path_equals "$payload_file" "$path" "$expected"
  done < <(php -r '
$f=json_decode(file_get_contents($argv[1]),true);
foreach(($f["expect"]["json"] ?? []) as $k=>$v){ echo $k, "\t", (string)$v, "\n"; }
' "$fixture")

  php -r '
$fixture=json_decode(file_get_contents($argv[1]),true);
$payload=json_decode(file_get_contents($argv[2]),true);
echo json_encode([
  "fixture" => $fixture["name"] ?? "unknown",
  "topic" => $fixture["expect"]["topic"] ?? "",
  "result" => "pass",
  "observedEventType" => $payload["event"]["type"] ?? null,
  "observedDevice" => $payload["device"]["imei"] ?? null,
], JSON_UNESCAPED_UNICODE), PHP_EOL;
' "$fixture" "$payload_file" >> "$REPORT_FILE"

done

echo "[replay] all fixtures passed"
