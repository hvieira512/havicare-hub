# Platform Overview

This project ingests smartwatch protocol traffic (Wonlex + Vivistar), normalizes events, and exposes platform integrations.

Integration references:

- `docs/MQTT-CONTRACT.md`
- `docs/CLIENT-ONBOARDING.md`
- `docs/INTEGRATION-CURRENT-ENDPOINTS.md`
- `docs/MQTT-ROADMAP.md`
- `docs/E2E-TESTING.md`

## Integration Direction

Primary direction:

- Client systems consume data from the platform MQTT broker.
- Clients are free to build their own APIs/products on top of MQTT data.
- Platform does not require creating a dedicated REST API per client.

Current reality in this repository:

- External MQTT publishing is available via a bridge worker (`bin/mqtt-publisher.php`) for telemetry, status, error, and command-state topics.
- Redis Streams are used internally for decoupling (`events`, `cmd:stream`).
- A platform REST API exists and can be used now for operations and command dispatch while MQTT is phased in.
- Command ingress is REST control-plane; MQTT carries outbound command state and device/event streams.

Canonical MQTT topics (target contract):

- `devices/{imei}/telemetry`
- `devices/{imei}/status`
- `devices/{imei}/error`
- `devices/{imei}/command/state`

## Runtime Architecture (Current)

```text
Smartwatch (Wonlex / Vivistar)
        |
        v
Protocol Adapters (internal)
        |
        v
Watch Server (WS + Vivistar TCP ingress)
        |
        +--> Redis stream: events ------> Worker ------> MySQL device_events
        |
        +--> Redis stream: events ------> MQTT Publisher ------> MQTT broker topic devices/{imei}/telemetry
        |
        +--> Latest in-memory state

Platform API (REST)
        |
        +--> Reads: devices/models/suppliers/events
        +--> Commands: direct (monolith) or via Redis cmd:stream (split mode)
```

## What Is Implemented Today

- WS ingestion server for Wonlex traffic.
- Native Vivistar TCP ingress (`tcp://...`, IW/AP/BP).
- Adapter layer for protocol-specific encode/decode.
- REST API (`/openapi.json`, `/docs`) for platform operations and command entry.
- Redis streams for internal decoupling.
- MySQL persistence for suppliers/models/devices/events.
- Worker process (`bin/worker.php`) for stream-to-DB persistence.
- MQTT bridge worker (`bin/mqtt-publisher.php`) for telemetry publish to `devices/{imei}/telemetry`.
- MQTT status publishing to `devices/{imei}/status` from device online/offline transitions.
- MQTT error publishing to `devices/{imei}/error` from integration/runtime failures.
- MQTT command state publishing to `devices/{imei}/command/state` (`dispatched`, `failed`, `ack`).
- Local Mosquitto broker service in Docker Compose.

## What Is Not Implemented Yet

- MQTT auth/ACL automation for integration consumers.
- Durable/full command lifecycle state machine (`requested -> accepted -> dispatched -> ack|timeout|failed`).

## Local Stack

`docker-compose.yml` currently provides:

- `mysql`
- `redis`
- `ws`
- `api`
- `worker`
- `mosquitto`
- `mqtt-publisher`
- `nginx`

Default ports:

- Wonlex/WebSocket ingress: `8080`
- Vivistar TCP ingress: `9000`
- HTTP API: `8081` (direct) and `80/443` via nginx
- MQTT broker: `1883`

Smoke validation:

```bash
make smoke-mqtt
```

## Simulator

`simulator/simulate.php` auto-selects protocol by model from `config/capabilities.json`.

Examples:

```bash
# Wonlex over WebSocket
php simulator/simulate.php --server ws://127.0.0.1:8080 \
  --model WONLEX-PRO --imei 865028000000306 \
  --command upHeartRate --data '{"heartRate":72}'

# Vivistar over TCP
php simulator/simulate.php --server tcp://127.0.0.1:9000 \
  --model VIVISTAR-CARE --imei 865028000000308 \
  --command AP49 --data '{"heartRate":68}'
```

## Decision Framework: MQTT-only vs REST + MQTT

If you want maximum freedom for clients:

- Publish canonical events on MQTT.
- Keep client responsibilities outside this repo (they consume and shape data however they want).

If you want easy command/governance operations during rollout:

- Keep existing REST API as platform control plane.
- Add MQTT as the main external data plane.

This project currently supports the second path immediately, and can evolve toward MQTT-first consumption without per-client REST duplication.
