import {getDevice as apiGetDevice} from "../api/index.js";
import {esc} from "../format.js";
import {syncPhoneControl} from "../phone.js";
import {state} from "../state.js";
import {cacheElements} from "./dom.js";
import {
    handleConfigFeedbackClosed,
    handleDeviceConfigChange,
    handleDeviceConfigClick,
    handleDeviceConfigInput,
    initDeviceConfigHandlers,
} from "./handlers/device-config.js";
import {
    handleApiUserListClick,
    handleCapabilityDeviceTypeClick,
    handleCapabilityGroupsChange,
    handleModelDeviceTypeClick,
    handleModelListClick,
    handleModelSupplierClick,
    initSettingsClickHandlers,
    jumpCapabilitySection,
    scrollCapabilityCatalogSection,
} from "./handlers/settings-clicks.js";
import {
    createDeviceFromWizard,
    initWizardHandlers,
    openWizard,
} from "./handlers/create-wizard.js";
import {
    clearDeviceFilters,
    handleDeviceFilterClick,
    handleDeviceModelFilterSearch,
    handleDeviceOnlineFilterChange,
    handleDownlinkPagerClick,
    handleTelemetryPagerClick,
    initDeviceFilterHandlers,
    storedFilterList,
} from "./handlers/device-filters.js";
import {
    FILTERS_STORAGE_KEY,
    SELECTED_DEVICE_STORAGE_KEY,
    loadJsonStorage,
    loadTextStorage,
} from "./storage.js";
import {
    handleDeviceListLimitChange,
    handleDeviceListSearchInput,
    handleDevicePaginationClick,
    initDeviceListDetail,
    loadDevice,
    ensureProtocolsLoaded,
    normalizeDeviceType,
    openDeviceSelector,
    selectDevice,
} from "../devices/list-detail.js";
import {
    applyDetailFilters,
    clearDetailFilters,
    updateDetailFilterDraft,
    applyDetailSearch,
    removeDetailFilter,
    requestTelemetryFeature,
    renderSelection,
} from "../devices/detail-view.js";
import {initDeviceStream} from "../devices/stream.js";
import {
    editWizardAnswered,
    initEditWizard,
} from "../devices/edit-wizard.js";
import {
    editDevice,
    ensureDeviceConfigurationCatalogLoaded,
    handleDeleteDeviceBtnClick,
    renderDeviceSelectors,
    renderDeviceTypeSelector,
    saveDevice,
    setDeviceFormError,
    syncDeviceModalContext,
    initDeviceModal,
} from "../devices/device-modal.js";
import {
    initDeviceConfigPanel,
    renderDeviceConfigurationModal,
    syncConfigSectionDirty,
    syncDeviceModalCommandStates,
} from "../devices/config-panel.js";
import {initCreateWizard} from "../devices/create-wizard.js";
import {
    initGatewayLinksUi,
    refreshGatewayOptions,
    updateGatewayLinkSelection,
} from "../devices/gateway-links-ui.js";
import {initNotifications} from "../notifications.js";
import {
    handleCompanyListClick,
    handleModelsListSearchInput,
    handleSettingsPaginationClick,
    initSettings,
    loadSettingsApiUsersSection,
    loadSettingsCompanySection,
    loadSettingsModal,
    loadSettingsModelsSection,
    resetApiUserForm,
    resetCompanyForm,
    resetLicenseForm,
    resetModelForm,
    saveApiUser,
    saveCompany,
    saveLicense,
    saveModel,
    syncApiUserRoleFields,
    updateModelProtocolAndPreview,
} from "../settings/index.js";
import {
    backToModelList,
    applyDiscoveryPreview,
    deleteCurrentModel,
    saveModelDetail,
    syncModelDetailDirty,
    resetModelDetailFields,
    handleCapabilitySupplierClick,
    handleCapabilityCatalogSearch,
    handleDiscoveryDeviceChange,
    generateDiscoveryPreview,
    loadSettingsCapabilitiesSection,
    loadDiscoveryDevices,
    openNewModelForm,
    revokeModelPreviewUrl,
    saveCapabilities,
    selectCapabilitySupplier,
} from "../settings/capabilities.js";

let els = {};
let deviceModal = null;
let deviceWizardModal = null;
let deviceSelectorModal = null;
let settingsModal = null;

