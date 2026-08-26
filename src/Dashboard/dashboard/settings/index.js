import {
    createCompany as apiCreateCompany,
    deleteApiUser as apiDeleteApiUser,
    deleteCompany as apiDeleteCompany,
    deleteLicense as apiDeleteLicense,
    getApiUsers as apiGetApiUsers,
    getCompanies as apiGetCompanies,
    getLicenses as apiGetLicenses,
    getModels as apiGetModels,
    saveApiUser as apiSaveApiUser,
    saveLicense as apiSaveLicense,
    saveModel as apiSaveModel,
    updateCompany as apiUpdateCompany,
} from "../api/index.js";
import { state } from "../state.js";
import { esc } from "../format.js";
import {normalizeDeviceType} from "../domain.js";
import {
    renderPagination,
    resolvePaginationPage,
} from "../pagination.js";
import {
    handleModelsListSearchInput,
    initSettingsModels,
    loadSettingsModelsSection,
    resetModelForm,
    selectModelDeviceType,
    selectModelSupplier,
    updateModelProtocolAndPreview,
} from "./models/index.js";
import {
    initSettingsCapabilities,
    ensureCapabilityCatalog,
    loadSettingsCapabilitiesSection as loadSettingsCapabilitiesSectionModule,
    backToModelList,
    refreshNewModelCapabilityTemplate,
} from "./capabilities.js";

let els;
let ui;
let apiUserLicenses = [];

/** O perfil de um utilizador da API, por palavras. Só a aba de utilizadores o mostra. */
function apiRoleLabel(role) {
    return role === "hub_admin" ? "Admin Hub" : "Cliente por licença";
}

export function initSettings(context) {
    els = context.els;
    ui = context.ui;
    initSettingsCapabilities({ els, ui });
    initSettingsModels({
        els,
        ui,
        callbacks: {
            ensureCapabilityCatalog,
            refreshNewModelCapabilityTemplate,
        },
    });
}

async function loadSettingsModal(
    section = state.settingsModal.section || "models",
) {
    state.settingsModal.sectionLoaded = {
        models: false,
        modelFilters: false,
        capabilities: false,
        company: false,
        apiUsers: false,
    };
    state.settingsModal.modelCatalog = [];
    state.settingsModal.companyPagination = null;
    state.settingsModal.licensesPagination = null;
    state.settingsModal.apiUsersPagination = null;
    state.settingsModal.modelFilters = [];
    state.settingsModal.capabilityCatalog = [];
    state.settingsModal.capabilitySupplier = "";
    state.settingsModal.capabilityModelId = null;
    state.settingsModal.capabilityModelTemplateKeys = [];
    state.settingsModal.capabilityEnabledCapabilities = [];
    state.settingsModal.capabilityRequestableCapabilities = [];
    state.settingsModal.currentCapabilitiesModel = null;
    state.settingsModal.discoveryDeviceImei = "";
    state.settingsModal.discoveryDeviceOptions = [];
    state.settingsModal.discoveryRun = null;
    state.settingsModal.discoveryLoading = false;
    state.settingsModal.discoveryError = "";
    state.modelModal.enabledCapabilities = [];
    state.modelModal.templateSummary = "";
    state.modelModal.templateSupplier = "";
    state.modelModal.templateDeviceType = "watch";
    activateSettingsSection(section);
    ui.settingsModal.show();
    void loadSettingsNavCounts();
    if (section === "models") {
        void loadSettingsModelsSection();
    } else if (section === "capabilities") {
        void loadSettingsCapabilitiesSectionModule();
    } else if (section === "company") {
        void loadSettingsCompanySection();
    } else if (section === "apiUsers") {
        void loadSettingsApiUsersSection();
    }
}

function renderSettingsPagination(
    pagination,
    rootEl,
    summaryEl,
    controlsEl,
    action,
) {
    renderPagination({
        pagination,
        rootEl,
        summaryEl,
        controlsEl,
        actionPrefix: action,
    });
}

function handleSettingsPaginationClick(event, paginationKey, loadFn) {
    const nextPage = resolvePaginationPage(
        event,
        state.settingsModal[paginationKey],
        paginationActionPrefix(paginationKey),
    );
    if (nextPage === null) return;
    void loadFn(nextPage);
}

