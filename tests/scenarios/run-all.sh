#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

SCENARIOS=(
  "tests/scenarios/scenario_s1_vivistar_telemetry.sh"
  "tests/scenarios/scenario_s2_wonlex_command_roundtrip.sh"
  "tests/scenarios/scenario_s3_status_and_error.sh"
  "tests/scenarios/scenario_s4_redis_failure.sh"
  "tests/scenarios/scenario_s5_replay_fixtures.sh"
)
PER_SCENARIO_TIMEOUT_SECONDS="${PER_SCENARIO_TIMEOUT_SECONDS:-420}"

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
    if ! "$timeout_bin" "${PER_SCENARIO_TIMEOUT_SECONDS}" "$scenario"; then
      echo "[scenarios] FAIL: $scenario"
      exit 1
    fi
  else
    echo "[scenarios] warning: no timeout binary found; running without per-scenario timeout"
    if ! "$scenario"; then
      echo "[scenarios] FAIL: $scenario"
      exit 1
    fi
  fi
  echo "[scenarios] OK: $scenario"
done

echo

echo "[scenarios] all scenarios passed"
