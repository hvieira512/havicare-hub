import {
    deleteDevice as apiDeleteDevice,
    getCapabilities as apiGetCapabilities,
    getCompanies as apiGetCompanies,
    getDevice as apiGetDevice,
    getLicenses as apiGetLicenses,
    saveDevice as apiSaveDevice,
} from "../api/index.js";
import {
    catalogForProtocol,
} from "../config.js";
import {
    renderDeviceConfigurationModal,
    resetConfigUiState,
} from "./config-panel.js";
import {
    renderSelection,
} from "./detail-view.js";
import {
    loadDiaperSensitivity,
    saveDiaperSensitivity,
    selectedDiaperSensitivity,
} from "./diaper-sensitivity-ui.js";
import {
    refreshGatewayOptions,
    selectedGatewayKeys,
    syncGatewayLinks,
} from "./gateway-links-ui.js";
import {
    gatewayKeysFromLinks,
} from "./gateway-links.js";
import {
    clearSelection,
    deriveFourPTouchDeviceId,
    deviceTypeOptions,
    ensureDeviceTypeSuppliersModelsLoaded,
    findModelInfo,
    isDeviceSelectorOpen,
    isFourPTouchSelection,
    linksToGateway,
    loadDevice,
    loadSummary,
    modelCommercialName,
    modelDeviceType,
    modelDisplayLabel,
    modelInternalName,
    modelsForSupplierAndType,
    normalizeDeviceType,
    supplierProtocol,
    suppliersForDeviceType,
    usesMacAddress,
} from "./list-detail.js";
import {
    disconnectDeviceStream,
} from "./stream.js";
import {
    esc,
} from "../format.js";
import {
    normalizePhoneControl,
    renderPhoneControl,
    resetPhoneControls,
} from "../phone.js";
import {
    modelPreviewHtml,
    renderButtonGroup,
} from "../renderers.js";
import {
    selectImei,
    state,
} from "../state.js";
import {
    SELECTED_DEVICE_STORAGE_KEY,
    clearStorageKey,
    saveTextStorage,
} from "../core/storage.js";

/**
 * The add/edit device modal: its selectors, its form, and saving or deleting
 * the device it is editing.
 *
 * Receives the element map and the Bootstrap modal instances through
 * initDeviceModal, the same way the other view modules take their context.
 */

let els;
let deviceModal;
let deviceSelectorModal;
let settingsModal;

export function initDeviceModal(context) {
    els = context.els;
    deviceModal = context.deviceModal;
    deviceSelectorModal = context.deviceSelectorModal;
    settingsModal = context.settingsModal;
}

export async function populateCompanySelect() {
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

export async function populateLicenseSelectForCompany(companyName) {
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

export async function handleCompanySelect() {
    const companyName = els.deviceCompanySelect.value;
    els.deviceCompany.value = companyName || "";
    els.deviceLicenseId.value = "0";
    if (companyName) {
        await populateLicenseSelectForCompany(companyName);
    } else {
        els.deviceLicenseSelect.innerHTML =
            '<option value="0">Nenhuma</option>';
        els.deviceLicenseSelect.disabled = true;
        els.deviceLicenseId.value = "0";
    }
    await refreshGatewayOptions([]);
}

export function handleLicenseSelect() {
    els.deviceLicenseId.value = els.deviceLicenseSelect.value || "0";
    void refreshGatewayOptions([]);
}

export function setDeviceFormError(message = "") {
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

export function activeDeviceModalTab() {
    return els.deviceConfigTabBtn?.classList.contains("active")
        || els.deviceConfigPane?.classList.contains("active")
        ? "config"
        : "general";
}

export async function openAddDevice(source = "") {
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
        linkedGatewayKeys: [],
        selectedGatewayKeys: [],
        gatewayOptions: [],
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
    await refreshGatewayOptions([]);
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

export async function editDevice(imei, supplier, model) {
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
        linkedGatewayKeys: [],
        selectedGatewayKeys: [],
        gatewayOptions: [],
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
        const linkedGatewayKeys = gatewayKeysFromLinks(
            detail.linkedDevices || [],
        );
        state.deviceModal.linkedGatewayKeys = linkedGatewayKeys;
        state.deviceModal.selectedGatewayKeys = linkedGatewayKeys;
        await refreshGatewayOptions(linkedGatewayKeys);
        await loadDiaperSensitivity(
            String(device.imei || ""),
            state.deviceModal.deviceType,
        );
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

export async function renderDeviceSelectors(
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

export function renderDeviceTypeSelector(selectedType = "watch") {
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
    els.deviceGatewayLinksRow?.classList.toggle(
        "d-none",
        !linksToGateway(deviceType),
    );
    els.deviceDiaperSensitivityRow?.classList.toggle(
        "d-none",
        deviceType !== "diaper_sensor",
    );

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
    } else if (usesMacAddress(deviceType)) {
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

export function updateDevicePreview() {
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

export async function syncDeviceModalContext(loadCatalog = false) {
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

export async function ensureDeviceConfigurationCatalogLoaded() {
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

export function applyFourPTouchDeviceIdUi() {
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

export async function saveDevice() {
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
    const desiredGatewayKeys = linksToGateway(deviceType)
        ? selectedGatewayKeys()
        : [];
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

    if (linksToGateway(deviceType)) {
        const currentGatewayKeys = originalImei && originalImei !== imei
            ? []
            : state.deviceModal.linkedGatewayKeys || [];
        const linkError = await syncGatewayLinks(
            imei,
            currentGatewayKeys,
            desiredGatewayKeys,
        );
        if (linkError) {
            setDeviceFormError(
                `Dispositivo guardado, mas não foi possível atualizar os gateways: ${linkError}`,
            );
            return;
        }
        state.deviceModal.linkedGatewayKeys = desiredGatewayKeys;
        state.deviceModal.selectedGatewayKeys = desiredGatewayKeys;
    }

    // Depois de o dispositivo existir, pela mesma razao que os links: a configuracao
    // tem uma chave estrangeira para a whitelist.
    const sensitivityError = await saveDiaperSensitivity(
        imei,
        selectedDiaperSensitivity(deviceType),
    );
    if (sensitivityError) {
        setDeviceFormError(
            `Dispositivo guardado, mas nao foi possivel atualizar a sensibilidade: ${sensitivityError}`,
        );
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

export async function deleteDevice(imei) {
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

export function handleDeleteDeviceBtnClick() {
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

export function renderDeviceSimNumberField(value = "") {
    if (!els.deviceSimNumberRoot) {
        return;
    }

    els.deviceSimNumberRoot.innerHTML = renderPhoneControl({
        value,
        placeholder: "Número do SIM",
    });
    resetPhoneControls(els.deviceSimNumberRoot);
}

export function getDeviceSimNumberValue(strict = false) {
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
