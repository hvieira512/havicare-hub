#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

SCENARIOS=(
  "tests/scenarios/scenario_hub_raw_mqtt_roundtrip.sh"
  "tests/scenarios/scenario_hub_downlink_queue.sh"
  "tests/scenarios/scenario_hub_persistent_mqtt_downlink.sh"
)
PER_SCENARIO_TIMEOUT_SECONDS="${PER_SCENARIO_TIMEOUT_SECONDS:-240}"

resolve_timeout_bin() {
  if command -v timeout >/dev/null 2>&1; then
    echo "timeout"
    return
  fi
  if command -v gtimeout >/dev/null 2>&1; then
    echo "gtimeout"
    return
  fi
  echo ""
}

if [ "${1:-}" != "" ]; then
  SCENARIOS=("$1")
fi

echo "[scenarios] running ${#SCENARIOS[@]} scenario(s)"

for scenario in "${SCENARIOS[@]}"; do
  echo
  echo "[scenarios] >>> $scenario"
  timeout_bin="$(resolve_timeout_bin)"
  if [ -n "$timeout_bin" ]; then
    "$timeout_bin" "${PER_SCENARIO_TIMEOUT_SECONDS}" "$scenario"
  else
    "$scenario"
  fi
  echo "[scenarios] OK: $scenario"
done

echo
echo "[scenarios] all scenarios passed"
