# Proximity: serving per-gateway signal so a client can raise an alarm

**Status:** implemented on the hub side. `ProximityTracker` owns the window;
`Bridge::recordSignal()` publishes per sighting and `Bridge::expireStaleProximity()`
reports silence from the existing `tick()`. Defaults live in `ProximityTracker`
(window 5 s, 10 samples, staleness 30 s) and are constructor arguments — they are
not yet exposed in `config`, which is deliberate until someone needs to change them.
The client side is not built.

**Division of responsibility.** The hub normalizes the radio signal between a
relayed device and each gateway linked to it, and publishes it faithfully. The
alarm — thresholds, what counts as "at the door", who gets called — belongs to the
client. The hub deliberately holds no opinion about danger.

| | Owns |
|---|---|
| **Hub** | one `proximity` message per sighting, per (device, gateway) pair: the raw reading plus window statistics; and reporting when a pair has gone silent |
| **Client** | thresholds (limiares), histerese, tempo de permanência, which gateway is a door, the alarm and its escalation |

Everything below exists because the obvious implementations of this feature fail in
ways that are measurable, and each measurement is recorded here so the decisions can
be re-checked rather than taken on trust.

---

## 1. The measurements this design is based on

All figures from bracelet `fbd87c59ba8b` relayed by MKGW4 `c5e390f30bce`, 2026-08-18.

### 1.1 A raw threshold is unusable

Sixty seconds with the bracelet **motionless on a desk**:

```
n=40   min=-79   max=-64   spread=15 dB   median=-69   stdev=5.1
raw: -66 -77 -77 -69 -69 -69 -66 -78 -69 -66 -69 -65 -77 -68 -65 -69 -77 -68 ...
```

Zone changes produced by each strategy, `limiar` −67 entering / −72 leaving:

| strategy | false alarms in 40 s |
|---|---|
| raw sample, no histerese, no permanência | **18** |
| median of last 5 only | 6 |
| median + histerese | 5 |
| median + histerese + permanência | **0** |

A stationary device would raise ~18 alarms a minute. The three mechanisms each
remove a different failure, and all three are needed.

### 1.2 The median alone misses a fast walk-through

Measured effective sample rate for that pair: **0.67 samples/s** (1.5 s apart).

| pace | time within ~2 m | samples captured |
|---|---|---|
| 1.4 m/s (normal) | 2.9 s | ~2 |
| 2.0 m/s (brisk) | 2.0 s | ~1 |

Injecting a burst of strong samples into the real "far" series:

| strong samples in the pass | median of 5 | max of window |
|---|---|---|
| 1 | −69 → **misses** | −52 → detects |
| 2 | −69 → **misses** | −52 → detects |
| 3 | −52 → detects | −52 → detects |

**A median needs ~3 samples (≈4.5 s in range) to move.** It is right for "is
someone lingering here" and wrong for "someone just went through".

### 1.3 Radio noise is asymmetric, and that decides which statistic to trust

On the stationary series: **+5 dB above the median, −9 dB below.**

Bodies, sleeves and walls attenuate; almost nothing makes a signal stronger than
line of sight. So a **strong** sample is trustworthy while a **weak** one is not.
This inverts the usual "always smooth" advice:

- detecting approach → trust the **maximum**, a single strong sample is real
- judging sustained presence, and clearing an alarm → trust the **median**

### 1.4 The publish path was lossy, and invisibly so

`ObservationStateStore::shouldPublish()` fingerprints `payload['data']` only, and
`rssiDbm` lives in `source`. A sighting whose signal moved 10 dB but whose motion
did not is **suppressed**: 40 sightings produced 31 publishes. The client cannot
distinguish a suppressed sample from a missed advertisement, so any statistic it
computes rests on a series with invisible holes.

This is why `proximity` must bypass that throttle rather than ride along on other
telemetry.

---

## 2. What already works

Verified in production. No further work needed.

