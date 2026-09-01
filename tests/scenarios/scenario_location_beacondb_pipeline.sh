#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "location_beacondb_pipeline"
trap scenario_cleanup EXIT

export LOCATION_RESOLUTION_ENABLED="true"
export BEACONDB_ENDPOINT="http://127.0.0.1:8099"
export BEACONDB_USER_AGENT="Havicare local hub location enrichment test"
export RADIO_MAP_ENABLED="true"
export RADIO_MAP_HASH_KEY="scenario-private-radio-map-hmac-key"

docker compose up -d --force-recreate --remove-orphans mosquitto hub >/dev/null
wait_for_mosquitto
start_mqtt_subscriber
docker compose exec -T redis sh -lc "redis-cli --scan --pattern 'hub:location:*' | xargs -r redis-cli del >/dev/null"
docker compose exec -T hub php -r 'require "vendor/autoload.php"; $pdo=(new Hub\Infrastructure\Persistence\DashboardDatabase(Hub\Config::load()->all()["database"]))->pdo(); $pdo->exec("DELETE FROM private_radio_map_access_points");'

docker compose exec -T hub sh -lc "rm -f /tmp/beacondb-requests.log /tmp/beacondb-mock.log"
docker compose exec -d hub sh -lc "php -S 127.0.0.1:8099 tests/scenarios/fixtures/beacondb-router.php >/tmp/beacondb-mock.log 2>&1"
docker compose exec -d hub sh -lc "BEACONDB_USER_AGENT='Havicare local location pipeline test' php simulator/location-beacondb-probe.php --host mosquitto --port 1883 --username '$MQTT_SMOKE_USERNAME' --password '$MQTT_SMOKE_PASSWORD' --topic '+/watch/+/telemetry' --count 3 --listen-timeout 15 --endpoint http://127.0.0.1:8099 > /tmp/location-probe.log 2>&1"

for _ in $(seq 1 20); do
  if docker compose exec -T hub sh -lc "grep -q 'Listening on mqtt://' /tmp/location-probe.log 2>/dev/null"; then
    break
  fi
  sleep 1
done

WONLEX='{"type":"location","device":{"id":"wonlex-local","supplier":"Wonlex"},"data":{"source":"wifi","hasCoordinates":false,"gpsValid":false,"wifiAccessPoints":[{"ssid":"Office","mac":"bc:5f:f6:1e:07:be","signalStrengthDbm":-55},{"ssid":"Lobby","mac":"c4:b8:b5:c4:14:79","signalStrengthDbm":-53}]},"source":{"protocol":"wonlex-json","nativeType":"upLocation"}}'
VIVISTAR='{"type":"location","device":{"id":"vivistar-local","supplier":"Vivistar"},"data":{"source":"cell_wifi","hasCoordinates":false,"gpsValid":false,"wifiAccessPoints":[{"ssid":"Clinic","mac":"74:de:2b:44:88:8c","signalStrengthDbm":-53},{"ssid":"Lobby","mac":"c4:b8:b5:c4:14:79","signalStrengthDbm":-46}]},"source":{"protocol":"vivistar-iw","nativeType":"AP02"}}'
FOUR_P='{"type":"location","device":{"id":"four-p-local","supplier":"4P Touch"},"data":{"source":"cell","hasCoordinates":false,"gpsValid":false,"radioType":"lte","mcc":"268","mnc":"1","baseStations":[{"mcc":"268","mnc":"1","lac":"13011","cellId":"23152151","radioType":"lte","signalStrengthDbm":-100}]},"source":{"protocol":"four-p-touch","nativeType":"UD_LTE"}}'

docker compose exec -T mosquitto mosquitto_pub -h 127.0.0.1 -u "$MQTT_PUBLISHER_USERNAME" -P "$MQTT_PUBLISHER_PASSWORD" -t null/watch/wonlex/telemetry -m "$WONLEX"
docker compose exec -T mosquitto mosquitto_pub -h 127.0.0.1 -u "$MQTT_PUBLISHER_USERNAME" -P "$MQTT_PUBLISHER_PASSWORD" -t null/watch/vivistar/telemetry -m "$VIVISTAR"
docker compose exec -T mosquitto mosquitto_pub -h 127.0.0.1 -u "$MQTT_PUBLISHER_USERNAME" -P "$MQTT_PUBLISHER_PASSWORD" -t null/watch/four-p/telemetry -m "$FOUR_P"

