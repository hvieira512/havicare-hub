import {
    deleteDevice as apiDeleteDevice,
    getDevice as apiGetDevice,
    saveDevice as apiSaveDevice,
} from "../api/index.js";
import { ensureCapabilityCatalog } from "../capability-catalog.js";
import { apiError, confirmDestructive } from "../dialogs.js";
import { clearInvalid, markInvalid } from "../validation.js";
import { ensureLicensesLoaded } from "../licenses.js";
import {
    deviceTypeCardsHtml,
    licenseTree,
    modelCardsHtml,
    supplierPillsHtml,
} from "./classification-ui.js";
import {
    renderEditWizard,
    resetEditWizard,
} from "./edit-wizard.js";
import {
    catalogForProtocol,
} from "./config/index.js";
import {
    renderDeviceConfigurationModal,
    resetConfigUiState,
} from "./config/panel.js";
import {
    renderSelection,
} from "./detail.js";
import {
    refreshGatewayOptions,
    selectedGatewayKeys,
    syncGatewayLinks,
} from "./gateway-links-ui.js";
import {
    gatewayKeysFromLinks,
} from "./gateway-links.js";
import {
    companyLabel,
    deriveFourPTouchDeviceId,
    deviceTypeFields,
    deviceTypeLabel,
    findModelInfo,
    isFourPTouchSelection,
    linksToGateway,
    modelCommercialName,
    modelInternalName,
    modelsForSupplierAndType,
    normalizeDeviceType,
    supplierProtocol,
    suppliersForDeviceType,
} from "../domain.js";
import {
    ensureDeviceTypeSuppliersModelsLoaded,
    isDeviceSelectorOpen,
    loadDevice,
    loadSummary,
} from "./list.js";
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
    modelImageHtml,
    modelPreviewHtml,
    onlineBadge,
} from "../widgets.js";
import {
    clearSelection,
    selectImei,
    state,
} from "../state.js";
import {
    SELECTED_DEVICE_STORAGE_KEY,
    clearStorageKey,
    saveTextStorage,
} from "../storage.js";

/**
 * O modal de adicionar e editar um dispositivo: os seus selectores, o formulário, e gravar
 * ou apagar. Recebe o mapa de elementos e as instâncias do Bootstrap pelo `initDeviceModal`,
 * como os outros módulos de vista.
 */

let els;
let deviceModal;

export function initDeviceModal(context) {
    els = context.els;
    deviceModal = context.deviceModal;
}

/**
 * As licenças, agrupadas pela empresa que as detém, para a árvore da classificação. Todas
 * de uma vez e não as da empresa do dispositivo: mudar de cliente é precisamente uma das
 * razões para abrir isto.
 *
 * Devolve `null` quando o pedido falha, para o modal distinguir "o servidor não respondeu"
 * de "não há licenças nenhumas" a quem está a olhar para uma árvore vazia.
 */
async function loadLicenseGroups() {
    const licenses = await ensureLicensesLoaded();
    return licenses === null ? null : licenseTree(licenses);
}

/**
 * O fornecedor e o modelo respondem-se no passo 1, que está escondido quando se grava: o
 * aviso deles tem de ser o do formulário, ao lado do botão, e não uma marca no campo.
 */
function classificationIsMissing(supplier, model) {
    if (supplier && model) return false;
    setDeviceFormError(
        supplier
            ? "Escolha o modelo na classificação."
            : "Escolha o fornecedor na classificação.",
    );
    return true;
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

function activeDeviceModalTab() {
    return els.deviceConfigTabBtn?.classList.contains("active") ||
        els.deviceConfigPane?.classList.contains("active")
        ? "config"
        : "general";
}

export async function editDevice(imei, supplier, model) {
    await ensureDeviceTypeSuppliersModelsLoaded();
    const activeTab = activeDeviceModalTab();
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
        configurationSync: { entries: {} },
        capabilities: {},
        enabledCapabilityKeys: [],
        configUi: {},
        errorMessage: "",
        loading: true,
    };
    setDeviceFormError("");
    clearInvalid(els.deviceForm);
    els.deviceConfigTabBtn?.classList.remove("d-none");
    els.deleteDeviceBtn.dataset.imei = imei;
    els.deleteDeviceBtn.classList.remove("d-none");
    renderDeviceTypeSelector("watch");
    els.deviceCompany.value = "";
    els.deviceLicenseId.value = "0";
    resetEditWizard([]);
    await renderDeviceSelectors(supplier, model);
    renderDeviceSimNumberField("");
    renderEditWizard();
    deviceModal.show();

    let licensesLoaded = true;
    try {
        const [detail, licenseGroups] = await Promise.all([
            apiGetDevice(imei),
            loadLicenseGroups(),
        ]);
        licensesLoaded = licenseGroups !== null;
        resetEditWizard(licenseGroups || []);
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
        // `null` é como a base de dados escreve "sem empresa": não é um nome.
        els.deviceCompany.value = deviceCompany === "null" ? "" : deviceCompany;
        els.deviceLicenseId.value = licenseId;
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
        state.deviceModal.configurations = detail.configurations || {};
        state.deviceModal.configurationSync = detail.configurationSync || { entries: {} };
        state.deviceModal.capabilities = detail.capabilities || {};
        state.deviceModal.enabledCapabilityKeys = detail.enabledCapabilityKeys || [];
        renderDeviceModalIdentity(device, deviceModel, deviceType);
    } finally {
        if (!licensesLoaded && state.deviceModal.errorMessage === "") {
            setDeviceFormError("Ligação ao servidor indisponível.");
        }
        state.deviceModal.loading = false;
        await syncDeviceModalContext();
        renderEditWizard();
        renderDeviceConfigurationModal();
    }
}

