import { html } from "../core/html.js";
/** Um aparelho sem dono tem a empresa e a licença a `null`. */
const hasLicense = (device) => Boolean(device.company && device.licenseId);

/** A licença numa linha: o nome, ou a empresa quando não há nome registado. */
export function deviceLicenseLabel(device) {
    if (!hasLicense(device)) return "Sem licença";

    const name = String(device.licenseName || "").trim();
    return name === "" ? String(device.company).trim() : name;
}

/** O nome em cima, a empresa e o número em baixo: o mesmo sítio pode ter licença em duas empresas. */
export function deviceLicenseBlock(device, { valueClass = "", noteClass = "" } = {}) {
    if (!hasLicense(device)) {
        return html`<span class="${`${valueClass} text-body-secondary`.trim()}">Sem licença</span>`;
    }

    const company = String(device.company).trim();
    const licenseId = String(device.licenseId);
    const name = String(device.licenseName || "").trim();
    const heading = deviceLicenseLabel(device);
    const owner = name === ""
        ? html`<span class="license-number">${licenseId}</span>`
        : html`${company}<span class="license-separator">·</span><span class="license-number">${licenseId}</span>`;

    // O nome corta com reticências numa coluna estreita: o `title` guarda-o inteiro.
    return html`<span class="${valueClass}" title="${heading}">${heading}</span><span class="${noteClass}">${owner}</span>`;
}
