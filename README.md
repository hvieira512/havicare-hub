# Hitecosystem Devices Hub

Raw multi-transport device hub that bridges authorized devices to MQTT.

The hub accepts devices over their native transport/protocol, identifies them only enough to enforce the whitelist, then forwards raw bytes through MQTT. It does not normalize telemetry, persist history, expose a REST API, or run Redis/MySQL workers.

## Architecture

```text
Device
  |
  | TCP / WebSocket using native device protocol
  v
Hitecosystem Devices Hub
  - transport ingress
  - device identity extraction
  - whitelist authorization
  - live connection registry
  - raw uplink -> MQTT
  - MQTT downlink -> raw device write
  |
  v
MQTT Broker
  |
  +--> downstream applications
```

## Runtime

```bash
composer server
```

Docker:

```bash
docker compose up -d
```

Useful Make targets:

```bash
make hub
make hub-logs
make smoke-hub
make test-all
```

## Ports

- WebSocket ingress: `WS_HOST` / `WS_PORT`, default `0.0.0.0:8080`
- TCP ingress: `VIVISTAR_TCP_HOST` / `VIVISTAR_TCP_PORT`, default `0.0.0.0:9000`
- MQTT: `MQTT_HOST` / `MQTT_PORT`, default `127.0.0.1:1883`

## MQTT Topics

Uplink from device to MQTT:

```text
devices/{imei}/uplink
```

Downlink from MQTT to connected device:

```text
devices/{imei}/downlink
```

Status and errors:

```text
devices/{imei}/status
devices/{imei}/error
```

`MQTT_TOPIC_PREFIX` is prepended when configured.

## Raw Payloads

Uplink payloads preserve bytes as base64:

```json
{
  "event": {
    "type": "device.raw.uplink",
    "id": "raw_..."
  },
  "occurredAt": "2026-06-09T12:00:00Z",
  "device": {
    "imei": "865028000000308"
  },
  "transport": "tcp",
  "protocol": "vivistar-iw",
  "encoding": "base64",
  "payload": "SVdBUDQ5LDcyIw==",
  "text": "IWAP49,72#",
  "size": 10
}
```

`payload` is canonical. `text` is included only when the bytes are valid text.

Downlink accepts either a raw MQTT payload string or JSON:

```json
{
  "encoding": "text",
  "payload": "IWBP03#"
}
```

```json
{
  "encoding": "base64",
  "payload": "SVdCUDAzIw=="
}
```

## Whitelist

Devices are authorized through [config/whitelist.json](config/whitelist.json). Unknown or disabled devices are disconnected and an auth rejection is published to `devices/{imei}/error`.

## Tests

```bash
composer test
```

The scenario smoke test starts Mosquitto and the hub, connects a simulated Vivistar TCP device, verifies raw MQTT uplink, publishes MQTT downlink, and verifies the device receives it.
