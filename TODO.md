I reviewed the repository structure, main runtime path, config, Docker setup, and unit tests. Unit tests pass: `39 / 39`, with 2 skipped.

## Executive summary

The codebase is relatively small and understandable, not heavily overengineered overall. The biggest production-readiness gaps are not algorithmic complexity; they are operational safety, configuration hygiene, protocol boundary clarity, and resilience under failure/load.

Most important issues to address before production:

1. Docker build context may include secrets and local artifacts.
2. `DeviceHubServer` is becoming a god class.
3. MQTT/TCP runtime has limited backpressure, rate limiting, and failure isolation.
4. Configuration and docs are inconsistent around `whitelist.json`.
5. No health/readiness endpoints, metrics, structured operational visibility, or graceful shutdown.
6. MQTT delivery semantics are at-most-once, with no persistence or retry queue.
7. Authentication relies on a device-claimed IMEI plus whitelist only.

---

## Good signs

- Clear core purpose: raw device hub bridging TCP/WebSocket devices to MQTT.
- Reasonable separation for:
  - `ConnectionRegistry`
  - `DeviceAuthorizer`
  - `DeviceIdentityExtractor`
  - `HubMqttBridge`
  - protocol adapters
- Unit tests cover a meaningful amount of protocol and hub behavior.
- The project consciously avoids unnecessary DB/Redis/worker layers, which is good for the current scope.

---

## High-priority production risks

### 1. Docker context can leak secrets/artifacts

File: `.dockerignore`

Current `.dockerignore` excludes only a few things:

```text
.git
.gitignore
.dockerignore
vendor/
var/
.DS_Store
*.md
*.log
```

But local files like these can still enter the Docker build context:

- `.env`
- `.env.*`
- `config/ssl/privkey.pem`
- `config/whitelist.json`
- `.idea/`
- `tests/artifacts/`
- local temp files

Even if they are not copied into the final image intentionally, they are still sent to Docker during build. This is a production security risk.

Recommended fix:

- Mirror relevant `.gitignore` entries into `.dockerignore`.
- Explicitly exclude:
  - `.env`
  - `.env.*`
  - `config/ssl/*`
  - `config/whitelist.json`
  - `.idea/`
  - `.vscode/`
  - `tests/artifacts/`
  - `temp.md`
  - local simulator/output files

---

### 2. `DeviceHubServer` is doing too much

File: `src/Hub/DeviceHubServer.php`

This class currently handles:

- connection lifecycle
- authentication
- authorization failure behavior
- MQTT publishing
- raw payload creation
- protocol-specific login acknowledgements
- protocol keepalive ACKs
- downlink sending
- status/event publication

At 255 lines it is not huge yet, but it is the highest-risk growth point.

Recommended split:

- `DeviceConnectionHandler`
  - open/message/close/error lifecycle
- `DeviceAuthenticationService`
  - identity extraction + whitelist authorization
- `DeviceEventPublisher`
  - status/event/raw MQTT publication
- `ProtocolResponder`
  - login ACK and keepalive ACK behavior
- `DownlinkRouter`
  - send to live device and report dropped messages

This would make production changes safer because protocol-specific behavior would not keep accumulating inside the hub server.

---

### 3. Runtime has weak overload protection

Files:

- `src/Hub/HubTcpIngress.php`
- `src/Hub/ConnectionRegistry.php`
- `src/Hub/HubDownlinkSubscriber.php`

Current safety mechanisms are minimal:

- TCP buffer is reset after `65535` bytes, but connection is not closed.
- No max connection count.
- No per-device connection policy besides latest map entry.
- No per-IMEI rate limit.
- No MQTT downlink payload size limit.
- No idle connection timeout.
- No authentication timeout.
- No circuit breaker if MQTT is unavailable.
- No backpressure from MQTT publishing to TCP input.

Production implications:

