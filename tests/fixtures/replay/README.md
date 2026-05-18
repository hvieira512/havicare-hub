# Replay Fixtures

Each fixture file describes one ingress packet scenario and expected downstream MQTT contract.

## Format

```json
{
  "name": "unique_fixture_name",
  "ingress": {
    "transport": "ws|tcp",
    "server": "ws://127.0.0.1:8080",
    "model": "WONLEX-HEALTH",
    "imei": "865028000000307",
    "command": "upBattery"
  },
  "expect": {
    "topic": "devices/{imei}/telemetry",
    "json": {
      "event.type": "telemetry.received",
      "device.imei": "865028000000307"
    }
  }
}
```

## Sanitization workflow for real-device captures

1. Replace real IMEI with a test IMEI present in the local seeded catalog.
2. Remove secrets/tokens and any personally identifying text fields.
3. Keep payload shape and value ranges representative of the original capture.
4. Add strict expected JSON-path assertions under `expect.json`.

Run replay via scenario S5:

```bash
make test-scenarios
```
