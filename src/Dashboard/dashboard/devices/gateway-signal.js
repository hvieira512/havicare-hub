import {ago, esc} from "../format.js";

/**
 * The last signal each side of a gateway link was heard on.
 *
 * RSSI belongs to the (device, gateway) pair rather than to the device, so it is
 * deliberately not a capability of its own: a device heard by three gateways has
 * three simultaneous values, and one "latest reading" would be a race between
 * them. It travels as `source.rssiDbm` on every uplink, and the hub keeps the
 * last one per pair so both sides of a link can render it -- a sensor's page
 * showing its gateways, and a gateway's page showing its sensors.
 *
 * Deriving it in the browser from the selected device's telemetry was not enough:
 * on a gateway's page that telemetry is the gateway's own, and carries no reading
 * for the sensors it relays.
 */

/** The reading a link row carries, or null when that pair has not been heard. */
export function linkSignal(linked) {
    // Checked before Number(), which turns both null and "" into a very
    // plausible-looking 0 dBm -- the strongest reading there is.
    if (linked?.rssiDbm === null || linked?.rssiDbm === undefined || linked.rssiDbm === "") {
        return null;
    }
    const rssiDbm = Number(linked.rssiDbm);
    if (!Number.isFinite(rssiDbm)) return null;

    return {rssiDbm, at: String(linked.signalSeenAt || "")};
}

/**
 * Signal strength bands, strongest first.
 *
 * `bars` and `tone` both change between neighbouring bands, so the meter never
 * relies on colour alone: a viewer who cannot separate the hues still reads the
 * number of filled bars, and the exact value stays in the tooltip as text.
 * The tones are Bootstrap's reserved status colours rather than chart hues,
 * because this reports a state and not a series.
 */
const SIGNAL_BANDS = [
    {atLeast: -60, label: "Excelente", bars: 4, tone: "success"},
    {atLeast: -67, label: "Bom", bars: 3, tone: "success"},
    {atLeast: -70, label: "Razoável", bars: 3, tone: "warning"},
    {atLeast: -80, label: "Fraco", bars: 2, tone: "warning"},
    {atLeast: -90, label: "Muito fraco", bars: 1, tone: "danger"},
    {atLeast: -Infinity, label: "Inutilizável", bars: 0, tone: "danger"},
];

const NO_SIGNAL = {label: "Sem sinal", bars: 0, tone: "secondary"};

/** The band a reading falls in, or the unheard band when there is none. */
export function signalBand(signal) {
    if (!signal) return NO_SIGNAL;

    return SIGNAL_BANDS.find((band) => signal.rssiDbm >= band.atLeast) || NO_SIGNAL;
}

/** Signal strength as text: the band, the reading, and how stale it is. */
export function signalLabel(signal) {
    const band = signalBand(signal);
    if (!signal) return band.label;

    const parts = [band.label, `${signal.rssiDbm} dBm`];
    // Staleness belongs here too: a strong reading from an hour ago does not
    // mean the device is nearby now.
    if (signal.at) parts.push(ago(signal.at));

    return parts.join(" · ");
}

/**
 * A four-bar meter, with the reading behind a tooltip.
 *
 * Rendered rather than written out because the bars are scannable down a column
 * of links, while the exact dBm only matters once you care about one of them.
 */
export function signalMeter(signal) {
    const band = signalBand(signal);
    const title = signalLabel(signal);
    const bars = [1, 2, 3, 4]
        .map((bar) => `<span class="signal-meter-bar${bar <= band.bars ? " signal-meter-bar-on" : ""}"></span>`)
        .join("");

    return `<span class="signal-meter text-${band.tone}" data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-title="${esc(title)}" aria-label="${esc(title)}" role="img" tabindex="0">${bars}</span>`;
}

/**
 * One row per link, so the pair the RSSI belongs to is visible.
 *
 * Links that were never heard are still listed, showing a dash: the absence of a
 * signal is information, not something to hide.
 *
 * @param {Array<Object>} linkedDevices rows from /api/devices/{imei}
 */
export function gatewaySignalRows(linkedDevices = []) {
    if (!linkedDevices.length) return "";

    return `<ul class="list-unstyled mb-0 small">${linkedDevices
        .map((linked) => {
            const key = String(linked.deviceKey || "").trim().toLowerCase();
            if (!key) return "";
            const model = String(linked.model || "");
            return `<li class="d-flex justify-content-between align-items-center gap-2">
                <span class="text-break font-monospace">${esc(key)}${model ? ` <span class="text-secondary">${esc(model)}</span>` : ""}</span>
                ${signalMeter(linkSignal(linked))}
            </li>`;
        })
        .join("")}</ul>`;
}
