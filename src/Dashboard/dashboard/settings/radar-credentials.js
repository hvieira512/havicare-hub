import {
    checkRadarCredentials as apiCheckRadarCredentials,
    forgetRadarCredentials as apiForgetRadarCredentials,
    getRadarCredentials as apiGetRadarCredentials,
    saveRadarCredentials as apiSaveRadarCredentials,
} from "../api/index.js";
import { html, raw } from "../html.js";
import { clearInvalid } from "../validation.js";
import { errorCodeText } from "../api/errors.js";

/** As credenciais da cloud dos radares, por licença: com a conta de outra, os radares respondem offline. */

export const EDITOR_KIND = "radarCredentials";

/** O que está guardado da licença aberta; os segredos não vêm. */
let loaded = null;

/** Traz o que está guardado antes de abrir a linha. Os segredos não vêm: a API não os dá. */
export async function loadRadarCredentials(licenseRefId) {
    const result = await apiGetRadarCredentials(licenseRefId);
    loaded = result.error ? null : (result.data ?? null);

    return result;
}

export function clearRadarCredentials() {
    loaded = null;
}

/** Um segredo guardado diz que está lá; o campo em branco quer dizer «fica como está». */
function secretField(id, field, label, stored) {
    return html`
        <div class="col-12 col-sm-6">
            <label class="section-label" for="${id}">${label}</label>
            ${raw(stored
                ? html`
            <div class="d-flex align-items-center gap-2" data-secret-kept="${field}">
                <span class="small text-body-secondary flex-grow-1"><i class="fa-solid fa-circle-check text-success me-1" aria-hidden="true"></i>guardada</span>
                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-action="replaceRadarSecret" data-secret="${field}">Substituir</button>
            </div>`
                : html`
            <input type="password" class="form-control form-control-sm" id="${id}" data-field="${field}" autocomplete="new-password">`)}
        </div>`;
}

/** Autenticar não prova que a conta é desta licença: diz-se quantos destes radares ela conhece. */
export function radarCheckMessage({ radars, responding, error }) {
    if (error) {
        return { tone: "danger", text: errorCodeText(error) ?? "Não ligou." };
    }
    if (radars === 0) {
        return {
            tone: "secondary",
            text: "Esta licença ainda não tem radares para experimentar.",
        };
    }

    const noun = responding === 1 ? "radar responde" : "radares respondem";
    if (responding === 0) {
        return {
            tone: "danger",
            text: `Autenticou, mas nenhum destes ${radars} radares é desta conta.`,
        };
    }
    if (responding < radars) {
        return { tone: "warning", text: `Ligou · ${responding} de ${radars} ${noun} nesta conta` };
    }

    return { tone: "success", text: `Ligou · ${responding} ${noun} nesta conta` };
}

/** A linha aberta; num telefone toma o cartão todo. */
export function radarCredentialsEditorRow(license) {
    const stored = loaded;

    return html`
        <div class="tree-row radar-credentials-row position-relative" data-editor="${EDITOR_KIND}" data-id="${license.id}">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="fa-solid fa-satellite-dish text-secondary" aria-hidden="true"></i>
                <span class="fw-semibold">Cloud dos radares</span>
                <span class="small text-body-secondary">Qinglanst · licença ${license.licenseId}</span>
            </div>
            <p class="small text-body-secondary mb-3">Cada licença tem a sua conta. Uma conta de outra licença autentica à mesma e só depois dá estes radares por offline — por isso testa-se antes de gravar.</p>
            <div class="row g-3">
                <div class="col-12">
                    <label class="section-label" for="radarRowBaseUrl">Endereço da API</label>
                    <input type="url" class="form-control form-control-sm" id="radarRowBaseUrl" data-field="baseUrl" value="${stored?.baseUrl || ""}" placeholder="https://radarconsole.com/prod-api">
                </div>
                <div class="col-12 col-sm-6">
                    <label class="section-label" for="radarRowUsername">Utilizador</label>
                    <input type="text" class="form-control form-control-sm" id="radarRowUsername" data-field="username" value="${stored?.username || ""}" autocomplete="off">
                </div>
                ${raw(secretField("radarRowPassword", "password", "Palavra-passe", stored?.hasPassword))}
                <div class="col-12 col-sm-6">
                    <label class="section-label" for="radarRowAppId">App ID</label>
                    <input type="text" class="form-control form-control-sm" id="radarRowAppId" data-field="appId" value="${stored?.appId || ""}" autocomplete="off">
                </div>
                ${raw(secretField("radarRowAppSecret", "appSecret", "App secret", stored?.hasAppSecret))}
            </div>
            <div id="radarCheckResult" class="small mt-3 d-none" role="status"></div>
            <div class="d-flex align-items-center flex-wrap gap-2 mt-3">
                ${raw(stored?.configured ? html`<button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm me-auto order-last order-sm-0" data-action="forgetRadarCredentials" data-id="${license.id}">Esquecer</button>` : "")}
                <button type="button" class="btn btn-outline-secondary btn-sm order-last order-sm-0 ${stored?.configured ? "" : "ms-auto"}" data-action="cancelEdit">Cancelar</button>
                <button type="button" class="btn btn-outline-primary btn-sm flex-grow-1 flex-sm-grow-0" data-action="checkRadarCredentials" data-id="${license.id}">Testar ligação</button>
                <button type="button" class="btn btn-primary btn-sm flex-grow-1 flex-sm-grow-0" data-action="saveRadarCredentialsRow">Guardar</button>
            </div>
        </div>`;
}

/** Substituir um segredo guardado: o aviso dá lugar ao campo. */
export function replaceRadarSecret(row, field) {
    const slot = row.el.querySelector(`[data-secret-kept="${field}"]`);
    if (!slot) return;

    const id = field === "password" ? "radarRowPassword" : "radarRowAppSecret";
    slot.outerHTML = `<input type="password" class="form-control form-control-sm" id="${id}" data-field="${field}" autocomplete="new-password">`;
    row.el.querySelector(`#${id}`)?.focus();
}

/** Experimenta o que está no ecrã. Os segredos em branco caem nos que já estão guardados. */
export function tryRadarCredentials(row) {
    return apiCheckRadarCredentials(row.id, {
        baseUrl: row.value("baseUrl"),
        username: row.value("username"),
        appId: row.value("appId"),
        password: row.value("password"),
        appSecret: row.value("appSecret"),
    });
}

/** Grava a linha; os campos em falta vêm marcados no `fields` da resposta. */
export function submitRadarCredentials(row) {
    clearInvalid(row.el);

    // Um segredo em branco fica como está no servidor.
    return apiSaveRadarCredentials(row.id, {
        baseUrl: row.value("baseUrl"),
        username: row.value("username"),
        appId: row.value("appId"),
        password: row.value("password"),
        appSecret: row.value("appSecret"),
    });
}

export function forgetRadarCredentials(licenseRefId) {
    return apiForgetRadarCredentials(licenseRefId);
}
