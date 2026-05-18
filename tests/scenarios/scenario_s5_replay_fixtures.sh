#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "s5_replay_fixtures"
trap scenario_cleanup EXIT

start_stack
restart_runtime
migrate_seed
wait_for_mosquitto
start_mqtt_subscriber

"$(cd "$(dirname "$0")" && pwd)/replay-fixtures.sh" "$SCENARIO_DIR"

scenario_pass
