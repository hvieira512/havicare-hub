import {ago, esc, rowPayload} from "../format.js";

/**
 * The last signal each gateway reported for a relayed device.
 *
 * RSSI belongs to the (device, gateway) pair rather than to the device, so it is
 * deliberately not a capability of its own: a device heard by three gateways has
 * three simultaneous values, and one "latest reading" would be a race between
 * them. It travels as `source.rssiDbm` on every uplink instead, which also keeps
 * it out of the publish fingerprint -- a value that changes on every sighting
 * would otherwise defeat the telemetry throttle entirely.
 *
 * Derived from the telemetry the detail view already loaded, so this costs no
 * extra request and no extra storage. A gateway that has not reported inside
 * that window simply has no entry, which is honest: stale is unknown, not near.
 *
 * ponytail: reads the sensor's own telemetry, so it only fills in on a relayed
 * device's page. Viewing a gateway lists its sensors with no signal, because the
 * readings live on each sensor. Persist the last sighting per (device, gateway)
 * pair server-side if the gateway's own page needs to show them too.
 *
 * @param {Array} recentTelemetry rows as served by /api/devices/{imei}
 * @returns {Map<string, {rssiDbm: number, at: string}>} keyed by gateway MAC
 */
export function gatewaySignals(recentTelemetry = []) {
    const signals = new Map();
    for (const row of recentTelemetry || []) {
        const payload = rowPayload(row);
        const source = payload?.source;
        const gatewayId = String(source?.gatewayId || "").trim().toLowerCase();
        const rssiDbm = Number(source?.rssiDbm);
        if (!gatewayId || !Number.isFinite(rssiDbm)) continue;
        const at = String(payload?.occurredAt || "");
        const previous = signals.get(gatewayId);
        // Rows arrive newest first or oldest first depending on the list, so
        // compare rather than trust the order. ISO-8601 sorts lexicographically.
        if (!previous || at > previous.at) signals.set(gatewayId, {rssiDbm, at});
    }
    return signals;
}

/** Signal strength as text, or a dash when this gateway has not been heard. */
export function signalLabel(signal) {
    if (!signal) return "—";
    return `${signal.rssiDbm} dBm · ${ago(signal.at)}`;
}

/**
 * One row per linked gateway, so the pair the RSSI belongs to is visible.
 *
 * @param {Array<Object>} linkedDevices rows from the device detail
 * @param {Map<string, {rssiDbm: number, at: string}>} signals
 */
export function gatewaySignalRows(linkedDevices = [], signals = new Map()) {
    if (!linkedDevices.length) return "";

    return `<ul class="list-unstyled mb-0 small">${linkedDevices
        .map((linked) => {
            const key = String(linked.deviceKey || "").trim().toLowerCase();
            if (!key) return "";
            const signal = signals.get(key);
            const model = String(linked.model || "");
            return `<li class="d-flex justify-content-between align-items-baseline gap-2">
                <span class="text-break font-monospace">${esc(key)}${model ? ` <span class="text-secondary">${esc(model)}</span>` : ""}</span>
                <span class="${signal ? "text-body" : "text-secondary"} text-nowrap">${esc(signalLabel(signal))}</span>
            </li>`;
        })
        .join("")}</ul>`;
}
