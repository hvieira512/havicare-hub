import { ago } from "../../format.js";
import { html } from "../../html.js";
import { normalizeKey } from "./gateway-links.js";

/** O último sinal de cada ligação a um gateway: o RSSI é do par, e viaja em `source.rssiDbm`. */

/** Null quando esse par nunca foi ouvido. */
export function linkSignal(linked) {
    // Antes do `Number()`, que faria do null e do "" um 0 dBm, a leitura mais forte que há.
    if (linked?.rssiDbm === null || linked?.rssiDbm === undefined || linked.rssiDbm === "") {
        return null;
    }
    const rssiDbm = Number(linked.rssiDbm);
    if (!Number.isFinite(rssiDbm)) return null;

    return { rssiDbm, at: String(linked.signalSeenAt || "") };
}

/** A mais forte primeiro; `bars` e `tone` mudam juntos, para o medidor não depender só da cor. */
const SIGNAL_BANDS = [
    { atLeast: -60, label: "Excelente", bars: 4, tone: "success" },
    { atLeast: -67, label: "Bom", bars: 3, tone: "success" },
    { atLeast: -70, label: "Razoável", bars: 3, tone: "warning" },
    { atLeast: -80, label: "Fraco", bars: 2, tone: "warning" },
    { atLeast: -90, label: "Muito fraco", bars: 1, tone: "danger" },
    { atLeast: -Infinity, label: "Inutilizável", bars: 0, tone: "danger" },
];

const NO_SIGNAL = { label: "Sem sinal", bars: 0, tone: "secondary" };

/** Os 30 s do `stalenessSeconds` do `ProximityTracker` mais os 30 s da sondagem da dashboard. */
const SIGNAL_MAX_AGE_SECONDS = 60;

export function signalBand(signal, now = Date.now()) {
    if (!signal) return NO_SIGNAL;

    // Uma leitura velha não é fraca: as barras ficam vazias, e o valor fica na etiqueta.
    const at = signal.at ? Date.parse(signal.at) : NaN;
    if (Number.isFinite(at) && (now - at) > (SIGNAL_MAX_AGE_SECONDS * 1000)) {
        return NO_SIGNAL;
    }

    return SIGNAL_BANDS.find((band) => signal.rssiDbm >= band.atLeast) || NO_SIGNAL;
}

export function signalLabel(signal) {
    const band = signalBand(signal);
    if (!signal) return band.label;

    const parts = [band.label, `${signal.rssiDbm} dBm`];
    // Uma leitura forte de há uma hora não quer dizer que o dispositivo esteja perto agora.
    if (signal.at) parts.push(ago(signal.at));

    return parts.join(" · ");
}

/** Quatro barras, com o dBm exacto atrás de uma tooltip. */
export function signalMeter(signal) {
    const band = signalBand(signal);
    const title = signalLabel(signal);
    const bars = [1, 2, 3, 4]
        .map((bar) => html`<span class="signal-meter-bar h-${bar * 25}${bar <= band.bars ? " opacity-100" : ""}"></span>`);

    return html`<span class="signal-meter d-inline-flex align-items-end flex-shrink-0 text-${band.tone}" data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-title="${title}" aria-label="${title}" role="img" tabindex="0">${bars}</span>`;
}

/** Com o gateway a calibrar, o par que teria dado o alarme no último minuto. */
function wouldFire(linked, now = Date.now()) {
    const at = Date.parse(String(linked.proximityAlarmCandidateAt || ""));
    return Number.isFinite(at) && now - at <= SIGNAL_MAX_AGE_SECONDS * 1000;
}

/** Uma linha por ligação; as nunca ouvidas ficam com um travessão, que a ausência é informação. */
export function gatewaySignalRows(linkedDevices) {
    if (!linkedDevices.length) return "";

    return html`<ul class="list-unstyled mb-0 small">${linkedDevices
        .map((linked) => {
            const key = normalizeKey(linked.deviceKey);
            if (!key) return "";
            const model = String(linked.model || "");
            return html`<li class="d-flex justify-content-between align-items-center gap-2">
                <span class="min-w-0 text-truncate font-monospace" title="${key}">${key}${model ? html` <span class="text-secondary">${model}</span>` : ""}</span>
                <span class="d-flex align-items-center gap-2 flex-shrink-0">
                    ${wouldFire(linked) ? html`<span class="badge text-bg-warning">Dispararia</span>` : ""}
                    ${signalMeter(linkSignal(linked))}
                </span>
            </li>`;
        })}</ul>`;
}
