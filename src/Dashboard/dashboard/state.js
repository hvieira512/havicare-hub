export const state = {
    companies: [],
    summary: {
        devices: [],
        models: [],
        devicePagination: { limit: 20, page: 1, total_pages: 1, total: 0 },
        deviceFiltersAvailable: {
            deviceType: [],
            licenseId: [],
            company: [],
            supplier: [],
            model: [],
        },
    },
    deviceListPageSize: 20,
    deviceTypeSuppliersModels: [],
    protocols: [],
    detailFilters: {
        from: "",
        to: "",
        type: "all",
    },
    detailFiltersDraft: {
        from: "",
        to: "",
        type: "all",
    },
    deviceFilters: {
        deviceType: null,
        licenseId: null,
        company: null,
        supplier: null,
        model: null,
    },
    deviceSearchQuery: "",
    selectedImei: null,
    selectedDetail: null,
    deviceModal: {
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
    },
    modelModalSuppliers: [],
    modelModal: {
        capabilities: [],
        enabledCapabilities: [],
        templateSummary: "",
        templateSupplier: "",
        templateDeviceType: "watch",
    },
    protocolCatalogs: {},
    settingsModal: {
        section: "suppliers",
        capabilityDeviceType: "",
        capabilityCatalog: [],
        capabilityCatalogByType: {},
        capabilitySupplier: "",
        capabilityTemplateEnabledKeys: [],
        capabilitySuppliersForDeviceType: [],
        capabilityModelId: null,
        capabilityModelTemplateKeys: [],
        capabilityEnabledCapabilities: [],
        capabilityRequestableCapabilities: [],
        currentCapabilitiesModel: null,
        modelFilters: [],
        discoveryDeviceImei: "",
        discoveryDeviceOptions: [],
        discoveryRun: null,
        discoveryLoading: false,
        discoveryError: "",
        modelsDeviceType: "",
        modelsSupplier: "",
        modelsSearchQuery: "",
        modelsPageSize: 20,
        sectionLoaded: {
            suppliers: false,
            models: false,
            modelFilters: false,
            capabilities: false,
            company: false,
            apiUsers: false,
        },
        suppliersPagination: null,
        modelsPagination: null,
        companyPagination: null,
        licensesPagination: null,
        apiUsersPagination: null,
    },
    modelPreviewObjectUrl: null,
    loadingCommands: new Set(),
    deviceListPage: 1,
    deviceListPageSize: 20,
    telemetryPage: 1,
    telemetryPageSize: 10,
};

export function selectImei(imei) {
    if (state.selectedImei !== imei) {
        state.telemetryPage = 1;
    }
    state.selectedImei = imei;
}

export function clearSelection() {
    state.selectedImei = null;
    state.selectedDetail = null;
    state.telemetryPage = 1;
    state.detailFiltersDraft = { from: "", to: "", type: "all" };
}

export function setTelemetryPage(page, totalPages) {
    state.telemetryPage = Math.min(Math.max(1, page), Math.max(1, totalPages));
}
