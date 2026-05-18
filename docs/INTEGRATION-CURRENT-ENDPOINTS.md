# Current Endpoints Guide

Status: implemented-now guide for the current REST API.

This file documents what exists today in runtime. It is useful while MQTT topic families are still being phased in.

## Base URL

- Local direct API: `http://127.0.0.1:8081`
- OpenAPI JSON: `GET /openapi.json`
- Swagger UI: `GET /docs`

## Device Operations

- `GET /devices`: list devices with filters/pagination
- `POST /devices`: register device (`imei`, `model`, optional `enabled`)
- `GET /devices/{imei}`: read one device
- `PUT /devices/{imei}`: update `model`/`enabled`
- `DELETE /devices/{imei}`: remove device
- `GET /devices/{imei}/features`: feature and native command mapping

## Model and Supplier Catalog

- `GET|POST /suppliers`
- `GET|PUT|DELETE /suppliers/{id}`
- `GET|POST /models`
- `GET|PUT|DELETE /models/{code}`

## Event Reads

- `GET /events/recent?limit=50&after=<id>`
- `GET /devices/{imei}/events/latest`

## Command Endpoints

- `POST /devices/{imei}/command`
  - body: `{ "type": "dnHeartRate", "data": { ... } }`
- `POST /devices/{imei}/features/{feature}/command`
  - body: `{ "data": { ... } }`

Current response pattern is immediate dispatch status:

```json
{
  "status": "sent",
  "device": { "imei": "865028000000306" },
  "command": {
    "feature": null,
    "nativeType": "dnHeartRate",
    "requestId": "ab12cd34ef56ab78",
    "payload": {}
  }
}
```

## Operational Endpoints

- `GET /health`
- `GET /metrics`

## MQTT (Current Runtime)

- Implemented publish topics: `devices/{imei}/telemetry`, `devices/{imei}/status`, `devices/{imei}/error`, `devices/{imei}/command/state`

## Important Behavior Notes

- Device must be authorized and online for command dispatch.
- Command lifecycle persistence (`commandId`, terminal states) is not yet implemented.
- API is platform-level and generic; it is not a client-specific API surface.

## REST Scope Policy (Current)

- REST is the platform control plane (governance, diagnostics, command ingress).
- MQTT is the primary external data plane for client integrations.
- External clients are not expected to receive a dedicated/custom REST API from this repository.