- A malformed or hostile TCP client can hold connections and continuously trigger parsing work.
- MQTT outages could cause repeated publish failures while devices continue sending data.
- A noisy device can dominate the event loop.

Recommended additions:

- Max TCP/WebSocket connections.
- Max unauthenticated session lifetime.
- Max frame/payload size per protocol.
- Close connection on repeated malformed frames.
- Per-device and global rate limits.
- Metrics for dropped uplinks/downlinks.
- Explicit behavior when MQTT is unavailable: drop, buffer, or disconnect.

---

### 4. MQTT delivery semantics are not production-safe for critical messages

Files:

- `src/Hub/HubMqttBridge.php`
- `src/Hub/HubDownlinkSubscriber.php`

Everything uses `QOS_AT_MOST_ONCE`.

That may be acceptable for raw telemetry where loss is tolerable, but it is risky for:

- status changes
- auth rejection events
- downlink confirmations
- operational audit trails

There is also no durable local queue. If MQTT is down, the message is logged and lost.

Recommended decision:

Define topic-level delivery policy:

| Topic | Suggested semantics |
|---|---|
| raw uplink | QoS 0 or QoS 1 depending business need |
| status | retained + QoS 1 |
| events | QoS 1 |
| downlink | QoS 1 if commands matter |
| audit/security | persistent or external sink |

If loss is acceptable, document it explicitly. If not, add a small durable queue or use MQTT QoS 1 with reconnect handling.

---

## Medium-priority structure issues

### 5. Protocol concerns leak across layers

Examples:

- `HubTcpIngress` knows about Wonlex binary frame starts.
- `DeviceHubServer` knows special behavior for `wonlex-json`, `vivistar-iw`, and `four-p-touch`.
- `VivistarAdapter` both parses raw protocol and enriches semantic measurements.

This creates coupling between transport, protocol, and application behavior.

Recommended direction:

Make adapters responsible for protocol-specific behavior:

```php
interface DeviceAdapterInterface
{
    public function decodeIncoming(...);
    public function encodeOutgoing(...);
    public function extractFrames(...);
    public function loginAck(...);
    public function keepaliveAck(...);
}
```

Then `HubTcpIngress` should not need to know about Wonlex specifically, and `DeviceHubServer` should not need `if ($protocol === ...)` branches.

---

### 6. `VivistarAdapter` may be over-scoped

File: `src/Protocol/Adapter/VivistarAdapter.php`

The README says this is a raw bridge and does not normalize telemetry. But `VivistarAdapter` enriches measurements:

- `heartRate`
- `systolic`
- `diastolic`
- `spo2`
- `bloodSugar`
- `temperature`
- `battery`
- etc.

If the product goal is raw bridging, this is unnecessary complexity. If the product goal is eventual normalization, the logic belongs in a separate normalization layer, not the low-level adapter.

Recommended choice:

- For raw bridge: remove semantic enrichment.
- For normalized pipeline: create `TelemetryNormalizer` separate from protocol framing.

---

### 7. Whitelist implementation and docs disagree

Files:

- `README.md`
- `src/Registry/Whitelist.php`
- `config/whitelist.example.json`

README shows this shape:

```json
{
  "865028000000306": "WONLEX-PRO"
}
```

But `Whitelist` expects this shape:

```json
{
  "865028000000306": {
    "supplier": "Wonlex",
    "model": "HW20PRO"
  }
}
```

This will cause setup confusion and possibly an empty whitelist if someone follows the README.

Recommended fix:

- Update README to match `config/whitelist.example.json`.
- Add validation/logging when whitelist entries are ignored.
- Fail fast in production if whitelist file is missing or empty, unless explicitly allowed.

---

### 8. `Whitelist` has mutation methods that are not used

File: `src/Registry/Whitelist.php`

Methods:

- `register`
- `unregister`
- `update`
- `saveFile`

These suggest runtime mutation, but there is no API or command path using them.

This is mild overengineering and can be risky because file writes in a long-running server process need locking, atomic writes, and concurrency handling.

