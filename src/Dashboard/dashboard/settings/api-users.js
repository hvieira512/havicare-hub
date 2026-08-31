import {
    deleteApiUser as apiDeleteApiUser,
    getApiUsers as apiGetApiUsers,
    saveApiUser as apiSaveApiUser,
} from "../api/index.js";
import { ensureLicensesLoaded } from "../licenses.js";
import { stateBadge } from "../widgets.js";
import { state } from "../state.js";
import { html, raw } from "../html.js";
import { apiError, confirmDestructive, toast } from "../dialogs.js";
import { clearInvalid, markInvalid } from "../validation.js";
import { setSettingsNavCount } from "./shell.js";
import { renderPagination } from "../pagination.js";

/**
 * O separador dos utilizadores da API.
 *
 * A edição acontece na própria linha. Antes havia um formulário só, escondido no topo do
 * painel e partilhado pelo criar e pelo editar: carregar no lápis da quinta linha abria-o
 * acima da dobra, sem rolar e sem marcar a linha de onde se veio, e o clique parecia não ter
 * feito nada. Agora a linha que se toca é a que se transforma -- nada se mexe de sítio, e o
 * que se está a editar é aquilo onde se está a olhar.
 *
 * As licenças vêm com a lista e ficam aqui porque só este ecrã as usa, como opções do select
 * -- e um cliente sem licença não pode ser gravado.
 */
let els;
let apiUserLicenses = [];
let currentUsers = [];

/** Quem está aberto para edição: `null`, `"new"` para o rascunho, ou o id de uma linha. */
let editing = null;

export function initSettingsApiUsers(context) {
    els = context.els;
}

/** O perfil de um utilizador da API, por palavras. Só a aba de utilizadores o mostra. */
function apiRoleLabel(role) {
    return role === "hub_admin" ? "Admin Hub" : "Cliente por licença";
}

export async function loadSettingsApiUsersSection(page = 1) {
    // As licenças não mudam por se gravar um utilizador: vêm da cache partilhada, e é só a
    // lista de utilizadores que se vai buscar outra vez.
    const [response, licenses] = await Promise.all([
        apiGetApiUsers({ page }),
        ensureLicensesLoaded(),
    ]);
    currentUsers = response.data || [];
    apiUserLicenses = licenses ?? [];
    state.settingsModal.apiUsersPagination = response.pagination || null;
    state.settingsModal.sectionLoaded.apiUsers = true;
    editing = null;
    renderApiUsersSection();
    renderPagination({
        pagination: state.settingsModal.apiUsersPagination,
        rootEl: els.settingsApiUsersPagination,
        summaryEl: els.settingsApiUsersPaginationSummary,
        controlsEl: els.settingsApiUsersPaginationControls,
        actionPrefix: "settingsApiUsersPage",
    });
}

function licenseOptionsHtml(selected) {
    return "<option value=\"\">Selecionar licença</option>" +
        apiUserLicenses
            .map((license) => html`<option value="${license.id}" ${raw(String(license.id) === String(selected) ? "selected" : "")}>${`${license.company_name || "-"} / ${license.license_id} — ${license.name || ""}`}</option>`)
            .join("");
}