function paginationActionPrefix(paginationKey) {
    return (
        {
            apiUsersPagination: "settingsApiUsersPage",
            companyPagination: "settingsCompanyPage",
            licensesPagination: "settingsLicensesPage",
        }[paginationKey] || ""
    );
}

async function loadSettingsApiUsersSection(page = 1) {
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

async function saveModel() {
    const supplierId = parseInt(els.modelForm.dataset.supplierId || "0");
    const internalModel = els.modelInternalModel.value.trim();
    const commercialName = els.modelCommercialName.value.trim();
    const deviceType = normalizeDeviceType(
        els.modelForm.dataset.deviceType || "watch",
    );
    if (!supplierId || !internalModel || !commercialName) {
        alert("Fornecedor, modelo interno e nome comercial são obrigatórios");
        return;
    }

    const body = new FormData();
    body.append("supplier_id", String(supplierId));
    body.append("internalModel", internalModel);
    body.append("commercialName", commercialName);
    body.append("deviceType", deviceType);
    if (els.modelImage.files[0]) {
        body.append("image", els.modelImage.files[0]);
    }
    if (!els.modelForm.dataset.modelId) {
        body.append("capabilitiesConfigured", "1");
        for (const feature of state.modelModal.enabledCapabilities || []) {
            body.append("capabilities[]", String(feature));
        }
    }

    const result = await apiSaveModel(
        els.modelForm.dataset.modelId || "",
        body,
    );
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }

    backToModelList();
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
        <tr>
        <td class="fw-semibold">${esc(user.username)}</td>
        <td class="section-label">${esc(apiRoleLabel(user.role))}</td>
        <td>${user.role === "hub_admin"
            // O âmbito é a informação com mais consequência da tabela -- quem vê os dados
            // de que licença. "Todas" estava em cinzento mais fraco que o resto da linha,
            // como se fosse um valor por omissão sem importância. É um privilégio.
            ? '<span class="config-state"><span class="config-state-dot"></span>Todas as licenças</span>'
            : esc(user.company_name && user.license_id ? `${user.company_name} / ${user.license_id}` : "Sem licença válida")}</td>
        <td>
            <span class="config-state ${Number(user.enabled) === 1 ? "config-state-success" : "config-state-secondary"}">
                <span class="config-state-dot"></span>${Number(user.enabled) === 1 ? "Ativo" : "Inativo"}
            </span>
        </td>
        <td class="text-end text-nowrap">
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

function resetApiUserForm() {
    els.apiUserForm.reset();
    els.apiUserId.value = "";
    els.apiUserRole.value = "license_client";
    els.apiUserEnabled.checked = true;
    els.apiUserPassword.placeholder = "Obrigatória para novo utilizador";
    syncApiUserRoleFields();
    toggleCollapse(els.apiUserFormCollapse, false);
}

function editApiUser(button) {
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

/**
 * As contagens do menu, todas, ao abrir o modal.
 *
 * Cada separador enche a sua quando carrega, mas so carrega quando se abre -- e o menu
 * ficava com um numero no primeiro separador e nada nos outros quatro. Sao quatro pedidos
 * de uma linha cada, so para ler o total da paginacao.
 *
 * Falha em silencio: um numero que nao se sabe nao aparece, e a contagem do separador
 * enche-a quando ele abrir.
 */
async function loadSettingsNavCounts() {
    const asks = [
        ["Models", apiGetModels],
        ["Company", apiGetCompanies],
        ["ApiUsers", apiGetApiUsers],
    ];
    await Promise.all(asks.map(async ([key, ask]) => {
        const response = await ask({page: 1, limit: 1});
        if (response?.error) return;
        const total = response?.pagination?.total;
        if (Number.isFinite(Number(total))) setSettingsNavCount(key, total);
    }));
}

/**
 * A contagem de uma seccao no menu das definicoes.
 *
 * Cada carregador chama isto com o seu total, em vez de haver um sitio que sabe contar
 * tudo -- so quem foi buscar a lista e que sabe quantos sao.
 */
export function setSettingsNavCount(key, total) {
    // `els?.` e nao `els.`: quem desenha uma seccao nao tem de saber se o modal ja foi
    // inicializado, e sem isto a primeira contagem antes do `initSettings` rebentava.
    const element = els?.[`settings${key}Count`];
    if (!element) return;
    const known = Number.isFinite(Number(total));
    element.textContent = known ? String(total) : "";
    element.classList.toggle("d-none", !known);
}

/** Abre ou fecha um `collapse` do Bootstrap sem depender do botao que o comanda. */
function toggleCollapse(element, show) {
    if (!element || typeof bootstrap === "undefined") return;
    const instance = bootstrap.Collapse.getOrCreateInstance(element, {toggle: false});
    if (show) {
        instance.show();
    } else {
        instance.hide();
    }
}

function syncApiUserRoleFields() {
    const isAdmin = els.apiUserRole.value === "hub_admin";
    els.apiUserLicenseRefId.disabled = isAdmin;
    if (isAdmin) {
        els.apiUserLicenseRefId.value = "";
    }
}

async function saveApiUser() {
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

async function toggleApiUser(button) {
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

async function deleteApiUser(id) {
    if (!confirm("Apagar utilizador API?")) return;
    const result = await apiDeleteApiUser(id);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}

function renderCompanySection(companies, licenses) {
    resetCompanyForm();
    if (els.companiesTabSummary) {
        const total = (companies || []).length;
        const owned = (licenses || []).length;
        els.companiesTabSummary.textContent =
            `${total} ${total === 1 ? "empresa" : "empresas"} · ${owned} ${owned === 1 ? "licença" : "licenças"}`;
        setSettingsNavCount("Company", total);
    }
    // Uma linha por empresa, e as suas licenças indentadas por baixo. Eram duas tabelas
    // lado a lado com o mesmo peso, e a relação -- uma licença pertence a uma empresa --
    // só se percebia porque o formulário da licença tinha um select de empresa.
    els.companyListBody.innerHTML = (companies || [])
        .map((item) => {
            const owned = (licenses || []).filter(
                (license) => String(license.company_id) === String(item.id),
            );
            return `
        <div class="card mb-2">
        <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="fw-semibold">${esc(item.name)}</div>
            <div class="d-flex align-items-center gap-2">
                <span class="config-state config-state-secondary"><span class="config-state-dot"></span>${owned.length} ${owned.length === 1 ? "licença" : "licenças"}</span>
                <button class="btn btn-link btn-sm p-0 text-decoration-none" data-action="newLicenseForCompany" data-company-id="${item.id}">Nova licença</button>
                <button class="btn btn-outline-secondary btn-sm row-action" data-action="editCompany" data-id="${item.id}" data-name="${esc(item.name)}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-secondary btn-sm row-action row-action-danger" data-id="${item.id}" data-action="deleteCompany" title="Apagar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        ${owned.map((license) => `
        <div class="tree-row justify-content-between">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <span class="section-label tabular-nums" style="letter-spacing:0">ID ${esc(license.license_id)}</span>
                <span class="text-truncate">${esc(license.name || "sem nome")}</span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <button class="btn btn-outline-secondary btn-sm row-action" data-action="editLicense" data-id="${license.id}" data-company-id="${license.company_id}" data-company-name="${esc(license.company_name || "")}" data-license-id="${esc(license.license_id)}" data-name="${esc(license.name || "")}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-secondary btn-sm row-action row-action-danger" data-id="${license.id}" data-action="deleteLicense" title="Apagar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>`).join("")}
        </div>
        </div>`;
        })
        .join("");
}

function resetCompanyForm() {
    els.companyForm.reset();
    els.companyId.value = "";
    toggleCollapse(els.companyFormCollapse, false);
}

function editCompany(button) {
    els.companyId.value = button.dataset.id || "";
    els.companyName.value = button.dataset.name || "";
    toggleCollapse(els.companyFormCollapse, true);
}

async function saveCompany() {
    const id = els.companyId.value.trim();
    const name = els.companyName.value.trim();
    if (!name) {
        alert("O nome é obrigatório");
        return;
    }
    const result = await (id
        ? apiUpdateCompany(id, name)
        : apiCreateCompany(name));
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function deleteCompany(id) {
    if (
        !confirm("Apagar empresa? Todas as licenças associadas serão apagadas.")
    )
        return;
    const result = await apiDeleteCompany(id);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

/**
 * As licencas ja se desenham dentro da empresa a que pertencem, na lista das empresas.
 * O que fica por fazer aqui e encher o select de empresa do formulario.
 */
function renderLicensesSection(licenses, companies) {
    resetLicenseForm();
    const companyOptions = (companies || [])
        .map((s) => `<option value="${s.id}">${esc(s.name)}</option>`)
        .join("");
    els.licenseCompanySelect.innerHTML =
        '<option value="">Selecionar empresa</option>' + companyOptions;
}

function resetLicenseForm() {
    els.licenseForm.reset();
    els.licenseId.value = "";
    toggleCollapse(els.licenseFormCollapse, false);
}

function editLicense(button) {
    els.licenseId.value = button.dataset.id || "";
    els.licenseCompanySelect.value = button.dataset.companyId || "";
    els.licenseLicenseId.value = button.dataset.licenseId || "";
    els.licenseName.value = button.dataset.name || "";
    toggleCollapse(els.licenseFormCollapse, true);
}

async function saveLicense() {
    const id = els.licenseId.value.trim();
    const companyId = els.licenseCompanySelect.value;
    const licenseId = els.licenseLicenseId.value.trim();
    const name = els.licenseName.value.trim();
    if (!companyId) {
        alert("Selecione uma empresa");
        return;
    }
    if (!licenseId) {
        alert("O ID da licença é obrigatório");
        return;
    }
    const body = { companyId: Number(companyId), licenseId, name };
    const result = await apiSaveLicense(id, body);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function deleteLicense(id) {
    if (!confirm("Apagar licença?")) return;
    const result = await apiDeleteLicense(id);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function loadSettingsCompanySection(companiesPage = 1) {
    // As licenças vêm todas de uma vez porque são desenhadas dentro da empresa a que
    // pertencem: paginá-las à parte deixava uma licença fora da página da sua empresa.
    // ponytail: limite de 1000, que é muito acima do real; se um dia passar disso, a
    // resposta é buscar as licenças por empresa e não aumentar o número.
    const [companyData, licensesData] = await Promise.all([
        apiGetCompanies({ page: companiesPage }),
        apiGetLicenses({ page: 1, limit: 1000 }),
    ]);
    const companies = companyData.data || [];
    const licenses = licensesData.data || [];
    state.settingsModal.sectionLoaded.company = true;
    state.settingsModal.companyPagination = companyData.pagination || null;
    renderCompanySection(companies, licenses);
    renderLicensesSection(licenses, companies);
    renderSettingsPagination(
        state.settingsModal.companyPagination,
        els.settingsCompanyPagination,
        els.settingsCompanyPaginationSummary,
        els.settingsCompanyPaginationControls,
        "settingsCompanyPage",
    );
}

function handleCompanyListClick(event) {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.dataset.action === "editCompany") {
        editCompany(button);
    } else if (button.dataset.action === "deleteCompany") {
        void deleteCompany(Number(button.dataset.id));
    } else if (button.dataset.action === "editLicense") {
        editLicense(button);
    } else if (button.dataset.action === "deleteLicense") {
        void deleteLicense(Number(button.dataset.id));
    } else if (button.dataset.action === "newLicenseForCompany") {
        // A licença nasce dentro da empresa: o formulário abre com a empresa já escolhida.
        resetLicenseForm();
        els.licenseCompanySelect.value = button.dataset.companyId || "";
        toggleCollapse(els.licenseFormCollapse, true);
    }
}

// O `handleLicenseListClick` saiu com a tabela de licencas: as linhas das licencas vivem
// na lista das empresas, e e o `handleCompanyListClick` que as trata.

function activateSettingsSection(section) {
    state.settingsModal.section = section;
    const button =
        {
            models: els.settingsModelsTabBtn,
            capabilities: els.settingsCapabilitiesTabBtn,
            company: els.settingsCompanyTabBtn,
            apiUsers: els.settingsApiUsersTabBtn,
        }[section] || els.settingsModelsTabBtn;
    bootstrap.Tab.getOrCreateInstance(button).show();
}

export {
    activateSettingsSection,
    deleteCompany,
    deleteLicense,
    deleteApiUser,
    editApiUser,
    editCompany,
    editLicense,
    handleCompanyListClick,
    handleModelsListSearchInput,
    handleSettingsPaginationClick,
    loadSettingsApiUsersSection,
    loadSettingsCompanySection,
    loadSettingsModal,
    loadSettingsModelsSection,
    paginationActionPrefix,
    resetApiUserForm,
    resetCompanyForm,
    resetLicenseForm,
    resetModelForm,
    saveApiUser,
    saveCompany,
    saveLicense,
    saveModel,
    selectModelDeviceType,
    selectModelSupplier,
    syncApiUserRoleFields,
    toggleApiUser,
    updateModelProtocolAndPreview,
};
