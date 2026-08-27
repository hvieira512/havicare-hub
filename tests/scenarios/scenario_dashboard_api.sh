#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "dashboard_api"
trap scenario_cleanup EXIT

export DASHBOARD_API_AUTH_REQUIRED="true"

# Um dispositivo do inventário semeado por `bin/seed-inventory.php`, para o cenário não
# depender de nenhum registo feito à mão.
IMEI="868705080304889"

docker compose up -d --force-recreate --remove-orphans mosquitto redis hub >/dev/null

wait_for_mosquitto

for _ in $(seq 1 40); do
  if docker compose exec -T hub php -r '$s=@fsockopen("127.0.0.1", 8081, $e, $m, 1); if ($s) { fclose($s); exit(0); } exit(1);' >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if ! docker compose exec -T hub php -r '$s=@fsockopen("127.0.0.1", 8081, $e, $m, 1); if ($s) { fclose($s); exit(0); } exit(1);' >/dev/null 2>&1; then
  scenario_fail "dashboard_failure" "dashboard HTTP listener did not become ready"
fi

docker compose exec -T hub php -r '
require "vendor/autoload.php";
Hub\Bootstrap::loadEnv(getcwd());
$config = Hub\Config::load()->all();
$db = Hub\Api\Repository\ApiDataAccess::fromDatabase(
    new Hub\Infrastructure\Persistence\DashboardDatabase($config["database"])
);
$existing = $db->apiUsers->findByUsername("admin");
$hash = password_hash("secret", PASSWORD_DEFAULT);
if (is_array($existing)) {
    $db->apiUsers->update((int)$existing["id"], "admin", "hub_admin", 0, true, $hash);
} else {
    $db->apiUsers->create("admin", $hash, "hub_admin", 0, true);
}
'

unauth_status="$(curl -s -o /tmp/dashboard-unauth.txt -w '%{http_code}' http://127.0.0.1:8081/api/devices)"
if [ "$unauth_status" != "401" ]; then
  scenario_fail "auth_failure" "dashboard API did not require bearer auth"
fi

login_response="$(curl -s -H 'Content-Type: application/json' -d '{"username":"admin","password":"secret"}' http://127.0.0.1:8081/api/auth/login)"
printf '%s' "$login_response" > "$SCENARIO_DIR/dashboard-login.json"
api_token="$(printf '%s' "$login_response" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (string)($j["token"]["access_token"] ?? "");')"
if [ -z "$api_token" ]; then
  scenario_fail "auth_failure" "dashboard API login did not issue bearer token"
fi

html="$(curl -s http://127.0.0.1:8081/dashboard)"
if ! printf '%s' "$html" | grep -q 'Hitecosystem Hub de Dispositivos'; then
  scenario_fail "dashboard_failure" "dashboard HTML did not render expected page"
fi

devices="$(curl -s -H "Authorization: Bearer $api_token" "http://127.0.0.1:8081/api/devices?limit=100&page=1")"
printf '%s' "$devices" > "$SCENARIO_DIR/dashboard-devices.json"
if ! printf '%s' "$devices" | grep -q '"data"'; then
  scenario_fail "dashboard_failure" "devices collection did not return data wrapper"
fi
if ! printf '%s' "$devices" | grep -q "$IMEI"; then
  scenario_fail "dashboard_failure" "devices collection did not include whitelist device"
fi

command_response="$(curl -s -H "Authorization: Bearer $api_token" -H 'Content-Type: application/json' -d '{"feature":"blood_oxygen"}' "http://127.0.0.1:8081/api/devices/$IMEI/requests")"
printf '%s' "$command_response" > "$SCENARIO_DIR/dashboard-command.json"
if ! printf '%s' "$command_response" | grep -q '"status":"queued"'; then
  scenario_fail "command_failure" "offline dashboard command was not queued"
fi
if ! printf '%s' "$command_response" | grep -q '"feature":"blood_oxygen"'; then
  scenario_fail "command_failure" "generic telemetry request did not echo requested feature"
fi

device="$(curl -s -H "Authorization: Bearer $api_token" "http://127.0.0.1:8081/api/devices/$IMEI")"
printf '%s' "$device" > "$SCENARIO_DIR/dashboard-device.json"
if ! printf '%s' "$device" | grep -q '"configurationSync"'; then
  scenario_fail "dashboard_failure" "device detail did not include configurationSync"
fi
if ! printf '%s' "$device" | grep -q '"blood_oxygen"'; then
  scenario_fail "dashboard_failure" "device detail did not include queued generic request state"
fi

scenario_pass
