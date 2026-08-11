import {
    createDeviceLink as apiCreateDeviceLink,
    deleteDevice as apiDeleteDevice,
    deleteDeviceLink as apiDeleteDeviceLink,
    getCapabilities as apiGetCapabilities,
    getCompanies as apiGetCompanies,
    getDevice as apiGetDevice,
    getDevices as apiGetDevices,
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
    FILTERS_STORAGE_KEY,
    SELECTED_DEVICE_STORAGE_KEY,
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
    linksToGateway,
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
    usesMacAddress,
} from "../devices/list-detail.js";
import {
    eligibleGateways,
    gatewayKeysFromLinks,
    gatewayLinkChanges,
} from "../devices/gateway-links.js";
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
    activeDeviceModalTab,
    applyFourPTouchDeviceIdUi,
    deleteDevice,
    editDevice,
    ensureDeviceConfigurationCatalogLoaded,
    getDeviceSimNumberValue,
    handleCompanySelect,
    handleDeleteDeviceBtnClick,
    handleLicenseSelect,
    openAddDevice,
    populateCompanySelect,
    populateLicenseSelectForCompany,
    renderDeviceSelectors,
    renderDeviceSimNumberField,
    renderDeviceTypeSelector,
    saveDevice,
    setDeviceFormError,
    syncDeviceModalContext,
    updateDevicePreview,
    initDeviceModal,
} from "../devices/device-modal.js";
import {
    armConfigFeedbackAutoClose,
    dismissConfigFeedback,
    initDeviceConfigPanel,
    refreshDeviceModalConfigurations,
    renderDeviceConfigurationModal,
    resetConfigUiState,
    saveDeviceConfiguration,
    setConfigUi,
    syncDeviceModalCommandStates,
} from "../devices/config-panel.js";
import {
    initGatewayLinksUi,
    refreshGatewayOptions,
    selectedGatewayKeys,
    syncGatewayLinks,
    updateGatewayLinkSelection,
} from "../devices/gateway-links-ui.js";
import {
    appendAlarmClockRow,
    appendContactRow,
    appendPhoneListRow,
    appendTakePillsReminder,
    appendWonlexMedicationPlan,
    createContactRow,
    isFourPTouchPhonebookSection,
    removeConfigRow,
    removeTakePillsReminder,
    removeWonlexMedicationPlan,
    renumberWonlexMedicationPlans,
    syncAlarmClockCustomVisibility,
    syncTakePillsRows,
} from "../config/row-editing.js";
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
    if (event.target.matches("#deviceGatewayLinksList input[data-gateway-key]")) {
        updateGatewayLinkSelection();
    }
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

    dismissConfigFeedback(alertEl.dataset.configFeedbackKey || "");
}

function handleDeviceSupplierClick(event) {
    const button = event.target.closest('[data-action="selectDeviceSupplier"]');
    if (button) renderDeviceSelectors(button.dataset.value, "");
}

async function handleDeviceTypeClick(event) {
    const button = event.target.closest('[data-action="selectDeviceType"]');
    if (!button) return;

    const deviceType = normalizeDeviceType(button.dataset.value);
    renderDeviceTypeSelector(deviceType);
    await renderDeviceSelectors("", "", deviceType);
    await refreshGatewayOptions([]);
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

export async function startDashboard() {
    els = cacheElements();
    initGatewayLinksUi({els});
    initDeviceConfigPanel({els});
    deviceModal = new bootstrap.Modal(document.getElementById("deviceModal"));
    deviceSelectorModal = new bootstrap.Modal(
        document.getElementById("deviceSelectorModal"),
    );
    settingsModal = new bootstrap.Modal(
        document.getElementById("settingsModal"),
    );
    initDeviceModal({els, deviceModal, deviceSelectorModal, settingsModal});
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
