import { resetSettingsModal, state } from "../state.js";
import {
    activateSettingsSection,
    initSettingsShell,
    loadSettingsNavCounts,
    showSettingsModal,
} from "./shell.js";
import { initSettingsApiUsers, loadSettingsApiUsersSection } from "./api-users.js";
import { initSettingsCompanies, loadSettingsCompanySection } from "./companies.js";
import { initSettingsDenylist, loadSettingsDenylistSection } from "./denylist.js";
import {
    initSettingsCapabilities,
    loadSettingsCapabilitiesSection,
} from "./capabilities.js";
import { initSettingsModels } from "./models/shell.js";
import { loadSettingsModelsSection } from "./models/list.js";

/** A raiz de composição do modal de definições; nenhuma secção importa daqui. */
export function initSettings(context) {
    initSettingsShell(context);
    initSettingsApiUsers(context);
    initSettingsCompanies(context);
    initSettingsDenylist(context);
    initSettingsCapabilities(context);
    initSettingsModels(context);
}

/** Abre o modal numa secção; o `shown.bs.tab` não dispara quando o separador já é o activo. */
export async function loadSettingsModal(
    section = state.settingsModal.section,
) {
    resetSettingsModal();
    state.modelModal.enabledCapabilities = [];
    state.modelModal.templateSummary = "";

    activateSettingsSection(section);
    showSettingsModal();
    void loadSettingsNavCounts();

    const load = {
        models: loadSettingsModelsSection,
        capabilities: loadSettingsCapabilitiesSection,
        company: loadSettingsCompanySection,
        denylist: loadSettingsDenylistSection,
        apiUsers: loadSettingsApiUsersSection,
    }[section];
    if (load) void load();
}
