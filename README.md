# Health Smartwatches Platform

Multi-protocol smartwatch ingestion platform (Wonlex + Vivistar) with:

- REST control plane for operations and command ingress
- MQTT data plane for client integrations
- Redis streams for internal decoupling
- MySQL persistence for device/event history

## Architecture Summary

External clients consume normalized data from MQTT topics.  
Commands enter through REST and command state is emitted back through MQTT.

```text
Watches (Wonlex WS / Vivistar TCP)
        |
        v
ws service (protocol adapters + session/auth + routing)
        |
        +--> Redis stream: events --------> worker --------> MySQL (device_events)
        |
        +--> Redis stream: events/status/errors/command_state
                        |
                        v
                 mqtt-publisher ---------> Mosquitto MQTT
                                              |
                                              v
                                 devices/{imei}/telemetry
                                 devices/{imei}/status
                                 devices/{imei}/error
                                 devices/{imei}/command/state

api service (REST control plane)
  - reads from MySQL
  - dispatches commands to ws (direct or via Redis command stream)
```

## Service Catalog

| Service | Purpose | Key Ports |
|---|---|---|
| `ws` | Device ingress server. Handles Wonlex WebSocket and Vivistar TCP, auth/login, protocol decoding, event normalization, command dispatch/replies. | `8080` (WS), `9000` (TCP) |
| `api` | Platform REST control plane: device/model/supplier management, event reads, command submission, health/docs. | `8081` |
| `worker` | Reads Redis `events` stream and persists normalized events to MySQL. | internal |
| `mqtt-publisher` | Reads Redis streams (`events`, `status`, `errors`, `command_state`) and publishes MQTT envelopes. | internal |
| `redis` | Internal event bus and transient runtime state. | `6379` |
| `mysql` | System of record for suppliers/models/devices/device_events. | `3306` |
| `mosquitto` | MQTT broker used by external consumers with ACL/password auth on plain/TLS listeners. | `1883`, `8883` |
| `nginx` | Optional reverse proxy/TLS entrypoint for API/WS. | `80`, `443` |

## Service Deep Dive

### `ws` (device ingress + protocol runtime)

Purpose:

- terminates device connections for Wonlex (`ws://:8080`) and Vivistar (`tcp://:9000`)
- authenticates/authorizes device logins
- decodes vendor payloads into canonical internal events
- dispatches downlink commands and captures replies

Data flow:

1. Device sends login + payload to `ws`.
2. `ws` validates whitelist/model/protocol compatibility.
3. Passive telemetry is pushed to Redis `events`.
4. Online/offline transitions are pushed to Redis `status`.
5. Command dispatch/reply/failure/timeout states are pushed to Redis `command_state`.
6. Runtime/protocol errors are pushed to Redis `errors`.

Examples:

```bash
# Wonlex passive event ingress
docker compose exec ws php simulator/simulate.php \
  --server ws://127.0.0.1:8080 \
  --model WONLEX-HEALTH \
  --imei 865028000000307 \
  --command upBattery
```

```bash
# Vivistar passive event ingress
docker compose exec ws php simulator/simulate.php \
  --server tcp://127.0.0.1:9000 \
  --model VIVISTAR-CARE \
  --imei 865028000000308 \
  --command AP49
```

### `api` (REST control plane)

Purpose:

- device/model/supplier CRUD
- health/docs/openapi endpoints
- command ingress (`POST /devices/{imei}/command`, feature command endpoint)

Data flow:

1. API validates device + capability + online status.
2. API emits command request to `ws` (direct in monolith mode or via Redis `cmd:stream` in split mode).
3. API returns immediate dispatch response with `requestId`.
4. Command lifecycle updates (`dispatched`, `ack`, `failed`, `timeout`) are later emitted on MQTT via Redis streams.

`GET /events/recent` consistency:

- Redis-backed ingress (`ws` connected): API prefers live ingress memory for freshest events, with DB fallback on cold start.
- Direct DB ingest (no Redis): API reads directly from DB as the durable source.

Examples:

```bash
curl -s -X POST http://127.0.0.1:8081/devices/865028000000306/command \
  -H 'Content-Type: application/json' \
  -d '{"type":"dnHeartRate","data":{}}'
```

```bash
curl -s http://127.0.0.1:8081/openapi.json | head -c 200
```

### `redis` (internal event bus + runtime coordination)

Purpose:

- decouple ingestion, persistence, command routing, and MQTT publishing
- hold online device registry and rate-limit counters

Stream roles:

- `events`: normalized passive telemetry
- `status`: online/offline transitions
- `errors`: integration/runtime errors
- `command_state`: command lifecycle transitions
- `cmd:stream`: API-to-WS command queue (split mode)

Examples:

```bash
make redis-cli
```

```bash
docker compose exec redis redis-cli XLEN events
docker compose exec redis redis-cli XLEN command_state
```

### `worker` (stream-to-DB persistence)

Purpose:

- consume `events` from Redis using consumer groups
- persist canonical events into MySQL `device_events`

Data flow:

1. Reads from Redis `events`.
2. Writes durable records to MySQL.
3. Acks consumed stream entries.

