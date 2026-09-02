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
            license: { companies: [], none: 0 },
        },
        deviceTotals: { total: 0, online: 0 },
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
    // Listas, porque os filtros aceitam vários valores. A licença guarda pares "empresa" ou
    // "empresa:número", ou "none". O estado é o único de valor único.
    deviceFilters: {
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    },
    deviceSearchQuery: "",
    selectedImei: null,
    selectedDetail: null,
    // Com cache, e aqui e não no `settingsModal` porque a coluna de detalhe também o lê.
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
        configurationSync: { entries: {} },
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
        apiUsersPagination: null,
    },
    modelPreviewObjectUrl: null,
    loadingCommands: new Set(),
    deviceListPage: 1,
    telemetryPage: 1,
    // O Redis guarda 100 entradas por lista, e as duas do painel dão 200 no pior caso. A 15
    // são catorze páginas, que é o que o paginador consegue mostrar numa linha só.
    telemetryPageSize: 15,
    downlinkPage: 1,
    downlinkPageSize: 15,
};

// Guarda contra gralhas, não uma tranca: um `state.selectedDetial = x` passa a atirar em vez
// de criar uma chave nova que ninguém lê. Os objectos aninhados continuam a mudar.
Object.seal(state);

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

/** O catálogo achatado de tipos×fornecedores×modelos, como veio da resposta. */
export function setDeviceTypeSuppliersModels(models) {
    state.deviceTypeSuppliersModels = models;
}

/**
 * Mexer no catálogo -- gravar ou apagar um modelo -- invalida a cópia em memória: a lista
 * vazia é o sinal de "pede outra vez", que é o que o carregador em `devices/list.js` lê.
 */
export function invalidateDeviceTypeSuppliersModels() {
    state.deviceTypeSuppliersModels = [];
}

/** Um dispositivo acabado de escolher: o histórico começa vazio porque só o stream o traz. */
export function setSelectedDetail(detail) {
    state.selectedDetail = detail;
    state.selectedDetail.recent = null;
}

/**
 * Reler o registo não é reler o histórico: o `GET /api/devices/{imei}` não devolve `recent`,
 * e sem o guardar de lado cada releitura limpava os painéis do detalhe.
 */
export function refreshSelectedDetail(detail) {
    const recent = state.selectedDetail?.recent ?? null;
    state.selectedDetail = detail;
    state.selectedDetail.recent = recent;
}

/** O histórico ao vivo, tal como o stream o entrega. A contraparte do `refreshSelectedDetail`. */
export function setSelectedDetailRecent(recent) {
    state.selectedDetail.recent = recent;
}

/**
 * A pré-visualização da imagem do modelo. Revoga o URL anterior aqui dentro, senão o blob
 * fica em memória até a página fechar. Sem argumento, limpa.
 */
export function setModelPreviewObjectUrl(url = null) {
    if (state.modelPreviewObjectUrl) {
        URL.revokeObjectURL(state.modelPreviewObjectUrl);
    }
    state.modelPreviewObjectUrl = url;
}

export function setDeviceListPage(page) {
    state.deviceListPage = page || 1;
}

/** Mudar o que a listagem mostra volta-a à primeira página: a página 4 era de outra lista. */
export function resetDeviceListPage() {
    state.deviceListPage = 1;
}

/** Uma chave dos filtros a mudar. A volta à primeira página é do par, não do chamador. */
export function changeDeviceFilter(key, value) {
    state.deviceFilters = { ...state.deviceFilters, [key]: value };
    state.deviceListPage = 1;
}

/** Os filtros inteiros: o que ficou guardado da sessão anterior, ou o limpar de todos. */
export function setDeviceFilters(filters) {
    state.deviceFilters = filters;
    state.deviceListPage = 1;
}

/**
 * O rascunho volta ao que está aplicado. Cópia e não a mesma referência: partilhá-la fazia o
 * filtro aplicar-se sem passar pelo botão.
 */
export function resetDetailFiltersDraft() {
    state.detailFiltersDraft = { ...state.detailFilters };
}

/** Campos do rascunho a mudar, sem tocar no que está aplicado. */
export function updateDetailFiltersDraft(changes) {
    state.detailFiltersDraft = { ...state.detailFiltersDraft, ...changes };
}
