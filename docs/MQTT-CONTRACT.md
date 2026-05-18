# MQTT Contract (External Data Plane)

Status: active contract in rollout. All topic families are wired; lifecycle depth is phased.

## Product Direction

Primary client integration direction:

- "Here is our MQTT server and event contract. Build what you need on top."
- Platform does not need to build a custom REST API for each client.

## Role of MQTT

MQTT is intended to carry:

- real-time telemetry
- device status updates
- command result/state events (when lifecycle is implemented)
- operational integration errors

Command ingress policy (current):

- Commands are sent to the platform via REST control-plane endpoints.
- MQTT `devices/{imei}/command/state` is an outbound state/event stream, not an inbound command queue.

## Topic Shape

Recommended topic families:

- `devices/{imei}/telemetry`
- `devices/{imei}/status`
- `devices/{imei}/error`
- `devices/{imei}/command/state`

Optional aggregate topics:

- `devices/all/telemetry`
- `devices/all/command/state`

Note: you can prepend environment namespaces when needed, for example `prod/devices/...`.

### Topic purpose

- `devices/{imei}/telemetry`: passive measurements/events from watch to platform.
- `devices/{imei}/status`: online/offline and operational status transitions.
- `devices/{imei}/error`: integration/runtime errors related to a specific watch.
- `devices/{imei}/command/state`: command lifecycle updates.

### Subscription patterns

- One device: `devices/{imei}/#`
- All telemetry: `devices/+/telemetry`
- All status: `devices/+/status`
- Full fleet stream: `devices/+/+`

## Payload Envelope

All payloads should be JSON UTF-8 with a stable envelope:

- `schemaVersion`
- `eventType`
- `eventId`
- `occurredAt`
- `imei`
- `model`
- `supplier`
- `correlation` (optional)
- `data`

Canonical event types:

- `telemetry.received`
- `device.status.changed`
- `command.state.changed`
- `integration.error`

## Compatibility and Versioning

- `schemaVersion` is mandatory on every event.
- Additive changes are allowed within `1.x` (for example, new optional fields).
- Breaking changes require a new major schema version (`2.0`, etc.).
- Consumers should ignore unknown fields and validate required fields only.

## Example Telemetry Event

```json
{
  "schemaVersion": "1.0",
  "eventType": "telemetry.received",
  "eventId": "evt_01J...",
  "occurredAt": "2026-05-18T16:30:00Z",
  "imei": "865028000000306",
  "model": "WONLEX-PRO",
  "supplier": "Wonlex",
  "data": {
    "feature": "heart_rate",
    "nativeType": "upHeartRate",
    "nativePayload": {
      "heartRate": 72
    }
  }
}
```

## QoS and Delivery Guidelines

- Telemetry: QoS 0 or QoS 1 based on SLA.
- Status and command results: QoS 1.
- Consumers should assume at-least-once and deduplicate by `eventId`.

## Security Guidelines

- Enable TLS in production.
- Use separate credentials by integration consumer.
- Use ACL deny-by-default.
- Never embed broker credentials in mobile/web clients directly.

## Current Runtime Scope

Implemented now:

- `devices/{imei}/telemetry` publish path from Redis `events` stream.
- `devices/{imei}/status` publish path from device online/offline status stream.
- `devices/{imei}/error` publish path from integration/runtime error stream.
- `devices/{imei}/command/state` publish path from dispatch/reply state stream.

Current command-state coverage:

- `dispatched`
- `failed`
- `ack`

Still in roadmap:

- full terminal coverage (`timeout`) and richer retry/timeout semantics.

Implementation phases are documented in `docs/MQTT-ROADMAP.md`.
