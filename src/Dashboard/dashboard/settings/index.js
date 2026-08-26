import {state} from "../state.js";
import {
    activateSettingsSection,
    initSettingsShell,
    loadSettingsNavCounts,
    showSettingsModal,
} from "./shell.js";
import {initSettingsApiUsers, loadSettingsApiUsersSection} from "./api-users.js";
import {initSettingsCompanies, loadSettingsCompanySection} from "./companies.js";
import {
    ensureCapabilityCatalog,
    initSettingsCapabilities,
    loadSettingsCapabilitiesSection,
} from "./capabilities.js";
import {initSettingsModels} from "./models/shell.js";
import {loadSettingsModelsSection} from "./models/list.js";
import {refreshNewModelCapabilityTemplate} from "./models/form.js";

/**
 * A raiz de composicao do modal de definicoes.
 *
 * E o unico modulo que conhece as quatro seccoes -- catalogo, capacidades, empresas e
 * utilizadores da API --, tal como o `app.js` e o unico que conhece as funcionalidades.
 * Nenhuma seccao importa daqui, e e por isso que este ficheiro as pode importar todas sem
 * fechar um ciclo.
 *
 * O que as seccoes partilham -- o menu, as contagens, a paginacao -- vive no `shell.js`.
 */
export function initSettings(context) {
    initSettingsShell(context);
    initSettingsApiUsers(context);
    initSettingsCompanies(context);
    initSettingsCapabilities(context);
    initSettingsModels({
        ...context,
        callbacks: {
            ensureCapabilityCatalog,
            refreshNewModelCapabilityTemplate,
        },
    });
}

/**
 * Abre o modal numa seccao, com tudo por carregar.
 *
 * A seccao carrega-se aqui e nao so pelo `shown.bs.tab`: esse evento nao dispara quando o
 * separador pedido ja e o activo, e nesse caso ficava um ecra vazio.
 */
export async function loadSettingsModal(
    section = state.settingsModal.section || "models",
) {
    state.settingsModal.sectionLoaded = {
        models: false,
        modelFilters: false,
        capabilities: false,
        company: false,
        apiUsers: false,
    };
    state.settingsModal.modelCatalog = [];
    state.settingsModal.companyPagination = null;
    state.settingsModal.licensesPagination = null;
    state.settingsModal.apiUsersPagination = null;
    state.settingsModal.modelFilters = [];
    state.settingsModal.capabilityCatalog = [];
    state.settingsModal.capabilitySupplier = "";
    state.settingsModal.capabilityModelId = null;
    state.settingsModal.capabilityModelTemplateKeys = [];
    state.settingsModal.capabilityEnabledCapabilities = [];
    state.settingsModal.capabilityRequestableCapabilities = [];
    state.settingsModal.currentCapabilitiesModel = null;
    state.modelModal.enabledCapabilities = [];
    state.modelModal.templateSummary = "";
    state.modelModal.templateSupplier = "";
    state.modelModal.templateDeviceType = "watch";

    activateSettingsSection(section);
    showSettingsModal();
    void loadSettingsNavCounts();

    const load = {
        models: loadSettingsModelsSection,
        capabilities: loadSettingsCapabilitiesSection,
        company: loadSettingsCompanySection,
        apiUsers: loadSettingsApiUsersSection,
    }[section];
    if (load) void load();
}
