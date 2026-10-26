import { html } from "../../core/html.js";
import { normalizeKey } from "./gateway-links.js";
import { signalMeter } from "./gateway-signal.js";

const THUMB_PLACEHOLDER = html`<svg class="gateway-card-thumb-icon text-secondary" viewBox="0 0 24 24" fill="none"
    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="2.5" y="13.5" width="19" height="7" rx="1.75"></rect>
    <path d="M6 17h.01M9.5 17h5"></path>
    <path d="M12 10.5v-7M8.75 6.75 12 3.5l3.25 3.25"></path>
</svg>`;

/**
 * O cartão de marcar um dispositivo: fotografia do modelo, identificador, modelo e sinal. Com
 * `configField`, a caixa é um campo da configuração e o valor é o identificador.
 */
export function deviceCheckCard(device, checked, signal = null, configField = "") {
    const key = normalizeKey(device.imei ?? device.deviceKey);
    const model = String(device.model || "").trim();
    const image = String(device.image || "").trim();
    const thumb = image
        ? html`<img class="object-fit-contain" src="${image}" alt="" loading="lazy" decoding="async">`
        : THUMB_PLACEHOLDER;
    const field = configField
        ? html`data-config-field="${configField}" value="${key}"`
        : html`data-gateway-key="${key}"`;

    return html`<label class="gateway-card d-flex align-items-center m-0 py-2">
        <input class="form-check-input gateway-card-check flex-shrink-0 m-0" type="checkbox" ${field}${checked ? " checked" : ""}>
        <span class="gateway-card-thumb d-flex align-items-center justify-content-center flex-shrink-0 overflow-hidden rounded-3">${thumb}</span>
        <span class="min-w-0">
            <span class="d-block fw-semibold text-truncate lh-sm">${key}</span>
            <span class="gateway-card-model d-block text-secondary text-truncate lh-sm">${model || "Modelo desconhecido"}</span>
        </span>
        ${signalMeter(signal)}
    </label>`;
}
