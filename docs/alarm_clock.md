# `alarm_clock` API contract

The API uses two layers:

- `capabilities` describes what the model supports, whether or not the device has saved configuration rows.
- `configurations` describes what is currently stored for a specific device.

For `alarm_clock`, the public contract is generic. The client should not care whether the backend maps it to `reminders`, `alarmClock`, `BP85`, or `REMIND`.

## `GET /api/devices/{imei}`

Use the device detail response to build the UI:

- `device` and `model` identify the selected device.
- `capabilities` tell you which sections and fields the model supports.
- `configurations` pre-fill the form with the saved values when present.

Relevant sections for watches:

- `capabilities.telemetry`
- `capabilities.health`
- `capabilities.contacts`
- `capabilities.alarms`
- `capabilities.settings_system`

### Example

```json
{
  "device": {
    "imei": "861265061009822",
    "company": "hitcare"
  },
  "model": {
    "supplier": "Vivistar",
    "internalModel": "L08 Pro",
    "deviceType": "watch"
  },
  "capabilities": {
    "telemetry": {
      "heart_rate": { "supported": true, "requestable": true },
      "battery": { "supported": true, "requestable": false }
    },
    "alarms": {
      "alarm_clock": {
        "value": [
          {
            "time": "08:10",
            "enabled": true,
            "type": 2,
            "recurrence": {
              "kind": "custom",
              "days": [1, 3, 5]
            },
            "days": [1, 3, 5],
            "custom": "135"
          }
        ],
        "_meta": {
          "limit": 3,
          "type": {
            "options": [
              { "value": 1, "label": "Medicação" },
              { "value": 2, "label": "Água" },
              { "value": 3, "label": "Sedentarismo" }
            ]
          },
          "recurrence": {
            "options": [
              { "value": "once", "label": "Uma vez" },
              { "value": "daily", "label": "Todos os dias" },
              { "value": "custom", "label": "Personalizado" }
            ]
          }
        }
      }
    }
  },
  "configurations": {
    "alarm_clock": {
      "items": [
        {
          "time": "08:10",
          "enabled": true,
          "type": 2,
          "recurrence": {
            "kind": "custom",
            "days": [1, 3, 5]
          }
        }
      ]
    }
  }
}
```

### Interpretation rules

- `capabilities.telemetry.*.supported === true`
  - show the telemetry card
- `capabilities.telemetry.*.requestable === true`
  - show the `Pedir` button
- `capabilities.telemetry.*.requestable === false`
  - show the telemetry card, but no button
- `capabilities.alarms.alarm_clock.value`
  - use this shape to render the editor
- `capabilities.alarms.alarm_clock._meta.limit`
  - maximum number of alarms
- `capabilities.alarms.alarm_clock._meta.*.options`
  - render selectors for recurrence and type
- `configurations.alarm_clock.items`
  - current saved values for the form

`_meta` is read-only metadata. The client uses it to build the editor, but does not send it back.

If a capability is supported by the model, it should appear in `capabilities` even when `configurations` is empty.

## `alarm_clock` UI rules

For watches, each alarm item can render:

- `time`
- `enabled`
- `type` when present in the model response
- `recurrence.kind`
- `recurrence.days` when `kind === "custom"`
- `days` and `custom` may also appear for Vivistar compatibility

### Vivistar

Vivistar exposes the richer contract:

- `type` is meaningful
- `type` is required on `PATCH`
- supported labels are:
  - Medicação
  - Água
  - Sedentarismo

### 4P Touch

4P Touch uses the same generic capability name:

- `alarm_clock`

But the public shape is simpler:

- `value` exists
- `type` is not part of the public shape
- recurrence is the important part
- `PATCH` must not include `type`

## `PATCH /api/devices/{imei}/configurations`

Send only the configurations you want to change, grouped under `configurations`.

For `alarm_clock`, send `items` and modify the values from `GET`.

### Example request

```json
{
  "configurations": {
    "alarm_clock": {
      "items": [
        {
          "time": "08:10",
          "enabled": true,
          "type": 2,
          "recurrence": {
            "kind": "custom",
            "days": [1, 3, 5]
          }
        },
        {
          "time": "20:00",
          "enabled": false,
          "type": 1,
          "recurrence": {
            "kind": "daily"
          }
        }
      ]
    }
  }
}
```

### Client rules

- Use the generic key `alarm_clock`
- Use `items` as the list container
- Send the current fields exposed by `GET`
- Send only user-editable values
- Do not send `_meta`
- Do not send supplier-native names like `BP85`, `REMIND`, or `alarmClock`
- Use `type` only for Vivistar

## PATCH response

The `PATCH` response confirms what was accepted and queued:

- `status`
- `results`
- `configurations`
- `pending`
- `transportPending`

Example:

```json
{
  "status": "ok",
  "results": [
    {
      "status": "queued",
      "key": "reminders",
      "command": "BP85",
      "id": "4f90052bb6f5f374"
    }
  ],
  "configurations": {
    "alarm_clock": {
      "items": [
        {
          "time": "11:11",
          "enabled": true,
          "type": 2,
          "recurrence": {
            "kind": "once"
          }
        }
      ]
    }
  },
  "pending": {
    "alarms": {
      "alarm_clock": {
        "status": "waiting_device",
        "desired": {
          "items": [
            {
              "time": "11:11",
              "enabled": true,
              "type": 2,
              "recurrence": {
                "kind": "once"
              }
            }
          ]
        }
      }
    }
  },
  "transportPending": []
}
```

### Meaning

- `results[*].key` is supplier-native and may be `reminders` or `alarmClock`
- `configurations` is the normalized state shown back in the UI
- `pending` shows whether the device has acknowledged the change
- `transportPending` is for queued transport-level commands

## Practical flow

1. Call `GET /api/devices/{imei}`
2. Render the device header from `device` and `model`
3. Render telemetry cards from `capabilities.telemetry`
4. Render configuration forms from `capabilities.alarms`, `capabilities.contacts`, `capabilities.health`, and `capabilities.settings_system`
5. Pre-fill the alarm editor from `configurations.alarm_clock.items`
6. If the user changes alarms, send `PATCH /api/devices/{imei}/configurations`
7. Refresh the device detail and use `pending` to show whether the update is still waiting on the device

## Key rule

The client should always speak generic capability names:

- `alarm_clock`
- `phonebook` for 4P Touch only
- `call_whitelist`
- `sos_contacts`
- `battery`
- `heart_rate`

The backend maps those to the supplier protocol internally.
