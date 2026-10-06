import { html, raw } from "../html.js";
import { normalizeLicenseId } from "../domain.js";

/** Um aparelho sem dono tem a empresa vazia ou a sentinela `null`, e a licença `0`. */
function hasLicense(device) {
    const company = String(device.company || "").trim();

    return company !== "" &&
        company.toLowerCase() !== "null" &&
        normalizeLicenseId(device.licenseId) !== "0";
}

/** A licença numa linha: o nome, ou a empresa quando não há nome registado. */
export function deviceLicenseLabel(device) {
    if (!hasLicense(device)) return "Sem licença";

    const name = String(device.licenseName || "").trim();
    return name === "" ? String(device.company).trim() : name;
}

/**
 * A licença de um aparelho: o nome em cima, a empresa e o número em baixo, porque o mesmo
 * sítio pode ter licença em duas empresas. As classes são de quem chama.
 */
export function deviceLicenseBlock(device, { valueClass = "", noteClass = "" } = {}) {
    if (!hasLicense(device)) {
        return html`<span class="${`${valueClass} text-body-secondary`.trim()}">Sem licença</span>`;
    }

    const company = String(device.company).trim();
    const licenseId = normalizeLicenseId(device.licenseId);
    const name = String(device.licenseName || "").trim();
    const heading = deviceLicenseLabel(device);
    const owner = name === ""
        ? html`<span class="license-number">${licenseId}</span>`
        : html`${company}<span class="license-separator">·</span><span class="license-number">${licenseId}</span>`;

    // O nome corta com reticências numa coluna estreita: o `title` guarda-o inteiro.
    return html`<span class="${valueClass}" title="${heading}">${heading}</span><span class="${noteClass}">${raw(owner)}</span>`;
}
