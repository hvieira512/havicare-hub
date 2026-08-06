import {
    deleteDevice as apiDeleteDevice,
    getCapabilities as apiGetCapabilities,
    getCompanies as apiGetCompanies,
    getDevice as apiGetDevice,
    getLicenses as apiGetLicenses,
    requestCapability as apiRequestCapability,
    saveConfiguration as apiSaveConfiguration,
    saveDevice as apiSaveDevice,
} from "../api/index.js";
import {esc} from "../format.js";
import {emptyPanel, modelPreviewHtml, renderButtonGroup} from "../renderers.js";
import {
    catalogForProtocol,
    readConfigPayload,
    renderDeviceConfigurationRoot,
    takePillsReminderGroup,
    wonlexMedicationPlanRow,
} from "../config.js";
import {
    normalizePhoneControl,
    renderPhoneControl,
    resetPhoneControls,
    syncPhoneControl,
} from "../phone.js";
import {selectImei, setTelemetryPage, state} from "../state.js";
import {cacheElements} from "./dom.js";
import {
    clearStorageKey,
    loadJsonStorage,
    loadTextStorage,
    saveJsonStorage,
    saveTextStorage,
} from "./storage.js";
import {
    clearSelection,
    deriveFourPTouchDeviceId,
    deviceTypeLabel,
    deviceTypeOptions,
    ensureDeviceTypeSuppliersModelsLoaded,
    findModelInfo,
    handleDeviceListLimitChange,
    handleDeviceListSearchInput,
    handleDevicePaginationClick,
    initDeviceListDetail,
    isDeviceSelectorOpen,
    isFourPTouchSelection,
    licenseDisplayLabel,
    loadDevice,
    loadSummary,
    ensureProtocolsLoaded,
    modelCommercialName,
    modelDisplayLabel,
    modelDisplayName,
    modelInternalName,
    modelDeviceType,
    modelsForSupplierAndType,
    normalizeDeviceType,
    normalizeFilterValue,
    openDeviceSelector,
    selectDevice,
    supplierProtocol,
    suppliersForDeviceType,
} from "../devices/list-detail.js";
import {
    allDetailItems,
    applyDetailFilters,
    clearDetailFilters,
    filterDetailItems,
    updateDetailFilterDraft,
    requestTelemetryFeature,
    renderTelemetryList,
    renderSelection,
} from "../devices/detail-view.js";
import {disconnectDeviceStream, initDeviceStream} from "../devices/stream.js";
import {
    clearTakePillsRecording,
    loadTakePillsAudio,
    startTakePillsRecording,
    stopTakePillsRecording,
    syncTakePillsCustomVisibility,
    syncTakePillsVoiceVisibility,
} from "../devices/take-pills-audio.js";
import {initNotifications} from "../notifications.js";
import {
    clearModelsFilters,
    deleteApiUser,
    editApiUser,
    handleActiveModelsFiltersClick,
    handleCompanyListClick,
    handleLicenseListClick,
    handleModelsListLimitChange,
    handleModelsListSearchInput,
    handleSettingsPaginationClick,
    initSettings,
    loadSettingsApiUsersSection,
    loadSettingsCompanySection,
    loadSettingsModal,
    loadSettingsModelsSection,
    loadSettingsSuppliersSection,
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
} from "../settings/index.js";
import {
    backToModelList,
    applyDiscoveryPreview,
    deleteCurrentModel,
    editCurrentModel,
    handleCapabilitySupplierClick,
    handleDiscoveryDeviceChange,
    generateDiscoveryPreview,
    loadSettingsCapabilitiesSection,
    loadDiscoveryDevices,
    openModelDetail,
    openNewModelForm,
    renderCapabilitiesSection,
    revokeModelPreviewUrl,
    saveCapabilities,
    selectCapabilitySupplier,
} from "../settings/capabilities.js";

let els = {};
let deviceModal = null;
let deviceSelectorModal = null;
let settingsModal = null;
const configFeedbackTimers = new Map();
const configPhaseTimers = new Map();
let deviceConfigRefreshPromise = null;
const FILTERS_STORAGE_KEY = "hub-dashboard-device-filters";
const SELECTED_DEVICE_STORAGE_KEY = "hub-dashboard-selected-device";


async function populateCompanySelect() {
    if (state.companies.length === 0) {
        const data = await apiGetCompanies({ limit: 500 });
        state.companies = data?.error ? [] : data.data || [];
    }
    if (state.companies.length === 0) {
        els.deviceCompanySelect.innerHTML =
            '<option value="">Sem empresa</option>';
        return false;
    }
    els.deviceCompanySelect.innerHTML =
        '<option value="">Sem empresa</option>' +
        state.companies
            .map(
                (s) =>
                    `<option value="${esc(s.name)}">${esc(s.name)}</option>`,
            )
            .join("");
    return true;
}

async function populateLicenseSelectForCompany(companyName) {
    const select = els.deviceLicenseSelect;
    if (!companyName) {
        select.innerHTML = '<option value="0">Nenhuma</option>';
        select.disabled = true;
        els.deviceLicenseId.value = "0";
        return;
    }
    let company = state.companies.find((s) => s.name === companyName);
    if (!company) {
        const fallback = await apiGetCompanies({ limit: 500 });
        state.companies = fallback?.error ? [] : fallback.data || [];
        company = state.companies.find((s) => s.name === companyName);
    }
    if (!company) {
        select.innerHTML = '<option value="0">Nenhuma</option>';
        select.disabled = true;
        els.deviceLicenseId.value = "0";
        return false;
    }
    const licData = await apiGetLicenses({
        limit: 500,
        companyId: company.id,
    });
    if (licData?.error) {
        select.innerHTML = '<option value="0">Nenhuma</option>';
        select.disabled = true;
        els.deviceLicenseId.value = "0";
        return false;
    }
    const licenses = licData.data || [];
    select.innerHTML =
        '<option value="0">Nenhuma</option>' +
        licenses
            .map(
                (l) =>
                    `<option value="${esc(l.license_id)}">${esc(l.license_id)}${l.name ? ` — ${esc(l.name)}` : ""}</option>`,
            )
            .join("");
    select.disabled = false;
    return true;
}

function handleCompanySelect() {
    const companyName = els.deviceCompanySelect.value;
    els.deviceCompany.value = companyName || "";
    if (companyName) {
        void populateLicenseSelectForCompany(companyName);
    } else {
        els.deviceLicenseSelect.innerHTML =
            '<option value="0">Nenhuma</option>';
        els.deviceLicenseSelect.disabled = true;
        els.deviceLicenseId.value = "0";
    }
}

function handleLicenseSelect() {
    els.deviceLicenseId.value = els.deviceLicenseSelect.value || "0";
}

function setDeviceFormError(message = "") {
    state.deviceModal.errorMessage = String(message || "");
    if (!els.deviceFormError) {
        return;
    }
    els.deviceFormError.textContent = state.deviceModal.errorMessage;
    els.deviceFormError.classList.toggle(
        "d-none",
        state.deviceModal.errorMessage === "",
    );
}

function activeDeviceModalTab() {
    return els.deviceConfigTabBtn?.classList.contains("active")
        || els.deviceConfigPane?.classList.contains("active")
        ? "config"
        : "general";
}

async function openAddDevice(source = "") {
    await ensureDeviceTypeSuppliersModelsLoaded();
    const notification = source && typeof source === "object" ? source : null;
    const identity = String(notification?.imei || source || "").trim();
    const protocol = String(notification?.protocol || "").trim();
    const reportedModel = String(notification?.model || "").trim();
    const protocolModels = (state.deviceTypeSuppliersModels || []).filter(
        (model) => String(model.protocol || "") === protocol,
    );
    const detectedModel = protocolModels.find(
        (model) =>
            modelInternalName(model) === reportedModel
            || modelCommercialName(model) === reportedModel,
    ) || protocolModels[0] || null;
    const detectedDeviceType = detectedModel
        ? modelDeviceType(detectedModel)
        : "watch";
    const detectedSupplier = String(detectedModel?.supplier || "");
    const detectedModelName = reportedModel === ""
        ? ""
        : modelInternalName(detectedModel);

    els.deviceModalLabel.textContent = "Adicionar dispositivo";
    els.deviceForm.reset();
    delete els.deviceImei.dataset.originalImei;
    resetConfigUiState();
    state.deviceModal = {
        mode: "create",
        activeTab: "general",
        activeCategory: "",
        imei: "",
        originalImei: "",
        deviceType: "watch",
        licenseId: "0",
        simNumber: "",
        deviceId: "",
        supplier: "",
        model: "",
        protocol: "",
        catalog: [],
        capabilityCatalog: [],
        catalogLoading: false,
        configurations: [],
        configurationSync: {entries: {}},
        capabilities: {},
        enabledCapabilityKeys: [],
        configUi: {},
        errorMessage: "",
        loading: false,
    };
    setDeviceFormError("");
    els.deviceConfigTabBtn?.classList.add("d-none");
    els.deviceConfigTabBtn?.classList.remove("active");
    els.deviceConfigTabBtn?.setAttribute("aria-selected", "false");
    els.deviceConfigPane?.classList.remove("show", "active");
    els.deviceGeneralTabBtn?.classList.add("active");
    els.deviceGeneralTabBtn?.setAttribute("aria-selected", "true");
    els.deviceGeneralPane?.classList.add("show", "active");
    els.deleteDeviceBtn.classList.add("d-none");
    renderDeviceSimNumberField("");
    renderDeviceTypeSelector(detectedDeviceType);
    await populateCompanySelect();
    els.deviceCompany.value = "";
    els.deviceLicenseSelect.innerHTML = '<option value="0">Nenhuma</option>';
    els.deviceLicenseSelect.disabled = true;
    els.deviceLicenseId.value = "0";
    els.deviceDeviceId.value = detectedDeviceType === "watch"
        ? ""
        : String(notification?.ident || identity).trim();
    await renderDeviceSelectors(
        detectedSupplier,
        detectedModelName,
        detectedDeviceType,
    );
    els.deviceImei.value = detectedDeviceType === "watch" ? identity : "";
    const identityInput = detectedDeviceType === "watch"
        ? els.deviceImei
        : els.deviceDeviceId;
    identityInput.dispatchEvent(new Event("input", {bubbles: true}));
    deviceModal.show();
    if (identityInput.value !== "") {
        identityInput.focus();
    }
}

