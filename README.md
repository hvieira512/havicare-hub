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

Runtime initialization is MySQL-only. [DashboardDatabase.php](/Users/hugo/dev/hitecosystem-devices-hub/src/Infrastructure/Persistence/DashboardDatabase.php) applies the idempotent base schema and then runs versioned migrations from `src/Infrastructure/Persistence/Migration/`. Applied versions are recorded in `schema_migrations` under a MySQL advisory lock.

Reference suppliers, models, capabilities, and initial model capability selections are seeded by one-time migrations. Restarting the hub does not recreate deleted catalog rows or overwrite administrator-managed model capability selections.

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

When API authentication is enabled, the dashboard presents an admin login before loading its application. `DASHBOARD_USERNAME` and `DASHBOARD_PASSWORD` define the bootstrap admin credential; by default that credential is `admin` / `secret`. Database users with the `hub_admin` role can also sign in. The bearer and refresh tokens returned by `/api/auth/login` are held in the browser tab's `sessionStorage`.

API access uses `POST /api/auth/login` and bearer tokens when `DASHBOARD_API_AUTH_REQUIRED=true` (default). The dashboard warns after 15 minutes without user activity and logs out after 20 minutes. In development, set `DASHBOARD_API_AUTH_REQUIRED=false` to expose `/api/*` and bypass the dashboard login. Additional users are managed by admins in the dashboard settings modal or through `/api/users`.

API user roles:

- `hub_admin`: unrestricted hub administration.
- `license_client`: tied to exactly one `license_id`; can list/read devices in that license, request telemetry/configuration downlinks, inspect those command statuses, and update configuration for those devices. Devices with license `0` are admin-only.

License clients must be created in MySQL through the dashboard settings or `/api/users`. Environment-backed tenant credentials are not supported because they cannot carry a durable license association.

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
- structured `request_body`
- structured `response_body`

If the client sends `X-Request-Id`, the hub reuses it and returns it in the response header.
Otherwise the hub generates one.

This logging is intentionally request-complete:

- successful requests are logged
- validation failures are logged
- unauthorized and forbidden requests are logged
- JSON request and response bodies are logged as structured objects

Operational note:

- Passwords, access tokens, refresh tokens, and token query parameters are replaced with `********` before logging
- API logs can still contain device and patient-related operational data and must use production retention controls

Logs go to stdout and, when `LOG_FILE` is set, also to that file.

## Device Configuration API

Generic device configuration is read through `GET /api/devices/{imei}` and updated through `PATCH /api/devices/{imei}/configurations`.

`GET /api/devices/{imei}` returns:

- `device`: registered device metadata plus runtime status.
- `model`: supplier/model metadata.
- `configuration`: summary counters for supported versus stored configuration entries.
- `configurations`: current generic desired values keyed by capability name. These entries contain values only.
- `effectiveConfigurations`: values confirmed as effective by the device contract.
- `configurationSync`: revisioned desired/effective convergence and native delivery operations, grouped by capability section.
- `capabilities`: supported capabilities grouped by section. Writable entries contain the current example/value and all UI metadata in `_meta`.

Important semantics:

- `capabilities` always reflects what the model supports and what the API can accept, not only what is currently stored or acknowledged by the device.
- `configurationSync` distinguishes delivery, acknowledgement, confirmation, failure, and supersession. Redis queue details are internal transport state.
- Public capability entries never expose protocol-native identity. Generic identity, section, and protocol support come from `CapabilityCatalog`; supplier command catalogs are transport-only.

Writable capability sections are:

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

- `value`: the current public capability payload
- `_meta`: generic options and seller-facing labels for client UI rendering

For alarm reminders, the generic capability is `alarm_clock`:

- Vivistar maps `alarm_clock` to native `reminders`
- 4P Touch maps `alarm_clock` to native `REMIND`

Clients must send generic keys under `configurations` to `PATCH /api/devices/{imei}/configurations`. Supplier-native keys and the old `configs` or writable `capabilities` payloads are rejected. The backend maps each generic value to one or more supplier-native operations.

Example:

```json
{
  "configurations": {
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
    },
    "working_mode": {
      "mode": 8,
      "intervalSeconds": 60,
      "gpsEnabled": true
    }
  }
}
```

Successful configuration updates return:

- `status`
- `results`: changed generic capability keys, each with `operations[]`
- `results[].operations[].nativeKey`: explicit protocol-native identity used for that delivery operation
- `configurations`: complete current generic values
- `effectiveConfigurations`
- `configurationSync`

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

## NCS MQTT Contract

Voerka W812 NCS gateways are handled as a separate ingress family. They do not publish telemetry in the current implementation. The hub normalizes only two upstream message kinds:

- `status/online`
- `events`

### Upstream topics

The hub listens to:

```text
/voerka/#
```

The firmware currently emits, at minimum:

- `/voerka/<scope>/devices/<gatewayId>/status/online`
- `/voerka/<scope>/devices/<gatewayId>/events`

### Normalized MQTT topics

The hub republishes NCS messages to:

- `havicare-hub-dev/{licenseId}/ncs/{deviceId}/raw`
- `havicare-hub-dev/{licenseId}/ncs/{deviceId}/status`
- `havicare-hub-dev/{licenseId}/ncs/{deviceId}/events`

There is no NCS telemetry topic today.

### Status payload

`status/online` is normalized into a status message with this shape:

```json
{
  "schemaVersion": 1,
  "state": "online",
  "updatedAt": "2026-07-15T11:30:00Z",
  "device": {
    "id": "bea6c3dd8e02",
    "supplier": "Voerka",
    "model": "W812"
  }
}
```

The normalized status payload does not use a `data` object.

The hub also emits a lifecycle event alongside status:

- `device.connected` when the device reports `online: true`
- `device.disconnected` when the device reports `online: false`

### Event payload

Button presses are normalized into flat event types:

- `help_call`
- `reset`

The normalized event payload shape is:

```json
{
  "schemaVersion": 1,
  "type": "help_call",
  "occurredAt": "2026-07-15T11:30:00Z",
  "device": {
    "id": "bea6c3dd8e02",
    "supplier": "Voerka",
    "model": "W812"
  },
  "data": {
    "pagerId": "482929"
  }
}
```

For `reset`, the same structure is used with `type: "reset"`.

Mapping details:

- firmware key `8` becomes `help_call`
- firmware keys `0`, `1`, and `2` become `reset`

If a firmware key cannot be mapped, the hub discards the normalized event.

### Raw payload

The hub always preserves the original upstream message in the `raw` stream for audit/debug purposes.

The raw payload keeps:

- the original upstream topic
- the source scope and message kind
- the decoded upstream payload

### NCS notes

- `location` may appear in the upstream firmware payload, but the hub does not normalize it into telemetry.
- The topic namespace already identifies the NCS family, so normalized `type` values stay flat and do not use dotted prefixes like `ncs.pager.help_call`.
- The generic capability exposed for this family is `pager_call` in `alarms`, with the display label `Chamada de enfermagem`.

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

`debug.payload` is the canonical raw body. For non-text bytes the hub normally emits base64 and sets
`debug.encoding` to `base64`. Wonlex is the exception: valid Wonlex TCP frames expose their native JSON
object directly in `debug.payload`, while the lossless base64 TCP frame is preserved in `debug.encoded`.
For Wonlex, `debug.encoding` describes the representation used by `debug.encoded`.

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

Scenario runs retain the newest 20 artifact directories by default. Override that with `ARTIFACT_RUNS_TO_KEEP`, or run `make clean-test-artifacts` to apply the retention policy manually.
