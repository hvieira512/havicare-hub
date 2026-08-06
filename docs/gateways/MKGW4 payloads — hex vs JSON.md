# MKGW4 Gateway payloads — HEX vs JSON

Findings from live-capturing the Minew MKGW4 gateways on the `health-hub` MQTT broker.

Both gateways publish to a topic named `havicare-hub/null/0/gw/<mac>/raw` (suffix `/raw`), but each
gateway serializes its payload differently:

| Gateway (MAC)      | Payload format      | msg_id 3004 (heartbeat)      | msg_id 3070 (BLE scan) |
|--------------------|---------------------|------------------------------|------------------------|
| `c5:e3:90:f3:0b:ce`| Binary HEX frame    | ✓                            | not observed           |
| `d4:8c:49:f7:90:9c`| JSON (UTF-8)        | ✓ (heartbeat only)           | ✓                      |

---

## 1. HEX gateway (`c5e390f30bce`)

### 1.1 Frame anatomy

Topic: `havicare-hub/null/0/gw/c5e390f30bce/raw`

The payload is a raw byte frame, hex-encoded as received. Observed layout:

```
ef 30 04 c5 e3 90 f3 0b ce  ...device fields...
│  │  │  └─ 6-byte gateway MAC
│  │  └──── msg_id (e.g. 3004 = status/heartbeat, 3089 = GPS/LBS)
│  └─────── msg_id high byte
└────────── frame start byte
```

### 1.2 msg_id 3004 — status / heartbeat

Decoded by the vendor script `docs/gateways/MKGW4-V2.js` (option "status"). Verified against
17 live captures (heartbeat index 955 → 971):

- **Network type / operator**: `FDD LTE`
- **CSQ (signal)**: ~17–23
- **Battery**: ~4192–4198 mV
- **IMEI**: `861076082232511`
- **Field structure**: TLV-like `id, len, value` fields (e.g. `07 07 "FDD LTE"`, `06 0f <IMEI>`).

> **Vendor script bug**: the `operator` field always decodes empty because
> `deviceDataArray.slice(deviceDataIndex, paramLength)` (MKGW4-V2.js:1538 and :1973) omits the
> `+ deviceDataIndex` offset, so the slice starts at 0 instead of the field position.

### 1.3 msg_id 3089 — GPS / LBS fix

- **Fix type**: LBS (cellular, not satellite GPS)
- **tacLac** = 48820, **ci (cell id)** = 677940

### 1.4 Using the decoder in MQTTX

Root cause of the earlier "garbage output": MQTTX runs the script against the payload in the
format selected under **"Received payload decoded by"**. When set to the default **Plaintext**,
MQTTX feeds the script the UTF-8-mojibake of the raw bytes, which cannot be parsed as hex.

**Fix (UI setting, no code change):** in MQTTX set the received payload format to **HEX**. The
script then receives the hex string and `handlePayload` decodes correctly.

---

## 2. JSON gateway (`d48c49f7909c`)

Topic: `havicare-hub/null/0/gw/d48c49f7909c/raw` — the payload is a UTF-8 JSON object.

### 2.1 Envelope

Every message has the shape:

```json
{ "msg_id": 3004, "device_info": { "mac": "d48c49f7909c" }, "data": ... }
```

Observed `msg_id`s: **3004** (gateway heartbeat, 237×) and **3070** (BLE scan, 3371× in the capture).

### 2.2 msg_id 3004 — gateway heartbeat

```json
{ "msg_id": 3004,
  "device_info": { "mac": "d48c49f7909c" },
  "data": { "timestamp": 0, "timezone": 0, "net_interface": 1, "wifi_rssi": -54 } }
```

Notes:
- `net_interface` 1 = WiFi; `wifi_rssi` in dBm.
- `timestamp`/`timezone` were 0 in all captured heartbeats.

### 2.3 msg_id 3070 — BLE scan

`data` is an array of up to 6 BLE advertisement events:

