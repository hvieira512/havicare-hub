# MQTT Rollout Roadmap

Status: phased rollout from current Redis-stream runtime to external MQTT data plane.

## Goal

Expose a stable external MQTT contract so clients can integrate directly, without requiring custom REST APIs per client.

## Migration Completion Checklist

This migration is considered complete when all items below are done:

1. Command ingress is explicitly locked to REST control-plane endpoints, with MQTT used for outbound command state events.
2. MQTT broker production hardening is in place (TLS, per-consumer credentials, ACL least privilege, rotation runbook).
3. Event contract compatibility rules are frozen (`schemaVersion`, additive-first minor changes, breaking-change major version bump).
4. Smoke verification is automated (`bin/smoke-mqtt.sh`, `make smoke-mqtt`) and runs in CI/pipeline for every release candidate.
5. REST scope policy is documented and enforced as platform control plane only (no per-client REST API commitments).

Current status:

- Item 1: done in docs.
- Item 2: pending production rollout/runbook validation.
- Item 3: done in docs; keep publisher outputs aligned.
- Item 4: local automation done; CI/pipeline wiring pending.
- Item 5: done in docs.

## Phase 0 (Now) - Stabilize Current Surface

- Keep existing REST API for platform control/operations.
- Keep Redis Streams as internal transport.
- Freeze canonical event envelope that will be reused for MQTT.

Exit criteria:

- OpenAPI and docs aligned with implemented behavior.
- Event envelope documented and versioned.

## Phase 1 - Internal MQTT Publisher Bridge

- Add a publisher worker:
  - reads from internal event stream
  - converts to canonical MQTT envelope
  - publishes to MQTT topics
- Keep Redis and REST unchanged.

Exit criteria:

- Telemetry events visible on MQTT topics.
- Publish failures logged and retried.

Current status:

- Implemented: telemetry publishing to `devices/{imei}/telemetry` via `bin/mqtt-publisher.php`.
- Pending in this phase: replay/ack hardening and operational dashboards.

## Phase 2 - Command Result and Status Events

- Add command result publications (`command.state.changed`).
- Add device status events.
- Add correlation metadata for command request/reply mapping.

Exit criteria:

- Command-related events published reliably with consistent schema.

Current status:

- Implemented: `devices/{imei}/status` publishing for online/offline transitions.
- Implemented: `devices/{imei}/error` and `devices/{imei}/command/state`.
- Pending: richer lifecycle depth (`timeout`) and retry semantics.

## Phase 3 - Hardened Operations and Security

- TLS everywhere in production.
- Credential lifecycle and ACL automation.
- Broker monitoring (connections, publish errors, lag).

Exit criteria:

- Security runbook validated.
- Operational dashboards/alerts in place.

## Phase 4 - Optional REST Scope Reduction

- Keep REST as platform control plane, but not mandatory for external clients.
- If desired, narrow REST usage to admin/ops only.

Exit criteria:

- External clients can integrate end-to-end with MQTT only.
- No client-specific REST APIs needed.

## Suggested First Implementation Slice

1. Publish telemetry only to `devices/{imei}/telemetry`.
2. Keep payload minimal and stable.
3. Add replay-safe `eventId` generation.
4. Validate with one reference consumer.
