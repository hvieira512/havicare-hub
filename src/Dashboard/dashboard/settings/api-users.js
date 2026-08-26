import {
    deleteApiUser as apiDeleteApiUser,
    getApiUsers as apiGetApiUsers,
    getLicenses as apiGetLicenses,
    saveApiUser as apiSaveApiUser,
} from "../api/index.js";
import {state} from "../state.js";
import {esc} from "../format.js";
import {
    renderSettingsPagination,
    setSettingsNavCount,
    toggleCollapse,
} from "./shell.js";

/**
 * O separador dos utilizadores da API: a tabela, o formulario, e ligar ou desligar um.
 *
 * As licencas vem com a lista e ficam aqui porque so este ecra as usa -- sao as opcoes do
 * select do formulario, e um cliente sem licenca nao pode ser gravado.
 */
let els;
let apiUserLicenses = [];

export function initSettingsApiUsers(context) {
    els = context.els;
}

/** O perfil de um utilizador da API, por palavras. Só a aba de utilizadores o mostra. */
function apiRoleLabel(role) {
    return role === "hub_admin" ? "Admin Hub" : "Cliente por licença";
}

export async function loadSettingsApiUsersSection(page = 1) {
    const [response, licensesResponse] = await Promise.all([
        apiGetApiUsers({ page }),
        apiGetLicenses({ page: 1, limit: 1000 }),
    ]);
    const users = response.data || [];
    apiUserLicenses = licensesResponse.data || [];
    renderApiUserLicenseOptions();
    state.settingsModal.apiUsersPagination = response.pagination || null;
    state.settingsModal.sectionLoaded.apiUsers = true;
    renderApiUsersSection(users);
    renderSettingsPagination(
        state.settingsModal.apiUsersPagination,
        els.settingsApiUsersPagination,
        els.settingsApiUsersPaginationSummary,
        els.settingsApiUsersPaginationControls,
        "settingsApiUsersPage",
    );
}

function renderApiUsersSection(users) {
    resetApiUserForm();
    const total = (users || []).length;
    const admins = (users || []).filter((user) => user.role === "hub_admin").length;
    if (els.apiUsersTabSummary) {
        setSettingsNavCount("ApiUsers", total);
        els.apiUsersTabSummary.textContent = total === 0
            ? "Nenhum utilizador"
            : `${total} ${total === 1 ? "utilizador" : "utilizadores"}`
              + (admins ? ` · ${admins} com acesso a todas as licenças` : "");
    }
    els.apiUserListBody.innerHTML = (users || [])
        .map(
            (user) => `
        <tr class="d-block d-sm-table-row">
        <td class="fw-semibold d-block d-sm-table-cell border-0 pb-0 py-sm-2">${esc(user.username)}</td>
        <td class="d-block d-sm-table-cell border-0 py-0 py-sm-2">
            <span class="section-label d-sm-none me-2">Perfil</span>
            <span class="section-label">${esc(apiRoleLabel(user.role))}</span>
        </td>
        <td class="d-block d-sm-table-cell border-0 py-0 py-sm-2">
        <span class="section-label d-sm-none me-2">Âmbito</span>${user.role === "hub_admin"
            // O âmbito é a informação com mais consequência da tabela -- quem vê os dados
            // de que licença. "Todas" estava em cinzento mais fraco que o resto da linha,
            // como se fosse um valor por omissão sem importância. É um privilégio.
            ? '<span class="config-state"><span class="config-state-dot"></span>Todas as licenças</span>'
            : esc(user.company_name && user.license_id ? `${user.company_name} / ${user.license_id}` : "Sem licença válida")}</td>
        <td class="d-block d-sm-table-cell border-0 py-0 py-sm-2">
            <span class="config-state ${Number(user.enabled) === 1 ? "config-state-success" : "config-state-secondary"}">
                <span class="config-state-dot"></span>${Number(user.enabled) === 1 ? "Ativo" : "Inativo"}
            </span>
        </td>
        <td class="text-end text-nowrap d-block d-sm-table-cell pt-2">
        <button class="btn btn-outline-secondary btn-sm row-action" data-action="editApiUser" data-id="${user.id}" data-username="${esc(user.username)}" data-role="${esc(user.role)}" data-license-ref-id="${esc(user.license_ref_id || "")}" data-enabled="${Number(user.enabled) === 1 ? "1" : ""}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-secondary btn-sm row-action" data-action="toggleApiUser" data-id="${user.id}" data-username="${esc(user.username)}" data-role="${esc(user.role)}" data-license-ref-id="${esc(user.license_ref_id || "")}" data-enabled="${Number(user.enabled) === 1 ? "1" : ""}" title="${Number(user.enabled) === 1 ? "Desativar" : "Ativar"}"><i class="fa-solid fa-${Number(user.enabled) === 1 ? "pause" : "play"}"></i></button>
        <button class="btn btn-outline-secondary btn-sm row-action row-action-danger" data-id="${user.id}" data-action="deleteApiUser" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`,
        )
        .join("");
}

function renderApiUserLicenseOptions() {
    els.apiUserLicenseRefId.innerHTML = '<option value="">Selecionar licença</option>'
        + apiUserLicenses.map((license) => `<option value="${esc(license.id)}">${esc(`${license.company_name || "-"} / ${license.license_id} — ${license.name || ""}`)}</option>`).join("");
}

export function resetApiUserForm() {
    els.apiUserForm.reset();
    els.apiUserId.value = "";
    els.apiUserRole.value = "license_client";
    els.apiUserEnabled.checked = true;
    els.apiUserPassword.placeholder = "Obrigatória para novo utilizador";
    syncApiUserRoleFields();
    toggleCollapse(els.apiUserFormCollapse, false);
}

export function editApiUser(button) {
    els.apiUserId.value = button.dataset.id || "";
    els.apiUsername.value = button.dataset.username || "";
    els.apiUserRole.value = button.dataset.role || "license_client";
    els.apiUserLicenseRefId.value = button.dataset.licenseRefId || "";
    els.apiUserEnabled.checked = !!button.dataset.enabled;
    els.apiUserPassword.value = "";
    els.apiUserPassword.placeholder = "Deixar vazio para manter";
    syncApiUserRoleFields();
    // O formulario esta fechado por omissao: editar tem de o abrir, senao o clique no
    // lapis preenchia campos que ninguem estava a ver.
    toggleCollapse(els.apiUserFormCollapse, true);
}

export function syncApiUserRoleFields() {
    const isAdmin = els.apiUserRole.value === "hub_admin";
    els.apiUserLicenseRefId.disabled = isAdmin;
    if (isAdmin) {
        els.apiUserLicenseRefId.value = "";
    }
}

export async function saveApiUser() {
    const id = els.apiUserId.value.trim();
    const body = {
        username: els.apiUsername.value.trim(),
        password: els.apiUserPassword.value,
        role: els.apiUserRole.value,
        licenseRefId: els.apiUserLicenseRefId.value.trim(),
        enabled: els.apiUserEnabled.checked,
    };
    if (!body.username) {
        alert("Utilizador é obrigatório");
        return;
    }
    if (!id && !body.password.trim()) {
        alert("Password é obrigatória para novo utilizador");
        return;
    }
    if (body.role === "license_client" && !body.licenseRefId) {
        alert("Licença é obrigatória para clientes");
        return;
    }

    const result = await apiSaveApiUser(id, body);
    if (result.error) {
        alert(result.error.message || result.error.code);
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
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}

export async function deleteApiUser(id) {
    if (!confirm("Apagar utilizador API?")) return;
    const result = await apiDeleteApiUser(id);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}