async function editDevice(imei, supplier, model) {
    await ensureDeviceTypeSuppliersModelsLoaded();
    const activeTab = activeDeviceModalTab();
    els.deviceModalLabel.textContent = "Editar dispositivo";
    els.deviceImei.value = imei;
    els.deviceImei.dataset.originalImei = imei;
    resetConfigUiState();
    state.deviceModal = {
        mode: "edit",
        activeTab,
        activeCategory: "",
        imei,
        originalImei: imei,
        deviceType: "watch",
        licenseId: "0",
        simNumber: "",
        deviceId: "",
        supplier,
        model,
        protocol: "",
        catalog: [],
        capabilityCatalog: [],
        catalogLoading: false,
        configurations: [],
        configurationSync: {entries: {}},
        capabilities: {},
        enabledCapabilityKeys: [],
        configUi: {},
        errorMessage: "",
        loading: true,
    };
    setDeviceFormError("");
    els.deviceConfigTabBtn?.classList.remove("d-none");
    els.deleteDeviceBtn.dataset.imei = imei;
    els.deleteDeviceBtn.classList.remove("d-none");
    renderDeviceTypeSelector("watch");
    const companiesLoaded = await populateCompanySelect();
    els.deviceCompany.value = "";
    els.deviceLicenseSelect.innerHTML = '<option value="0">Nenhuma</option>';
    els.deviceLicenseSelect.disabled = true;
    els.deviceLicenseId.value = "0";
    await renderDeviceSelectors(supplier, model);
    renderDeviceSimNumberField("");
    deviceModal.show();

    try {
        const detail = await apiGetDevice(imei);
        if (detail?.error) {
            setDeviceFormError(detail.error.message || "Nao foi possivel carregar o dispositivo.");
            return;
        }
        const device = detail.device || {};
        const deviceModel = detail.model;
        const deviceType = String(deviceModel?.deviceType || "watch");
        const licenseId = String(device.licenseId || "0");
        const deviceCompany = String(device.company || "");
        renderDeviceTypeSelector(deviceType);
        await renderDeviceSelectors(
            String(deviceModel?.supplier || supplier),
            String(deviceModel?.internalModel || model),
            deviceType,
        );
        if (deviceCompany !== "" && deviceCompany !== "null") {
            const optExists = [...els.deviceCompanySelect.options].some(
                (o) => o.value === deviceCompany,
            );
            if (!optExists) {
                const opt = document.createElement("option");
                opt.value = deviceCompany;
                opt.textContent = deviceCompany;
                els.deviceCompanySelect.appendChild(opt);
            }
            els.deviceCompanySelect.value = deviceCompany;
            els.deviceCompany.value = deviceCompany;
            const licensesLoaded =
                await populateLicenseSelectForCompany(deviceCompany);
            if (licensesLoaded && licenseId !== "0" && licenseId !== "") {
                const licOptExists = [...els.deviceLicenseSelect.options].some(
                    (o) => o.value === licenseId,
                );
                if (licOptExists) {
                    els.deviceLicenseSelect.value = licenseId;
                    els.deviceLicenseId.value = licenseId;
                }
            }
        }
        state.deviceModal.deviceType = normalizeDeviceType(deviceType);
        state.deviceModal.licenseId = licenseId;
        renderDeviceSimNumberField(String(device.simNumber || ""));
        state.deviceModal.simNumber = String(device.simNumber || "");
        els.deviceDeviceId.value = String(device.deviceId || "");
        applyFourPTouchDeviceIdUi();
        state.deviceModal.deviceId = String(device.deviceId || "");
        state.deviceModal.configurations = detail.configurations || {};
        state.deviceModal.configurationSync = detail.configurationSync || {entries: {}};
        state.deviceModal.capabilities = detail.capabilities || {};
        state.deviceModal.enabledCapabilityKeys = detail.enabledCapabilityKeys || [];
    } finally {
        if (!companiesLoaded && state.deviceModal.errorMessage === "") {
            setDeviceFormError("Ligacao ao servidor indisponivel.");
        }
        state.deviceModal.loading = false;
        await syncDeviceModalContext();
        renderDeviceConfigurationModal();
    }
}

async function renderDeviceSelectors(
    selectedSupplier = "",
    selectedModel = "",
    deviceType = "",
) {
    const currentDeviceType = normalizeDeviceType(
        deviceType || els.deviceForm.dataset.deviceType || "watch",
    );
    const models = state.deviceTypeSuppliersModels || [];
    const suppliers = suppliersForDeviceType(currentDeviceType, models);
    const supplier = suppliers.includes(selectedSupplier)
        ? selectedSupplier
        : suppliers[0] || "";
    const availableModels = modelsForSupplierAndType(
        supplier,
        currentDeviceType,
        models,
    ).filter(
        (entry) =>
            modelInternalName(entry) !== "4P-TOUCH" &&
            modelCommercialName(entry) !== "4P-TOUCH",
    );
    const availableModelNames = availableModels.map((model) =>
        modelInternalName(model),
    );
    const model = availableModelNames.includes(selectedModel)
        ? selectedModel
        : availableModelNames[0] || "";

    els.deviceForm.dataset.supplier = supplier;
    els.deviceForm.dataset.model = model;

    renderButtonGroup(
        els.deviceSupplierButtons,
        suppliers.map((value) => ({ value, label: value })),
        supplier,
        "selectDeviceSupplier",
    );
    renderButtonGroup(
        els.deviceModelButtons,
        availableModels.map((entry) => ({
            value: modelInternalName(entry),
            label: modelDisplayLabel(entry),
        })),
        model,
        "selectDeviceModel",
    );
    updateDevicePreview();
    await syncDeviceModalContext();
    renderDeviceConfigurationModal();
}

function renderDeviceTypeSelector(selectedType = "watch") {
    const deviceType = normalizeDeviceType(selectedType);
    els.deviceForm.dataset.deviceType = deviceType;
    renderButtonGroup(
        els.deviceTypeButtons,
        deviceTypeOptions,
        deviceType,
        "selectDeviceType",
    );

    const showImeiSim = deviceType === "watch";
    const showDeviceId = deviceType !== "watch";
    els.deviceImeiRow?.classList.toggle("d-none", !showImeiSim);
    els.deviceSimRow?.classList.toggle("d-none", !showImeiSim);
    els.deviceDeviceIdRow?.classList.toggle("d-none", !showDeviceId);

    if (deviceType === "ncs") {
        els.deviceDeviceIdLabel.textContent = "Device ID (MAC)";
        els.deviceDeviceIdHelp.textContent =
            "MAC address do dispositivo NCS (ex.: bea6c3dd8e02). Obrigatório.";
        els.deviceDeviceId.placeholder = "MAC address (ex.: bea6c3dd8e02)";
    } else if (deviceType === "radar") {
        els.deviceDeviceIdLabel.textContent = "Device ID";
        els.deviceDeviceIdHelp.textContent =
            "Identificador do dispositivo radar no protocolo.";
        els.deviceDeviceId.placeholder = "ID do dispositivo";
    } else if (deviceType === "gateway" || deviceType === "diaper_sensor") {
        els.deviceDeviceIdLabel.textContent = "MAC";
        els.deviceDeviceIdHelp.textContent =
            "Endereço MAC canónico, sem separadores (12 caracteres hexadecimais).";
        els.deviceDeviceId.placeholder = "d48c49f7909c";
    } else {
        els.deviceDeviceIdLabel.textContent = "Device ID";
        els.deviceDeviceIdHelp.textContent =
            "Identificador do dispositivo no protocolo (IMEI, MAC, etc.).";
        els.deviceDeviceId.placeholder = "ID do dispositivo no protocolo";
    }
}