| Piece | Where |
|---|---|
| RSSI reaches the hub | `W6rDecoder`, `MonitMecsProDecoder` → `rssiDbm` |
| Honest per-gateway attribution | `source.gatewayId` + per-gateway throttle key |
| Last sighting per pair | `DashboardStoreContract::recordGatewaySighting()`, written before the throttle |
| Authorization | `Bridge::linkedDevice()` — link enabled, same company and licence |
| Continuous beaconing | W6R slot "Stay advertising before triggered" ON → ~40 sightings/min |

---

## 3. The contract

### 3.1 Topic

Published on the **device's** existing topics — never on the gateway's, and never
on `gateway/{mac}/raw`, which is the unfiltered relay (one frame carried 22 entries
including unrelated phones and iBeacons) and sits upstream of the link and tenancy
checks.

```
havicare-hub/{company}/{licenseId}/{deviceType}/{deviceKey}/telemetry
```

One message per **sighting**, per **(device, gateway) pair**. A device heard by
three gateways produces three independent streams.

### 3.2 Payload

```jsonc
{
  "schemaVersion": 2,
  "type": "proximity",
  "occurredAt": "2026-08-18T11:33:16Z",
  "device": { "id": "fbd87c59ba8b", "supplier": "MOKO", "model": "W6R" },
  "data": {
    "gatewayId": "c5e390f30bce",  // inside data: enters the throttle key, and the
                                  // client should not have to read provenance
    "state": "measured",          // measured | unknown
    "rssiDbm": -68,               // this sighting; charts and debugging
    "rssiMaxDbm": -52,            // strongest in window -> detect approach
    "rssiMedianDbm": -69,         // middle of window   -> sustained presence
    "rssiMinDbm": -79,            // weakest in window   -> diagnostics
    "samples": 5,                 // how much the statistics rest on
    "windowSeconds": 5
  },
  "source": { "protocol": "moko-w6r", "gatewayId": "c5e390f30bce", "rssiDbm": -68 }
}
```

### 3.3 Which field to use for what

| Purpose | Field | Why |
|---|---|---|
| Someone approached or passed | **`rssiMaxDbm`** | a 1–2 sample burst is all a brisk walk gives (§1.2); a strong sample is trustworthy (§1.3) |
| Lingering / sustained presence | **`rssiMedianDbm`** | rejects downward spikes |
| Clearing an alarm | **`rssiMedianDbm`** | one weak sample must never clear it |
| Charts, debugging | `rssiDbm`, `rssiMinDbm` | — |

**`samples` is a confidence gate.** A median or max built from 1–2 samples is
barely better than a raw reading; that is the state right after silence or when a
device first comes into range. Require at least 3 before acting, and treat fewer as
`unknown` — otherwise the first sample after a device reappears can raise an alarm
on its own.

### 3.4 Silence: `state: "unknown"`

After `stalenessSeconds` (default 30) with no sighting for a pair, the hub publishes
once:

```jsonc
{ "type": "proximity", "data": { "gatewayId": "c5e390f30bce", "state": "unknown", "samples": 0 } }
```

This is the only stateful thing the hub does, and it is liveness rather than policy:
absence cannot be pushed over MQTT, so a client that receives nothing has nothing to
react to. Only a server-side clock can report it, and the hub already runs one
(`expireStaleDevices`).

**`unknown` is not `far`.** It means the hub no longer knows. Out of range, a dead
battery, a gateway offline and a misconfigured filter are indistinguishable from
each other and from "nobody there".

### 3.5 Window

Short by design: `windowSeconds` default **5**, holding at most 10 samples. §1.2 is
the reason — a long window cannot resolve a walk-through. The window is a hub
implementation detail reported in the payload, not a tuning knob for alarms.

### 3.6 Rate and history

- `proximity` **bypasses the fingerprint throttle** (§1.4). Every sighting is
  published, so the client's series has no invisible gaps.
- `proximity` is **not appended to device history**. `Bridge` writes every other
  telemetry into the device's Redis list; at ~40/min/pair that would flood both the
  history and the dashboard's telemetry table.

---

## 4. Client guidance

