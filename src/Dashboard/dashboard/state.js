export const state = {
    summary: {
        devices: [],
        models: [],
        devicePagination: {limit: 5, page: 1, total_pages: 1, total: 0},
        deviceFiltersAvailable: {deviceType: [], licenseId: [], supplier: [], model: []},
    },
    filtersOpen: false,
    deviceFilters: {
        deviceType: null,
        licenseId: null,
        supplier: null,
        model: null,
    },
    pendingDeviceFilters: {
        deviceType: null,
        licenseId: null,
        supplier: null,
        model: null,
    },
    deviceSearchQuery: '',
    selectedImei: null,
    selectedDetail: null,
    deviceModal: {
        mode: 'create',
        activeTab: 'general',
        activeCategory: '',
        imei: '',
        originalImei: '',
        deviceType: 'watch',
        licenseId: '0',
        simNumber: '',
        deviceId: '',
        supplier: '',
        model: '',
        protocol: '',
        catalog: [],
        configurations: [],
        configUi: {},
        loading: false,
    },
    modelModalSuppliers: [],
    modelPreviewObjectUrl: null,
    loadingCommands: new Set(),
    deviceListPage: 1,
    deviceListPageSize: 5,
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
}

export function setTelemetryPage(page, totalPages) {
    state.telemetryPage = Math.min(Math.max(1, page), Math.max(1, totalPages));
}