function updateDevicePreview() {
    const supplier = els.deviceForm.dataset.supplier || "";
    const model = els.deviceForm.dataset.model || "";
    const modelInfo = findModelInfo(
        supplier,
        model,
        state.deviceTypeSuppliersModels,
    );
    els.devicePreview.innerHTML = modelPreviewHtml(
        modelInfo,
        model || "Selecione um modelo",
    );
    applyFourPTouchDeviceIdUi();
}

async function syncDeviceModalContext(loadCatalog = false) {
    const supplier = els.deviceForm.dataset.supplier || "";
    const model = els.deviceForm.dataset.model || "";
    const protocol = supplierProtocol(supplier, state.deviceTypeSuppliersModels);
    state.deviceModal.supplier = supplier;
    state.deviceModal.model = model;
    state.deviceModal.protocol = protocol;
    if (loadCatalog || state.deviceModal.activeTab === "config") {
        state.deviceModal.catalog = await catalogForProtocol(protocol);
    } else {
        state.deviceModal.catalog = state.protocolCatalogs[protocol] || [];
    }
    state.deviceModal.imei = els.deviceImei.value.trim();
    state.deviceModal.deviceType = normalizeDeviceType(
        els.deviceForm.dataset.deviceType || "watch",
    );
    const cachedCapabilityCatalog =
        state.settingsModal.capabilityCatalogByType?.[state.deviceModal.deviceType];
    if (cachedCapabilityCatalog) {
        state.deviceModal.capabilityCatalog = cachedCapabilityCatalog;
    } else {
        const response = await apiGetCapabilities({
            deviceType: state.deviceModal.deviceType,
        });
        const capabilityCatalog = response?.error ? [] : response.data || [];
        state.settingsModal.capabilityCatalogByType = {
            ...(state.settingsModal.capabilityCatalogByType || {}),
            [state.deviceModal.deviceType]: capabilityCatalog,
        };
        state.deviceModal.capabilityCatalog = capabilityCatalog;
    }
    state.deviceModal.licenseId = els.deviceLicenseId.value.trim() || "0";
    state.deviceModal.simNumber = getDeviceSimNumberValue(false);
    state.deviceModal.deviceId = els.deviceDeviceId?.value.trim() || "";
}

async function ensureDeviceConfigurationCatalogLoaded() {
    const protocol = state.deviceModal.protocol;
    if (!protocol || state.protocolCatalogs[protocol]) {
        await syncDeviceModalContext(false);
        return;
    }

    state.deviceModal.catalogLoading = true;
    renderDeviceConfigurationModal();
    try {
        await syncDeviceModalContext(true);
    } finally {
        state.deviceModal.catalogLoading = false;
    }
}

function applyFourPTouchDeviceIdUi() {
    if (!els.deviceDeviceId) {
        return;
    }

    const isFourPTouch = isFourPTouchSelection(
        els.deviceForm.dataset.supplier || "",
        els.deviceForm.dataset.model || "",
        state.deviceTypeSuppliersModels,
    );
    if (isFourPTouch) {
        const derived = deriveFourPTouchDeviceId(els.deviceImei.value.trim());
        els.deviceDeviceId.value = derived;
        els.deviceDeviceId.readOnly = true;
        els.deviceDeviceIdLabel.textContent = "Device ID";
        els.deviceDeviceIdHelp.textContent =
            "Derivado automaticamente do IMEI para 4P Touch.";
        els.deviceDeviceId.placeholder = "Derivado do IMEI";
    } else {
        els.deviceDeviceId.readOnly = false;
    }
}

function renderDeviceConfigurationModal() {
    if (!els.deviceConfigRoot) {
        return;
    }

    if (state.deviceModal.loading) {
        els.deviceConfigRoot.innerHTML = emptyPanel(
            "A carregar configurações...",
        );
        return;
    }

    if (state.deviceModal.catalogLoading) {
        els.deviceConfigRoot.innerHTML = emptyPanel(
            "A carregar configurações...",
        );
        return;
    }

    if (!state.deviceModal.imei) {
        els.deviceConfigRoot.innerHTML = emptyPanel(
            "Preencha o IMEI para gerir as configurações.",
        );
        return;
    }

    const enabledCapKeys = state.deviceModal.enabledCapabilityKeys;
    const filteredCatalog = enabledCapKeys.length
        ? state.deviceModal.catalog.filter(
              (entry) =>
                  entry.capabilityKey
                  && enabledCapKeys.includes(entry.capabilityKey),
          )
        : state.deviceModal.catalog.filter((entry) => entry.capabilityKey);

    els.deviceConfigRoot.innerHTML = renderDeviceConfigurationRoot({
        protocol: state.deviceModal.protocol,
        catalog: filteredCatalog,
        capabilityCatalog: state.deviceModal.capabilityCatalog,
        configurations: state.deviceModal.configurations,
        configurationSync: state.deviceModal.configurationSync,
        capabilities: state.deviceModal.capabilities,
        uiByKey: state.deviceModal.configUi,
        supplier: state.deviceModal.supplier,
        model: state.deviceModal.model,
        activeCategory: state.deviceModal.activeCategory,
        disabled: !state.deviceModal.protocol,
    });
    resetPhoneControls(els.deviceConfigRoot);
    armConfigFeedbackAutoClose();
}

async function saveDevice() {
    setDeviceFormError("");
    let imei = els.deviceImei.value.trim();
    let simNumber = "";
    const deviceType = normalizeDeviceType(
        els.deviceForm.dataset.deviceType || "watch",
    );
    const licenseId = els.deviceLicenseId.value.trim();
    const supplier = els.deviceForm.dataset.supplier || "";
    const model = els.deviceForm.dataset.model || "";
    const deviceId = isFourPTouchSelection(
        supplier,
        model,
        state.deviceTypeSuppliersModels,
    )
        ? deriveFourPTouchDeviceId(imei)
        : deviceType === "watch"
            ? ""
            : els.deviceDeviceId.value.trim();

    if (deviceType !== "watch") {
        if (!deviceId || !supplier || !model) {
            alert("Device ID, fornecedor e modelo são obrigatórios");
            return;
        }
        imei = deviceId;
        simNumber = "";
    } else {
        try {
            simNumber = getDeviceSimNumberValue(true);
        } catch (error) {
            alert(
                error instanceof Error
                    ? error.message
                    : "Número do SIM inválido",
            );
            return;
        }
        if (!imei || !supplier || !model) {
            alert("IMEI, fornecedor e modelo são obrigatórios");
            return;
        }
    }

    const originalImei = els.deviceImei.dataset.originalImei || "";
    const company = els.deviceCompany.value || "null";
    if (deviceType !== "watch" && (licenseId === "" || licenseId === "0")) {
        alert(
            "É necessário selecionar uma licença para este tipo de dispositivo",
        );
        return;
    }

    const result = await apiSaveDevice(
        imei,
        supplier,
        model,
        deviceType,
        licenseId,
        simNumber,
        deviceId,
        originalImei,
        company,
    );
    if (result.error) {
        if (result._httpStatus === 409) {
            setDeviceFormError("Este IMEI já existe.");
            return;
        }
        setDeviceFormError(result.error.message || result.error.code);
        return;
    }

    if (
        state.selectedImei &&
        originalImei &&
        state.selectedImei === originalImei
    ) {
        selectImei(imei);
        if (state.selectedImei) {
            saveTextStorage(SELECTED_DEVICE_STORAGE_KEY, state.selectedImei);
        }
        await loadDevice(imei);
    }
    deviceModal.hide();
    if (isDeviceSelectorOpen()) {
        await loadSummary();
    }
}

async function deleteDevice(imei) {
    if (!confirm(`Apagar o dispositivo ${imei}?`)) return;
    await apiDeleteDevice(imei);
    if (state.selectedImei === imei) {
        disconnectDeviceStream();
        clearSelection();
        clearStorageKey(SELECTED_DEVICE_STORAGE_KEY);
    }
    if (isDeviceSelectorOpen()) {
        await loadSummary();
    } else {
        renderSelection();
    }
}

function handleDeleteDeviceBtnClick() {
    const imei = els.deleteDeviceBtn.dataset.imei;
    if (!imei) return;
    if (!confirm(`Apagar o dispositivo ${imei}?`)) return;
    apiDeleteDevice(imei).then(() => {
        deviceModal.hide();
        if (state.selectedImei === imei) {
            disconnectDeviceStream();
            clearSelection();
            clearStorageKey(SELECTED_DEVICE_STORAGE_KEY);
        }
        if (isDeviceSelectorOpen()) {
            loadSummary();
        } else {
            renderSelection();
        }
    });
}


