#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

SCENARIOS=(
  "tests/scenarios/scenario_hub_raw_mqtt_roundtrip.sh"
  "tests/scenarios/scenario_hub_downlink_queue.sh"
  "tests/scenarios/scenario_dashboard_api.sh"
  "tests/scenarios/scenario_ncs_mqtt_ingress.sh"
  "tests/scenarios/scenario_location_beacondb_pipeline.sh"
)
PER_SCENARIO_TIMEOUT_SECONDS="${PER_SCENARIO_TIMEOUT_SECONDS:-240}"

# Deita abaixo a pilha dos cenários no fim, corra ela bem ou mal. Sem isto ficavam quatro
# contentores e meio giga de memória à espera da corrida seguinte. É `down` e não `stop`
# porque um contentor parado continua na lista do Docker, que é metade do incómodo.
#
# O `-v` fica de fora de propósito: o volume `scenario_mysql_data` é o que evita migrar e
# semear a base de dados de raiz a cada corrida.
cleanup() {
  tests/scenarios/cleanup-artifacts.sh
  COMPOSE_PROJECT_NAME=havicare-scenarios \
    COMPOSE_FILE="$ROOT_DIR/docker-compose.yml:$ROOT_DIR/docker-compose.scenarios.yml" \
    docker compose down --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

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
