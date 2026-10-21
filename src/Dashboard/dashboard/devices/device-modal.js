import {
    deleteDevice as apiDeleteDevice,
    getDevice as apiGetDevice,
    saveDevice as apiSaveDevice,
} from "../api/index.js";
import { ensureCapabilityCatalog } from "../capability-catalog.js";
import { apiError, confirmDestructive } from "../dialogs.js";
import { clearInvalid, markApiFields } from "../validation.js";
import { ensureLicensesLoaded } from "../licenses.js";
import {
    deviceTypeCardsHtml,
    licenseTree,
    modelCardsHtml,
    supplierCardsHtml,
} from "./classification-ui.js";
import {
    renderEditWizard,
    resetEditWizard,
    showDeviceFields,
} from "./edit-wizard.js";
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
    deviceTypeFields,
    deviceTypeLabel,
    findModelInfo,
    linksToGateway,
    modelInternalName,
    modelsForSupplierAndType,
    normalizeDeviceType,
    supplierModelLabel,
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
    html,
    raw,
} from "../html.js";
import {
    normalizePhoneControl,
    renderPhoneControl,
    resetPhoneControls,
} from "../phone.js";
import {
    modelImageHtml,
    modelPreviewHtml,
} from "../components/model-image.js";
import {
    blankDeviceModal,
    clearSelection,
    selectImei,
    state,
} from "../state.js";
import {
    SELECTED_DEVICE_STORAGE_KEY,
    clearStorageKey,
    saveTextStorage,
} from "../storage.js";

let els;
let deviceModal;

/** O painel de configurações entra por `import()` quando se abre o separador: uma porta só. */
let configPanel = null;
let loadedPanel = null;

export function loadConfigPanel() {
    return (configPanel ??= Promise.all([
        import("./config/index.js"),
        import("./config/panel.js"),
        import("./config/handlers.js"),
    ])
        .then(([catalog, panel, handlers]) => {
            panel.initDeviceConfigPanel({ els });
            loadedPanel = { catalog, panel, handlers };
            return loadedPanel;
        })
        .catch((error) => {
            // Limpa-se para a tentativa seguinte voltar a pedir.
            configPanel = null;
            throw error;
        }));
}

/** O painel se já cá estiver, sem o mandar vir. */
export function configPanelIfLoaded() {
    return loadedPanel;
}

export function initDeviceModal(context) {
    els = context.els;
    deviceModal = context.deviceModal;
}

/** Todas as licenças, porque mudar de cliente é razão para abrir isto; `null` se o pedido falha. */
async function loadLicenseGroups() {
    const licenses = await ensureLicensesLoaded();
    return licenses === null ? null : licenseTree(licenses);
}

/** O passo 1 está escondido ao gravar: o aviso é o do formulário, e não uma marca no campo. */
function classificationIsMissing(supplier, model) {
    if (supplier && model) return false;
    setDeviceFormError(
        supplier
            ? "Escolha o modelo na classificação."
            : "Escolha o fornecedor na classificação.",
    );
    return true;
}