Examples:

```bash
docker compose logs -f worker
```

```bash
curl -s "http://127.0.0.1:8081/events/recent?limit=5"
```

### `mqtt-publisher` (external data plane bridge)

Purpose:

- consume Redis streams and publish MQTT topics for external clients
- apply canonical envelope (`schemaVersion`, `eventType`, `eventId`, `occurredAt`, etc.)

Data flow:

1. Reads `events`, `status`, `errors`, `command_state`.
2. Converts to normalized MQTT payloads.
3. Publishes to `devices/{imei}/telemetry|status|error|command/state`.
4. Stores cursors in `var/` for resume safety.

Examples:

```bash
docker compose logs -f mqtt-publisher
```

### `mosquitto` (MQTT broker)

Purpose:

- expose integration topics to external consumers
- enforce auth + ACL on plain (`1883`) and TLS (`8883`) listeners

Default ACL model in this repo:

- `${MQTT_PUBLISHER_USERNAME}`: write-only on platform topics
- `${MQTT_SMOKE_USERNAME}`: read-only on `devices/#`

Examples:

```bash
docker compose exec mosquitto sh -lc \
  "mosquitto_sub -h 127.0.0.1 -p 1883 -u \"$MQTT_SMOKE_USERNAME\" -P \"$MQTT_SMOKE_PASSWORD\" -v -t 'devices/#'"
```

```bash
docker compose restart mosquitto
```

### `mysql` (system of record)

Purpose:

- persist suppliers/models/devices/device events for governance and auditing
- back REST read paths and metadata resolution used in MQTT payload enrichment

Examples:

```bash
docker compose exec mysql sh -lc 'mysql -u"$DB_USER" -p"$DB_PASS" -e "use $DB_NAME; show tables;"'
```

### `nginx` (optional edge/proxy)

Purpose:

- provide unified ingress and optional TLS termination for API/WS
- useful when exposing this stack behind a single endpoint

Examples:

```bash
docker compose logs -f nginx
```

## Integration Contract

Primary MQTT topics:

- `devices/{imei}/telemetry`
- `devices/{imei}/status`
- `devices/{imei}/error`
- `devices/{imei}/command/state`

Policy:

- Command ingress is REST (`POST /devices/{imei}/command` and feature command endpoint).
- MQTT is outbound integration stream (not inbound command queue).

Default broker users:

- `${MQTT_PUBLISHER_USERNAME}` (write only to platform topics)
- `${MQTT_SMOKE_USERNAME}` (read for smoke verification)

## Quick Start

Prerequisites:

- Docker + Docker Compose
- `make`

Boot stack:

```bash
cp .env.example .env
# edit .env and set required secrets:
#   MYSQL_ROOT_PASSWORD, DB_PASS
#   MQTT_PUBLISHER_USERNAME, MQTT_PUBLISHER_PASSWORD
#   MQTT_SMOKE_USERNAME, MQTT_SMOKE_PASSWORD
#   DEMO_API_ENABLED=true  # optional, enables /demo/simulate and /demo/listener*
#   WHITELIST_CACHE_TTL_SECONDS=3  # optional, refresh DB-backed whitelist across processes
make up
make migrate
```

Useful checks:

```bash
make ps
curl -s http://127.0.0.1:8081/health
curl -s http://127.0.0.1:8081/openapi.json | head -c 200
```

## Full Circuit Test

### 1. Automated smoke test (recommended)

Runs end-to-end verification across all four MQTT topic families:

```bash
make smoke-mqtt
```

What it validates:

- telemetry publish
- status publish
- error publish
- command state publish

### 2. Manual full-circuit test (4 terminals)

Terminal A: keep platform logs visible

```bash
make up
docker compose logs -f ws api mqtt-publisher
```

Terminal B: subscribe to MQTT output

```bash
docker compose exec mosquitto sh -lc "mosquitto_sub -h 127.0.0.1 -p 1883 -u \"$MQTT_SMOKE_USERNAME\" -P \"$MQTT_SMOKE_PASSWORD\" -v -t 'devices/#'"
```

Terminal C: keep one device online for command ack

```bash
docker compose exec ws php simulator/simulate.php \
  --server ws://127.0.0.1:8080 \
  --model WONLEX-PRO \
  --imei 865028000000306 \
  --listen
```

Terminal D: trigger all paths

```bash
# telemetry
docker compose exec ws php simulator/simulate.php \
  --server ws://127.0.0.1:8080 \
  --model WONLEX-HEALTH \
  --imei 865028000000307 \
  --command upBattery

# command dispatch (and ack from Terminal C device)
curl -s -X POST http://127.0.0.1:8081/devices/865028000000306/command \
  -H 'Content-Type: application/json' \
  -d '{"type":"dnHeartRate","data":{}}'

# protocol mismatch to force integration error path
docker compose exec ws php simulator/simulate.php \
  --server ws://127.0.0.1:8080 \
  --model VIVISTAR-CARE \
  --imei 865028000000306 \
  --command AP49
```

Expected topics in Terminal B:

