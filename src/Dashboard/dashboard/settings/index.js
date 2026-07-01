import {
    createCompany as apiCreateCompany,
    deleteApiUser as apiDeleteApiUser,
    deleteCompany as apiDeleteCompany,
    deleteLicense as apiDeleteLicense,
    deleteModel as apiDeleteModel,
    getApiUsers as apiGetApiUsers,
    getCapabilities as apiGetCapabilities,
    getCompanies as apiGetCompanies,
    getLicenses as apiGetLicenses,
    getModel as apiGetModel,
    getSuppliers as apiGetSuppliers,
    saveApiUser as apiSaveApiUser,
    saveLicense as apiSaveLicense,
    saveModel as apiSaveModel,
    updateCompany as apiUpdateCompany,
    updateSupplier as apiUpdateSupplier,
} from "../api/index.js";
import { state } from "../state.js";
import { esc } from "../format.js";
import {
    emptyPanel,
    modelImageHtml,
    modelPreviewHtml,
    renderButtonGroup,
} from "../renderers.js";
import {
    apiRoleLabel,
    capabilitiesGroupedBySection,
    capabilityCatalogEntryByKey,
    capabilityLabelByKey,
    deviceTypeLabel,
    deviceTypeOptions,
    flattenedCapabilityKeys,
    humanizeCapabilityKey,
    licenseDisplayLabel,
    modelCommercialName,
    modelDeviceType,
    modelDisplayLabel,
    modelInternalName,
    normalizeDeviceType,
} from "../devices/list-detail.js";
import {
    renderPagination,
    resolvePaginationPage,
} from "../shared/pagination.js";
import {
    clearModelsFilters,
    editModel,
    handleActiveModelsFiltersClick,
    handleModelsListLimitChange,
    handleModelsListSearchInput,
    initSettingsModels,
    loadSettingsModelsSection,
    resetModelForm,
    revokeModelPreviewUrl,
    selectModelDeviceType,
    selectModelSupplier,
    selectModelsDeviceType,
    selectModelsSupplier,
    updateModelProtocolAndPreview,
} from "./models/index.js";

const SECTION_TRANSLATIONS = {
    telemetry: "Telemetria",
    health: "Saúde",
    contacts: "Contactos",
    alarms: "Alertas",
    settings_system: "Sistema",
};

const CAPABILITY_LABEL_TRANSLATIONS = {
    location: "Localização",
    heart_rate: "Frequência cardíaca",
    blood_pressure: "Pressão arterial",
    blood_oxygen: "Oxigénio no sangue",
    temperature: "Temperatura",
    breath_rate: "Frequência respiratória",
    sleep: "Sono",
    ecg: "ECG",
    hrv: "VFC",
    ppg: "PPG",
    rr_interval: "Intervalo RR",
    auto_vitals_interval: "Vitais automáticos",
    heart_rate_measurement_interval: "Frequência cardíaca",
    blood_pressure_measurement_interval: "Pressão arterial",
    blood_oxygen_measurement_interval: "Oxigénio no sangue",
    temperature_measurement_interval: "Temperatura",
    breath_rate_measurement_interval: "Frequência respiratória",
    ecg_measurement_interval: "ECG",
    hrv_measurement_interval: "VFC",
    ppg_measurement_interval: "PPG",
    rr_interval_measurement_interval: "Intervalo RR",
    heart_rate_continuous: "Frequência cardíaca contínua",
    blood_oxygen_continuous: "Oxigénio no sangue contínuo",
    blood_pressure_trend: "Tendência de pressão arterial",
    temperature_continuous: "Temperatura contínua",
    step_goal: "Meta de passos",
    sleep_monitoring: "Sono",
    blood_pressure_calibration: "Calibração de pressão arterial",
    step_reporting_interval: "Passos",
    pedometer_schedule: "Pedómetro",
    sos_contacts: "Contactos SOS",
    phonebook: "Contactos",
    call_whitelist: "Chamadas permitidas",
    monitor_number: "Número de monitorização",
    alarm_clock: "Despertador",
    medication_reminders: "Medicação",
    low_battery_alert: "Bateria fraca",
    fall_detection: "Quedas",
    fall_sensitivity: "Sensibilidade de queda",
    sos_sms_alert: "SOS SMS",
    blood_oxygen_alert: "Oxigénio no sangue",
    temperature_high_alert: "Temperatura alta",
    temperature_low_alert: "Temperatura baixa",
    blood_pressure_alert: "Pressão arterial",
    heart_rate_high_alert: "Frequência cardíaca alta",
    heart_rate_low_alert: "Frequência cardíaca baixa",
    remove_watch_alarm: "Remoção do relógio",
    remove_watch_sms_alert: "SMS de remoção do relógio",
    location_reporting_interval: "Localização",
    working_mode: "Modo de funcionamento",
    device_binding: "Vincular dispositivo",
    call_in_restriction: "Chamadas recebidas",
    device_settings_sync: "Sincronizar definições",
    device_password: "Palavra-passe",
    language_timezone: "Idioma e fuso horário",
};