function bindEvents() {
    els.addDeviceBtn.addEventListener("click", () => {
        void openAddDevice();
    });
    els.openAddDeviceFromSelectorBtn.addEventListener("click", () => {
        deviceSelectorModal?.hide();
        void openAddDevice();
    });
    els.openDeviceSelectorBtn.addEventListener("click", () => {
        void openDeviceSelector();
    });
    els.emptyStateSelectDeviceBtn.addEventListener("click", () => {
        void openDeviceSelector();
    });
    els.selectedDeviceEditBtn.addEventListener("click", () => {
        if (!state.selectedDetail?.device) return;
        const m = state.selectedDetail.model;
        void editDevice(
            state.selectedDetail.device.imei,
            m?.supplier || "",
            m?.internalModel || "",
        );
    });
    els.saveDeviceBtn.addEventListener("click", saveDevice);
    els.deviceForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveDevice();
    });
    els.deviceCompanySelect.addEventListener("change", handleCompanySelect);
    els.deviceLicenseSelect.addEventListener("change", handleLicenseSelect);
    els.deviceListLimit.addEventListener("change", handleDeviceListLimitChange);
    els.deviceListSearch.addEventListener("input", handleDeviceListSearchInput);
    els.deviceTypeFilter.addEventListener("change", handleDeviceFilterChange);
    els.deviceLicenseFilter.addEventListener(
        "change",
        handleDeviceFilterChange,
    );
    els.deviceCompanyFilter.addEventListener(
        "change",
        handleDeviceFilterChange,
    );
    els.deviceSupplierFilter.addEventListener(
        "change",
        handleDeviceFilterChange,
    );
    els.deviceModelFilter.addEventListener("change", handleDeviceFilterChange);
    els.clearDeviceFiltersBtn.addEventListener("click", clearDeviceFilters);
    els.deviceActiveFilters.addEventListener(
        "click",
        handleActiveDeviceFiltersClick,
    );
    els.deviceImei.addEventListener("input", handleDeviceImeiInput);
    els.deviceLicenseId.addEventListener("input", handleDeviceImeiInput);
    els.deviceDeviceId.addEventListener("input", handleDeviceImeiInput);
    els.deviceForm.addEventListener("input", handleDeviceFormInput);
    els.deviceForm.addEventListener("change", handleDeviceFormChange);
    els.manageSettingsBtn.addEventListener("click", () => {
        void loadSettingsModal("suppliers");
    });

    els.saveModelBtn.addEventListener("click", saveModel);
    els.resetModelBtn.addEventListener("click", () => resetModelForm());
    els.modelForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveModel();
    });
    els.modelInternalModel.addEventListener("input", () =>
        updateModelProtocolAndPreview(),
    );
    els.modelCommercialName.addEventListener("input", () =>
        updateModelProtocolAndPreview(),
    );
    els.modelImage.addEventListener("change", handleModelImageChange);
    els.saveCapabilitiesBtn.addEventListener("click", () => {
        void saveCapabilities();
    });
    els.saveApiUserBtn.addEventListener("click", () => {
        void saveApiUser();
    });
    els.resetApiUserBtn.addEventListener("click", resetApiUserForm);
    els.apiUserForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveApiUser();
    });
    els.apiUserRole.addEventListener("change", syncApiUserRoleFields);
    els.telemetryPager.addEventListener("click", handleTelemetryPagerClick);
    els.applyDetailFiltersBtn.addEventListener("click", applyDetailFilters);
    els.clearDetailFiltersBtn.addEventListener("click", clearDetailFilters);
    els.detailFilterFrom.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterTo.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterType.addEventListener("change", updateDetailFilterDraft);
    els.deleteDeviceBtn.addEventListener("click", handleDeleteDeviceBtnClick);
    els.deviceSupplierButtons.addEventListener(
        "click",
        handleDeviceSupplierClick,
    );
    els.deviceTypeButtons.addEventListener("click", handleDeviceTypeClick);
    els.deviceModelButtons.addEventListener("click", handleDeviceModelClick);
    els.modelSupplierButtons.addEventListener(
        "click",
        handleModelSupplierClick,
    );
    els.modelDeviceTypeButtons.addEventListener(
        "click",
        handleModelDeviceTypeClick,
    );
    els.capabilityGroups.addEventListener(
        "change",
        handleCapabilityGroupsChange,
    );
    els.capabilitySectionNav.addEventListener("click", jumpCapabilitySection);
    els.capabilityDeviceTypeButtons.addEventListener(
        "click",
        handleCapabilityDeviceTypeClick,
    );
    els.capabilitySupplierButtons.addEventListener(
        "click",
        handleCapabilitySupplierClick,
    );
    els.capabilitySupplierClear.addEventListener(
        "click",
        () => selectCapabilitySupplier(""),
    );
    els.discoveryDeviceSelect?.addEventListener(
        "change",
        handleDiscoveryDeviceChange,
    );
    els.discoveryRefreshDevicesBtn?.addEventListener("click", () => {
        void loadDiscoveryDevices();
    });
    els.discoveryGenerateBtn?.addEventListener("click", () => {
        void generateDiscoveryPreview();
    });
    els.discoveryApplyBtn?.addEventListener("click", () => {
        void applyDiscoveryPreview();
    });
    els.modelsBreadcrumbModels.addEventListener("click", backToModelList);
    els.modelsNewModelBtn.addEventListener("click", openNewModelForm);
    els.clearModelsFiltersBtn.addEventListener("click", clearModelsFilters);
    els.modelDetailEditBtn.addEventListener("click", editCurrentModel);
    els.modelDetailDeleteBtn.addEventListener("click", () => {
        void deleteCurrentModel();
    });
    els.modelsCarousel.addEventListener("click", (event) => {
        const button = event.target.closest('[data-action="backToModelList"]');
        if (button) backToModelList();
    });
    els.modelsActiveFilters.addEventListener(
        "click",
        handleActiveModelsFiltersClick,
    );
    els.settingsSuppliersTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "suppliers";
        if (!state.settingsModal.sectionLoaded.suppliers) {
            void loadSettingsSuppliersSection();
        }
    });
    els.settingsModelsTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "models";
        if (!state.settingsModal.sectionLoaded.models) {
            void loadSettingsModelsSection();
        }
        if (!state.settingsModal.modelsCarousel && els.modelsCarousel) {
            state.settingsModal.modelsCarousel = new bootstrap.Carousel(
                els.modelsCarousel,
                {
                    interval: false,
                    wrap: false,
                    touch: false,
                },
            );
        }
    });
    els.settingsCapabilitiesTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "capabilities";
        if (!state.settingsModal.sectionLoaded.capabilities) {
            void loadSettingsCapabilitiesSection();
        }
    });
    els.settingsApiUsersTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "apiUsers";
        if (!state.settingsModal.sectionLoaded.apiUsers) {
            void loadSettingsApiUsersSection();
        }
    });
    els.settingsCompanyTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "company";
        if (!state.settingsModal.sectionLoaded.company) {
            void loadSettingsCompanySection();
        }
    });
    els.deviceGeneralTabBtn.addEventListener("shown.bs.tab", () => {
        state.deviceModal.activeTab = "general";
    });
    els.deviceConfigTabBtn.addEventListener("shown.bs.tab", () => {
        state.deviceModal.activeTab = "config";
        void (async () => {
            await ensureDeviceConfigurationCatalogLoaded();
            renderDeviceConfigurationModal();
        })();
    });
    els.saveCompanyBtn.addEventListener("click", () => {
        void saveCompany();
    });
    els.resetCompanyBtn.addEventListener("click", resetCompanyForm);
    els.companyForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveCompany();
    });
    els.saveLicenseBtn.addEventListener("click", () => {
        void saveLicense();
    });
    els.resetLicenseBtn.addEventListener("click", resetLicenseForm);
    els.licenseForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveLicense();
    });
    els.settingsSuppliersPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(
            event,
            "suppliersPagination",
            loadSettingsSuppliersSection,
        ),
    );
    els.settingsModelsPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(
            event,
            "modelsPagination",
            loadSettingsModelsSection,
        ),
    );
    els.settingsApiUsersPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(
            event,
            "apiUsersPagination",
            loadSettingsApiUsersSection,
        ),
    );
    els.settingsCompanyPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(event, "companyPagination", (page) =>
            loadSettingsCompanySection(page, 1),
        ),
    );
    els.settingsLicensesPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(event, "licensesPagination", (page) =>
            loadSettingsCompanySection(1, page),
        ),
    );
    els.deviceList.addEventListener("click", handleDeviceListClick);
    els.deviceListPagination.addEventListener(
        "click",
        handleDevicePaginationClick,
    );
    els.requestGrid.addEventListener("click", handleRequestGridClick);
    els.supplierListBody.addEventListener("click", handleSupplierListClick);
    els.modelListBody.addEventListener("click", handleModelListClick);
    els.modelsDeviceTypeButtons.addEventListener(
        "click",
        handleModelsDeviceTypeClick,
    );
    els.modelsSupplierButtons.addEventListener(
        "click",
        handleModelsSupplierClick,
    );
    els.modelsListLimit.addEventListener("change", handleModelsListLimitChange);
    els.modelsListSearch.addEventListener("input", handleModelsListSearchInput);
    els.apiUserListBody.addEventListener("click", handleApiUserListClick);
    els.companyListBody.addEventListener("click", handleCompanyListClick);
    els.licenseListBody.addEventListener("click", handleLicenseListClick);
    els.deviceConfigRoot.addEventListener("click", handleDeviceConfigClick);
    els.deviceConfigRoot.addEventListener("input", handleDeviceConfigInput);
    els.deviceConfigRoot.addEventListener("change", handleDeviceConfigChange);
    els.deviceConfigRoot.addEventListener(
        "closed.bs.alert",
        handleConfigFeedbackClosed,
    );
}

