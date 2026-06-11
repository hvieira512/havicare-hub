## Recommended production-readiness roadmap

### Phase 1: Safety/hygiene

- Harden .dockerignore.
- Fix README whitelist format.
- Add config validation.
- Add startup failure when whitelist is missing/empty in production.
- Add payload size limits for MQTT downlink and TCP frames.
- Add authentication timeout.
- Add graceful shutdown.

### Phase 2: Architecture cleanup

- Split DeviceHubServer.
- Move protocol-specific ACK behavior into adapters.
- Decide whether adapters are raw-only or normalization-aware.
- Remove or formalize whitelist mutation methods.
- Extract MQTT client factory from bin/server-hub.php.

### Phase 3: Operations

- Add health/readiness checks.
- Add metrics.
- Add structured logs.
- Add Docker healthcheck.
- Define MQTT QoS policy.
- Add reconnect/circuit-breaker behavior.
- Add load/soak tests for many devices.

### Phase 4: Security hardening

- Firewall/private network assumptions documented.
- Non-default credentials enforcement.
- Optional TLS/mTLS for device ingress.
- Duplicate IMEI/session policy.
- Audit trail for auth failures and downlink commands.