function bindEvents() {
    els.addDeviceBtn.addEventListener("click", () => {
        void openWizard();
    });
    els.openAddDeviceFromSelectorBtn.addEventListener("click", () => {
        deviceSelectorModal?.hide();
        void openWizard();
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
    els.deviceGatewayLinksSelectAllBtn.addEventListener("click", () => {
        els.deviceGatewayLinksList
            ?.querySelectorAll("input[data-gateway-key]")
            .forEach((input) => {
                input.checked = true;
            });
        updateGatewayLinkSelection();
    });
    els.deviceGatewayLinksClearBtn.addEventListener("click", () => {
        els.deviceGatewayLinksList
            ?.querySelectorAll("input[data-gateway-key]")
            .forEach((input) => {
                input.checked = false;
            });
        updateGatewayLinkSelection();
    });
    els.deviceListLimit.addEventListener("change", handleDeviceListLimitChange);
    els.deviceListSearch.addEventListener("input", handleDeviceListSearchInput);
    // Um ouvinte por coluna e não um por controlo: as opções são redesenhadas a cada
    // resposta, e ligar o ouvinte a cada botão obrigava a religá-los todos de cada vez.
    for (const root of [
        els.deviceTypeFilter,
        els.deviceSupplierFilter,
        els.deviceModelFilter,
        els.deviceLicenseFilter,
    ]) {
        root?.addEventListener("click", handleDeviceFilterClick);
    }
    els.deviceModelFilterSearch?.addEventListener(
        "input",
        handleDeviceModelFilterSearch,
    );
    for (const input of document.querySelectorAll('input[name="deviceOnlineFilter"]')) {
        input.addEventListener("change", handleDeviceOnlineFilterChange);
    }
    els.clearDeviceFiltersBtn.addEventListener("click", clearDeviceFilters);
    els.deviceImei.addEventListener("input", handleDeviceImeiInput);
    els.deviceLicenseId.addEventListener("input", handleDeviceImeiInput);
    els.deviceDeviceId.addEventListener("input", handleDeviceImeiInput);
    els.deviceForm.addEventListener("input", handleDeviceFormInput);
    els.deviceForm.addEventListener("change", handleDeviceFormChange);
    els.manageSettingsBtn.addEventListener("click", () => {
        void loadSettingsModal("models");
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
    els.downlinkPager?.addEventListener("click", handleDownlinkPagerClick);
    els.applyDetailFiltersBtn.addEventListener("click", applyDetailFilters);
    els.clearDetailFiltersBtn.addEventListener("click", clearDetailFilters);
    els.detailFilterFrom.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterTo.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterType.addEventListener("change", updateDetailFilterDraft);
    els.detailSearch.addEventListener("input", applyDetailSearch);
    els.detailActiveFilters.addEventListener("click", (event) => {
        const button = event.target.closest('[data-action="removeDetailFilter"]');
        if (!button) return;
        const key = button.dataset.filterKey;
        // A pastilha do intervalo cobre as duas datas, por isso limpa as duas.
        if (key === "range") {
            removeDetailFilter("from");
            removeDetailFilter("to");
            return;
        }
        removeDetailFilter(key);
    });
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
    els.capabilityCatalogSectionNav?.addEventListener(
        "click",
        scrollCapabilityCatalogSection,
    );
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
    els.capabilityCatalogSearch?.addEventListener(
        "input",
        handleCapabilityCatalogSearch,
    );
    els.capabilityActiveFilters?.addEventListener("click", (event) => {
        if (!event.target.closest('[data-action="removeCapabilityFilter"]')) return;
        selectCapabilitySupplier("");
    });
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
    els.modelDetailSaveBtn.addEventListener("click", saveModelDetail);
    els.modelDetailResetBtn.addEventListener("click", resetModelDetailFields);
    els.modelDetailFields.addEventListener("input", syncModelDetailDirty);
    els.modelDetailFields.addEventListener("change", syncModelDetailDirty);
    els.modelDetailDeleteBtn.addEventListener("click", () => {
        void deleteCurrentModel();
    });
    els.modelsCarousel.addEventListener("click", (event) => {
        const button = event.target.closest('[data-action="backToModelList"]');
        if (button) backToModelList();
    });
    els.settingsModelsTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "models";
        if (!state.settingsModal.sectionLoaded.models) {
            void loadSettingsModelsSection();
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
    els.settingsApiUsersPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(
            event,
            "apiUsersPagination",
            loadSettingsApiUsersSection,
        ),
    );
    els.settingsCompanyPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(event, "companyPagination", (page) =>
            loadSettingsCompanySection(page),
        ),
    );
    els.deviceList.addEventListener("click", handleDeviceListClick);
    els.deviceListPagination.addEventListener(
        "click",
        handleDevicePaginationClick,
    );
    els.requestGrid.addEventListener("click", handleRequestGridClick);
    // A arvore do catalogo abre e fecha pelo `collapse` do Bootstrap, sem JS nosso: aqui
    // escuta-se so o que leva a algum lado, que e a folha. O `keydown` esta ao lado do
    // `click` porque a folha e uma linha accionavel e nao um `<button>`.
    els.modelCatalog.addEventListener("click", handleModelListClick);
    els.modelCatalog.addEventListener("keydown", handleModelListClick);
    els.modelsListSearch.addEventListener("input", handleModelsListSearchInput);
    els.apiUserListBody.addEventListener("click", handleApiUserListClick);
    els.companyListBody.addEventListener("click", handleCompanyListClick);
    els.deviceConfigRoot.addEventListener("click", handleDeviceConfigClick);
    els.deviceConfigRoot.addEventListener("input", handleDeviceConfigInput);
    els.deviceConfigRoot.addEventListener("change", handleDeviceConfigChange);
    // O "Enviar" de cada bloco acende por diferenca. Um ouvinte na raiz para os dois
    // eventos, depois de quem trata o campo ja ter feito o seu trabalho.
    for (const type of ["input", "change", "click"]) {
        els.deviceConfigRoot.addEventListener(type, (event) => {
            const section = event.target.closest("[data-config-section]");
            if (section) syncConfigSectionDirty(section);
        });
    }
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
    if (event.target.matches("#deviceGatewayLinksList input[data-gateway-key]")) {
        updateGatewayLinkSelection();
    }
    if (event.target.matches("[data-phone-country]")) {
        syncPhoneControl(event.target);
        void syncDeviceModalContext();
    }
}

function handleDeviceSupplierClick(event) {
    const button = event.target.closest('[data-action="selectDeviceSupplier"]');
    // Escolher o fornecedor nao responde a pergunta do modelo: e o par que identifica, e
    // a pergunta so fecha quando houver modelo.
    if (button) renderDeviceSelectors(button.dataset.value, "");
}

async function handleDeviceTypeClick(event) {
    const button = event.target.closest('[data-action="selectDeviceType"]');
    if (!button) return;

    const deviceType = normalizeDeviceType(button.dataset.value);
    renderDeviceTypeSelector(deviceType);
    await renderDeviceSelectors("", "", deviceType);
    await refreshGatewayOptions([]);
    editWizardAnswered("type");
}

function handleDeviceModelClick(event) {
    const button = event.target.closest('[data-action="selectDeviceModel"]');
    if (!button) return;
    els.deviceForm.dataset.model = button.dataset.value;
    renderDeviceSelectors(
        els.deviceForm.dataset.supplier,
        button.dataset.value,
    );
    editWizardAnswered("model");
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

export async function startDashboard() {
    els = cacheElements();
    initGatewayLinksUi({els});
    initDeviceConfigPanel({els});
    deviceModal = new bootstrap.Modal(document.getElementById("deviceModal"));
    deviceWizardModal = new bootstrap.Modal(
        document.getElementById("deviceWizardModal"),
    );
    deviceSelectorModal = new bootstrap.Modal(
        document.getElementById("deviceSelectorModal"),
    );
    settingsModal = new bootstrap.Modal(
        document.getElementById("settingsModal"),
    );
    initDeviceModal({els, deviceModal, deviceSelectorModal, settingsModal});
    initEditWizard({
        els,
        // A autorizacao de um gateway e por empresa e licenca: mudar de licenca muda quais
        // sao os elegiveis, e os que estavam marcados eram de outro cliente.
        onLicenseChange: () => void refreshGatewayOptions([]),
    });
    initCreateWizard({
        els,
        onCreate: createDeviceFromWizard,
    });
    initDeviceConfigHandlers({els});
    initDeviceFilterHandlers({els});
    initWizardHandlers({wizardModal: deviceWizardModal});
    initSettingsClickHandlers({els});
    initDeviceListDetail({
        els,
        ui: {deviceModal, deviceSelectorModal, settingsModal},
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
        openAddDevice: openWizard,
    });
    bindEvents();
    await ensureProtocolsLoaded();

    // Os filtros guardados podem ser da forma antiga -- um valor por chave, e `licenseId` e
    // `company` separados. `storedFilterList` aceita as duas, para que quem tinha filtros
    // guardados os veja convertidos em vez de perdidos.
    const stored = loadJsonStorage(FILTERS_STORAGE_KEY);
    if (stored && typeof stored === "object") {
        state.deviceFilters = {
            deviceType: storedFilterList(stored.deviceType),
            supplier: storedFilterList(stored.supplier),
            model: storedFilterList(stored.model),
            license: storedFilterList(stored.license),
            online: typeof stored.online === "boolean" ? stored.online : null,
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