function handleModelImageChange() {
    revokeModelPreviewUrl();
    const file = els.modelImage.files[0];
    if (file) {
        state.modelPreviewObjectUrl = URL.createObjectURL(file);
        const label =
            els.modelCommercialName.value.trim() ||
            els.modelInternalModel.value.trim() ||
            "Modelo";
        els.modelPreviewContent.innerHTML = `<img src="${esc(state.modelPreviewObjectUrl)}" class="object-fit-contain w-100 h-100" alt="${esc(label)}" style="max-height:180px;">`;
    } else {
        updateModelProtocolAndPreview();
    }
}

async function handleDeviceImeiInput() {
    await syncDeviceModalContext();
    renderDeviceConfigurationModal();
}

function handleDeviceFormInput(event) {
    setDeviceFormError("");
    if (event.target.matches("[data-phone-local]")) {
        syncPhoneControl(event.target);
        void syncDeviceModalContext();
    }
}

function handleDeviceFormChange(event) {
    setDeviceFormError("");
    if (event.target.matches("[data-phone-country]")) {
        syncPhoneControl(event.target);
        void syncDeviceModalContext();
    }
}

async function handleDeviceFilterChange() {
    state.deviceFilters = {
        deviceType: normalizeFilterValue(els.deviceTypeFilter.value),
        licenseId: normalizeFilterValue(els.deviceLicenseFilter.value),
        company: normalizeFilterValue(els.deviceCompanyFilter.value),
        supplier: normalizeFilterValue(els.deviceSupplierFilter.value),
        model: normalizeFilterValue(els.deviceModelFilter.value),
    };
    state.deviceListPage = 1;
    saveJsonStorage(FILTERS_STORAGE_KEY, state.deviceFilters);
    await loadSummary();
}

async function handleActiveDeviceFiltersClick(event) {
    const button = event.target.closest('[data-action="removeDeviceFilter"]');
    if (!button) return;

    const key = button.dataset.filterKey;
    if (!key || !(key in state.deviceFilters)) return;

    state.deviceFilters = {
        ...state.deviceFilters,
        [key]: null,
    };
    state.deviceListPage = 1;
    saveJsonStorage(FILTERS_STORAGE_KEY, state.deviceFilters);
    await loadSummary();
}

async function clearDeviceFilters() {
    const defaults = {
        deviceType: null,
        licenseId: null,
        company: null,
        supplier: null,
        model: null,
    };
    state.deviceFilters = { ...defaults };
    state.deviceListPage = 1;
    clearStorageKey(FILTERS_STORAGE_KEY);
    await loadSummary();
}

function handleTelemetryPagerClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button || !state.selectedDetail) return;
    const allItems = allDetailItems();
    const filtered = filterDetailItems(allItems);
    const telemetryRows = filtered
        .filter((item) => ["telemetry", "event"].includes(item._source))
        .map((item) => item.raw);
    const totalPages = Math.max(
        1,
        Math.ceil(telemetryRows.length / state.telemetryPageSize),
    );
    if (button.dataset.action === "telemetryPrev")
        setTelemetryPage(state.telemetryPage - 1, totalPages);
    if (button.dataset.action === "telemetryNext")
        setTelemetryPage(state.telemetryPage + 1, totalPages);
    if (button.dataset.action === "telemetryPageGo")
        setTelemetryPage(parseInt(button.dataset.page || "1", 10), totalPages);
    renderTelemetryList(telemetryRows);
}

function handleDeviceConfigClick(event) {
    const button = event.target.closest(
        "[data-config-category], [data-action]",
    );
    if (!button) return;

    if (button.dataset.configCategory) {
        event.preventDefault();
        state.deviceModal.activeCategory = button.dataset.configCategory;
        renderDeviceConfigurationModal();
        return;
    }

    const section = button.closest("[data-config-section]");
    if (!section) return;

    if (button.dataset.action === "saveConfig") {
        void saveDeviceConfiguration(section);
        return;
    }

    if (button.dataset.action === "selectConfigChoice") {
        updateConfigChoice(section, button);
        return;
    }

    if (button.dataset.action === "addContactRow") {
        appendContactRow(section);
        return;
    }

    if (button.dataset.action === "removeContactRow") {
        removeConfigRow(button.closest('[data-repeat-row="contacts"]'));
        return;
    }

    if (button.dataset.action === "addSosContactRow") {
        appendPhoneListRow(section, "sos_contacts");
        return;
    }

    if (button.dataset.action === "removeSosContactRow") {
        removeConfigRow(button.closest('[data-repeat-row="sos_contacts"]'));
        return;
    }

    if (button.dataset.action === "addWhitelistRow") {
        appendPhoneListRow(section, "call_whitelist");
        return;
    }

    if (button.dataset.action === "removeWhitelistRow") {
        removeConfigRow(button.closest('[data-repeat-row="call_whitelist"]'));
        return;
    }

    if (button.dataset.action === "addAlarmClockRow") {
        appendAlarmClockRow(section);
        return;
    }

    if (button.dataset.action === "removeAlarmClockRow") {
        removeConfigRow(button.closest('[data-repeat-row="alarm_clock"]'));
        return;
    }

    if (button.dataset.action === "addWonlexMedicationPlan") {
        appendWonlexMedicationPlan(section);
        return;
    }

    if (button.dataset.action === "addTakePillsReminder") {
        appendTakePillsReminder(section);
        return;
    }

    if (button.dataset.action === "removeTakePillsReminder") {
        removeTakePillsReminder(
            button.closest("[data-takepills-reminder-group]"),
        );
        return;
    }

    if (button.dataset.action === "removeWonlexMedicationPlan") {
        removeWonlexMedicationPlan(
            button.closest('[data-repeat-row="wonlexMedicationPlan"]'),
        );
        return;
    }

    if (button.dataset.action === "takePillsRecord") {
        void startTakePillsRecording(section);
        return;
    }

    if (button.dataset.action === "takePillsStop") {
        void stopTakePillsRecording(section);
        return;
    }

    if (button.dataset.action === "takePillsClear") {
        clearTakePillsRecording(section);
    }
}

function updateConfigChoice(section, button) {
    const field = String(button.dataset.configField || "");
    if (!field) return;

    const value = String(button.dataset.configValue || "");
    const input = section.querySelector(`[data-config-field="${field}"]`);
    if (!input) return;

    input.value = value;

    const group = button.closest("[data-config-choice-group]");
    if (!group) return;

    const buttons = group.querySelectorAll(
        '[data-action="selectConfigChoice"]',
    );
    buttons.forEach((choice) => {
        const selected =
            String(choice.dataset.configField || "") === field &&
            String(choice.dataset.configValue || "") === value;
        choice.classList.toggle("active", selected);
        choice.setAttribute("aria-pressed", selected ? "true" : "false");
    });
}

