Below is the practical decoder for device→server messages on that page.

### Common frame

```text
[3G*DEVICE_ID*LEN*CONTENT]
```

`DEVICE_ID` is 10 digits from the IMEI. `LEN` is 4 ASCII hex digits for the byte/character length of `CONTENT`; e.g. `000D` = 13. Commands are ASCII inside TCP socket frames. ([4p-touch.com][1])

---

## 1. `LK` — heartbeat / link keep

```text
[3G*0304187109*0009*LK,0,0,21]
[3G*8800000015*000D*LK,50,100,100]
```

Decode:

```text
LK,
steps,
sleep/tumbling_count,
battery_percent
```

Example:

```json
{
  "type": "LK",
  "device_id": "8800000015",
  "steps": 50,
  "tumbling_count": 100,
  "battery_percent": 100
}
```

Server must reply:

```text
[3G*8800000015*0002*LK]
```

The device sends `LK` every minute at first, then every 5–8 minutes once connected. ([4p-touch.com][2])

---

## 2. `FFZDPAYH5` — opaque message

```text
[3G*0304187109*0009*FFZDPAYH5]
```

The guide labels this only as “message” and does not decode the payload. Treat as an unknown/opaque device message. ([4p-touch.com][2])

---

## 3. `UD_LTE` — 4G position report

```text
[3G*0304187109*0120*UD_LTE,310120,184347,V,0.0,N,0.0,E,22.0,0,-1,0,100,11,0,0,00000001,1,1,334,020,13011,23152151,100,5,...]
```

Decode:

```text
UD_LTE,
date_ddmmyy,
time_hhmmss_utc,
gps_valid_A_or_V,
latitude,
N_or_S,
longitude,
E_or_W,
speed,
direction,
altitude,
satellites,
gsm_signal_percent,
battery_percent,
steps,
tumbling_count,
status_alarm_hex,
base_station_count,
network_type_or_lbs_marker,
mcc,
mnc,
lac_or_tac,
cell_id,
cell_signal,
wifi_count,
wifi_ssid_1, wifi_bssid_1, wifi_rssi_1,
...
accuracy_m
```

Important: `A` means GPS fix is valid; `V` means GPS is invalid, so use LBS/Wi-Fi data instead. The guide says the device may send multiple `UD` strings after a request, but only `A` positions should be trusted as valid GPS. ([4p-touch.com][2])

Example decoded:

```json
{
  "type": "UD_LTE",
  "date": "31-01-2020",
  "time_utc": "18:43:47",
  "gps_valid": false,
  "lat": 0.0,
  "lat_dir": "N",
  "lon": 0.0,
  "lon_dir": "E",
  "altitude": 22.0,
  "satellites": 0,
  "gsm_signal": 100,
  "battery_percent": 11,
  "steps": 0,
  "tumbling_count": 0,
  "status_alarm": "00000001",
  "mcc": "334",
  "mnc": "020",
  "cell_id": "23152151",
  "wifi_count": 5
}
```

No server reply is required for normal `UD`/position reports. ([4p-touch.com][1])

---

## 4. `AL_LTE` — 4G alarm / SOS / fall / low battery

Same field layout as `UD_LTE`, but the command is `AL_LTE`.

```text
[3G*0304187109*0120*AL_LTE,310120,184251,V,0.0,N,0.0,E,22.0,0,-1,0,100,11,0,0,00010001,...]
```

Decode the position fields exactly like `UD_LTE`. The main difference is the `status_alarm_hex` field.

Alarm bit examples:

```text
00010000 = SOS alarm
00020000 = low battery alarm
00030000 = SOS + low battery
00200000 = fall-down alarm
```

The alarm/status field is 8 hex characters = 32 bits. The guide says lower 16 bits are status and higher 16 bits are alarms. ([4p-touch.com][2])

Server should acknowledge alarm:

```text
[3G*DEVICE_ID*0006*AL_LTE]
```

or for older `AL`:

```text
[3G*DEVICE_ID*0002*AL]
```

The protocol page says alarms repeat until the server confirms them. ([4p-touch.com][1])

---

## 5. `bphrt` — blood pressure + heart rate report

```text
[3G*8800000015*0013*bphrt,112,73,67,,,,]
```

Decode:

```text
bphrt,
systolic_bp,
diastolic_bp,
heart_rate,
height_cm,
gender,
age,
weight_kg
```

`0` means invalid for BP/HR fields. Gender: `1` male, `2` female. ([4p-touch.com][2])

Example:

```json
{
  "type": "bphrt",
  "systolic": 112,
  "diastolic": 73,
  "heart_rate": 67,
  "height_cm": null,
  "gender": null,
  "age": null,
  "weight_kg": null
}
```

Server reply:

```text
[3G*8800000015*0005*bphrt]
```

---

## 6. `CONFIG` — device configuration report

```text
[3G*7703713643*0008*CONFIG,1]
```

or fuller form:

```text
[3G*8800000015*len*CONFIG,TY:G75,UL:600,SY:1,CM:1]
```

Decode known keys:

```text
TY = device type
UL = upload interval
SY = education config
CM = remote selfie
```

Server reply:

```text
[3G*DEVICE_ID*LEN*CONFIG,1]
```

where `1` = OK, `0` = fail. ([4p-touch.com][2])

---

## 7. Other possible device-originating commands

The guide says 4G devices may send additional phone-feature-related commands, and if they are not needed, reply to stop repeated sending or ignore them. Examples listed: ([4p-touch.com][2])

```text
[3G*7103000140*0005*ICCID]
[3G*7103000140*0006*RYIMEI]
[3G*7103000140*000D*APPCONTACTTEL]
```

Treat these as device metadata / configuration probes unless you have the paid/full protocol.

[1]: https://www.4p-touch.com/beesure-gps-setracker-server-protocol.html "Beesure GPS SeTracker Server Protocol - Shenzhen Yushengchang Technology Co.,LTD"
[2]: https://www.4p-touch.com/server-portal-configuration-guide.html "Server Portal Configuration Guide - Shenzhen Yushengchang Technology Co.,LTD"