```json
{ "msg_id": 3070,
  "device_info": { "mac": "d48c49f7909c" },
  "data": [
    { "timestamp": 1246, "timezone": 0,
      "adv_data": "0201061afff70455...",
      "rsp_data": "06094a41425834",
      "type_code": 10, "type": "other",
      "rssi": -82, "connectable": 0, "mac": "080020000a04" },
    ...
  ]}
```

Event schema:

| Field          | Meaning                                                              |
|----------------|----------------------------------------------------------------------|
| `timestamp`    | Uptime (seconds) — unless the gateway has a synced clock, then epoch ms |
| `timezone`     | Seconds offset from UTC (0 in capture)                               |
| `adv_data`     | Hex of the advertising PDU AD structures (only present for `type_code` 10) |
| `rsp_data`     | Hex of the scan-response AD structures (only present for `type_code` 10) |
| `type_code`    | Gateway-decoded type id (see 2.4)                                    |
| `type`         | Human-readable type name                                             |
| `rssi`         | dBm                                                                  |
| `connectable`  | 0 / 1                                                                |
| `mac`          | Scanned device MAC (12 hex chars, no separator)                      |

### 2.4 Pre-decoded types (no raw bytes)

The gateway decodes these types itself — `adv_data`/`rsp_data` are **absent** for them:

| type_code | type             | Notes |
|-----------|------------------|-------|
| 0         | `ibeacon`        | uuid / major / minor / rssi_1m |
| 1         | `eddystone-uid`  | |
| 2         | `eddystone-url`  | |
| 3         | `eddystone-tlm`  | batt_vol / temperature / adv_count / runtime |
| 5         | `bxp-acc`        | |
| 8         | `bxp-tag`        | |
| 9         | `pir`            | door_status / sensitivity / detection_status / batt_vol / adv_name (e.g. `MkP167F...`) |

Everything else falls through as `type_code` 10 `"other"` and exposes the raw AD structures.

### 2.5 Type distribution in the capture

| type_code | type            | count |
|-----------|-----------------|------:|
| 10        | other           | 2595  |
| 0         | ibeacon         | 97    |
| 3         | eddystone-tlm   | 20    |
| 1         | eddystone-uid   | 16    |
| 9         | pir             | 10    |
| 5         | bxp-acc         | 3     |
| 2         | eddystone-url   | 2     |
| 8         | bxp-tag         | 1     |

### 2.6 Examples of raw AD decoding (type_code 10)

Sample devices seen in the scan (distinct MACs: 309):

- **Jabra**: `rsp_data 06094a41425834` → name `JABX4`, mfg company `0x04F7`.
- **Sony WH-1000XM4/XM5**: service UUID `0xfe03` + mfg `0x012d`.
- **Apple**: mfg `0x004c`, subtypes `0x10 / 0x0f / 0x05`.
- **MKGW4-F1F7**: another MKGW4 reporting its own name + firmware `V2.0.3` via service data `0xaa11`.
- **GR551-a7be**: service `0xfbc0`.
- **E2 Pro 2007**: mfg `0x424e`.

---

## 3. MONIT MECS PRO diaper sensor (`ee:c5:00:02:02:f9`)

Appears 16× in the 3070 captures as a non-connectable (`connectable: 0`) device, RSSI ≈ −83…−97 dBm.

Sample entry:

```json
{ "timestamp": 1017, "timezone": 0,
  "adv_data": "0201041aff5900021535c80410418015dc8200410418415dc8200202f9c3",
  "rsp_data": "0f094d4f4e4954204d4543532050524f",
  "type_code": 10, "type": "other", "rssi": -83, "connectable": 0,
  "mac": "eec5000202f9" }
```

### 3.1 rsp_data — device name

`0f 09 4d 4f 4e 49 54 20 4d 45 43 53 20 50 52 4f`
→ AD type `0x09` (Complete Local Name), len 15 → **`MONIT MECS PRO`**.

