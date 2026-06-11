For device uplinks, the hub publishes a JSON envelope to:

```text
hitecosystem-hub/devices/{imei}/uplink
```

Example:

```json
{
  "event": {
    "type": "device.raw.uplink",
    "id": "raw_..."
  },
  "occurredAt": "2026-06-09T14:30:00Z",
  "device": {
    "imei": "8800000015"
  },
  "transport": "tcp",
  "protocol": "four-p-touch",
  "encoding": "base64",
  "payload": "WzNHKjg4MDAwMDAwMTUqMDAwRCpMSyw1MCwxMDAsMTAwXQ==",
  "size": 34,
  "connectionId": "1000000",
  "text": "[3G*8800000015*000D*LK,50,100,100]"
}
```

Key fields:

```text
event.type    = device.raw.uplink
device.imei   = device identifier
transport     = tcp or websocket
protocol      = vivistar-iw, wonlex-json, four-p-touch
encoding      = base64
payload       = raw device bytes, base64 encoded
text          = raw text version, only if valid text
size          = byte length
connectionId  = hub connection id
occurredAt    = UTC timestamp
```

For status events, the hub publishes to:

```text
hitecosystem-hub/devices/{imei}/status
```

Example online status:

```json
{
  "event": {
    "type": "device.status.changed",
    "id": "raw_..."
  },
  "occurredAt": "2026-06-09T14:30:00Z",
  "device": {
    "imei": "8800000015"
  },
  "transport": "tcp",
  "protocol": "four-p-touch",
  "connectionId": "1000000",
  "data": {
    "state": "online"
  }
}
```

Example downlink sent status:

```json
{
  "event": {
    "type": "device.downlink.sent",
    "id": "raw_..."
  },
  "occurredAt": "2026-06-09T14:30:00Z",
  "device": {
    "imei": "8800000015"
  },
  "transport": "tcp",
  "protocol": "four-p-touch"
}
```

For auth errors, the hub publishes to:

```text
hitecosystem-hub/devices/{imei}/error
```

Example:

```json
{
  "event": {
    "type": "device.auth.rejected",
    "id": "raw_..."
  },
  "occurredAt": "2026-06-09T14:30:00Z",
  "device": {
    "imei": "8800000015"
  },
  "protocol": "four-p-touch",
  "reason": "device_not_authorized"
}
```

The important bit: the hub is currently a **raw bridge**, not a normalizer. It does not convert 4P Touch fields like `LK,50,100,100` into semantic JSON. It preserves the original payload as base64 and optionally includes `text` for readability.
