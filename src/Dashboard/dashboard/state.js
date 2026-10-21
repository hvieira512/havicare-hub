/** O modal de dispositivo em branco: o `editDevice` substitui o estado inteiro. */
export function blankDeviceModal(overrides = {}) {
    return {
        activeTab: "general",
        activeCategory: "",
        // No telemóvel, se a secção ativa está aberta ou se se está a ver a lista delas.
        configSectionOpen: false,
        // A falso, o formulário tem valores de arranque e não representa registo nenhum.
        detailLoaded: false,
        imei: "",
        deviceType: "watch",
        licenseId: "0",
        simNumber: "",
        deviceId: "",
        linkedGatewayKeys: [],
        selectedGatewayKeys: [],
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
        // O estado do último pedido de cada acção, que não é configuração guardada.
        actionDeliveries: {},
        configUi: {},
        errorMessage: "",
        loading: false,
        // Enquanto o detalhe não chega assume-se ligado, para o aviso de fila não aparecer por não se saber.
        online: true,
        ...overrides,
    };
}

/** O modal de definições em branco; a secção aberta sobrevive, e quem repõe passa-a de volta. */
export function blankSettingsModal(overrides = {}) {
    return {
        radarCredentials: null,
        capabilityDeviceType: "",
        capabilityCatalog: [],
        activeCapabilityCatalogSection: "",
        activeCapabilitySection: "",
        capabilitySupplier: "",
        capabilityQuery: "",
        capabilityTemplateEnabledKeys: [],
        capabilitySuppliersForDeviceType: [],
        capabilityModelTemplateKeys: [],
        capabilityEnabledCapabilities: [],
        capabilityRequestableCapabilities: [],
        currentCapabilitiesModel: null,
        modelFilters: [],
        modelCatalog: [],
        modelsSearchQuery: "",
        modelDetailPristine: null,
        sectionLoaded: {
            models: false,
            modelFilters: false,
            capabilities: false,
            company: false,
            denylist: false,
            apiUsers: false,
        },
        companyPagination: null,
        apiUsersPagination: null,
        ...overrides,
    };
}

export const state = {
    // Gateways registados, para o assistente de adicionar oferecer os elegíveis.
    wizardGateways: [],
    summary: {
        devices: [],
        devicePagination: { limit: 20, page: 1, totalPages: 1, total: 0 },
        deviceFiltersAvailable: {
            deviceType: [],
            licenseId: [],
            company: [],
            supplier: [],
            model: [],
        },
        // As contagens por opção: o que se ganha ao marcar mais uma caixa.
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
        severity: "all",
        q: "",
    },
    detailFiltersDraft: {
        from: "",
        to: "",
        type: "all",
        severity: "all",
        q: "",
    },
    // Listas, porque os filtros aceitam vários valores; a licença guarda "empresa", "empresa:número" ou "none".
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
    // Aqui e não no `settingsModal`, porque a coluna de detalhe também o lê.
    capabilityCatalogByType: {},
    deviceModal: blankDeviceModal(),
    // O `imei` diz de quem é a planta aberta: o modal sobrevive a uma troca de dispositivo.
    radarMap: { imei: "", layout: null },
    modelModalSuppliers: [],
    modelModal: {
        capabilities: [],
        templateSummary: "",
    },
    protocolCatalogs: {},
    // As licenças, uma vez por sessão; o filtro da listagem também as lê.
    licenses: [],
    settingsModal: blankSettingsModal({
        section: "models",
    }),
    modelPreviewObjectUrl: null,
    loadingCommands: new Set(),
    deviceListPage: 1,
    telemetryPage: 1,
    // O Redis guarda 100 entradas por lista, e a 15 são as catorze páginas que o paginador mostra.
    telemetryPageSize: 15,
    // Com o «Carregar mais» do telemóvel, a página seguinte junta-se às anteriores.
    telemetryCumulative: false,
    downlinkPage: 1,
    downlinkPageSize: 15,
    downlinkCumulative: false,
};

// Uma gralha como `state.selectedDetial = x` passa a atirar em vez de criar uma chave nova.
Object.seal(state);

/** Mexer num filtro ou trocar de aparelho leva as duas listas ao princípio, e desfaz o «Carregar mais». */
export function resetActivityPaging() {
    state.telemetryPage = 1;
    state.telemetryCumulative = false;
    state.downlinkPage = 1;
    state.downlinkCumulative = false;
}

const blankDetailFilters = () => ({ from: "", to: "", type: "all", severity: "all", q: "" });

export function selectImei(imei) {
    if (state.selectedImei !== imei) {
        resetActivityPaging();
        // Os filtros eram do aparelho anterior: herdados, escondiam as leituras do novo.
        state.detailFilters = blankDetailFilters();
        state.detailFiltersDraft = blankDetailFilters();
    }
    state.selectedImei = imei;
}

export function clearSelection() {
    state.selectedImei = null;
    state.selectedDetail = null;
    resetActivityPaging();
    state.detailFilters = blankDetailFilters();
    state.detailFiltersDraft = blankDetailFilters();
}

const clampPage = (page, totalPages) =>
    Math.min(Math.max(1, page), Math.max(1, totalPages));

export function setTelemetryPage(page, totalPages) {
    state.telemetryPage = clampPage(page, totalPages);
}

export function setDownlinkPage(page, totalPages) {
    state.downlinkPage = clampPage(page, totalPages);
}

/** Mexer no catálogo invalida a cópia em memória: a lista vazia é o sinal de pedir outra vez. */
export function invalidateDeviceTypeSuppliersModels() {
    state.deviceTypeSuppliersModels = [];
}

/** Um dispositivo acabado de escolher: o histórico começa vazio porque só o stream o traz. */
export function setSelectedDetail(detail) {
    state.selectedDetail = detail;
    state.selectedDetail.recent = null;
}

/** Reler o registo não é reler o histórico: o `GET /api/devices/{imei}` não devolve `recent`. */
export function refreshSelectedDetail(detail) {
    const recent = state.selectedDetail?.recent ?? null;
    state.selectedDetail = detail;
    state.selectedDetail.recent = recent;
}

/** A pré-visualização da imagem do modelo; revoga o URL anterior, senão o blob fica em memória. */
export function setModelPreviewObjectUrl(url = null) {
    if (state.modelPreviewObjectUrl) {
        URL.revokeObjectURL(state.modelPreviewObjectUrl);
    }
    state.modelPreviewObjectUrl = url;
}

export function setDeviceListPage(page) {
    state.deviceListPage = page || 1;
}

/** Mudar o que a listagem mostra volta-a à primeira página: a página 4 é de outra lista. */
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

/** O rascunho volta ao aplicado, por cópia: a mesma referência aplicava o filtro sem o botão. */
export function resetDetailFiltersDraft() {
    state.detailFiltersDraft = { ...state.detailFilters };
}

export function updateDetailFiltersDraft(changes) {
    state.detailFiltersDraft = { ...state.detailFiltersDraft, ...changes };
}

/** Abrir as definições recomeça o modal. A secção sobrevive. */
export function resetSettingsModal() {
    state.settingsModal = blankSettingsModal({ section: state.settingsModal.section });
}