Recommended options:

- Remove mutation methods if whitelist is config-only.
- Or formalize it as a repository with atomic writes and tests.

---

## Missing production architecture pieces

### Health and readiness

There is no obvious health endpoint or readiness check.

Recommended:

- Liveness: process/event loop alive.
- Readiness: MQTT connected, TCP/WS sockets bound, whitelist loaded.
- Docker healthcheck.
- Optional `/metrics` endpoint if exposing HTTP is acceptable.

---

### Observability

Current logging is useful but not enough for production operations.

Recommended metrics:

- active connections
- authenticated devices
- auth failures
- MQTT publish failures
- MQTT reconnect count
- downlinks sent/dropped
- malformed frames
- bytes in/out
- per-protocol message counts
- event loop lag

Also consider structured JSON logs in production rather than line formatter text.

---

### Graceful shutdown

The server does not appear to handle SIGTERM/SIGINT explicitly.

Recommended:

- Stop accepting new connections.
- Publish offline statuses for authenticated sessions.
- Disconnect MQTT clients.
- Close sockets.
- Exit within a configured timeout.

This matters for Docker/systemd deployments.

---

### Configuration validation

File: `src/Config.php`

Config loading is simple, but production should validate:

- required `MQTT_HOST`
- valid ports
- TLS file existence when TLS enabled
- log file writability
- whitelist file existence
- topic prefix validity
- non-default credentials in production

Recommended:

- Add `ConfigValidator`.
- Fail fast with clear startup errors.

---

### Security model

Current authorization appears to be:

1. Device sends IMEI.
2. Hub checks IMEI against whitelist.

This is okay for initial internal deployments, but not strong authentication.

Risks:

- IMEI spoofing.
- No per-device secret.
- No mTLS device auth.
- Plain TCP ingress unless placed behind secured network.
- WebSocket ingress exposed on `0.0.0.0` by default.

Recommended production stance:

- Treat this as network-trusted only unless stronger auth is added.
- Put ingress behind firewall/VPN/private network.
- Consider per-device credentials, signed login, or TLS client certs.
- Add audit logging for rejected/duplicate IMEIs.

---

## Files that look most overengineered or misplaced

### `src/Hub/DeviceHubServer.php`

Not overengineered yet, but too central. It should be split before adding more protocols or business rules.

### `src/Protocol/Adapter/VivistarAdapter.php`

Likely over-scoped because it mixes raw frame handling with measurement enrichment.

### `src/Registry/Whitelist.php`

Mutation/persistence methods are probably unnecessary unless runtime whitelist management is planned.

### `temp.md`

Looks like stale design/documentation scratch content. Should either move into proper docs or delete.

### Local workspace artifacts

The working tree contains large local artifacts under `tests/artifacts`, `var/log`, docs scans, and SSL files. They seem ignored, not tracked, but they make local analysis/builds noisy. Keep them out of Docker context too.

---

## Recommended production-readiness roadmap

### Phase 1: Safety/hygiene

- Harden `.dockerignore`.
- Fix README whitelist format.
- Add config validation.
- Add startup failure when whitelist is missing/empty in production.
- Add payload size limits for MQTT downlink and TCP frames.
- Add authentication timeout.
- Add graceful shutdown.

### Phase 2: Architecture cleanup

- Split `DeviceHubServer`.
- Move protocol-specific ACK behavior into adapters.
- Decide whether adapters are raw-only or normalization-aware.
- Remove or formalize whitelist mutation methods.
- Extract MQTT client factory from `bin/server-hub.php`.

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

---

## Overall assessment

The codebase is a solid prototype/small production candidate, but not yet production-hardened. The architecture is intentionally lean, which is good, but the central server class and protocol leakage will become painful as more devices and safety requirements are added. The highest-value immediate work is not adding more layers; it is tightening Docker/config/security hygiene and adding operational guardrails.
