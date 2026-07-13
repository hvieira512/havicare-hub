# Hitecosystem Devices Hub

Raw multi-transport device hub that bridges authorized devices to MQTT.

The hub accepts devices over their native transport/protocol, identifies them only enough to enforce the whitelist, then forwards raw bytes through MQTT. It queues offline downlinks in Redis so intermittently connected devices receive pending commands after they reconnect.

The project runs as a plain PHP/ReactPHP application. There is no framework HTTP layer or ORM in the active runtime path.
MySQL is the control-plane source of truth for registered devices and dashboard data. Redis is used for runtime state such as presence, pending downlinks, and short-lived command/history buffers.

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

Database schemas:

```text
database/schema.sql
```

Runtime initialization applies the driver-appropriate schema directly in [src/Dashboard/DashboardDatabase.php](/Users/hugo/dev/hitecosystem-devices-hub/src/Dashboard/DashboardDatabase.php).

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

- TCP ingress: `TCP_INGRESS_HOST` / `TCP_INGRESS_PORT`, default `0.0.0.0:9000`
- Dashboard: `DASHBOARD_HOST` / `DASHBOARD_PORT`, default `0.0.0.0:8081`
- MQTT: `MQTT_HOST` / `MQTT_PORT`, default `127.0.0.1:1883`
- MySQL control-plane DB: `DB_HOST` / `DB_PORT`, default `127.0.0.1:3306`
- Redis downlink queue: `REDIS_HOST` / `REDIS_PORT`, default `127.0.0.1:6379`

## Dashboard

The hub serves a Bootstrap 5 dashboard at:

```text
http://127.0.0.1:8081/dashboard
```

The dashboard page itself is public. `DASHBOARD_USERNAME` and `DASHBOARD_PASSWORD` define the bootstrap admin API credential used to issue the dashboard session token; by default that credential is `admin` / `secret`. Override it when needed. The dashboard queries registered devices from MySQL and overlays runtime status from Redis.

API access uses `POST /api/auth/login` and bearer tokens when `DASHBOARD_API_AUTH_REQUIRED=true` (default). In development, set `DASHBOARD_API_AUTH_REQUIRED=false` to expose `/api/*` without login, which is useful for Swagger and local exploration without refreshing bearer tokens. The dashboard credentials issue a bootstrap `hub_admin` token when auth is enabled. Additional users are managed by admins in the dashboard settings modal or through `/api/users`.

API user roles:

- `hub_admin`: unrestricted hub administration.
- `license_client`: tied to exactly one `license_id`; can list/read devices in that license, request telemetry/configuration downlinks, inspect those command statuses, and update configuration for those devices. Devices with license `0` are admin-only.

`API_CLIENT_USERNAME` and `API_CLIENT_PASSWORD` remain available as a legacy restricted login fallback, but DB-backed `license_client` users should be used for real tenants because they carry the license scope in the token.

### API Logging

Every `/api/*` request is logged through the `api` log channel.

Each API log entry includes:

- `request_id`
- `method`
- `path`
- `query`
- `route`
- `status`
- `duration_ms`
- `auth_state`
- `username`
- `role`
- `license_id`
- exact raw `request_body`

If the client sends `X-Request-Id`, the hub reuses it and returns it in the response header.
Otherwise the hub generates one.

This logging is intentionally request-complete:

- successful requests are logged
- validation failures are logged
- unauthorized and forbidden requests are logged
- the exact raw request body is logged as received by the API

Operational note:

- `POST /api/auth/login` bodies are logged exactly as sent, including credentials
- API logs therefore need to be treated as sensitive production data

Logs go to stdout and, when `LOG_FILE` is set, also to that file.

## Device Configuration API

Generic device configuration now lives directly on `GET /api/devices/{imei}` and `PUT /api/devices/{imei}`.
The legacy `GET/PUT /api/devices/{imei}/configuration` endpoints were removed.

`GET /api/devices/{imei}` returns:

- `device`: registered device metadata plus runtime status.
- `model`: supplier/model metadata.
- `configuration`: summary counters for supported vs stored native configuration entries.
- `configurations`: raw native desired configuration rows currently stored for the device.
- `capabilities`: normalized device capabilities grouped by section. This is the main generic configuration shape for clients.
- `pending`: normalized configuration entries whose desired state still differs from the last reported state from the device.
- `transportPending`: raw queued transport downlinks still waiting in Redis because the device was offline.

Important semantics:

- `capabilities` always reflects the last configuration accepted by the API, not only the last configuration acknowledged by the device.
- `pending` exists to show which normalized configuration values are still waiting for device confirmation or have diverged from the last reported state.
- `transportPending` is lower-level transport state. It does not replace `pending`.

Writable sections inside `capabilities` are currently:

- `health`
- `contacts`
- `alarms`
- `settings_system`

`capabilities.telemetry` is read-only and only describes telemetry features that can be requested or reported for the model.
Each telemetry entry now exposes:

- `supported`
- `requestable`

Example:

```json
{
  "capabilities": {
    "telemetry": {
      "heart_rate": {
        "supported": true,
        "requestable": true
      },
      "location": {
        "supported": true,
        "requestable": true
      }
    }
  }
}
```

Writable capability entries may also include helper metadata:

- `_meta`: generic options and seller-facing labels for client UI rendering
- legacy wrapped capabilities may still expose `_type`, but `alarm_clock` does not

For alarm reminders, the generic capability is `alarm_clock`:

- Vivistar maps `alarm_clock` to native `reminders`
- 4P Touch maps `alarm_clock` to native `REMIND`

The API accepts the generic alias on `PUT /api/devices/{imei}` and `PUT /api/devices/{imei}/configurations` when the model supports it.

`PUT /api/devices/{imei}` supports two modes:

