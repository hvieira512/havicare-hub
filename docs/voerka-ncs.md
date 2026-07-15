# Voerka NCS MQTT contract

This document describes the normalized contract used by the hub for Voerka W812 NCS gateways.

## Upstream topics

The hub subscribes to:

- `/voerka/#`

The firmware publishes at least these message families:

- `/voerka/<scope>/devices/<gatewayId>/status/online`
- `/voerka/<scope>/devices/<gatewayId>/events`

## Normalized MQTT topics

The hub republishes to:

- `havicare-hub-dev/{licenseId}/ncs/{deviceId}/raw`
- `havicare-hub-dev/{licenseId}/ncs/{deviceId}/status`
- `havicare-hub-dev/{licenseId}/ncs/{deviceId}/events`

There is no NCS telemetry topic for the current implementation.

## Status contract

`status/online` is normalized into a status message with:

- `state: online` or `state: offline`
- `device` metadata

No `data` object is used in the normalized status payload.

The hub also emits the device lifecycle event:

- `device.connected` when `online` is `true`
- `device.disconnected` when `online` is `false`

## Event contract

Button events are normalized into `events` with flat types:

- `help_call`
- `reset`

Normalized event payloads include:

- `schemaVersion`
- `type`
- `occurredAt`
- `device`
- `data.pagerId` when present

Mapping details:

- firmware key `8` becomes `help_call`
- firmware keys `0`, `1`, and `2` become `reset`

If the firmware emits a button key that is not mapped, the hub keeps only the `raw` payload and discards the normalized event.

## Generic capability

The generic capability exposed for NCS is:

- `pager_call` in `alarms` with the display label `Chamada de enfermagem`

It is associated with the Voerka `W812` model and is used as catalog metadata for model/supplier capability discovery.

## Raw contract

The `raw` topic keeps the original upstream payload for audit/debug purposes.

It includes:

- the original upstream topic
- the source scope and message kind
- the decoded upstream payload

## Notes

- `key` is the button key string coming from the firmware.
- `type`, `code`, `level`, `result`, and `progress` are source fields, not the normalized business contract.
- The firmware may include a `location` object in event payloads, but the hub does not turn it into location telemetry.
- The topic namespace already identifies the NCS family, so the normalized `type` stays flat instead of using dotted prefixes.