function handleDeviceConfigChange(event) {
    if (event.target.matches("[data-phone-country]")) {
        syncPhoneControl(event.target);
        return;
    }

    if (event.target.matches('[data-time-format="24h"]')) {
        normalizeTwentyFourHourTimeInput(event.target);
    }

    if (event.target.matches('[data-action="takePillsFile"]')) {
        const section = event.target.closest("[data-config-section]");
        if (!section) return;
        const file = event.target.files?.[0] || null;
        void loadTakePillsAudio(section, file);
        return;
    }

    const section = event.target.closest("[data-config-section]");
    if (!section) return;

    if (event.target.matches('[data-config-field="voiceEnabled"]')) {
        syncTakePillsVoiceVisibility(section);
    }

    if (event.target.matches('[data-takepills-field="reminderFrequency"]')) {
        syncTakePillsCustomVisibility(section);
    }

    if (event.target.matches("[data-medication-period]")) {
        const row = event.target.closest(
            '[data-repeat-row="wonlexMedicationPlan"]',
        );
        const periodTime = row?.querySelector(
            `[data-medication-period-time="${event.target.value}"]`,
        );
        if (periodTime) {
            periodTime.disabled = !event.target.checked;
            if (event.target.checked && String(periodTime.value || "") === "") {
                periodTime.value = "08:00";
            }
        }
    }

    if (event.target.matches('[data-config-field="mode"]')) {
        const extra = section.querySelector("[data-working-mode-extra]");
        if (extra) {
            extra.classList.toggle(
                "d-none",
                String(event.target.value) !== "8",
            );
        }
        const alarmRow = event.target.closest("[data-fourptouch-alarm-row]");
        if (alarmRow) {
            syncFourPTouchAlarmCustomVisibility(alarmRow);
        }
    }

    if (event.target.matches('[data-alarm-clock-field="recurrenceKind"]')) {
        const row = event.target.closest('[data-repeat-row="alarm_clock"]');
        if (row) {
            syncAlarmClockCustomVisibility(row);
        }
    }

    if (
        event.target.matches(
            '.form-check-input[type="checkbox"][role="switch"]',
        )
    ) {
        const label = event.target.parentElement?.querySelector(
            "[data-switch-label]",
        );
        if (label) {
            label.textContent = event.target.checked
                ? label.dataset.switchOn || "Ligado"
                : label.dataset.switchOff || "Desligado";
        }
    }

    if (event.target.matches('[data-action="fallTotalLevels"]')) {
        const section = event.target.closest("[data-config-section]");
        if (!section) return;
        const total = parseInt(event.target.value, 10);
        const btns = section.querySelectorAll(
            '[data-config-choice-group="sensitivity"] .sens-level-btn',
        );
        const currentInput = section.querySelector(
            '[data-config-field="sensitivity"]',
        );
        btns.forEach((btn, i) => {
            const visible = i + 1 <= total;
            btn.classList.toggle("d-none", !visible);
            btn.disabled = !visible;
        });
        if (currentInput && parseInt(currentInput.value, 10) > total) {
            const lastEnabled = Array.from(btns).find(
                (btn) => !btn.classList.contains("d-none") && !btn.disabled,
            );
            if (lastEnabled) {
                currentInput.value = String(
                    parseInt(lastEnabled.dataset.configValue || "1", 10) || 1,
                );
                btns.forEach((btn) => {
                    const selected =
                        btn.dataset.configValue === currentInput.value;
                    btn.classList.toggle("active", selected);
                    btn.setAttribute(
                        "aria-pressed",
                        selected ? "true" : "false",
                    );
                });
            }
        }
    }
}

function handleDeviceConfigInput(event) {
    if (event.target.matches("[data-phone-local]")) {
        syncPhoneControl(event.target);
    }

    if (event.target.matches('[data-time-format="24h"]')) {
        normalizeTwentyFourHourTimeInput(event.target);
    }
}

function syncFourPTouchAlarmCustomVisibility(row) {
    if (!row) {
        return;
    }

    const mode = parseInt(
        String(row.querySelector('[data-fourptouch-field="mode"]')?.value ?? "1"),
        10,
    ) || 1;
    const custom = row.querySelector("[data-fourptouch-custom-wrapper]");
    if (custom) {
        custom.classList.toggle("d-none", mode !== 3);
    }
}

function normalizeTwentyFourHourTimeInput(input) {
    if (!(input instanceof HTMLInputElement)) {
        return;
    }
    const digits = String(input.value || "").replace(/[^0-9]/g, "").slice(0, 4);
    if (digits.length === 0) {
        input.value = "";
        return;
    }
    if (digits.length <= 2) {
        input.value = digits;
        return;
    }
    input.value = `${digits.slice(0, 2)}:${digits.slice(2)}`;
}

function handleConfigFeedbackClosed(event) {
    const alertEl = event.target.closest("[data-config-feedback-key]");
    if (!alertEl) return;

    const key = alertEl.dataset.configFeedbackKey || "";
    clearTimeout(configFeedbackTimers.get(key));
    configFeedbackTimers.delete(key);
    clearConfigFeedback(key);
}

function handleDeviceSupplierClick(event) {
    const button = event.target.closest('[data-action="selectDeviceSupplier"]');
    if (button) renderDeviceSelectors(button.dataset.value, "");
}

function handleDeviceTypeClick(event) {
    const button = event.target.closest('[data-action="selectDeviceType"]');
    if (!button) return;

    const deviceType = normalizeDeviceType(button.dataset.value);
    renderDeviceTypeSelector(deviceType);
    renderDeviceSelectors("", "", deviceType);
}

function handleDeviceModelClick(event) {
    const button = event.target.closest('[data-action="selectDeviceModel"]');
    if (!button) return;
    els.deviceForm.dataset.model = button.dataset.value;
    renderDeviceSelectors(
        els.deviceForm.dataset.supplier,
        button.dataset.value,
    );
}

function handleModelSupplierClick(event) {
    const button = event.target.closest('[data-action="selectModelSupplier"]');
    if (button) selectModelSupplier(button.dataset.value);
}

function handleModelDeviceTypeClick(event) {
    const button = event.target.closest(
        '[data-action="selectModelDeviceType"]',
    );
    if (button) selectModelDeviceType(button.dataset.value);
}

function handleModelsDeviceTypeClick(event) {
    const button = event.target.closest(
        '[data-action="selectModelsDeviceType"]',
    );
    if (button) selectModelsDeviceType(button.dataset.value);
}

function handleModelsSupplierClick(event) {
    const button = event.target.closest('[data-action="selectModelsSupplier"]');
    if (button) selectModelsSupplier(button.dataset.value);
}

function handleCapabilityDeviceTypeClick(event) {
    const button = event.target.closest(
        '[data-action="selectCapabilityDeviceType"]',
    );
    if (!button) return;
    void loadSettingsCapabilitiesSection(button.dataset.value);
}

function handleCapabilityGroupsChange(event) {
    const checkbox = event.target.closest([
        '[data-action="toggleCapabilitySupport"]',
        '[data-action="toggleCapabilityRequestability"]',
    ].join(","));
    if (!checkbox) return;

    const feature = String(checkbox.dataset.feature || "");
    if (!feature) return;

    const enabled = new Set(
        state.settingsModal.capabilityEnabledCapabilities || [],
    );
    const requestable = new Set(
        state.settingsModal.capabilityRequestableCapabilities || [],
    );
    if (checkbox.dataset.action === "toggleCapabilitySupport") {
        if (checkbox.checked) {
            enabled.add(feature);
        } else {
            enabled.delete(feature);
            requestable.delete(feature);
        }
    } else {
        if (checkbox.checked && enabled.has(feature)) {
            requestable.add(feature);
        } else {
            requestable.delete(feature);
        }
    }
    state.settingsModal.capabilityEnabledCapabilities = [...enabled];
    state.settingsModal.capabilityRequestableCapabilities = [...requestable];
    renderCapabilitiesSection();
}

function jumpCapabilitySection(event) {
    const button = event.target.closest(
        '[data-action="jumpCapabilitySection"]',
    );
    if (!button) return;

    const section = button.dataset.section;
    if (!section) return;

    state.settingsModal.activeCapabilitySection = section;
    renderCapabilitiesSection();
}

function handleDeviceListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const { action, imei } = button.dataset;
    if (action === "select") selectDevice(imei);
}

function handleRequestGridClick(event) {
    const button = event.target.closest('[data-action="requestFeature"]');
    if (button) requestTelemetryFeature(String(button.dataset.feature || ""));
}

function handleSupplierListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const { id, action, enabled } = button.dataset;
    if (action === "toggleSupplier") toggleSupplier(parseInt(id), !!enabled);
}

function handleModelListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    if (button.dataset.action === "modelCapabilities") {
        void openModelDetail(parseInt(button.dataset.id));
    }
}

function handleApiUserListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    if (button.dataset.action === "editApiUser") {
        editApiUser(button);
    }
    if (button.dataset.action === "toggleApiUser") {
        toggleApiUser(button);
    }
    if (button.dataset.action === "deleteApiUser") {
        deleteApiUser(parseInt(button.dataset.id));
    }
}

async function saveDeviceConfiguration(section) {
    const key = section.dataset.configKey || "";
    if (!key) return;

    let payload;
    try {
        payload = readConfigPayload(section);
    } catch (error) {
        alert(error instanceof Error ? error.message : "Configuração inválida");
        return;
    }

    setConfigUi(key, { phase: "submitting" });
    renderDeviceConfigurationModal();

    try {
        const isTransientAction = section.dataset.configTransient === "1";
        const capabilityKey = section.dataset.capabilityKey || section.dataset.configKey || "";
        const result = isTransientAction
            ? await apiRequestCapability(state.deviceModal.imei, capabilityKey, payload)
            : await apiSaveConfiguration(state.deviceModal.imei, {
                configurations: {
                    [key]: payload,
                },
            });
        if (result.error) {
            setConfigUi(key, {
                phase: "idle",
                feedback: {
                    tone: "danger",
                    message:
                        result.error.message ||
                        result.error.code ||
                        "Falha ao enviar configuração",
                },
            });
            renderDeviceConfigurationModal();
            return;
        }

        if (!isTransientAction) {
            state.deviceModal.configurations =
                result.configurations || state.deviceModal.configurations;
            state.deviceModal.configurationSync =
                result.configurationSync || state.deviceModal.configurationSync;
            state.deviceModal.capabilities =
                result.capabilities || state.deviceModal.capabilities;
        }

        setConfigUi(key, {
            phase: "sent",
            feedback: {
                tone: "success",
                message: isTransientAction
                    ? "Pedido enviado ao dispositivo."
                    : "Valor guardado no Hub e enviado. A aguardar confirmação do dispositivo.",
            },
        });
        renderDeviceConfigurationModal();
        transitionConfigPhase(key, "sent", 1200, () => {
            clearConfigUiPhase(key, "sent");
            renderDeviceConfigurationModal();
        });
    } catch (error) {
        setConfigUi(key, {
            phase: "idle",
            feedback: {
                tone: "danger",
                message:
                    error instanceof Error
                        ? error.message
                        : "Falha ao enviar configuração",
            },
        });
        renderDeviceConfigurationModal();
    }
}

