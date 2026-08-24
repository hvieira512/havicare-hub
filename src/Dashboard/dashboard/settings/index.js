import {
    createCompany as apiCreateCompany,
    deleteApiUser as apiDeleteApiUser,
    deleteCompany as apiDeleteCompany,
    deleteLicense as apiDeleteLicense,
    getApiUsers as apiGetApiUsers,
    getCompanies as apiGetCompanies,
    getLicenses as apiGetLicenses,
    getSuppliers as apiGetSuppliers,
    saveApiUser as apiSaveApiUser,
    saveLicense as apiSaveLicense,
    saveModel as apiSaveModel,
    updateCompany as apiUpdateCompany,
    updateSupplier as apiUpdateSupplier,
} from "../api/index.js";
import { state } from "../state.js";
import { esc } from "../format.js";
import {modelPreviewHtml} from "../renderers.js";
import {apiRoleLabel} from "../devices/list-detail.js";
import {normalizeDeviceType} from "../domain.js";
import {
    renderPagination,
    resolvePaginationPage,
} from "../pagination.js";
import {
    clearModelsFilters,
    handleActiveModelsFiltersClick,
    handleModelsListLimitChange,
    handleModelsListSearchInput,
    initSettingsModels,
    loadSettingsModelsSection,
    resetModelForm,
    selectModelDeviceType,
    selectModelSupplier,
    selectModelsDeviceType,
    selectModelsSupplier,
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

export function initSettings(context) {
    els = context.els;
    ui = context.ui;
    initSettingsCapabilities({ els, ui });
    initSettingsModels({
        els,
        ui,
        callbacks: {
            ensureCapabilityCatalog,
            loadSettingsSuppliersSection,
            refreshNewModelCapabilityTemplate,
            renderSettingsPagination,
        },
    });
}

async function loadSettingsModal(
    section = state.settingsModal.section || "suppliers",
) {
    state.settingsModal.sectionLoaded = {
        suppliers: false,
        models: false,
        modelFilters: false,
        capabilities: false,
        company: false,
        apiUsers: false,
    };
    state.settingsModal.suppliersPagination = null;
    state.settingsModal.modelsPagination = null;
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
    if (section === "suppliers") {
        void loadSettingsSuppliersSection();
    } else if (section === "models") {
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
            suppliersPagination: "settingsSuppliersPage",
            modelsPagination: "settingsModelsPage",
            apiUsersPagination: "settingsApiUsersPage",
            companyPagination: "settingsCompanyPage",
            licensesPagination: "settingsLicensesPage",
        }[paginationKey] || ""
    );
}

