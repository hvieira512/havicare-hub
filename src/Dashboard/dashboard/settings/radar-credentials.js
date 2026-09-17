import {
    forgetRadarCredentials as apiForgetRadarCredentials,
    getRadarCredentials as apiGetRadarCredentials,
    saveRadarCredentials as apiSaveRadarCredentials,
} from "../api/index.js";
import { state } from "../state.js";
import { html, raw } from "../html.js";
import { clearInvalid, markInvalid } from "../validation.js";

/**
 * O acesso à cloud do fabricante dos radares, que é de cada licença.
 *
 * Não há conta que veja a frota toda: com a conta de uma licença, os radares das outras
 * respondem que estão offline mesmo a publicar telemetria nesse minuto, e nem o endereço base
 * coincide entre inquilinos.
 */

export const EDITOR_KIND = "radarCredentials";

/** Traz o que está guardado antes de abrir a linha. Os segredos não vêm: a API não os dá. */
export async function loadRadarCredentials(licenseRefId) {
    const result = await apiGetRadarCredentials(licenseRefId);
    state.settingsModal.radarCredentials = result.error ? null : (result.data ?? null);

    return result;
}

export function clearRadarCredentials() {
    state.settingsModal.radarCredentials = null;
}

/**
 * Um segredo já guardado mostra-se como preenchido e não como vazio: o campo em branco quer
 * dizer "fica como está", e sem esta distinção quem corrige o endereço fica sem saber se está
 * prestes a apagar a palavra-passe.
 */
function secretField(id, label, stored) {
    return html`
        <div class="flex-grow-1" style="min-width:11rem">
            <label class="section-label d-block mb-1" for="${id}">${label}</label>
            <input type="password" class="form-control form-control-sm" id="${id}" data-field="${id === "radarRowPassword" ? "password" : "appSecret"}" autocomplete="new-password" placeholder="${stored ? "guardada — deixe em branco para manter" : "obrigatória"}">
        </div>`;
}

export function radarCredentialsEditorRow(license) {
    const stored = state.settingsModal.radarCredentials;

    return html`
        <div class="tree-row position-relative" data-editor="${EDITOR_KIND}" data-id="${license.id}">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fa-solid fa-satellite-dish text-secondary" aria-hidden="true"></i>
                <span class="section-label mb-0">Cloud dos radares · licença ${license.license_id}</span>
            </div>
            <div class="d-flex align-items-end gap-2 flex-wrap">
                <div class="flex-grow-1" style="min-width:16rem">
                    <label class="section-label d-block mb-1" for="radarRowBaseUrl">Endereço da API</label>
                    <input type="url" class="form-control form-control-sm" id="radarRowBaseUrl" data-field="baseUrl" value="${stored?.baseUrl || ""}" placeholder="https://radarconsole.com/prod-api">
                </div>
                <div class="flex-grow-1" style="min-width:10rem">
                    <label class="section-label d-block mb-1" for="radarRowUsername">Utilizador</label>
                    <input type="text" class="form-control form-control-sm" id="radarRowUsername" data-field="username" value="${stored?.username || ""}" autocomplete="off">
                </div>
                ${raw(secretField("radarRowPassword", "Palavra-passe", stored?.hasPassword))}
                <div class="flex-grow-1" style="min-width:10rem">
                    <label class="section-label d-block mb-1" for="radarRowAppId">App ID</label>
                    <input type="text" class="form-control form-control-sm" id="radarRowAppId" data-field="appId" value="${stored?.appId || ""}" autocomplete="off">
                </div>
                ${raw(secretField("radarRowAppSecret", "App secret", stored?.hasAppSecret))}
            </div>
            <div class="d-flex align-items-center gap-2 mt-2">
                ${raw(stored?.configured ? html`<button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm me-auto" data-action="forgetRadarCredentials" data-id="${license.id}">Esquecer</button>` : "")}
                <button type="button" class="btn btn-outline-secondary btn-sm ${stored?.configured ? "" : "ms-auto"}" data-action="cancelEdit">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" data-action="saveRadarCredentialsRow">Guardar</button>
            </div>
            <div class="form-text mt-2">As credenciais são desta licença. Uma conta de outra licença autentica na mesma, mas depois dá todos estes radares por offline.</div>
        </div>`;
}

/**
 * Grava o que está na linha. Devolve o resultado da API, ou `null` quando um campo
 * obrigatório está vazio -- a marcação do campo já aconteceu aqui.
 */
export async function submitRadarCredentials(row) {
    clearInvalid(row.el);

    const baseUrl = row.value("baseUrl");
    const username = row.value("username");
    const appId = row.value("appId");
    const missing = [
        [baseUrl, "baseUrl", "O endereço da API é obrigatório"],
        [username, "username", "O utilizador é obrigatório"],
        [appId, "appId", "O App ID é obrigatório"],
    ].find(([value]) => !value);

    if (missing) {
        markInvalid(row.field(missing[1]), missing[2]);
        return null;
    }

    // Os segredos vão em branco quando ninguém lhes tocou, e em branco o servidor mantém os
    // que já lá estão.
    return apiSaveRadarCredentials(row.id, {
        baseUrl,
        username,
        appId,
        password: row.value("password"),
        appSecret: row.value("appSecret"),
    });
}

export function forgetRadarCredentials(licenseRefId) {
    return apiForgetRadarCredentials(licenseRefId);
}
