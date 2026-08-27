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
    initSettingsCapabilities,
    loadSettingsCapabilitiesSection,
} from "./capabilities.js";
import {initSettingsModels} from "./models/shell.js";
import {loadSettingsModelsSection} from "./models/list.js";

/**
 * A raiz de composição do modal de definições, e o único módulo que conhece as quatro
 * secções -- catálogo, capacidades, empresas e utilizadores da API. Nenhuma secção importa
 * daqui, e é por isso que este ficheiro as pode importar todas sem fechar um ciclo.
 *
 * O que as secções partilham -- o menu, as contagens, a paginação -- vive no `shell.js`.
 */
export function initSettings(context) {
    initSettingsShell(context);
    initSettingsApiUsers(context);
    initSettingsCompanies(context);
    initSettingsCapabilities(context);
    initSettingsModels(context);
}

/**
 * Abre o modal numa secção. Carrega-se aqui e não só pelo `shown.bs.tab`, porque esse
 * evento não dispara quando o separador pedido já é o activo.
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
