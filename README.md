# Hitecosystem Devices Hub

Raw multi-transport device hub that bridges authorized devices to MQTT.

The hub accepts devices over their native transport/protocol, identifies them only enough to enforce the whitelist, then forwards raw bytes through MQTT. It queues offline downlinks in Redis so intermittently connected devices receive pending commands after they reconnect.

## Architecture

```text
Device
  |
  | TCP using native device protocol
  v
Hitecosystem Devices Hub
  - transport ingress
  - device identity extraction
  - whitelist authorization
  - live connection registry
  - raw uplink -> MQTT
  - MQTT downlink -> raw device write or Redis queue
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

- TCP ingress: `VIVISTAR_TCP_HOST` / `VIVISTAR_TCP_PORT`, default `0.0.0.0:9000`
- Dashboard: `DASHBOARD_HOST` / `DASHBOARD_PORT`, default `0.0.0.0:8081`
- MQTT: `MQTT_HOST` / `MQTT_PORT`, default `127.0.0.1:1883`
- Redis downlink queue: `REDIS_HOST` / `REDIS_PORT`, default `127.0.0.1:6379`

## Dashboard

The hub serves a Bootstrap 5 dashboard at:

```text
http://127.0.0.1:8081/dashboard
```

Set `DASHBOARD_USERNAME` and `DASHBOARD_PASSWORD` to enable Basic auth. The dashboard uses Redis for recent device history, queued downlinks, and command outcomes.

## MQTT Topics

Uplink from device to MQTT:

```text
devices/{imei}/uplink
```

Downlink from MQTT to connected device:

```text
devices/{imei}/downlink
```

If the device is offline, the hub stores the latest pending downlink per IMEI and native command in Redis for `DOWNLINK_QUEUE_TTL_SECONDS` seconds, default `300`. The hub publishes `device.downlink.queued` when queued and `device.downlink.sent` when it is delivered after the next device login.

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

Devices are authorized through [config/whitelist.json](config/whitelist.json) as key-value pairs of IMEI to configured model:

```json
{
  "865028000000306": "WONLEX-PRO",
  "865028000000308": "VIVISTAR-CARE"
}
```

Unknown devices are disconnected and an auth rejection is published to `devices/{imei}/error`. The model is checked only when the device protocol includes a model in its login payload; otherwise the hub authorizes by IMEI and records the configured model from the whitelist.

## Tests

```bash
composer test
```

The scenario smoke test starts Mosquitto and the hub, connects a simulated Vivistar TCP device, verifies raw MQTT uplink, publishes MQTT downlink, and verifies the device receives it.