for _ in $(seq 1 20); do
  if [ "$(docker compose exec -T hub sh -lc "grep -c '^RESOLVED:' /tmp/location-probe.log 2>/dev/null || true" | tr -d '\r')" = "3" ]; then
    break
  fi
  sleep 1
done

docker compose exec -T hub sh -lc "cat /tmp/location-probe.log 2>/dev/null || true" > "$SCENARIO_DIR/location-probe.log"
if [ "$(grep -c '^RESOLVED:' "$SCENARIO_DIR/location-probe.log" || true)" != "3" ]; then
  scenario_fail "resolution_failure" "expected three MQTT location messages to resolve through the HTTP endpoint"
fi
if ! grep -q '"considerIp": false' "$SCENARIO_DIR/location-probe.log"; then
  scenario_fail "contract_failure" "BeaconDB request did not explicitly disable IP positioning"
fi

# Um fixo de GPS de confiança acompanhado de Wi-Fi ensina o mapa de rádio privado.
docker compose exec -T hub php -r '
require "vendor/autoload.php";
$adapter = new Hub\Protocol\Adapter\WonlexAdapter();
$socket = fsockopen("127.0.0.1", 9000, $errno, $error, 3);
if (!$socket) { fwrite(STDERR, "$error\n"); exit(1); }
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "login",
    "imei" => "868705080304962",
    "data" => ["deviceModel" => "HW20PRO"],
]));
usleep(200000);
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "upLocation",
    "imei" => "868705080304962",
    "data" => [
        "baseStationType" => 0,
        "positionDataType" => 1,
        "baseStation" => [["mcc" => 268, "mnc" => 3, "lac" => 180, "cellId" => 194809015]],
        "Wifi" => [
            ["ssid" => "Office", "mac" => "bc:5f:f6:1e:07:be", "signal" => -55],
            ["ssid" => "Lobby", "mac" => "c4:b8:b5:c4:14:79", "signal" => -53],
        ],
    ],
]));
usleep(1000000);
fclose($socket);
'

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep -q '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" && grep -q '"lat":41.706841' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
docker compose exec -T hub sh -lc "cat /tmp/beacondb-requests.log 2>/dev/null || true" > "$SCENARIO_DIR/beacondb-requests.log"
if ! grep -q '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "hub did not publish the resolved Wonlex location"
fi
LOCATION_JSON="$(grep '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" | tail -n 1 | cut -d' ' -f2-)"
if ! printf '%s' "$LOCATION_JSON" | jq -e '.data.source == "cell_wifi" and .data.hasCoordinates == true and .data.lat == 41.706841 and .data.lon == -8.793279 and .data.accuracyMeters == 120' >/dev/null; then
  scenario_fail "contract_failure" "resolved coordinates were not merged inside telemetry.data"
fi
if printf '%s' "$LOCATION_JSON" | jq -e 'has("coordinates")' >/dev/null; then
  scenario_fail "contract_failure" "hub introduced a second coordinates envelope"
fi
if ! grep -q '"cellTowers"' "$SCENARIO_DIR/beacondb-requests.log" || ! grep -q '"wifiAccessPoints"' "$SCENARIO_DIR/beacondb-requests.log"; then
  scenario_fail "resolution_failure" "hub did not send normalized cell and Wi-Fi evidence to BeaconDB"
fi

docker compose exec -T hub php -r '
require "vendor/autoload.php";
$adapter = new Hub\Protocol\Adapter\WonlexAdapter();
$socket = fsockopen("127.0.0.1", 9000, $errno, $error, 3);
if (!$socket) { fwrite(STDERR, "$error\n"); exit(1); }
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "login", "imei" => "868705080304962", "data" => ["deviceModel" => "HW20PRO"],
]));
usleep(200000);
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "upLocation", "imei" => "868705080304962",
    "data" => [
        "gpsValid" => true, "lat" => 41.710001, "lon" => -8.790002,
        "accuracy" => 20, "satellites" => 7,
        "baseStationType" => 0, "positionDataType" => 1,
        "baseStation" => [["mcc" => 268, "mnc" => 3, "lac" => 180, "cellId" => 194809015]],
        "Wifi" => [
            ["ssid" => "Fallback One", "mac" => "10:11:12:13:14:15", "signal" => -55],
            ["ssid" => "Fallback Two", "mac" => "20:21:22:23:24:25", "signal" => -53],
        ],
    ],
]));
usleep(1000000);
fclose($socket);
'

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" | grep -q '"lat":41.710001'; then
    break
  fi
  sleep 1
