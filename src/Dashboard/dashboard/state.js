export const state = {
    // Gateways registados, para o assistente de adicionar oferecer os elegiveis.
    wizardGateways: [],
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
        // As contagens por opção, que dizem o que se ganha ao marcar mais uma caixa. Vêm na
        // mesma resposta que traz a lista, logo actualizam-se com ela.
        deviceFilterCounts: {
            deviceType: [],
            supplier: [],
            model: [],
            license: {companies: [], none: 0},
        },
        deviceTotals: {total: 0, online: 0},
    },
    deviceListPageSize: 20,
    deviceTypeSuppliersModels: [],
    protocols: [],
    detailFilters: {
        from: "",
        to: "",
        type: "all",
        q: "",
    },
    detailFiltersDraft: {
        from: "",
        to: "",
        type: "all",
        q: "",
    },
    // Listas e não valores: todos os filtros da listagem aceitam vários valores ao mesmo
    // tempo. A licença guarda pares "empresa" ou "empresa:número", ou "none" para os
    // dispositivos sem empresa nem licença -- as duas coisas são um filtro só, porque uma
    // licença pertence a uma empresa e um dispositivo tem as duas ou nenhuma.
    //
    // O estado é o único de valor único: "ligados e desligados" é "todos", que já é a
    // terceira opção, e por isso guarda-se como `null`, `true` ou `false`.
    deviceFilters: {
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    },
    deviceModelFilterSearch: "",
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
        // A arvore do catalogo, inteira: tipos, fornecedores e modelos numa chamada. Nao ha
        // pagina nem filtros porque nao ha paginacao -- a busca corre sobre isto em memoria.
        modelCatalog: [],
        modelsSearchQuery: "",
        sectionLoaded: {
            models: false,
            modelFilters: false,
            capabilities: false,
            company: false,
            apiUsers: false,
        },
        companyPagination: null,
        licensesPagination: null,
        apiUsersPagination: null,
    },
    modelPreviewObjectUrl: null,
    loadingCommands: new Set(),
    deviceListPage: 1,
    telemetryPage: 1,
    // Doze e nao dez: com os detalhes reduzidos ao que cada tipo declara, a maioria das
    // linhas passou a ter uma linha de texto em vez de duas ou tres, e cabem mais duas na
    // mesma altura. Cem eventos passam de dez paginas para nove.
    telemetryPageSize: 12,
    downlinkPage: 1,
    downlinkPageSize: 12,
};

export function selectImei(imei) {
    if (state.selectedImei !== imei) {
        state.telemetryPage = 1;
        state.downlinkPage = 1;
    }
    state.selectedImei = imei;
}

export function clearSelection() {
    state.selectedImei = null;
    state.selectedDetail = null;
    state.telemetryPage = 1;
    state.downlinkPage = 1;
    state.detailFiltersDraft = { from: "", to: "", type: "all", q: "" };
}

export function setTelemetryPage(page, totalPages) {
    state.telemetryPage = Math.min(Math.max(1, page), Math.max(1, totalPages));
}

export function setDownlinkPage(page, totalPages) {
    state.downlinkPage = Math.min(Math.max(1, page), Math.max(1, totalPages));
}