function viewRow(user) {
    const enabled = Number(user.enabled) === 1;
    // O âmbito é a informação com mais consequência da tabela -- quem vê os dados de que
    // licença --, e "Todas" é um privilégio e não um valor por omissão.
    const scope = user.role === "hub_admin"
        ? raw(stateBadge("Todas as licenças"))
        : user.company_name && user.license_id
            ? `${user.company_name} / ${user.license_id}`
            : "Sem licença válida";

    return html`
        <tr class="d-block d-sm-table-row">
        <td class="fw-semibold d-block d-sm-table-cell border-0 pb-0 py-sm-2">${user.username}</td>
        <td class="d-block d-sm-table-cell border-0 py-0 py-sm-2">
            <span class="section-label d-sm-none me-2">Perfil</span>
            <span class="section-label">${apiRoleLabel(user.role)}</span>
        </td>
        <td class="d-block d-sm-table-cell border-0 py-0 py-sm-2">
        <span class="section-label d-sm-none me-2">Âmbito</span>${scope}</td>
        <td class="d-block d-sm-table-cell border-0 py-0 py-sm-2">
            ${raw(stateBadge(
                enabled ? "Ativo" : "Inativo",
                enabled ? "config-state-success" : "config-state-secondary",
            ))}
        </td>
        <td class="text-end text-nowrap d-block d-sm-table-cell border-0 pt-2">
        <button class="btn btn-outline-secondary btn-sm" data-action="editApiUser" data-id="${user.id}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-secondary btn-sm" data-action="toggleApiUser" data-id="${user.id}" data-username="${user.username}" data-role="${user.role}" data-license-ref-id="${user.license_ref_id || ""}" data-enabled="${enabled ? "1" : ""}" title="${enabled ? "Desativar" : "Ativar"}"><i class="fa-solid fa-${enabled ? "pause" : "play"}"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${user.id}" data-action="deleteApiUser" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`;
}

/**
 * A linha aberta para edição.
 *
 * A password é o caso que obrigava a explicar o ecrã: no criar, o campo vazio quer dizer
 * "põe esta"; no editar, queria dizer "não lhe toques" -- a mesma caixa vazia com dois
 * sentidos opostos e nada escrito que os separasse. Aqui, editar não mostra campo nenhum:
 * mostra uma acção, e o campo só existe depois de alguém a pedir.
 */
function editorRow(user) {
    const isNew = user === null;
    const role = user?.role || "license_client";
    const enabled = user ? Number(user.enabled) === 1 : true;
    const isAdmin = role === "hub_admin";

    return html`
        <tr class="d-block d-sm-table-row">
        <td class="border-0 p-0 pb-2 d-block d-sm-table-cell" colspan="5">
        <div class="border rounded-3 p-3 d-flex flex-column gap-3 bg-body-tertiary" data-api-user-editor data-id="${user?.id || ""}">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-3">
                    <label class="section-label d-block mb-1" for="apiUserRowUsername">Utilizador</label>
                    <input type="text" class="form-control form-control-sm" id="apiUserRowUsername" data-field="username" autocomplete="off" value="${user?.username || ""}">
                </div>
                <div class="col-12 col-md-3">
                    <label class="section-label d-block mb-1" for="apiUserRowRole">Perfil</label>
                    <select class="form-select form-select-sm" id="apiUserRowRole" data-field="role">
                        <option value="license_client" ${raw(isAdmin ? "" : "selected")}>Cliente por licença</option>
                        <option value="hub_admin" ${raw(isAdmin ? "selected" : "")}>Admin Hub</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="section-label d-block mb-1" for="apiUserRowLicense">Licença</label>
                    <select class="form-select form-select-sm" id="apiUserRowLicense" data-field="licenseRefId" ${raw(isAdmin ? "disabled" : "")}>${raw(licenseOptionsHtml(user?.license_ref_id))}</select>
                </div>
                <div class="col-12 col-md-2">
                    <div class="form-check form-switch mt-md-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="apiUserRowEnabled" data-field="enabled" ${raw(enabled ? "checked" : "")}>
                        <label class="form-check-label section-label" for="apiUserRowEnabled">Ativo</label>
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-end gap-2 flex-wrap">
                <div class="${raw(isNew ? "" : "d-none")}" data-password-field style="min-width:14rem">
                    <label class="section-label d-block mb-1" for="apiUserRowPassword">Password</label>
                    <input type="password" class="form-control form-control-sm" id="apiUserRowPassword" data-field="password" autocomplete="new-password" placeholder="${isNew ? "Obrigatória" : "Nova password"}">
                </div>
                ${raw(isNew ? "" : html`<button type="button" class="btn btn-outline-secondary btn-sm" data-action="revealApiUserPassword">Redefinir password</button>`)}
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="cancelApiUserEdit">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" data-action="saveApiUserRow">Guardar</button>
                </div>
            </div>
        </div>
        </td>
        </tr>`;
}