async function loadSettingsSuppliersSection(page = 1) {
    const response = await apiGetSuppliers({ page });
    const suppliers = response.data || [];
    state.settingsModal.suppliersPagination = response.pagination || null;
    state.modelModalSuppliers = suppliers;
    state.settingsModal.sectionLoaded.suppliers = true;
    renderSuppliersSection(suppliers);
    renderSettingsPagination(
        state.settingsModal.suppliersPagination,
        els.settingsSuppliersPagination,
        els.settingsSuppliersPaginationSummary,
        els.settingsSuppliersPaginationControls,
        "settingsSuppliersPage",
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

function renderSuppliersSection(suppliers) {
    els.supplierListBody.innerHTML = (suppliers || [])
        .map(
            (supplier) => `
        <tr>
        <td>
            <span class="config-state ${supplier.enabled ? "config-state-success" : "config-state-secondary"}">
                <span class="config-state-dot"></span>${supplier.enabled ? "Ativo" : "Inativo"}
            </span>
        </td>
        <td class="fw-semibold">${esc(supplier.name)}</td>
        <td class="tabular-nums">${supplier.model_count}</td>
        <td class="text-end">
        <button class="btn btn-outline-secondary btn-sm row-action" data-id="${supplier.id}" data-enabled="${supplier.enabled ? "1" : ""}" data-action="toggleSupplier" title="${supplier.enabled ? "Desativar" : "Ativar"}"><i class="fa-solid fa-${supplier.enabled ? "pause" : "play"}"></i></button>
        </td>
        </tr>`,
        )
        .join("");
}

async function toggleSupplier(id, enabled) {
    const result = await apiUpdateSupplier(id, { enabled: !enabled });
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }
    state.settingsModal.sectionLoaded.suppliers = false;
    await loadSettingsSuppliersSection();
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
        els.apiUsersTabSummary.textContent = total === 0
            ? "Nenhum utilizador"
            : `${total} ${total === 1 ? "utilizador" : "utilizadores"}`
              + (admins ? ` · ${admins} com acesso a todas as licenças` : "");
    }
    els.apiUserListBody.innerHTML = (users || [])
        .map(
            (user) => `
        <tr>
        <td>
            <span class="config-state ${Number(user.enabled) === 1 ? "config-state-success" : "config-state-secondary"}">
                <span class="config-state-dot"></span>${Number(user.enabled) === 1 ? "Ativo" : "Inativo"}
            </span>
        </td>
        <td class="fw-semibold">${esc(user.username)}</td>
        <td class="section-label">${esc(apiRoleLabel(user.role))}</td>
        <td>${user.role === "hub_admin"
            // O âmbito é a informação com mais consequência da tabela -- quem vê os dados
            // de que licença. "Todas" estava em cinzento mais fraco que o resto da linha,
            // como se fosse um valor por omissão sem importância. É um privilégio.
            ? '<span class="config-state"><span class="config-state-dot"></span>Todas as licenças</span>'
            : esc(user.company_name && user.license_id ? `${user.company_name} / ${user.license_id}` : "Sem licença válida")}</td>
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

function renderCompanySection(companies) {
    resetCompanyForm();
    if (els.companiesTabSummary) {
        const total = (companies || []).length;
        const licenses = (companies || []).reduce(
            (sum, item) => sum + Number(item.license_count ?? 0),
            0,
        );
        els.companiesTabSummary.textContent =
            `${total} ${total === 1 ? "empresa" : "empresas"} · ${licenses} ${licenses === 1 ? "licença" : "licenças"}`;
    }
    els.companyListBody.innerHTML = (companies || [])
        .map(
            (item) => `
        <tr>
        <td class="fw-semibold">${esc(item.name)}</td>
        <td>
            <span class="config-state config-state-secondary"><span class="config-state-dot"></span>${item.license_count ?? 0} ${Number(item.license_count) === 1 ? "licença" : "licenças"}</span>
        </td>
        <td class="text-end text-nowrap">
        <button class="btn btn-outline-secondary btn-sm row-action" data-action="editCompany" data-id="${item.id}" data-name="${esc(item.name)}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-secondary btn-sm row-action row-action-danger" data-id="${item.id}" data-action="deleteCompany" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`,
        )
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

function renderLicensesSection(licenses, companies) {
    resetLicenseForm();
    const companyOptions = (companies || [])
        .map((s) => `<option value="${s.id}">${esc(s.name)}</option>`)
        .join("");
    els.licenseCompanySelect.innerHTML =
        '<option value="">Selecionar empresa</option>' + companyOptions;
    els.licenseListBody.innerHTML = (licenses || [])
        .map(
            (item) => `
        <tr>
        <td class="section-label">${esc(item.company_name || "-")}</td>
        <td class="tabular-nums fw-semibold">${esc(item.license_id)}</td>
        <td>${esc(item.name || "-")}</td>
        <td class="text-end text-nowrap">
        <button class="btn btn-outline-secondary btn-sm row-action" data-action="editLicense" data-id="${item.id}" data-company-id="${item.company_id}" data-company-name="${esc(item.company_name || "")}" data-license-id="${esc(item.license_id)}" data-name="${esc(item.name || "")}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-secondary btn-sm row-action row-action-danger" data-id="${item.id}" data-action="deleteLicense" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`,
        )
        .join("");
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

async function loadSettingsCompanySection(companiesPage = 1, licensesPage = 1) {
    const [companyData, licensesData] = await Promise.all([
        apiGetCompanies({ page: companiesPage }),
        apiGetLicenses({ page: licensesPage }),
    ]);
    const companies = companyData.data || [];
    const licenses = licensesData.data || [];
    state.settingsModal.sectionLoaded.company = true;
    state.settingsModal.companyPagination = companyData.pagination || null;
    state.settingsModal.licensesPagination = licensesData.pagination || null;
    renderCompanySection(companies);
    renderLicensesSection(licenses, companies);
    renderSettingsPagination(
        state.settingsModal.companyPagination,
        els.settingsCompanyPagination,
        els.settingsCompanyPaginationSummary,
        els.settingsCompanyPaginationControls,
        "settingsCompanyPage",
    );
    renderSettingsPagination(
        state.settingsModal.licensesPagination,
        els.settingsLicensesPagination,
        els.settingsLicensesPaginationSummary,
        els.settingsLicensesPaginationControls,
        "settingsLicensesPage",
    );
}

function handleCompanyListClick(event) {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.dataset.action === "editCompany") {
        editCompany(button);
    } else if (button.dataset.action === "deleteCompany") {
        void deleteCompany(Number(button.dataset.id));
    }
}

function handleLicenseListClick(event) {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.dataset.action === "editLicense") {
        editLicense(button);
    } else if (button.dataset.action === "deleteLicense") {
        void deleteLicense(Number(button.dataset.id));
    }
}

function activateSettingsSection(section) {
    state.settingsModal.section = section;
    const button =
        {
            suppliers: els.settingsSuppliersTabBtn,
            models: els.settingsModelsTabBtn,
            capabilities: els.settingsCapabilitiesTabBtn,
            company: els.settingsCompanyTabBtn,
            apiUsers: els.settingsApiUsersTabBtn,
        }[section] || els.settingsSuppliersTabBtn;
    bootstrap.Tab.getOrCreateInstance(button).show();
}

export {
    activateSettingsSection,
    clearModelsFilters,
    deleteCompany,
    deleteLicense,
    deleteApiUser,
    editApiUser,
    editCompany,
    editLicense,
    handleActiveModelsFiltersClick,
    handleCompanyListClick,
    handleLicenseListClick,
    handleModelsListLimitChange,
    handleModelsListSearchInput,
    handleSettingsPaginationClick,
    loadSettingsApiUsersSection,
    loadSettingsCompanySection,
    loadSettingsModal,
    loadSettingsModelsSection,
    loadSettingsSuppliersSection,
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
    selectModelsDeviceType,
    selectModelsSupplier,
    syncApiUserRoleFields,
    toggleApiUser,
    toggleSupplier,
    updateModelProtocolAndPreview,
};
