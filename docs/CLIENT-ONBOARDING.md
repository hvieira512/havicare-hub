# Client Onboarding Guide

Status: target workflow for MQTT-first external integrations.

## Integration Philosophy

You integrate by consuming our MQTT event contract.

- We provide broker access + documented topic/payload contract.
- You decide how to transform/store/serve that data in your own systems.
- We do not need to create a custom REST API per client.

## What We Provide

- MQTT broker host/port/TLS details
- integration credentials
- allowed topic scope
- payload contract and schema version policy
- example events and runbook contacts

Default topic contract:

- `devices/{imei}/telemetry`
- `devices/{imei}/status`
- `devices/{imei}/error`
- `devices/{imei}/command/state` (when command lifecycle publishing is enabled)

## What You Build

- your consumer service
- your persistence/indexing model
- your product-facing APIs/UI
- your alerting and retry behavior

## Command Model (Current)

- Device commands are submitted through platform REST endpoints.
- MQTT is the outbound integration stream for telemetry/status/error/command-state.
- If your product needs a command API, build it in your own stack and route to the platform REST control plane.

## Recommended Consumer Flow

1. Connect to MQTT broker.
2. Subscribe to agreed topics (for example: `devices/+/telemetry`).
3. Validate envelope and schema version.
4. Deduplicate with `eventId`.
5. Persist and fan out inside your own stack.

## Reliability Checklist

- Automatic reconnect with backoff.
- Idempotent event processing by `eventId`.
- Dead-letter handling for invalid payloads.
- Monitoring for lag, disconnects, and parse failures.

## Security Checklist

- TLS enabled.
- Credential rotation policy.
- Broker ACL least privilege.
- No direct credential exposure in frontend applications.

## Current State Note

Today, this repository has platform REST, internal Redis stream processing, and MQTT publishing for:

- `devices/{imei}/telemetry`
- `devices/{imei}/status`
- `devices/{imei}/error`
- `devices/{imei}/command/state`

Command-state depth is still evolving (`timeout` semantics and richer lifecycle coverage are roadmap items in `docs/MQTT-ROADMAP.md`).