/**
 * A identidade do dispositivo no cabeçalho do modal, sempre à vista -- mesmo com o separador
 * das configurações aberto e a página a rolar.
 */
function renderDeviceModalIdentity(device, deviceModel, deviceType) {
    if (!els.deviceModalIdentity) return;

    const imei = String(device?.imei || state.deviceModal.imei || "");
    const commercial = String(deviceModel?.commercialName || deviceModel?.internalModel || "");
    const supplier = String(deviceModel?.supplier || "");
    const company = companyLabel(device?.company);
    const licenseId = String(device?.licenseId || "0");
    const online = Boolean(device?.online);
    const meta = [
        deviceTypeLabel(normalizeDeviceType(deviceType)),
        [supplier, commercial].filter((part) => part !== "").join(" "),
        licenseId !== "0" && licenseId !== ""
            ? `${company} / ${licenseId}`
            : company,
    ].filter((part) => part !== "");

    els.deviceModalIdentity.innerHTML = `
        <span class="modal-device-thumb">${modelImageHtml(deviceModel, 26)}</span>
        <span class="min-w-0">
            <span class="d-flex align-items-center gap-2 flex-wrap">
                <h5 class="modal-title mb-0 tabular-nums" id="deviceModalLabel">${esc(imei)}</h5>
                ${onlineBadge(online)}
            </span>
            <span class="d-block small text-secondary">${esc(meta.join(" · "))}</span>
        </span>`;
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

    els.deviceSupplierButtons.innerHTML = supplierPillsHtml({
        suppliers,
        selected: supplier,
        attrsFor: (name) =>
            `data-action="selectDeviceSupplier" data-value="${esc(name)}"`,
    });
    els.deviceModelButtons.innerHTML = availableModels.length
        ? modelCardsHtml({
                models: availableModels,
                selected: model,
                attrsFor: (internal) =>
                    `data-action="selectDeviceModel" data-value="${esc(internal)}"`,
            })
        : "<div class=\"text-secondary small py-2\">Sem modelos deste fornecedor.</div>";
    updateDevicePreview();
    renderEditWizard();
    await syncDeviceModalContext();
    renderDeviceConfigurationModal();
}

export function renderDeviceTypeSelector(selectedType = "watch") {
    const deviceType = normalizeDeviceType(selectedType);
    els.deviceForm.dataset.deviceType = deviceType;
    els.deviceTypeButtons.innerHTML = deviceTypeCardsHtml({
        selected: deviceType,
        attrsFor: (value) =>
            `data-action="selectDeviceType" data-value="${esc(value)}"`,
    });

    // Uma linha da tabela em vez de quatro cadeias de `if` e cinco toggles decididos aqui.
    const fields = deviceTypeFields(deviceType);
    const byImei = fields.identity.field === "imei";
    els.deviceImeiRow?.classList.toggle("d-none", !byImei);
    els.deviceSimRow?.classList.toggle("d-none", !fields.sim);
    els.deviceDeviceIdRow?.classList.toggle("d-none", byImei);
    els.deviceGatewayLinksRow?.classList.toggle("d-none", !fields.gatewayLinks);

    if (!byImei) {
        els.deviceDeviceIdLabel.textContent = fields.identity.label;
        els.deviceDeviceIdHelp.textContent = fields.identity.help;
        els.deviceDeviceId.placeholder = fields.identity.placeholder;
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
    state.deviceModal.capabilityCatalog = await ensureCapabilityCatalog(
        state.deviceModal.deviceType,
    );
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

export async function saveDevice() {
    setDeviceFormError("");
    clearInvalid(els.deviceForm);
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
        if (classificationIsMissing(supplier, model)) return;
        if (!deviceId) {
            markInvalid(els.deviceDeviceId, "O Device ID é obrigatório");
            return;
        }
        imei = deviceId;
        simNumber = "";
    } else {
        try {
            simNumber = getDeviceSimNumberValue(true);
        } catch {
            // O próprio controlo do número já marca o campo e diz o que está errado.
            els.deviceSimNumberRoot
                ?.querySelector("[data-phone-local]")
                ?.focus();
            return;
        }
        if (classificationIsMissing(supplier, model)) return;
        if (!imei) {
            markInvalid(els.deviceImei, "O IMEI é obrigatório");
            return;
        }
    }

    const originalImei = els.deviceImei.dataset.originalImei || "";
    const company = els.deviceCompany.value || "null";
    const desiredGatewayKeys = linksToGateway(deviceType)
        ? selectedGatewayKeys()
        : [];
    if (deviceType !== "watch" && (licenseId === "" || licenseId === "0")) {
        // A licença também é do passo 1: mesma razão, mesmo sítio para o aviso.
        setDeviceFormError(
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
        setDeviceFormError(apiError(result));
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

export async function handleDeleteDeviceBtnClick() {
    const imei = els.deleteDeviceBtn.dataset.imei;
    if (!imei) return;
    const { isConfirmed } = await confirmDestructive(`Apagar o dispositivo ${imei}?`);
    if (!isConfirmed) return;
    await apiDeleteDevice(imei);
    deviceModal.hide();
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