Not requirements — the hub does not enforce these — but the arithmetic in §1 is
what it costs to ignore them.

1. **Two limiares, not one** (histerese). Enter on `rssiMaxDbm >= enterDbm`; leave
   only when `rssiMedianDbm < exitDbm`. A single threshold flickers (§1.1).
2. **Require permanência before *clearing***, not before firing. For a safety alarm,
   fire on the first credible strong reading — a brisk pass gives you one sample —
   and require several quiet seconds before standing down. Asymmetric on purpose:
   a missed escape is worse than an extra notification.
3. **Gate on `samples >= 3`.**
4. **Treat `state: "unknown"` as unmonitored, never as safe.**
5. **Keep state per (device, gateway) pair.** Never collapse to "nearest gateway":
   a device can legitimately be close to one door and far from another, and one
   door's reading must not overwrite another's.
6. **Do not convert to metres.** The spread on a *motionless* device was 15 dB;
   body blocking alone costs 10–20 dB. A distance figure invites comparison against
   a number that does not mean anything.

---

## 5. Failure modes

| What happened | Client sees | Must assume |
|---|---|---|
| Device out of range | `state: unknown` after `stalenessSeconds` | position unknown — **not** safe |
| Device battery dead | `state: unknown` | as above; correlate with `battery` |
| Gateway offline | `state: unknown` for all its devices | the door is unmonitored |
| Gateway RSSI filter stricter than the alarm limiar | device silently never appears | monitoring broken, not "nobody there" |
| Link disabled or tenancy mismatch | nothing published at all | monitoring off |
| Tag beaconing only on trigger | readings only after a button press | not trackable — see §6 |

**Silence is never safety.** A missing signal escalates to unknown; it never decays
to far.

The hub records each gateway's configured RSSI filter, so it can warn when a
gateway's filter is stricter than the range it is expected to report — a
configuration that cannot work.

---

## 6. Operational preconditions

The feature depends on device configuration the hub does not control. Each of these
was found by having it silently break something.

| Setting | Required | Why |
|---|---|---|
| Gateway scan mode (`0x2040`) | `1` real-time & immediate | periodic modes have a 600 s minimum report interval |
| Gateway RSSI filter (`0x2051`) | permissive, e.g. `−127` | a `−26` filter made every device in the building invisible |
| Gateway filtration logic (`0x2050`) | `Only ADV Name` | the MAC filter caps at 10 devices per gateway; the name filter scales and cuts uplink volume |
| W6R "Stay advertising before triggered" | ON | otherwise the tag is radio-silent until pressed |
| W6R `Adv interval` | as short as battery allows | **this sets the detection limit.** At 1 s the effective rate was 0.67 samples/s, so a 2 s walk-through yields ~1 sample. No hub-side maths recovers a sample never transmitted. |

With the filter wide open one MKGW4 relayed ~101 MAC addresses and pushed
**~9.5 MiB/hour** of cellular data. The ADV-name filter is what keeps that bounded.

---

## 7. Out of scope

- **Zones, thresholds and alarms in the hub.** Client policy (see the table at the
  top).
- **Distance in metres.** §4.6.
- **Presence fusion across gateways** (triangulation, "which room"). Different
  problem; per-pair readings are the primitive it would build on.

---

## 8. Acceptance criteria

1. Every sighting of a linked, in-range device yields exactly one `proximity`
   publish for that pair — sighting count equals publish count, no throttle gaps.
2. Two gateways hearing one device produce two streams whose `data.gatewayId`
   differ, neither suppressing the other.
3. `rssiMaxDbm`, `rssiMedianDbm`, `rssiMinDbm` and `samples` are consistent with the
   last `windowSeconds` of readings for that pair.
4. A pair with no sighting for `stalenessSeconds` receives exactly one
   `state: "unknown"` publish, and no further ones until it is heard again.
5. `proximity` never appears in the device's telemetry history list.
6. A single strong sample surrounded by weak ones raises `rssiMaxDbm` while leaving
   `rssiMedianDbm` unchanged — the property §1.2 depends on.