async function refreshDeviceModalConfigurations(shouldRender = true) {
    if (
        !state.deviceModal.imei ||
        !state.deviceModal.supplier ||
        !state.deviceModal.model
    ) {
        return null;
    }

    if (deviceConfigRefreshPromise) {
        return deviceConfigRefreshPromise;
    }

    const snapshot = [
        state.deviceModal.imei,
        state.deviceModal.supplier,
        state.deviceModal.model,
    ].join("|");

    deviceConfigRefreshPromise = apiGetDevice(state.deviceModal.imei)
        .then((result) => {
            const current = [
                state.deviceModal.imei,
                state.deviceModal.supplier,
                state.deviceModal.model,
            ].join("|");
            if (snapshot !== current) {
                return result;
            }

            state.deviceModal.configurations = result?.configurations || {};
            state.deviceModal.configurationSync =
                result?.configurationSync || {entries: {}};
            state.deviceModal.capabilities = result?.capabilities || {};
            if (shouldRender) {
                renderDeviceConfigurationModal();
            }
            return result;
        })
        .finally(() => {
            deviceConfigRefreshPromise = null;
        });

    return deviceConfigRefreshPromise;
}

function syncDeviceModalCommandStates(imei, commands) {
    if (String(state.deviceModal.imei || "") !== String(imei || "")) {
        return;
    }

    const commandsById = new Map(
        (commands || []).map((command) => [String(command?.id || ""), command]),
    );
    let changed = false;
    for (const section of Object.values(state.deviceModal.configurationSync?.entries || {})) {
        for (const delivery of Object.values(section || {})) {
            const operation = (delivery?.operations || []).find((item) =>
                commandsById.has(String(item?.operationId || "")),
            );
            const command = operation
                ? commandsById.get(String(operation.operationId || ""))
                : null;
            if (!command) {
                continue;
            }

            const commandStatus = String(command.status || "");
            const nextStatus = ["failed", "dropped"].includes(commandStatus)
                ? "failed"
                : commandStatus === "acked"
                    ? (String(operation?.confirmationMode || "") === "ack_only"
                        ? "confirmation_unavailable"
                        : "confirmed")
                    : ["queued", "waiting", "sent"].includes(commandStatus)
                        ? (commandStatus === "queued" ? "pending_delivery" : "awaiting_ack")
                        : String(delivery.status || "");
            const nextError = ["failed", "dropped"].includes(commandStatus)
                ? String(command.lastError || command.error || commandStatus)
                : "";
            if (
                nextStatus !== String(delivery.status || "")
                || nextError !== String(delivery.error || "")
            ) {
                delivery.status = nextStatus;
                delivery.error = nextError;
                changed = true;
            }
        }
    }

    if (
        changed
        && state.deviceModal.activeTab === "config"
        && document.getElementById("deviceModal")?.classList.contains("show")
    ) {
        renderDeviceConfigurationModal();
    }
}

function setConfigUi(key, updates) {
    state.deviceModal.configUi[key] = {
        ...(state.deviceModal.configUi[key] || {}),
        ...updates,
    };
}

function clearConfigUiPhase(key, phase) {
    const current = state.deviceModal.configUi[key];
    if (!current || current.phase !== phase) {
        return;
    }

    const next = { ...current };
    delete next.phase;
    if (Object.keys(next).length === 0) {
        delete state.deviceModal.configUi[key];
        return;
    }
    state.deviceModal.configUi[key] = next;
}

function clearConfigFeedback(key) {
    const current = state.deviceModal.configUi[key];
    if (!current) {
        return;
    }

    const next = { ...current };
    delete next.feedback;
    if (Object.keys(next).length === 0) {
        delete state.deviceModal.configUi[key];
        return;
    }
    state.deviceModal.configUi[key] = next;
}

function transitionConfigPhase(key, phase, delayMs, callback) {
    clearTimeout(configPhaseTimers.get(key));
    configPhaseTimers.set(
        key,
        setTimeout(() => {
            const current = state.deviceModal.configUi[key];
            if (current?.phase === phase) {
                callback();
            }
            configPhaseTimers.delete(key);
        }, delayMs),
    );
}

function armConfigFeedbackAutoClose() {
    const alerts = Array.from(
        els.deviceConfigRoot.querySelectorAll("[data-config-feedback-key]"),
    );
    for (const alertEl of alerts) {
        const key = alertEl.dataset.configFeedbackKey || "";
        if (!key || configFeedbackTimers.has(key)) {
            continue;
        }

        configFeedbackTimers.set(
            key,
            setTimeout(() => {
                const liveAlert = els.deviceConfigRoot.querySelector(
                    `[data-config-feedback-key="${CSS.escape(key)}"]`,
                );
                if (liveAlert) {
                    bootstrap.Alert.getOrCreateInstance(liveAlert).close();
                } else {
                    clearConfigFeedback(key);
                }
                configFeedbackTimers.delete(key);
            }, 3500),
        );
    }
}

function resetConfigUiState() {
    for (const timer of configFeedbackTimers.values()) {
        clearTimeout(timer);
    }
    configFeedbackTimers.clear();

    for (const timer of configPhaseTimers.values()) {
        clearTimeout(timer);
    }
    configPhaseTimers.clear();

    deviceConfigRefreshPromise = null;
}

function renderDeviceSimNumberField(value = "") {
    if (!els.deviceSimNumberRoot) {
        return;
    }

    els.deviceSimNumberRoot.innerHTML = renderPhoneControl({
        value,
        placeholder: "Número do SIM",
    });
    resetPhoneControls(els.deviceSimNumberRoot);
}

function getDeviceSimNumberValue(strict = false) {
    const control =
        els.deviceSimNumberRoot?.querySelector("[data-phone-control]") || null;
    if (!control) {
        return "";
    }

    if (!strict) {
        try {
            return normalizePhoneControl(control);
        } catch {
            return "";
        }
    }

    return normalizePhoneControl(control);
}

function appendContactRow(section) {
    const list = section.querySelector("[data-repeat-limit]");
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || "10", 10);
    const rows = list.querySelectorAll('[data-repeat-row="contacts"]');
    if (rows.length >= limit) return;

    const isFourPTouchPhonebook = isFourPTouchPhonebookSection(section);
    const template = rows[rows.length - 1] || createContactRow({
        phonebook: isFourPTouchPhonebook,
        nameMaxLength: parseInt(section.dataset.phonebookNameMaxLength || (isFourPTouchPhonebook ? "10" : "0"), 10) || 0,
        phoneMaxLength: parseInt(section.dataset.phonebookPhoneMaxLength || (isFourPTouchPhonebook ? "20" : "0"), 10) || 0,
    });
    const clone = template.cloneNode(true);
    clone.querySelectorAll("input").forEach((input) => {
        if (input.matches("[data-phone-local]")) {
            input.value = "";
            return;
        }
        input.value = "";
    });
    const countrySelect = clone.querySelector("[data-phone-country]");
    if (countrySelect) {
        countrySelect.value = "PT";
    }
    resetPhoneControls(clone);
    list.appendChild(clone);
}

function appendPhoneListRow(section, rowType) {
    const list = section.querySelector(`[data-repeat-kind="${rowType}"]`);
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || "10", 10);
    const rows = list.querySelectorAll(`[data-repeat-row="${rowType}"]`);
    if (rows.length >= limit) return;

    const template = rows[rows.length - 1] || null;
    if (!template) return;

    const clone = template.cloneNode(true);
    clone.querySelectorAll("input").forEach((input) => {
        if (input.matches("[data-phone-local]")) {
            input.value = "";
            return;
        }
        input.value = "";
    });
    const countrySelect = clone.querySelector("[data-phone-country]");
    if (countrySelect) {
        countrySelect.value = "PT";
    }
    resetPhoneControls(clone);
    list.appendChild(clone);
}