### 3.2 adv_data — manufacturer data

```
02 01 04                     → AD Flags = 0x04 (BR/EDR not supported, LE only)
1a ff 59 00 02 15 36 08 04 10 41 80 15 dc 82 00 41 04 18 41 5d c8 20 02 02 f9 c3
└──┬──┘ └──┬──┘ └──────────────────────────────────────────────────────────────┘
 len26   company 0x0059    MECSPro raw20 payload (20 bytes) + tx byte
         (Nordic)
```

Company ID `59 00` = **0x0059 (Nordic Semiconductor)** — the nRF52 SDK default. The 20-byte payload
after `02 15` is the "Raw20" packet, followed by 1 tx-power byte (`c3` = −61 dBm). The last 3
payload bytes mirror the tail of the device MAC (`02 02 f9`).

### 3.3 Raw20 bit layout (per "Monit - BLE decode.docx")

The 20 bytes are parsed as one continuous **160-bit stream, MSB-first**:

| Bit offset | Length | Field            | Notes |
|------------|--------|------------------|-------|
| 0–2        | 3      | Packet Type      | 0–7 (type 1 in capture) |
| 3–9        | 7      | Battery          | 0–127, used directly as % |
| 10         | 1      | Alarm Type       | 0–1 |
| 11–12      | 2      | TX Strength      | 0–3 |
| 13–15      | 3      | Event Status     | 0–7 |
| 16–75      | 60     | Baseline values  | 10 × 6 bits |
| 76–135     | 60     | Raw sensor values| 10 × 6 bits |
| 136–159    | 24     | MAC suffix       | last 3 bytes of MAC |

6-bit sensor values cross byte boundaries — must be parsed bitwise, not bytewise.

**Header byte mapping:**

- Byte 0: bits 7–5 Packet Type; bits 4–0 Battery bits 6–2
- Byte 1: bits 7–6 Battery bits 1–0; bit 5 Alarm Type; bits 4–3 TX Strength; bits 2–0 Event Status

### 3.4 Decoded capture (both observed packets)

```
packetType = 1   battery = 88 (pkt A) / 87 (pkt B)   alarm = 0
txStrength = 1   eventStatus = 0

baseline   = 01 01 01 01 32 01 23 28 32 32
raw        = 01 01 01 01 33 01 23 28 32 32
normalized = 00 00 00 00 01 00 00 00 00 00     (max(raw−baseline, 0))
```

MAC suffix `02 02 f9` → full MAC **`EE:C5:00:02:02:F9`** (fixed prefix `EE:C5:00`), matching the
scan MAC exactly.

The only difference between the two packets was the battery bits (88 → 87). There was **no**
sensor-state change between them.

### 3.5 Sensor interpretation

- `Normalized[i] = max(Raw[i] − Baseline[i], 0)`.
- Default app settings: required channel count **4**, sensor threshold **12**.
- Status 2 (clean): all 10 normalized < 4.
- Status 1: count(channels ≥ threshold) ≥ required count.
- Status 0: anything else.

With normalized values all 0–1, both captured packets classify as **Status 2 = clean/dry**. The
packet contains no explicit humidity value; "soiled" is derived from channels deviating from their
baseline. `PollutionRange` (2–10) and `PollutionValue` (5–25) are configurable; presets: More
alerts (3/7), Normal (4/12), Fewer (7/15).

---

## 4. Reference data used

- `/tmp/mk_hex.log` — hex capture of `c5e390f30bce` (83× msg 3004, 1× msg 3089).
- `/tmp/mk_json.log` — JSON capture of `d48c49f7909c` (3608 messages: 237× 3004, 3371× 3070).
- `/tmp/mk_ble_decode.js` — AD-structure decoder + distinct-device report.
- `/tmp/mecspro.js` — bit-level MECSPro Raw20 decoder.
- Vendor decoder: `docs/gateways/MKGW4-V2.js` (has the `operator` slice bug, see 1.2).