done
GPS_JSON="$(grep '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" | grep '"lat":41.710001' | tail -n 1 | cut -d' ' -f2-)"
if ! printf '%s' "$GPS_JSON" | jq -e '.data.source == "gps" and .data.hasCoordinates == true and .data.accuracyMeters == 20' >/dev/null; then
  scenario_fail "radio_map_learning_failure" "trusted GPS fix was not published unchanged"
fi

RADIO_ROWS="$(docker compose exec -T hub php -r 'require "vendor/autoload.php"; $pdo=(new Hub\Infrastructure\Persistence\DashboardDatabase(Hub\Config::load()->all()["database"]))->pdo(); echo $pdo->query("SELECT COUNT(*) FROM private_radio_map_access_points")->fetchColumn();' | tr -d '[:space:]')"
if [ "$RADIO_ROWS" != "2" ]; then
  scenario_fail "radio_map_learning_failure" "trusted GPS fix did not persist two hashed access points"
fi
REQUEST_COUNT_BEFORE_PRIVATE="$(docker compose exec -T hub sh -lc "wc -l < /tmp/beacondb-requests.log" | tr -d '[:space:]')"

# O relatório seguinte não traz GPS. Tem de resolver localmente, antes do fornecedor público
# e antes da cache de resolução dele.
docker compose exec -T hub php -r '
require "vendor/autoload.php";
$adapter = new Hub\Protocol\Adapter\WonlexAdapter();
$socket = fsockopen("127.0.0.1", 9000, $errno, $error, 3);
if (!$socket) { fwrite(STDERR, "$error\n"); exit(1); }
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "login", "imei" => "868705080304962", "data" => ["deviceModel" => "HW20PRO"],
]));
usleep(200000);
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "upLocation", "imei" => "868705080304962",
    "data" => [
        "baseStationType" => 0, "positionDataType" => 1,
        "baseStation" => [["mcc" => 268, "mnc" => 3, "lac" => 180, "cellId" => 194809015]],
        "Wifi" => [
            ["ssid" => "Fallback One", "mac" => "10:11:12:13:14:15", "signal" => -55],
            ["ssid" => "Fallback Two", "mac" => "20:21:22:23:24:25", "signal" => -53],
        ],
    ],
]));
usleep(1000000);
fclose($socket);
'

for _ in $(seq 1 20); do
  capture_mqtt_log
  if grep '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" | grep -q '"accuracyMeters":25'; then
    break
  fi
  sleep 1
done
PRIVATE_JSON="$(grep '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" | grep '"accuracyMeters":25' | tail -n 1 | cut -d' ' -f2-)"
if ! printf '%s' "$PRIVATE_JSON" | jq -e '.data.source == "cell_wifi" and .data.hasCoordinates == true and .data.lat == 41.710001 and .data.lon == -8.790002 and .data.accuracyMeters == 25' >/dev/null; then
  scenario_fail "radio_map_resolution_failure" "private radio map did not resolve non-GPS evidence"
fi
REQUEST_COUNT_AFTER_PRIVATE="$(docker compose exec -T hub sh -lc "wc -l < /tmp/beacondb-requests.log" | tr -d '[:space:]')"
if [ "$REQUEST_COUNT_AFTER_PRIVATE" != "$REQUEST_COUNT_BEFORE_PRIVATE" ]; then
  scenario_fail "radio_map_priority_failure" "hub contacted BeaconDB despite a trusted private radio-map match"
fi

CACHE_KEYS="$(docker compose exec -T redis redis-cli --scan --pattern 'hub:location:resolution:*' | tr -d '\r')"
if [ -z "$CACHE_KEYS" ]; then
  scenario_fail "cache_failure" "hub did not persist the successful radio evidence resolution in Redis"
fi
REQUEST_COUNT_BEFORE="$(docker compose exec -T hub sh -lc "wc -l < /tmp/beacondb-requests.log" | tr -d '[:space:]')"