function appendAlarmClockRow(section) {
    const list = section.querySelector("[data-alarm-clock-list]");
    if (!list) return;

    const template = list.querySelector('[data-repeat-row="alarm_clock"]');
    if (!template) return;

    const clone = template.cloneNode(true);
    clone.querySelectorAll("input, select").forEach((input) => {
        if (input.matches('[data-alarm-clock-field="enabled"]')) {
            input.checked = true;
            return;
        }

        if (input.matches('[data-alarm-clock-field="recurrenceKind"]')) {
            input.checked = false;
            return;
        }

        if (input.matches('[data-alarm-clock-field="type"]')) {
            input.checked = input.value === "1";
            return;
        }

        if (input.type === "checkbox") {
            input.checked = false;
            return;
        }

        input.value = "";
    });
    const recurrenceInputs = Array.from(
        clone.querySelectorAll('[data-alarm-clock-field="recurrenceKind"]'),
    );
    const defaultRecurrence = recurrenceInputs.find(
        (input) => String(input.value || "").trim().toLowerCase() === "once",
    ) || recurrenceInputs[0];
    if (defaultRecurrence) defaultRecurrence.checked = true;

    syncAlarmClockCustomVisibility(clone);
    const switchLabel = clone.querySelector('[data-alarm-clock-field="enabled"]')
        ?.parentElement?.querySelector("[data-switch-label]");
    if (switchLabel) {
        switchLabel.textContent = "Ligado";
    }

    list.appendChild(clone);
}

function appendWonlexMedicationPlan(section) {
    const list = section.querySelector("[data-wonlex-medication-list]");
    if (!list) return;

    const index = list.querySelectorAll(
        '[data-repeat-row="wonlexMedicationPlan"]',
    ).length;
    list.insertAdjacentHTML(
        "beforeend",
        wonlexMedicationPlanRow({}, index),
    );
    renumberWonlexMedicationPlans(list);
}

function appendTakePillsReminder(section) {
    const list = section.querySelector("[data-takepills-reminders-list]");
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || "3", 10) || 3;
    const index = list.querySelectorAll(
        "[data-takepills-reminder-group]",
    ).length;
    if (index >= limit) return;

    list.insertAdjacentHTML(
        "beforeend",
        takePillsReminderGroup(
            {time: "08:00", enabled: true, frequency: 1, custom: ""},
            index,
            [
                {value: 1, label: "Uma vez"},
                {value: 2, label: "Diariamente"},
                {value: 3, label: "Personalizado"},
            ],
        ),
    );
    syncTakePillsRows(section);
}

function removeTakePillsReminder(row) {
    const section = row?.closest("[data-config-section]");
    if (!row || !section) return;

    row.remove();
    syncTakePillsRows(section);
}

function syncTakePillsRows(section) {
    const list = section.querySelector("[data-takepills-reminders-list]");
    if (!list) return;

    const rows = list.querySelectorAll("[data-takepills-reminder-group]");
    rows.forEach((row, index) => {
        row.dataset.takepillsReminderGroup = String(index);
        const number = row.querySelector("[data-takepills-reminder-number]");
        if (number) {
            number.textContent = `Lembrete ${index + 1}`;
        }
        row.querySelectorAll("[data-takepills-index]").forEach((field) => {
            field.dataset.takepillsIndex = String(index);
        });
        const custom = row.querySelector("[data-takepills-custom-wrapper]");
        if (custom) {
            custom.dataset.takepillsCustomWrapper = String(index);
        }
    });

    const limit = parseInt(list.dataset.repeatLimit || "3", 10) || 3;
    const addButton = section.querySelector(
        '[data-action="addTakePillsReminder"]',
    );
    if (addButton) {
        addButton.disabled = rows.length >= limit;
    }
    syncTakePillsCustomVisibility(section);
}

function removeWonlexMedicationPlan(row) {
    const list = row?.closest("[data-wonlex-medication-list]");
    if (!row || !list) return;

    row.remove();
    renumberWonlexMedicationPlans(list);
}

function renumberWonlexMedicationPlans(list) {
    list.querySelectorAll(
        '[data-repeat-row="wonlexMedicationPlan"]',
    ).forEach((row, index) => {
        const number = row.querySelector("[data-medication-plan-number]");
        if (number) {
            number.textContent = String(index + 1);
        }
    });
}

function removeConfigRow(row) {
    if (!row) return;
    const parent = row.parentElement;
    if (!parent) return;
    if (parent.children.length <= 1) {
        row.querySelectorAll("input, select").forEach((input) => {
            if (input.matches('[data-alarm-clock-field="enabled"]')) {
                input.checked = true;
                return;
            }
            if (input.matches('[data-alarm-clock-field="recurrenceKind"]')) {
                input.checked = false;
                return;
            }
            if (input.matches('[data-alarm-clock-field="type"]')) {
                input.checked = input.value === "1";
                return;
            }
            if (input.type === "checkbox") {
                input.checked = false;
            } else if (input.matches("[data-phone-country]")) {
                input.value = "PT";
            } else {
                input.value = "";
            }
        });
        const recurrenceInputs = Array.from(
            row.querySelectorAll('[data-alarm-clock-field="recurrenceKind"]'),
        );
        const defaultRecurrence = recurrenceInputs.find(
            (input) => String(input.value || "").trim().toLowerCase() === "once",
        ) || recurrenceInputs[0];
        if (defaultRecurrence) defaultRecurrence.checked = true;
        syncAlarmClockCustomVisibility(row);
        resetPhoneControls(row);
        return;
    }
    row.remove();
}

function syncAlarmClockCustomVisibility(row) {
    const customWrapper = row?.querySelector("[data-alarm-clock-custom-wrapper]");
    if (!customWrapper) return;

    const recurrence = row.querySelector('[data-alarm-clock-field="recurrenceKind"]:checked');
    customWrapper.classList.toggle(
        "d-none",
        String(recurrence?.value || "").trim().toLowerCase() !== "custom",
    );
}

function isFourPTouchPhonebookSection(section) {
    return String(section?.dataset?.configProtocol || "") === "four-p-touch"
        && String(section?.dataset?.configKey || "") === "phonebook";
}

function createContactRow({ phonebook = false, nameMaxLength = 0, phoneMaxLength = 0 } = {}) {
    const wrapper = document.createElement("div");
    wrapper.className = "row g-2 align-items-end";
    wrapper.dataset.repeatRow = "contacts";
    wrapper.innerHTML = `
        <div class="col-md-6">
            <input class="form-control" type="text" placeholder="Nome"${phonebook && nameMaxLength > 0 ? ` maxlength="${nameMaxLength}"` : ""} data-repeat-field="name">
        </div>
        <div class="col-md-6">
            <div class="d-flex gap-2">
                <div class="flex-grow-1">
                    ${renderPhoneControl({ repeatField: "phone", placeholder: "Telefone", maxLength: phoneMaxLength })}
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeContactRow">-</button>
            </div>
        </div>`;
    resetPhoneControls(wrapper);
    return wrapper;
}

export async function startDashboard() {
    els = cacheElements();
    deviceModal = new bootstrap.Modal(document.getElementById("deviceModal"));
    deviceSelectorModal = new bootstrap.Modal(
        document.getElementById("deviceSelectorModal"),
    );
    settingsModal = new bootstrap.Modal(
        document.getElementById("settingsModal"),
    );
    initDeviceListDetail({
        els,
        ui: {deviceModal, deviceSelectorModal, settingsModal},
        services: {},
    });
    initSettings({
        els,
        ui: {deviceModal, deviceSelectorModal, settingsModal},
    });
    initDeviceStream({
        state,
        renderSelection,
        onCommandsUpdated: syncDeviceModalCommandStates,
    });
    initNotifications({
        els,
        openAddDevice,
    });
    bindEvents();
    await ensureProtocolsLoaded();

    const stored = loadJsonStorage(FILTERS_STORAGE_KEY);
    if (stored && typeof stored === "object") {
        state.deviceFilters = {
            deviceType: normalizeFilterValue(stored.deviceType),
            licenseId: normalizeFilterValue(stored.licenseId),
            company: normalizeFilterValue(stored.company),
            supplier: normalizeFilterValue(stored.supplier),
            model: normalizeFilterValue(stored.model),
        };
    }
    const storedSelectedImei = loadTextStorage(SELECTED_DEVICE_STORAGE_KEY);
    if (storedSelectedImei) {
        state.selectedImei = storedSelectedImei;
        void loadDevice(storedSelectedImei);
    } else {
        renderSelection();
    }

    setInterval(() => {
        if (
            document.body.dataset.dashboardAuthRequired === "true"
            && !window.hubDashboardApiToken?.access_token
        ) {
            return;
        }
        if (state.selectedImei) {
            apiGetDevice(state.selectedImei).then((detail) => {
                if (detail?.error) return;
                if (state.selectedImei !== detail.device?.imei) return;
                const recent = state.selectedDetail?.recent;
                state.selectedDetail = detail;
                state.selectedDetail.recent = recent;
                renderSelection();
            });
        }
    }, 30000);
}