- `devices/865028000000307/telemetry`
- `devices/865028000000306/status` (and/or other device status updates)
- `devices/865028000000306/error`
- `devices/865028000000306/command/state`

## REST Surface (Control Plane)

Key endpoints:

- `GET /openapi.json`
- `GET /docs`
- `GET /health`
- `GET /devices`
- `POST /devices/{imei}/command`
- `POST /devices/{imei}/features/{feature}/command`

Demo control endpoints (`/demo/simulate`, `/demo/listener*`) are disabled by default and require `DEMO_API_ENABLED=true`.

## MQTT Credentials

Set credentials in `.env`:

```bash
MQTT_PUBLISHER_USERNAME=...
MQTT_PUBLISHER_PASSWORD=...
MQTT_SMOKE_USERNAME=...
MQTT_SMOKE_PASSWORD=...
```

Then apply:

```bash
docker compose restart mosquitto mqtt-publisher
```

For TLS clients (port `8883`), use:

- CA: `config/ssl/fullchain.pem`
- username/password from your `.env` values

## MQTT Topics & Payload Format

The publisher forwards events to per-device topics under `devices/{imei}/`:

| Topic pattern | Direction | When |
|---|---|---|
| `devices/{imei}/telemetry` | watch → server | Sensor readings (HR, BP, SpO2, temp, location, battery, activity, ...) |
| `devices/{imei}/status` | watch → server | Online/offline state changes |
| `devices/{imei}/error` | watch → server | Integration-level errors |
| `devices/{imei}/command/state` | server → watch | Command lifecycle (dispatched, ack, failed, timeout) |

All topic payloads share a common envelope with three root groups:

| Group | Fields | Purpose |
|---|---|---|
| `event` | `type`, `id` | Message routing and deduplication |
| `device` | `imei`, `model`, `supplier` | Device identity |
| `data` | _topic-specific_ | The actual content |

**Telemetry** — `data` contains vendor-agnostic normalized values:

```json
{
  "event": { "type": "telemetry.received", "id": "evt_1712345678_0" },
  "occurredAt": "2026-05-18T11:51:26Z",
  "device": {
    "imei": "865028000000307",
    "model": "WONLEX-HEALTH",
    "supplier": "Wonlex"
  },
  "data": { "heartRateBpm": 72 }
}
```

Normalized fields available per feature:

| Feature | `data` keys | Example |
|---|---|---|
| `heart_rate` | `heartRateBpm` | `72` |
| `blood_pressure` | `systolicMmHg`, `diastolicMmHg`, `pulseBpm` | `120`, `80` |
| `blood_oxygen` | `spo2Percent` | `98` |
| `temperature` | `bodyTemperatureC` | `36.6` |
| `location` | `latitude`, `longitude`, `altitudeMeters`, `satelliteCount` | `38.7223`, `-9.1393` |
| `battery` | `batteryPercent`, `chargingState`, `batteryType` | `90`, `0`, `2` |
| `activity` | `steps`, `distanceMeters`, `caloriesKcal` | |
| `heartbeat` | `beats`, `intervalMs` | |
| `respiration` | `respirationPerMin` | |
| `sleep` | `durationMinutes`, `deepMinutes`, `lightMinutes`, `awakeMinutes` | |

**Status**:

```json
{
  "event": { "type": "device.status.changed", "id": "evt_..." },
  "device": { "imei": "865028000000307", "model": "WONLEX-HEALTH", "supplier": "Wonlex" },
  "data": { "state": "online", "reason": "login_ok" }
}
```

**Error**:

```json
{
  "event": { "type": "integration.error", "id": "evt_..." },
  "device": { "imei": "865028000000308", "model": "VIVISTAR-CARE", "supplier": "VIVISTAR" },
  "data": { "code": "timeout", "message": "Device did not ack" }
}
```

**Command State**:

```json
{
  "event": { "type": "command.state.changed", "id": "evt_..." },
  "device": { "imei": "865028000000306", "model": "WONLEX-PRO", "supplier": "Wonlex" },
  "data": { "state": "dispatched", "requestId": "req_abc123" }
}
```

Subscribe with the smoke account to watch live data:

```bash
docker compose exec mosquitto sh -lc \
  'mosquitto_sub -h 127.0.0.1 -p 1883 -u "$MQTT_SMOKE_USERNAME" -P "$MQTT_SMOKE_PASSWORD" -v -t "devices/#"'
```

## Production Readiness Notes

Implemented:

- protocol abstraction and multi-ingress support
- stream-decoupled internal architecture
- MQTT topic fanout for telemetry/status/error/command-state
- end-to-end smoke script for regression checks
- MQTT broker auth + ACL enforcement (no anonymous access)
- TLS MQTT listener on `8883`
- command terminal states for `dispatched`, `ack`, `failed`, `timeout`
- CI pipeline workflow (`.github/workflows/mqtt-smoke.yml`) running `make smoke-mqtt`

Still recommended before large-scale production traffic:

- rotate default dev credentials and keep secrets outside git-managed `.env`
- replace self-signed TLS certs with CA-issued cert chain
- add alerting/SLOs around command timeout rates and publish failures
