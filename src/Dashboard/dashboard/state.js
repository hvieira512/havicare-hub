export const state = {
    summary: {devices: [], models: [], counts: {}},
    selectedImei: null,
    selectedDetail: null,
    deviceModal: {
        mode: 'create',
        activeTab: 'general',
        activeCategory: '',
        imei: '',
        originalImei: '',
        simNumber: '',
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
