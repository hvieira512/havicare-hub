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
0/watch/{deviceKey}/raw
0/watch/{deviceKey}/telemetry
0/watch/{deviceKey}/events
0/watch/{deviceKey}/status
```

Downlink from MQTT to connected device:

```text
0/watch/{deviceKey}/downlink
```

If the device is offline, the hub stores the latest pending downlink per IMEI and native command in Redis for `DOWNLINK_QUEUE_TTL_SECONDS` seconds, default `300`. The hub publishes `device.downlink.queued` when queued and `device.downlink.sent` when it is delivered after the next device login.

`MQTT_TOPIC_PREFIX` is prepended when configured.

Topic semantics:

- `0` is the reserved default license scope for watches.
- `watch` is the current TCP-ingress device type.
- `deviceKey` is the canonical topic identity. For watches it is the IMEI.

## Telemetry Payload Contract

Telemetry messages published to `0/watch/{deviceKey}/telemetry` share one envelope across all suppliers and models:

```json
{
  "schemaVersion": 2,
  "type": "location",
  "occurredAt": "2026-06-17T13:48:29Z",
  "device": {
    "id": "637507597567372",
    "supplier": "4P Touch",
    "model": "D46"
  },
  "data": {},
  "source": {
    "protocol": "four-p-touch",
    "nativeType": "UD_LTE"
  },
  "extra": {}
}
```

Field meaning:

- `schemaVersion`: telemetry schema version. Current value is `2`.
- `type`: normalized feature name such as `location`, `heart_rate`, `battery`, `activity`, `alarm`, `blood_pressure`, `blood_oxygen`, `temperature`, `heartbeat`, `device_config`, or `weather`.
- `occurredAt`: server-side publish timestamp in UTC.
- `device.id`: canonical device identity used by the hub and MQTT topics.
- `device.supplier` and `device.model`: whitelist/model metadata resolved by the hub.
- `data`: normalized shared shape for the feature.
- `source.protocol`: protocol adapter that decoded the message, for example `vivistar-iw`, `wonlex-json`, or `four-p-touch`.
- `source.nativeType`: native supplier message type, for example `AP01`, `upLocation`, `UD_LTE`, `LK`, or `bphrt`.
- `extra`: protocol-specific decoded fields that are intentionally preserved but not part of the normalized shared shape.

### Shared Feature Shapes

#### `location`

`data` for `type: "location"` may contain:

- `source`: normalized positioning origin. Current values include `gps`, `cell`, `wifi`, `cell_wifi`, and some legacy supplier-specific values that should be phased out.
- `lat`
- `lon`
- `gpsValid`
- `speedKmh`
- `heading`
- `altitudeMeters`
- `satelliteCount`
- `gsmSignal`
- `mcc`
- `mnc`
- `lac`
- `cellId`
- `accuracyMeters`
- `baseStations`: array of nearby/serving cells
- `wifiAccessPoints`: array of nearby Wi-Fi access points

Semantics:

- `lat` and `lon` mean the device reported coordinates.
- `gpsValid` means the protocol marked the GNSS fix as valid. Coordinates may still exist when `gpsValid` is `false`.
- `source` describes how the position should be interpreted. It should not be inferred only from the presence of coordinates.
- `baseStations` and `wifiAccessPoints` are optional evidence fields and may appear even when coordinates are absent.

Example:

```json
{
  "source": "cell",
  "lat": 41.706128,
  "lon": -8.7937862,
  "gpsValid": false,
  "speedKmh": 0,
  "heading": 0,
  "altitudeMeters": 19.1,
  "satelliteCount": 0,
  "gsmSignal": 32,
  "mcc": "268",
  "mnc": "6",
  "lac": "48820",
  "cellId": "677900",
  "accuracyMeters": 0,
  "baseStations": [
    {
      "lac": "48820",
      "cellId": "677900",
      "gsmSignal": 140
    }
  ]
}
```

#### `heart_rate`

- `bpm`

#### `battery`

- `percent`
- `chargingState`
- `batteryType`

#### `activity`

- `steps`
- `distanceMeters`
- `caloriesKcal`
- `exerciseSeconds`
- `standMinutes`

#### `blood_pressure`

- `systolicMmHg`
- `diastolicMmHg`
- `pulseBpm`

#### `blood_oxygen`

- `spo2Percent`

#### `blood_sugar`

- `glucoseMgDl`

#### `temperature`

- `bodyCelsius`

#### `heartbeat`

- `status`
- `steps`
- `gsmSignal`
- `satelliteCount`
- `batteryPercent`
- `chargingState`
- `batteryType`
- `rollFrequency`
- `remainingSpace`
- `fortificationState`
- `workMode`

#### `alarm`

- `code`
- `sos`
- `lowBattery`
- `fall`
- `wearingNotice`

#### `device_config`

- `status`
- `ack`
- `settings`

#### `weather`

- `status`
- `summary`
- `weatherType`
- `reportedAt`
- `temperatureCelsius`
- `lowCelsius`
- `highCelsius`
- `humidityPercent`

When a supplier exposes additional fields that do not map cleanly into the shared shape, keep them in `extra` rather than overloading `data` with protocol-specific names.

## Raw Payloads

Raw messages published to `0/watch/{deviceKey}/raw` preserve the device bytes:

```json
{
  "schemaVersion": 1,
  "direction": "uplink",
  "occurredAt": "2026-06-17T13:48:29Z",
  "device": {
    "id": "637507597567372",
    "supplier": "4P Touch",
    "model": "D46"
  },
  "debug": {
    "protocol": "four-p-touch",
    "transport": "tcp",
    "encoding": "text",
    "payload": "[3G*3707975737*0073*UD_LTE,...]",
    "size": 136,
    "connectionId": "1000002"
  }
}
```

`debug.payload` is the canonical raw body. For non-text bytes the hub emits base64 and sets `debug.encoding` to `base64`.

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

Devices are authorized through [config/whitelist.json](config/whitelist.json) as key-value pairs of canonical device identity to metadata:

```json
{
  "865028000000306": {
    "supplier": "Wonlex",
    "model": "HW20PRO"
  },
  "637507597567372": {
    "supplier": "4P Touch",
    "model": "D46",
    "deviceId": "3707975737"
  }
}
```

For 4P Touch, store the full canonical IMEI as the whitelist key and the protocol-level 10-digit identifier separately in `deviceId`. The hub resolves `deviceId` during auth and downlink building, but all MQTT topics and stored device identity remain keyed by the canonical IMEI.

Unknown devices are disconnected and a rejection is published to `0/watch/{deviceKey}/status` and `0/watch/{deviceKey}/events`. The model is checked only when the device protocol includes a model in its login payload; otherwise the hub authorizes by identity and records the configured metadata from the whitelist.

## Tests

```bash
composer test
```

The scenario smoke test starts Mosquitto and the hub, connects a simulated Vivistar TCP device, verifies raw MQTT uplink, publishes MQTT downlink, and verifies the device receives it.
