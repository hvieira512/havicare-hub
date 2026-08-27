export const state = {
    // Gateways registados, para o assistente de adicionar oferecer os elegíveis.
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
    // O catálogo de capacidades por tipo de dispositivo, com cache. Vive aqui e não no
    // `settingsModal` porque a coluna de detalhe também o lê -- é dela que saem os nomes
    // das capacidades nos cartões e na lista de eventos.
    capabilityCatalogByType: {},
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
        // As licenças, uma vez por sessão: são a árvore do filtro, a do assistente e as
        // opções de três formulários. Quem as muda limpa-as.
        licenses: [],
        capabilityDeviceType: "",
        capabilityCatalog: [],
        // A secção do catálogo para onde a tira de pastilhas levou, para o realce sobreviver
        // a uma reconstrução da tira.
        activeCapabilityCatalogSection: "",
        capabilitySupplier: "",
        capabilityTemplateEnabledKeys: [],
        capabilitySuppliersForDeviceType: [],
        capabilityModelId: null,
        capabilityModelTemplateKeys: [],
        capabilityEnabledCapabilities: [],
        capabilityRequestableCapabilities: [],
        currentCapabilitiesModel: null,
        modelFilters: [],
        // A árvore do catálogo, inteira: tipos, fornecedores e modelos numa chamada. Não há
        // página nem filtros porque não há paginação -- a busca corre sobre isto em memória.
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
    // Doze e não dez: com os detalhes reduzidos ao que cada tipo declara, a maioria das
    // linhas leva uma linha de texto, e cabem mais duas na mesma altura.
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