/** Só em ecrã largo, e só no Geral: no separador das configurações ninguém veio apagar. */
export function syncDeleteFooterButton() {
    const deletable = !els.deleteDeviceBtn.classList.contains("d-none");
    els.deleteDeviceFooterBtn?.classList.toggle(
        "d-none",
        !deletable || state.deviceModal.activeTab === "config",
    );
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
    configPanelIfLoaded()?.panel.resetConfigUiState();
    state.deviceModal = blankDeviceModal({
        activeTab,
        imei,
        supplier,
        model,
        loading: true,
    });
    setDeviceFormError("");
    clearInvalid(els.deviceForm);
    els.deviceConfigTabBtn?.classList.remove("d-none");
    // A conta do separador é do dispositivo aberto: esconde-se até o painel chegar.
    els.deviceConfigCount?.classList.add("d-none");
    els.deleteDeviceBtn.dataset.imei = imei;
    els.deleteDeviceBtn.classList.remove("d-none");
    syncDeleteFooterButton();
    renderDeviceTypeSelector("watch");
    els.deviceCompany.value = "";
    els.deviceLicenseId.value = "";
    resetEditWizard([]);
    await renderDeviceSelectors(supplier, model);
    renderDeviceSimNumberField("");
    renderEditWizard();
    deviceModal.show();

    // Entre abrir e responder pode abrir-se outro aparelho: o que chega atrasado não pinta.
    const superseded = () => state.deviceModal.imei !== imei;

    let licensesLoaded = true;
    try {
        const [detail, licenseGroups] = await Promise.all([
            apiGetDevice(imei),
            loadLicenseGroups(),
        ]);
        if (superseded()) return;
        licensesLoaded = licenseGroups !== null;
        resetEditWizard(licenseGroups || []);
        if (detail?.error) {
            setDeviceFormError(apiError(detail));
            return;
        }
        const device = detail.device || {};
        const deviceModel = detail.model;
        const deviceType = String(deviceModel?.deviceType || "watch");
        const licenseId = String(device.licenseId ?? "");
        renderDeviceTypeSelector(deviceType);
        await renderDeviceSelectors(
            String(deviceModel?.supplier || supplier),
            String(deviceModel?.internalModel || model),
            deviceType,
        );
        if (superseded()) return;
        els.deviceCompany.value = device.company ?? "";
        els.deviceLicenseId.value = licenseId;
        state.deviceModal.deviceType = normalizeDeviceType(deviceType);
        state.deviceModal.licenseId = licenseId;
        renderDeviceSimNumberField(String(device.simNumber || ""));
        state.deviceModal.simNumber = String(device.simNumber || "");
        els.deviceDeviceId.value = String(device.deviceId || "");
        state.deviceModal.deviceId = String(device.deviceId || "");
        const linkedGatewayKeys = gatewayKeysFromLinks(
            detail.linkedDevices || [],
        );
        state.deviceModal.linkedGatewayKeys = linkedGatewayKeys;
        state.deviceModal.selectedGatewayKeys = linkedGatewayKeys;
        await refreshGatewayOptions(linkedGatewayKeys);
        if (superseded()) return;
        state.deviceModal.configurations = detail.configurations || {};
        state.deviceModal.configurationSync = detail.configurationSync || { entries: {} };
        state.deviceModal.capabilities = detail.capabilities || {};
        state.deviceModal.enabledCapabilityKeys = detail.enabledCapabilityKeys || [];
        state.deviceModal.online = Boolean(device.online);
        state.deviceModal.detailLoaded = true;
        renderDeviceModalIdentity(device, deviceModel, deviceType);
    } finally {
        if (!licensesLoaded && state.deviceModal.errorMessage === "") {
            setDeviceFormError("Ligação ao servidor indisponível.");
        }
        state.deviceModal.loading = false;
        await syncDeviceModalContext();
        renderEditWizard();
        configPanelIfLoaded()?.panel.renderDeviceConfigurationModal();
    }
}