# Reiniciar o hub também pára o fornecedor simulado. A mesma evidência tem de continuar a
# resolver a partir do Redis, sem pedido nenhum ao fornecedor: é a prova de que a cache
# persiste.
docker compose restart hub >/dev/null
for _ in $(seq 1 20); do
  if docker compose exec -T hub php -r '$s=@fsockopen("127.0.0.1",9000,$e,$m,1); if ($s) { fclose($s); exit(0); } exit(1);'; then
    break
  fi
  sleep 1
done

docker compose exec -T hub php -r '
require "vendor/autoload.php";
$adapter = new Hub\Protocol\Adapter\WonlexAdapter();
$socket = fsockopen("127.0.0.1", 9000, $errno, $error, 3);
if (!$socket) { fwrite(STDERR, "$error\n"); exit(1); }
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "login", "imei" => "868705080304962", "data" => ["deviceModel" => "HW20PRO"],
]));
usleep(200000);
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "upLocation", "imei" => "868705080304962",
    "data" => [
        "baseStationType" => 0, "positionDataType" => 1,
        "baseStation" => [["mcc" => 268, "mnc" => 3, "lac" => 180, "cellId" => 194809015]],
        "Wifi" => [
            ["ssid" => "Office", "mac" => "bc:5f:f6:1e:07:be", "signal" => -55],
            ["ssid" => "Lobby", "mac" => "c4:b8:b5:c4:14:79", "signal" => -53],
        ],
    ],
]));
usleep(1000000);
fclose($socket);
'

for _ in $(seq 1 20); do
  capture_mqtt_log
  if [ "$(grep -c '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" || true)" -ge 2 ]; then
    break
  fi
  sleep 1
done
REQUEST_COUNT_AFTER="$(docker compose exec -T hub sh -lc "wc -l < /tmp/beacondb-requests.log" | tr -d '[:space:]')"
if [ "$(grep -c '^havicare/1/watch/868705080304962/telemetry ' "$MQTT_LOG_FILE" || true)" -lt 2 ]; then
  scenario_fail "cache_failure" "hub restart did not resolve repeated evidence from Redis"
fi
if [ "$REQUEST_COUNT_AFTER" != "$REQUEST_COUNT_BEFORE" ]; then
  scenario_fail "cache_failure" "hub contacted the provider for radio evidence already cached in Redis"
fi

# O mapa privado é estado durável em MySQL, e não uma cache efémera no Redis.
docker compose exec -T hub php -r '
require "vendor/autoload.php";
$adapter = new Hub\Protocol\Adapter\WonlexAdapter();
$socket = fsockopen("127.0.0.1", 9000, $errno, $error, 3);
if (!$socket) { fwrite(STDERR, "$error\n"); exit(1); }
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "login", "imei" => "868705080304962", "data" => ["deviceModel" => "HW20PRO"],
]));
usleep(200000);
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "upLocation", "imei" => "868705080304962",
    "data" => [
        "baseStationType" => 0, "positionDataType" => 1,
        "Wifi" => [
            ["ssid" => "Fallback One", "mac" => "10:11:12:13:14:15", "signal" => -55],
            ["ssid" => "Fallback Two", "mac" => "20:21:22:23:24:25", "signal" => -53],
        ],
    ],
]));
usleep(1000000);
fclose($socket);
'
for _ in $(seq 1 20); do
  capture_mqtt_log
  if [ "$(grep -c '"accuracyMeters":25' "$MQTT_LOG_FILE" || true)" -ge 2 ]; then
    break
  fi
  sleep 1
done
if [ "$(grep -c '"accuracyMeters":25' "$MQTT_LOG_FILE" || true)" -lt 2 ]; then
  scenario_fail "radio_map_persistence_failure" "private radio map did not survive the hub restart"
fi
REQUEST_COUNT_AFTER_RADIO_RESTART="$(docker compose exec -T hub sh -lc "wc -l < /tmp/beacondb-requests.log" | tr -d '[:space:]')"
if [ "$REQUEST_COUNT_AFTER_RADIO_RESTART" != "$REQUEST_COUNT_AFTER" ]; then
  scenario_fail "radio_map_priority_failure" "restarted hub contacted BeaconDB for private radio-map evidence"
fi

scenario_pass