let els;
let ui;

export function initSettings(context) {
    els = context.els;
    ui = context.ui;
    initSettingsModels({
        els,
        ui,
        callbacks: {
            ensureCapabilityCatalog,
            loadSettingsSuppliersSection,
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
    state.settingsModal.capabilityEnabledCapabilities = [];
    state.settingsModal.currentCapabilitiesModel = null;
    activateSettingsSection(section);
    ui.settingsModal.show();
    if (section === "suppliers") {
        void loadSettingsSuppliersSection();
    } else if (section === "models") {
        void loadSettingsModelsSection();
    } else if (section === "capabilities") {
        void loadSettingsCapabilitiesSection();
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

async function ensureCapabilityCatalog(deviceType) {
    const normalized = normalizeDeviceType(
        deviceType || state.settingsModal.capabilityDeviceType || "watch",
    );
    const cached = state.settingsModal.capabilityCatalogByType?.[normalized];
    if (cached) {
        state.settingsModal.capabilityCatalog = cached;
        return cached;
    }

    const response = await apiGetCapabilities({ deviceType: normalized });
    const catalog = response.data || [];
    state.settingsModal.capabilityCatalogByType = {
        ...(state.settingsModal.capabilityCatalogByType || {}),
        [normalized]: catalog,
    };
    state.settingsModal.capabilityCatalog = catalog;
    return catalog;
}

async function loadSettingsCapabilitiesSection(
    deviceType = state.settingsModal.capabilityDeviceType || "watch",
) {
    const normalized = normalizeDeviceType(deviceType);
    state.settingsModal.capabilityDeviceType = normalized;
    await ensureCapabilityCatalog(normalized);
    state.settingsModal.sectionLoaded.capabilities = true;
    renderCapabilitiesCatalogSection();
}

function renderCapabilitiesCatalogSection() {
    renderButtonGroup(
        els.capabilityDeviceTypeButtons,
        deviceTypeOptions,
        state.settingsModal.capabilityDeviceType || "watch",
        "selectCapabilityDeviceType",
    );

    const sections = capabilitiesGroupedBySection(
        state.settingsModal.capabilityCatalog,
    );
    els.capabilityCatalogEmpty.classList.toggle("d-none", sections.length > 0);
    els.capabilityCatalogViewer.innerHTML = sections
        .map(
            ({ section, label, entries }) => `
        <section class="border rounded bg-body-tertiary p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h6 mb-0">${esc(label)}</h3>
                <span class="small text-secondary">${entries.length} capacidades</span>
            </div>
            <div class="vstack gap-2">
                ${entries
                    .map(
                        (entry) => `
                    <div class="border rounded px-3 py-2 bg-white">
                        <div class="fw-semibold">${esc(entry.label || humanizeCapabilityKey(entry.key))}</div>
                        <div class="small text-secondary">${esc(entry.key)}</div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <span class="badge text-bg-${entry.isTelemetry ? "info" : "secondary"}">${entry.isTelemetry ? "telemetria" : "configuração"}</span>
                            <span class="badge text-bg-${entry.isConfigurable ? "primary" : "secondary"}">${entry.isConfigurable ? "configurável" : "não configurável"}</span>
                            <span class="badge text-bg-${entry.isRequestable ? "success" : "secondary"}">${entry.isRequestable ? "solicitável" : "não solicitável"}</span>
                        </div>
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </section>
    `,
        )
        .join("");
}

async function loadSettingsApiUsersSection(page = 1) {
    const response = await apiGetApiUsers({ page });
    const users = response.data || [];
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
        <td>${esc(supplier.name)}</td>
        <td>${supplier.model_count}</td>
        <td><span class="badge ${supplier.enabled ? "text-bg-success" : "text-bg-secondary"}">${supplier.enabled ? "ativo" : "inativo"}</span></td>
        <td>
        <button class="btn btn-outline-${supplier.enabled ? "warning" : "success"} btn-sm" data-id="${supplier.id}" data-enabled="${supplier.enabled ? "1" : ""}" data-action="toggleSupplier" title="${supplier.enabled ? "Desativar" : "Ativar"}"><i class="fa-solid fa-${supplier.enabled ? "pause" : "play"}"></i></button>
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
    els.apiUserListBody.innerHTML = (users || [])
        .map(
            (user) => `
        <tr>
        <td>${esc(user.username)}</td>
        <td><span class="badge text-bg-light border">${esc(apiRoleLabel(user.role))}</span></td>
        <td>${user.role === "hub_admin" ? '<span class="text-secondary">Todas</span>' : esc(user.license_id || "-")}</td>
        <td><span class="badge ${Number(user.enabled) === 1 ? "text-bg-success" : "text-bg-secondary"}">${Number(user.enabled) === 1 ? "ativo" : "inativo"}</span></td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-action="editApiUser" data-id="${user.id}" data-username="${esc(user.username)}" data-role="${esc(user.role)}" data-license-id="${esc(user.license_id || "")}" data-enabled="${Number(user.enabled) === 1 ? "1" : ""}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-${Number(user.enabled) === 1 ? "warning" : "success"} btn-sm" data-action="toggleApiUser" data-id="${user.id}" data-username="${esc(user.username)}" data-role="${esc(user.role)}" data-license-id="${esc(user.license_id || "")}" data-enabled="${Number(user.enabled) === 1 ? "1" : ""}" title="${Number(user.enabled) === 1 ? "Desativar" : "Ativar"}"><i class="fa-solid fa-${Number(user.enabled) === 1 ? "pause" : "play"}"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${user.id}" data-action="deleteApiUser" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`,
        )
        .join("");
}

function resetApiUserForm() {
    els.apiUserForm.reset();
    els.apiUserId.value = "";
    els.apiUserRole.value = "license_client";
    els.apiUserEnabled.checked = true;
    els.apiUserPassword.placeholder = "Obrigatória para novo utilizador";
    syncApiUserRoleFields();
}

function editApiUser(button) {
    els.apiUserId.value = button.dataset.id || "";
    els.apiUsername.value = button.dataset.username || "";
    els.apiUserRole.value = button.dataset.role || "license_client";
    els.apiUserLicenseId.value = button.dataset.licenseId || "";
    els.apiUserEnabled.checked = !!button.dataset.enabled;
    els.apiUserPassword.value = "";
    els.apiUserPassword.placeholder = "Deixar vazio para manter";
    syncApiUserRoleFields();
}

function syncApiUserRoleFields() {
    const isAdmin = els.apiUserRole.value === "hub_admin";
    els.apiUserLicenseId.disabled = isAdmin;
    if (isAdmin) {
        els.apiUserLicenseId.value = "";
    }
}

async function saveApiUser() {
    const id = els.apiUserId.value.trim();
    const body = {
        username: els.apiUsername.value.trim(),
        password: els.apiUserPassword.value,
        role: els.apiUserRole.value,
        licenseId: els.apiUserLicenseId.value.trim(),
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
    if (body.role === "license_client" && !body.licenseId) {
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
        licenseId: button.dataset.licenseId || "",
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
    els.companyListBody.innerHTML = (companies || [])
        .map(
            (item) => `
        <tr>
        <td>${esc(item.name)}</td>
        <td>${item.license_count ?? 0}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-action="editCompany" data-id="${item.id}" data-name="${esc(item.name)}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${item.id}" data-action="deleteCompany" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`,
        )
        .join("");
}

function resetCompanyForm() {
    els.companyForm.reset();
    els.companyId.value = "";
}

function editCompany(button) {
    els.companyId.value = button.dataset.id || "";
    els.companyName.value = button.dataset.name || "";
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
        <td>${esc(item.company_name || "-")}</td>
        <td>${esc(item.license_id)}</td>
        <td>${esc(item.name || "-")}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-action="editLicense" data-id="${item.id}" data-company-id="${item.company_id}" data-company-name="${esc(item.company_name || "")}" data-license-id="${esc(item.license_id)}" data-name="${esc(item.name || "")}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${item.id}" data-action="deleteLicense" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`,
        )
        .join("");
}

function resetLicenseForm() {
    els.licenseForm.reset();
    els.licenseId.value = "";
}

function editLicense(button) {
    els.licenseId.value = button.dataset.id || "";
    els.licenseCompanySelect.value = button.dataset.companyId || "";
    els.licenseLicenseId.value = button.dataset.licenseId || "";
    els.licenseName.value = button.dataset.name || "";
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

async function openModelDetail(modelId) {
    const response = await apiGetModel(modelId);
    const model = response.data || response;
    await ensureCapabilityCatalog(
        model.device_type || model.deviceType || "watch",
    );

    state.settingsModal.currentCapabilitiesModel = model;
    state.settingsModal.capabilityModelId = Number(model.id);
    state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(
        model.capabilities || {},
    );

    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbCurrent.textContent = modelCommercialName(model);
    els.modelsBreadcrumbCurrent.classList.remove("d-none");
    els.modelsBreadcrumbCurrent.classList.add("active");

    renderModelDetailInfo(model);
    renderCapabilitiesSection();

    if (state.settingsModal.modelsCarousel) {
        state.settingsModal.modelsCarousel.to(2);
    }
}

function renderModelDetailInfo(model) {
    const label = modelCommercialName(model);
    els.modelDetailImage.innerHTML = modelImageHtml(model)
        ? modelImageHtml(model).replace(
              'style="width:40px;height:40px;"',
              'style="max-height:120px;" class="object-fit-contain"',
          )
        : `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
    els.modelDetailName.textContent = label;
    els.modelDetailTitle.textContent = label;
    els.modelDetailSupplier.textContent = String(model.supplier || "");
    els.modelDetailSupplierValue.textContent = String(model.supplier || "");
    els.modelDetailTypeValue.textContent = deviceTypeLabel(
        modelDeviceType(model),
    );
    els.modelDetailInternalModelValue.textContent = modelInternalName(model);
}

function backToModelList() {
    if (!state.settingsModal.modelsCarousel) return;

    if (
        state.settingsModal.modelsCarousel._element.querySelector(
            ".carousel-item.active",
        ) ===
        state.settingsModal.modelsCarousel._element.firstElementChild
            ?.firstElementChild
    ) {
        return;
    }

    els.modelsBreadcrumbModels.classList.add("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbNew.classList.remove("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");
    els.modelsBreadcrumbCurrent.textContent = "";

    state.settingsModal.modelsCarousel.to(0);

    state.settingsModal.currentCapabilitiesModel = null;
    state.settingsModal.sectionLoaded.models = false;
    state.settingsModal.sectionLoaded.modelFilters = false;
    void loadSettingsModelsSection();
}

function openNewModelForm() {
    resetModelForm();

    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.textContent = "Novo modelo";
    els.modelsBreadcrumbNew.classList.remove("d-none");
    els.modelsBreadcrumbNew.classList.add("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");

    if (state.settingsModal.modelsCarousel) {
        state.settingsModal.modelsCarousel.to(1);
    }
}

function editCurrentModel() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) return;

    editModel(
        Number(model.id),
        Number(model.supplier_id || model.supplierId || 0),
        model.supplier || "",
        model.internal_model || model.internalModel || "",
        model.commercial_name || model.commercialName || "",
        model.device_type || model.deviceType || "watch",
        model.image || "",
    );

    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.textContent = `Editar: ${modelCommercialName(model)}`;
    els.modelsBreadcrumbNew.classList.remove("d-none");
    els.modelsBreadcrumbNew.classList.add("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");

    if (state.settingsModal.modelsCarousel) {
        state.settingsModal.modelsCarousel.to(1);
    }
}

async function deleteCurrentModel() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) return;
    if (
        !window.confirm(
            `Tem a certeza que deseja apagar o modelo "${modelCommercialName(model)}"?`,
        )
    )
        return;

    await apiDeleteModel(model.id);
    backToModelList();
}

function renderCapabilitiesSection() {
    const model = state.settingsModal.currentCapabilitiesModel;
    const enabled = new Set(
        state.settingsModal.capabilityEnabledCapabilities || [],
    );
    const catalogSections = capabilitiesGroupedBySection(
        state.settingsModal.capabilityCatalog,
    );

    if (model) {
        const label = modelCommercialName(model);
        els.modelDetailImage.innerHTML = modelImageHtml(model)
            ? modelImageHtml(model).replace(
                  'style="width:40px;height:40px;"',
                  'style="max-height:120px;" class="object-fit-contain"',
              )
            : `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
        els.modelDetailName.textContent = label;
    } else {
        els.modelDetailImage.innerHTML = `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">Modelo</div></div>`;
        els.modelDetailName.textContent = "Modelo";
    }

    const capabilities =
        model?.capabilities && typeof model.capabilities === "object"
            ? model.capabilities
            : {};

    els.capabilityTitle.textContent = model
        ? modelCommercialName(model)
        : "Capacidades";
    els.capabilitySubtitle.textContent = String(model?.supplier || "");

    const sections = catalogSections
        .map(({ section, label, entries }) => {
            const sectionEntries = entries
                .filter((entry) => entry.isTelemetry || entry.isConfigurable)
                .map((entry) => entry.key);
            if (sectionEntries.length === 0) {
                return null;
            }
            return { section, label, entries: sectionEntries };
        })
        .filter(Boolean);

    const totalCapabilities = sections.reduce(
        (count, item) => count + item.entries.length,
        0,
    );
    els.capabilitySummary.textContent = `${enabled.size}/${totalCapabilities} ativos`;

    const sectionButtonConfig = {
        telemetry: { icon: "fa-chart-line", color: "btn-outline-info" },
        health: { icon: "fa-heart-pulse", color: "btn-outline-success" },
        contacts: { icon: "fa-address-book", color: "btn-outline-primary" },
        alarms: { icon: "fa-bell", color: "btn-outline-danger" },
        settings_system: { icon: "fa-gear", color: "btn-outline-secondary" },
    };

    let activeSection = state.settingsModal.activeCapabilitySection;
    if (!activeSection || !sections.some((s) => s.section === activeSection)) {
        activeSection = sections[0]?.section || "";
        state.settingsModal.activeCapabilitySection = activeSection;
    }

    els.capabilitySectionNav.innerHTML = sections
        .map(({ section, label }) => {
            const cfg = sectionButtonConfig[section] || {
                icon: "fa-gear",
                color: "btn-secondary",
            };
            const isActive = section === activeSection;
            const ptLabel = SECTION_TRANSLATIONS[section] || label;
            return `
        <button type="button" class="btn btn-sm flex-fill ${cfg.color} ${isActive ? "active" : ""} d-flex align-items-center justify-content-center gap-2" data-action="jumpCapabilitySection" data-section="${esc(section)}">
            <i class="fa-solid ${cfg.icon}"></i> ${esc(ptLabel)}
        </button>`;
        })
        .join("");

    const section = sections.find((s) => s.section === activeSection);
    if (section) {
        const sectionLabel =
            SECTION_TRANSLATIONS[section.section] || section.label;
        els.capabilityGroups.innerHTML = `
        <section class="border rounded bg-body-tertiary p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h6 mb-0">${esc(sectionLabel)}</h3>
                <span class="small text-secondary">${section.entries.filter((f) => enabled.has(f)).length}/${section.entries.length} ativos</span>
            </div>
            <div class="d-flex flex-column gap-2">
                ${section.entries
                    .map((feature) => {
                        const labelText =
                            CAPABILITY_LABEL_TRANSLATIONS[feature] ||
                            capabilityLabelByKey(feature);
                        const sectionState =
                            capabilities[section.section] || {};
                        const isInModelPayload =
                            Object.prototype.hasOwnProperty.call(
                                sectionState,
                                feature,
                            );
                        return `
                        <div class="d-flex justify-content-between align-items-start gap-3 border rounded px-3 py-2 bg-white">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilityRequest" data-feature="${esc(feature)}" id="cap-${esc(feature)}" ${enabled.has(feature) ? "checked" : ""}>
                                <label class="form-check-label" for="cap-${esc(feature)}">${esc(labelText)}</label>
                                ${!isInModelPayload ? '<div class="small text-secondary">Disponível no catálogo do tipo de dispositivo.</div>' : ""}
                            </div>
                            ${
                                section.section === "telemetry" &&
                                capabilityCatalogEntryByKey(feature)
                                    ?.isRequestable
                                    ? `<span class="badge bg-success bg-opacity-10 text-success px-3 py-2 fw-medium">${esc("Solicitável")}</span>`
                                    : ""
                            }
                        </div>`;
                    })
                    .join("")}
            </div>
        </section>`;
    } else {
        els.capabilityGroups.innerHTML = "";
    }
}

async function saveCapabilities() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) {
        alert("Selecione um modelo");
        return;
    }

    const body = new FormData();
    body.append("supplier_id", String(model.supplier_id));
    body.append("internalModel", String(modelInternalName(model)));
    body.append("commercialName", String(modelCommercialName(model)));
    body.append("deviceType", String(modelDeviceType(model)));
    body.append("protocol", String(model.protocol || ""));
    body.append("capabilitiesConfigured", "1");
    for (const feature of state.settingsModal.capabilityEnabledCapabilities ||
        []) {
        body.append("capabilities[]", String(feature));
    }

    const result = await apiSaveModel(model.id, body);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }

    backToModelList();
}

export {
    activateSettingsSection,
    backToModelList,
    clearModelsFilters,
    deleteCompany,
    deleteCurrentModel,
    deleteLicense,
    deleteApiUser,
    editApiUser,
    editCompany,
    editCurrentModel,
    editLicense,
    handleActiveModelsFiltersClick,
    handleCompanyListClick,
    handleLicenseListClick,
    handleModelsListLimitChange,
    handleModelsListSearchInput,
    handleSettingsPaginationClick,
    loadSettingsApiUsersSection,
    loadSettingsCapabilitiesSection,
    loadSettingsCompanySection,
    loadSettingsModal,
    loadSettingsModelsSection,
    loadSettingsSuppliersSection,
    openModelDetail,
    openNewModelForm,
    paginationActionPrefix,
    renderCapabilitiesSection,
    resetApiUserForm,
    resetCompanyForm,
    resetLicenseForm,
    resetModelForm,
    revokeModelPreviewUrl,
    saveApiUser,
    saveCapabilities,
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
