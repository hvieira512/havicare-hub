import { html, raw } from "../html.js";
import { normalizeLicenseId } from "../domain.js";

/**
 * A licença de um aparelho: o nome em cima, a empresa e o número em baixo.
 *
 * A empresa fica porque o mesmo sítio pode ter licença em duas empresas, e só o nome deixava
 * as duas linhas indistinguíveis.
 */
export function deviceLicenseBlock(device) {
    const company = String(device.company || "").trim();
    const licenseId = normalizeLicenseId(device.licenseId);
    if (company === "" || company.toLowerCase() === "null" || licenseId === "0") {
        return html`<span class="device-card-field-value empty">Sem licença</span>`;
    }

    const name = String(device.licenseName || "").trim();
    // Sem nome registado, a empresa sobe: a primeira linha não fica vazia.
    const heading = name === "" ? company : name;
    const owner = name === ""
        ? html`<span class="license-number">${licenseId}</span>`
        : html`${company}<span class="license-separator">·</span><span class="license-number">${licenseId}</span>`;

    return html`<span class="device-card-field-value">${heading}</span><span class="device-card-field-note text-truncate">${raw(owner)}</span>`;
}