function renderApiUsersSection() {
    const users = currentUsers || [];
    const total = users.length;
    const admins = users.filter((user) => user.role === "hub_admin").length;
    if (els.apiUsersTabSummary) {
        setSettingsNavCount("ApiUsers", total);
        els.apiUsersTabSummary.textContent = total === 0
            ? "Nenhum utilizador"
            : `${total} ${total === 1 ? "utilizador" : "utilizadores"}` +
                (admins ? ` · ${admins} com acesso a todas as licenças` : "");
    }

    els.apiUserListBody.innerHTML =
        (editing === "new" ? editorRow(null) : "") +
        users
            .map((user) => (String(user.id) === String(editing) ? editorRow(user) : viewRow(user)))
            .join("");

    els.apiUserListBody
        .querySelector("[data-api-user-editor] input, [data-api-user-editor] select")
        ?.focus();
}

/** Abrir um rascunho no topo da lista: criar é uma linha nova, não um formulário à parte. */
export function newApiUser() {
    editing = "new";
    renderApiUsersSection();
}

export function editApiUser(button) {
    editing = button.dataset.id || null;
    renderApiUsersSection();
}

export function cancelApiUserEdit() {
    editing = null;
    renderApiUsersSection();
}

/** O campo da password só aparece quando alguém pede para a mudar. */
export function revealApiUserPassword(button) {
    const editor = button.closest("[data-api-user-editor]");
    editor?.querySelector("[data-password-field]")?.classList.remove("d-none");
    button.classList.add("d-none");
    editor?.querySelector("[data-field=\"password\"]")?.focus();
}

/** O perfil de admin manda em todas as licenças, por isso a escolha de uma não se aplica. */
export function syncApiUserRowRole(select) {
    const editor = select.closest("[data-api-user-editor]");
    const license = editor?.querySelector("[data-field=\"licenseRefId\"]");
    if (!license) return;
    const isAdmin = select.value === "hub_admin";
    license.disabled = isAdmin;
    if (isAdmin) license.value = "";
}

export async function saveApiUserRow(button) {
    const editor = button.closest("[data-api-user-editor]");
    if (!editor) return;

    const field = (name) => editor.querySelector(`[data-field="${name}"]`);
    const id = editor.dataset.id || "";
    const passwordEl = field("password");
    // Numa edição sem "Redefinir password" não se envia password nenhuma, em vez de enviar
    // uma vazia: o pedido diz o que se quer mudar, e não o que se quer que fique na mesma.
    const changingPassword = !id || !passwordEl.closest("[data-password-field]").classList.contains("d-none");

    const body = {
        username: field("username").value.trim(),
        role: field("role").value,
        licenseRefId: field("licenseRefId").value.trim(),
        enabled: field("enabled").checked,
    };
    if (changingPassword) body.password = passwordEl.value;

    clearInvalid(editor);
    if (!body.username) {
        markInvalid(field("username"), "Utilizador é obrigatório");
    }
    if (!id && !(body.password || "").trim()) {
        markInvalid(passwordEl, "Password é obrigatória para novo utilizador");
    }
    if (body.role === "license_client" && !body.licenseRefId) {
        markInvalid(field("licenseRefId"), "Licença é obrigatória para clientes");
    }
    if (editor.querySelector(".is-invalid")) return;

    const result = await apiSaveApiUser(id, body);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }

    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}

export async function toggleApiUser(button) {
    const result = await apiSaveApiUser(button.dataset.id, {
        username: button.dataset.username || "",
        role: button.dataset.role || "license_client",
        licenseRefId: button.dataset.licenseRefId || "",
        enabled: !button.dataset.enabled,
    });
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}

export async function deleteApiUser(id) {
    const { isConfirmed } = await confirmDestructive("Apagar utilizador API?");
    if (!isConfirmed) return;
    const result = await apiDeleteApiUser(id);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}
