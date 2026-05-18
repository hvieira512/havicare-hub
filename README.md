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
| `mosquitto` | MQTT broker used by external consumers. | `1883` |
| `nginx` | Optional reverse proxy/TLS entrypoint for API/WS. | `80`, `443` |

## Integration Contract

Primary MQTT topics:

- `devices/{imei}/telemetry`
- `devices/{imei}/status`
- `devices/{imei}/error`
- `devices/{imei}/command/state`

Policy:

- Command ingress is REST (`POST /devices/{imei}/command` and feature command endpoint).
- MQTT is outbound integration stream (not inbound command queue).

## Quick Start

Prerequisites:

- Docker + Docker Compose
- `make`

Boot stack:

```bash
cp .env.example .env
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
docker compose exec mosquitto sh -lc "mosquitto_sub -h 127.0.0.1 -p 1883 -v -t 'devices/#'"
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

## Production Readiness Notes

Implemented:

- protocol abstraction and multi-ingress support
- stream-decoupled internal architecture
- MQTT topic fanout for telemetry/status/error/command-state
- end-to-end smoke script for regression checks

Still required for hardened production rollout:

- MQTT TLS + per-consumer credentials + ACL automation
- command lifecycle depth completion (`timeout`, durable terminal coverage)
- CI pipeline job to run `make smoke-mqtt` on release candidates