- Device metadata update when the body contains standard device fields like `imei`, `supplier`, `model`, `licenseId`, and related metadata.
- Generic configuration update when the body contains `capabilities`.

For generic configuration updates, the client should send the same normalized `capabilities` structure returned by `GET`, but only writable entries and without helper metadata like `_meta`.
The backend validates the payload against the model capability catalog, compares it against the current desired state, persists only real changes, and sends downlinks only for the native configuration entries that actually changed.

Example:

```json
{
  "capabilities": {
    "alarms": {
      "alarm_clock": {
        "items": [
          {
            "time": "08:10",
            "enabled": true,
            "type": 2,
            "recurrence": {
              "kind": "custom",
              "days": [1, 3, 5]
            }
          }
        ]
      }
    },
    "settings_system": {
      "working_mode": {
        "value": {
          "mode": 8,
          "intervalSeconds": 60,
          "gpsEnabled": true
        }
      }
    }
  }
}
```

Successful configuration updates return:

- `changed`: normalized capability paths that produced one or more native downlinks.
- `unchanged`: normalized capability paths ignored because the desired state already matched.
- `configuration`
- `capabilities`
- `pending`
- `transportPending`

The current implementation still accepts the older native payload form with `configs` on `PUT /api/devices/{imei}` for compatibility, but new clients should use the generic `capabilities` form.

## Telemetry Requests

Telemetry measurement requests should use the generic endpoint:

```text
POST /api/devices/{imei}/requests
```

with payload:

```json
{
  "feature": "heart_rate"
}
```

The client should not depend on native command ids such as `BPXL` or `dnHeartRate`.
The hub maps the generic feature to the correct protocol-specific downlink internally and later publishes the resulting normalized telemetry through MQTT.

The dashboard derives requestable telemetry actions directly from `GET /api/devices/{imei}` by reading `capabilities.telemetry.{feature}.requestable`.

## MQTT Topics

Uplink from device to MQTT:

```text
{company}/{licenseId}/watch/{deviceKey}/raw
{company}/{licenseId}/watch/{deviceKey}/telemetry
{company}/{licenseId}/watch/{deviceKey}/events
{company}/{licenseId}/watch/{deviceKey}/status
{company}/{licenseId}/radar/{deviceKey}/telemetry
{company}/{licenseId}/radar/{deviceKey}/events
{licenseId}/ncs/{deviceKey}/raw
{licenseId}/ncs/{deviceKey}/telemetry
{licenseId}/ncs/{deviceKey}/events
{licenseId}/ncs/{deviceKey}/status
```

Downlink from MQTT to connected device:

```text
{company}/{licenseId}/watch/{deviceKey}/downlink
```

If the device is offline, the hub stores the latest pending downlink per IMEI and native command in Redis for `DOWNLINK_QUEUE_TTL_SECONDS` seconds, default `300`. The hub publishes `device.downlink.queued` when queued and `device.downlink.sent` when it is delivered after the next device login.

`MQTT_TOPIC_PREFIX` is prepended when configured.

Topic semantics:

- `company` is the tenant namespace for watch devices. Unassociated devices use `null`.
- `licenseId` is the tenant license scope for watches. Unassociated devices use `0`.
- `watch` is the TCP-ingress device type. `radar` and `ncs` are MQTT-ingress device types.
- `deviceKey` is the canonical topic identity for watches and NCS. For Qinglanst radars the hub republishes on the upstream radar UID from the source topic.

## Telemetry Payload Contract

Telemetry messages are published to:

```text
{company}/{licenseId}/watch/{deviceKey}/telemetry
{company}/{licenseId}/radar/{deviceKey}/telemetry
{licenseId}/ncs/{deviceKey}/telemetry
```

They share one envelope across all suppliers, models, and supported device types:

```json
{
  "schemaVersion": 2,
  "type": "location",
  "occurredAt": "2026-06-17T13:48:29Z",
  "device": {
    "id": "637507597567372",
    "supplier": "4P Touch",
    "model": "4P-TOUCH",
    "commercialName": "4P Touch D46"
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
- `device.supplier`: whitelist supplier metadata resolved by the hub.
- `device.model`: internal whitelist model metadata resolved by the hub.
- `device.commercialName`: commercial/display model name resolved from the model catalog when available.
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

Raw messages preserve the device or upstream payload on:

```text
{company}/{licenseId}/watch/{deviceKey}/raw
{licenseId}/ncs/{deviceKey}/raw
```

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
  },
  "ncs-gateway-01": {
    "supplier": "Voerka",
    "model": "W812",
    "deviceType": "ncs",
    "licenseId": "1001",
    "deviceId": "gw-001"
  }
}
```

For 4P Touch, store the full canonical IMEI as the whitelist key and the protocol-level 10-digit identifier separately in `deviceId`. The hub resolves `deviceId` during auth and downlink building, but all MQTT topics and stored device identity remain keyed by the canonical IMEI.

For NCS, register each gateway or source under its canonical hub key with `deviceType: "ncs"`, an explicit `licenseId`, and the upstream `from` value in `deviceId`. The hub subscribes to `NCS_TOPIC_FILTER` (default `/voerka/#`), resolves that source through the registry, and republishes normalized records to `{licenseId}/ncs/{deviceKey}/{raw|status|events|telemetry}`.

Unknown devices are disconnected and a rejection is published to `null/0/watch/{deviceKey}/status` and `null/0/watch/{deviceKey}/events`. The model is checked only when the device protocol includes a model in its login payload; otherwise the hub authorizes by identity and records the configured metadata from the whitelist.

## Tests

```bash
composer test
```

The scenario smoke test starts Mosquitto and the hub, connects a simulated Vivistar TCP device, verifies raw MQTT uplink, publishes MQTT downlink, and verifies the device receives it.