/** A identidade no cabeçalho do modal, à vista mesmo com a página a rolar. */
function renderDeviceModalIdentity(device, deviceModel, deviceType) {
    if (!els.deviceModalIdentity) return;

    const imei = String(device?.imei || state.deviceModal.imei || "");
    const commercial = String(deviceModel?.commercialName || deviceModel?.internalModel || "");
    const supplier = String(deviceModel?.supplier || "");
    const company = companyLabel(device?.company);
    const online = Boolean(device?.online);
    const meta = [
        deviceTypeLabel(normalizeDeviceType(deviceType)),
        supplierModelLabel(supplier, commercial),
        device?.licenseId ? `${company} / ${device.licenseId}` : company,
    ].filter((part) => part !== "");

    // O estado é um ponto e não uma pastilha, para a linha caber; quem não vê o ponto lê a palavra.
    els.deviceModalIdentity.innerHTML = html`
        <span class="modal-device-thumb d-flex align-items-center justify-content-center flex-shrink-0 rounded-3">${raw(modelImageHtml(deviceModel, 24))}</span>
        <span class="min-w-0">
            <h5 class="modal-title mb-0 tabular-nums text-truncate" id="deviceModalLabel">${imei}</h5>
            <span class="modal-device-meta d-flex align-items-center gap-2 min-w-0">
                <span class="state-badge-dot rounded-circle flex-shrink-0${online ? " bg-success" : ""}" aria-hidden="true"></span>
                <span class="visually-hidden">${online ? "Ligado" : "Desligado"}</span>
                <span class="text-truncate">${meta.join(" · ")}</span>
            </span>
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
    const availableModels = modelsForSupplierAndType(supplier, currentDeviceType, models);
    const availableModelNames = availableModels.map((model) =>
        modelInternalName(model),
    );
    const model = availableModelNames.includes(selectedModel)
        ? selectedModel
        : availableModelNames[0] || "";

    els.deviceForm.dataset.supplier = supplier;
    els.deviceForm.dataset.model = model;

    els.deviceSupplierButtons.innerHTML = supplierCardsHtml({
        suppliers,
        selected: supplier,
        attrsFor: (name) =>
            html`data-action="selectDeviceSupplier" data-value="${name}"`,
    });
    els.deviceModelButtons.innerHTML = availableModels.length
        ? modelCardsHtml({
                models: availableModels,
                selected: model,
                attrsFor: (internal) =>
                    html`data-action="selectDeviceModel" data-value="${internal}"`,
            })
        : "<div class=\"text-secondary small py-2\">Sem modelos deste fornecedor.</div>";
    updateDevicePreview();
    renderEditWizard();
    await syncDeviceModalContext();
    applyDeviceTypeFields();
    configPanelIfLoaded()?.panel.renderDeviceConfigurationModal();
}

export function renderDeviceTypeSelector(selectedType = "watch") {
    const deviceType = normalizeDeviceType(selectedType);
    els.deviceForm.dataset.deviceType = deviceType;
    els.deviceTypeButtons.innerHTML = deviceTypeCardsHtml({
        selected: deviceType,
        attrsFor: (value) =>
            html`data-action="selectDeviceType" data-value="${value}"`,
    });

    applyDeviceTypeFields();
}

/** Os campos do tipo, corrigidos pelo protocolo do modelo. */
function applyDeviceTypeFields() {
    const fields = deviceTypeFields(els.deviceForm.dataset.deviceType, state.deviceModal.protocol);
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
}

export async function syncDeviceModalContext(loadCatalog = false) {
    const supplier = els.deviceForm.dataset.supplier || "";
    const model = els.deviceForm.dataset.model || "";
    // Com o modelo: a Wonlex vende relógios TCP e a pulseira MF91, que é BLE.
    const protocol = supplierProtocol(supplier, state.deviceTypeSuppliersModels, model);
    state.deviceModal.supplier = supplier;
    state.deviceModal.model = model;
    state.deviceModal.protocol = protocol;
    if (loadCatalog || state.deviceModal.activeTab === "config") {
        const { catalog } = await loadConfigPanel();
        state.deviceModal.catalog = await catalog.catalogForProtocol(protocol);
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
    state.deviceModal.licenseId = els.deviceLicenseId.value.trim();
    state.deviceModal.simNumber = deviceSimNumber();
    state.deviceModal.deviceId = els.deviceDeviceId?.value.trim() || "";
}

export async function ensureDeviceConfigurationCatalogLoaded() {
    const protocol = state.deviceModal.protocol;
    if (!protocol || state.protocolCatalogs[protocol]) {
        await syncDeviceModalContext(false);
        return;
    }

    state.deviceModal.catalogLoading = true;
    configPanelIfLoaded()?.panel.renderDeviceConfigurationModal();
    try {
        await syncDeviceModalContext(true);
    } finally {
        state.deviceModal.catalogLoading = false;
    }
}

export async function saveDevice() {
    // Editar o que não chegou a carregar é gravar os valores de arranque por cima do registo real.
    if (!state.deviceModal.detailLoaded) {
        setDeviceFormError("O dispositivo não chegou a carregar. Feche e volte a abrir.");
        return;
    }
    // A validação abaixo marca campos que só existem no passo do aparelho.
    showDeviceFields();
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
    // A identidade e o SIM são perguntas distintas: o gateway identifica-se por MAC e leva SIM.
    const fields = deviceTypeFields(deviceType, state.deviceModal.protocol);
    const identifiedByImei = fields.identity.field === "imei";
    const deviceId = identifiedByImei ? "" : els.deviceDeviceId.value.trim();

    if (classificationIsMissing(supplier, model)) return;

    if (!identifiedByImei) imei = deviceId;

    if (fields.sim) {
        try {
            simNumber = deviceSimNumberOrThrow();
        } catch {
            // O próprio controlo do número já marca o campo e diz o que está errado.
            els.deviceSimNumberRoot
                ?.querySelector("[data-phone-local]")
                ?.focus();
            return;
        }
    }

    const originalImei = els.deviceImei.dataset.originalImei;
    const company = els.deviceCompany.value;
    const desiredGatewayKeys = linksToGateway(deviceType, state.deviceModal.protocol)
        ? selectedGatewayKeys()
        : [];

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
        const identity = identifiedByImei ? els.deviceImei : els.deviceDeviceId;
        if (!markApiFields(result, (field) => ({ imei: identity, deviceId: els.deviceDeviceId })[field])) {
            setDeviceFormError(apiError(result));
        }
        return;
    }

    if (linksToGateway(deviceType, state.deviceModal.protocol)) {
        const currentGatewayKeys = originalImei !== imei
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

    if (state.selectedImei === originalImei) {
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
    const result = await apiDeleteDevice(imei);
    if (result.error) {
        setDeviceFormError(apiError(result));
        return;
    }
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

    els.deviceSimNumberRoot.innerHTML = renderPhoneControl({ value });
    resetPhoneControls(els.deviceSimNumberRoot);
}

/** O número do SIM, ou vazio se ainda não for um número. */
function deviceSimNumber() {
    try {
        return deviceSimNumberOrThrow();
    } catch {
        return "";
    }
}

/** O mesmo, a gravar: um número inválido para a gravação. */
function deviceSimNumberOrThrow() {
    const control =
        els.deviceSimNumberRoot?.querySelector("[data-phone-control]") || null;

    return control ? normalizePhoneControl(control) : "";
}
