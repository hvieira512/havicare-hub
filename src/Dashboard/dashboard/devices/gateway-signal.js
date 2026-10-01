import { ago } from "../format.js";
import { html } from "../html.js";
import { normalizeKey } from "./gateway-links.js";

/**
 * O último sinal de cada ligação a um gateway. O RSSI pertence ao par e não ao dispositivo --
 * um sensor ouvido por três gateways tem três valores ao mesmo tempo --, e por isso viaja em
 * `source.rssiDbm` em vez de ser capacidade própria.
 */

/** A leitura que a linha traz, ou null quando esse par nunca foi ouvido. */
export function linkSignal(linked) {
    // Verificado antes do `Number()`, que transforma o null e o "" num 0 dBm muito
    // plausível -- que é a leitura mais forte que há.
    if (linked?.rssiDbm === null || linked?.rssiDbm === undefined || linked.rssiDbm === "") {
        return null;
    }
    const rssiDbm = Number(linked.rssiDbm);
    if (!Number.isFinite(rssiDbm)) return null;

    return { rssiDbm, at: String(linked.signalSeenAt || "") };
}

/**
 * As bandas de sinal, a mais forte primeiro. O `bars` e o `tone` mudam os dois entre bandas
 * vizinhas, para o medidor nunca depender só da cor.
 */
const SIGNAL_BANDS = [
    { atLeast: -60, label: "Excelente", bars: 4, tone: "success" },
    { atLeast: -67, label: "Bom", bars: 3, tone: "success" },
    { atLeast: -70, label: "Razoável", bars: 3, tone: "warning" },
    { atLeast: -80, label: "Fraco", bars: 2, tone: "warning" },
    { atLeast: -90, label: "Muito fraco", bars: 1, tone: "danger" },
    { atLeast: -Infinity, label: "Inutilizável", bars: 0, tone: "danger" },
];

const NO_SIGNAL = { label: "Sem sinal", bars: 0, tone: "secondary" };

/**
 * A partir de quando uma leitura deixa de dizer alguma coisa sobre agora.
 *
 * São os 30 s que o hub tolera antes de dar o par por calado -- o `stalenessSeconds` do
 * `ProximityTracker` -- mais os 30 s a que a dashboard sonda o dispositivo. Mais apertado do
 * que isto e um aparelho vivo dizia «sem sinal» só por atraso da sondagem.
 */
const SIGNAL_MAX_AGE_SECONDS = 60;

/** A banda em que uma leitura cai, ou a banda do "nunca ouvido" quando não há leitura. */
export function signalBand(signal, now = Date.now()) {
    if (!signal) return NO_SIGNAL;

    // Uma leitura velha não é uma leitura fraca: as barras ficam vazias e a qualidade deixa
    // de se afirmar, mas o valor e a idade continuam a ler-se na etiqueta.
    const at = signal.at ? Date.parse(signal.at) : NaN;
    if (Number.isFinite(at) && (now - at) > (SIGNAL_MAX_AGE_SECONDS * 1000)) {
        return NO_SIGNAL;
    }

    return SIGNAL_BANDS.find((band) => signal.rssiDbm >= band.atLeast) || NO_SIGNAL;
}

/** A intensidade em texto: a banda, a leitura, e a idade dela. */
export function signalLabel(signal) {
    const band = signalBand(signal);
    if (!signal) return band.label;

    const parts = [band.label, `${signal.rssiDbm} dBm`];
    // A idade também: uma leitura forte de há uma hora não quer dizer que o dispositivo
    // esteja perto agora.
    if (signal.at) parts.push(ago(signal.at));

    return parts.join(" · ");
}

/**
 * Um medidor de quatro barras, com a leitura atrás de uma tooltip: as barras percorrem-se
 * de relance numa coluna de ligações, e o dBm exacto só importa para uma delas.
 */
export function signalMeter(signal) {
    const band = signalBand(signal);
    const title = signalLabel(signal);
    // A altura de cada barra é `h-25` a `h-100`, por ordem.
    const bars = [1, 2, 3, 4]
        .map((bar) => html`<span class="signal-meter-bar h-${bar * 25}${bar <= band.bars ? " opacity-100" : ""}"></span>`);

    return html`<span class="signal-meter d-inline-flex align-items-end flex-shrink-0 text-${band.tone}" data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-title="${title}" aria-label="${title}" role="img" tabindex="0">${bars}</span>`;
}

/**
 * Uma linha por ligação, para o par a que o RSSI pertence ficar à vista. As que nunca foram
 * ouvidas ficam listadas com um travessão: a ausência de sinal é informação.
 */
export function gatewaySignalRows(linkedDevices) {
    if (!linkedDevices.length) return "";

    return html`<ul class="list-unstyled mb-0 small">${linkedDevices
        .map((linked) => {
            const key = normalizeKey(linked.deviceKey);
            if (!key) return "";
            const model = String(linked.model || "");
            return html`<li class="d-flex justify-content-between align-items-center gap-2">
                <span class="min-w-0 text-truncate font-monospace" title="${key}">${key}${model ? html` <span class="text-secondary">${model}</span>` : ""}</span>
                ${signalMeter(linkSignal(linked))}
            </li>`;
        })}</ul>`;
}
