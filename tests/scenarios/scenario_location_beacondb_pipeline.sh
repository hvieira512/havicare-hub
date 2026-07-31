#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "$0")" && pwd)/lib/common.sh"

scenario_begin "location_beacondb_pipeline"
trap scenario_cleanup EXIT

export MQTT_PUBLISHER_USERNAME="${MQTT_PUBLISHER_USERNAME:-hub_publisher}"
export MQTT_PUBLISHER_PASSWORD="${MQTT_PUBLISHER_PASSWORD:-hub_publisher_pass}"
export MQTT_SMOKE_USERNAME="${MQTT_SMOKE_USERNAME:-hub_smoke}"
export MQTT_SMOKE_PASSWORD="${MQTT_SMOKE_PASSWORD:-hub_smoke_pass}"
export MQTT_HOST="mosquitto"
export MQTT_PORT="1883"
export MQTT_USERNAME="$MQTT_PUBLISHER_USERNAME"
export MQTT_PASSWORD="$MQTT_PUBLISHER_PASSWORD"
export MQTT_TOPIC_PREFIX=""
export WHITELIST_FILE="config/whitelist.example.json"
export LOCATION_RESOLUTION_ENABLED="true"
export BEACONDB_ENDPOINT="http://127.0.0.1:8099"
export BEACONDB_USER_AGENT="HaviCare local hub location enrichment test"

docker compose up -d --force-recreate --remove-orphans mosquitto hub >/dev/null
wait_for_mosquitto
start_mqtt_subscriber

docker compose exec -T hub sh -lc "rm -f /tmp/beacondb-requests.log /tmp/beacondb-mock.log"
docker compose exec -d hub sh -lc "php -S 127.0.0.1:8099 tests/scenarios/fixtures/beacondb-router.php >/tmp/beacondb-mock.log 2>&1"
docker compose exec -d hub sh -lc "BEACONDB_USER_AGENT='HaviCare local location pipeline test' php simulator/location-beacondb-probe.php --host mosquitto --port 1883 --username '$MQTT_SMOKE_USERNAME' --password '$MQTT_SMOKE_PASSWORD' --topic '+/watch/+/telemetry' --count 3 --listen-timeout 15 --endpoint http://127.0.0.1:8099 > /tmp/location-probe.log 2>&1"

for _ in $(seq 1 20); do
  if docker compose exec -T hub sh -lc "grep -q 'Listening on mqtt://' /tmp/location-probe.log 2>/dev/null"; then
    break
  fi
  sleep 1
done

WONLEX='{"schemaVersion":2,"type":"location","device":{"id":"wonlex-local","supplier":"Wonlex"},"data":{"source":"wifi","hasCoordinates":false,"gpsValid":false,"wifiAccessPoints":[{"ssid":"Office","mac":"bc:5f:f6:1e:07:be","signalStrengthDbm":-55},{"ssid":"Lobby","mac":"c4:b8:b5:c4:14:79","signalStrengthDbm":-53}]},"source":{"protocol":"wonlex-json","nativeType":"upLocation"}}'
VIVISTAR='{"schemaVersion":2,"type":"location","device":{"id":"vivistar-local","supplier":"Vivistar"},"data":{"source":"cell_wifi","hasCoordinates":false,"gpsValid":false,"wifiAccessPoints":[{"ssid":"Clinic","mac":"74:de:2b:44:88:8c","signalStrengthDbm":-53},{"ssid":"Lobby","mac":"c4:b8:b5:c4:14:79","signalStrengthDbm":-46}]},"source":{"protocol":"vivistar-iw","nativeType":"AP02"}}'
FOUR_P='{"schemaVersion":2,"type":"location","device":{"id":"four-p-local","supplier":"4P Touch"},"data":{"source":"cell","hasCoordinates":false,"gpsValid":false,"radioType":"lte","mcc":"268","mnc":"1","baseStations":[{"mcc":"268","mnc":"1","lac":"13011","cellId":"23152151","radioType":"lte","signalStrengthDbm":-100}]},"source":{"protocol":"four-p-touch","nativeType":"UD_LTE"}}'

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

docker compose exec -T hub php -r '
require "vendor/autoload.php";
$adapter = new Hub\Protocol\Adapter\WonlexAdapter();
$socket = fsockopen("127.0.0.1", 9000, $errno, $error, 3);
if (!$socket) { fwrite(STDERR, "$error\n"); exit(1); }
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "login",
    "imei" => "868705080300697",
    "data" => ["deviceModel" => "HW20PRO"],
]));
usleep(200000);
fwrite($socket, $adapter->encodeOutgoing([
    "type" => "upLocation",
    "imei" => "868705080300697",
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
  if grep -q '^null/0/watch/868705080300697/telemetry ' "$MQTT_LOG_FILE" && grep -q '"lat":41.706841' "$MQTT_LOG_FILE"; then
    break
  fi
  sleep 1
done

capture_mqtt_log
docker compose exec -T hub sh -lc "cat /tmp/beacondb-requests.log 2>/dev/null || true" > "$SCENARIO_DIR/beacondb-requests.log"
if ! grep -q '^null/0/watch/868705080300697/telemetry ' "$MQTT_LOG_FILE"; then
  scenario_fail "publish_failure" "hub did not publish the resolved Wonlex location"
fi
LOCATION_JSON="$(grep '^null/0/watch/868705080300697/telemetry ' "$MQTT_LOG_FILE" | tail -n 1 | cut -d' ' -f2-)"
if ! printf '%s' "$LOCATION_JSON" | jq -e '.data.source == "cell_wifi" and .data.hasCoordinates == true and .data.lat == 41.706841 and .data.lon == -8.793279 and .data.accuracyMeters == 120' >/dev/null; then
  scenario_fail "contract_failure" "resolved coordinates were not merged inside telemetry.data"
fi
if printf '%s' "$LOCATION_JSON" | jq -e 'has("coordinates")' >/dev/null; then
  scenario_fail "contract_failure" "hub introduced a second coordinates envelope"
fi
if ! grep -q '"cellTowers"' "$SCENARIO_DIR/beacondb-requests.log" || ! grep -q '"wifiAccessPoints"' "$SCENARIO_DIR/beacondb-requests.log"; then
  scenario_fail "resolution_failure" "hub did not send normalized cell and Wi-Fi evidence to BeaconDB"
fi

scenario_pass
