import { resetSettingsModal, state } from "../core/state.js";
import { els } from "../core/dom.js";
import { initSettingsShell, loadSettingsNavCounts, showSettingsModal } from "./shell.js";
import { initSettingsApiUsers, loadSettingsApiUsersSection } from "./api-users.js";
import { initSettingsCompanies, loadSettingsCompanySection } from "./companies.js";
import { initSettingsDenylist, loadSettingsDenylistSection } from "./denylist.js";
import {
    initSettingsCapabilities,
    loadSettingsCapabilitiesSection,
    resetCapabilityCatalogView,
} from "./capabilities.js";
import { initModelList, loadSettingsModelsSection } from "./models/list.js";
import { initModelForm } from "./models/form.js";
import { initModelDetail } from "./models/detail.js";
import { initCapabilitiesEditor } from "./models/capabilities-editor.js";

/** As secções do modal: o separador de cada uma e o que a carrega. */
const SECTIONS = {
    models: { tab: "settingsModelsTabBtn", load: loadSettingsModelsSection },
    capabilities: { tab: "settingsCapabilitiesTabBtn", load: loadSettingsCapabilitiesSection },
    company: { tab: "settingsCompanyTabBtn", load: loadSettingsCompanySection },
    denylist: { tab: "settingsDenylistTabBtn", load: loadSettingsDenylistSection },
    apiUsers: { tab: "settingsApiUsersTabBtn", load: loadSettingsApiUsersSection },
};

/** A raiz de composição do modal de definições; nenhuma secção importa daqui. */
export function initSettings(context) {
    initSettingsShell(context);
    initSettingsApiUsers();
    initSettingsCompanies();
    initSettingsDenylist();
    initSettingsCapabilities();
    initModelList();
    initModelForm();
    initModelDetail();
    initCapabilitiesEditor();

    els.manageSettingsBtn.addEventListener("click", () => void loadSettingsModal("models"));
    // Cada aba carrega a sua secção da primeira vez que é aberta, e não antes.
    for (const [section, { tab, load }] of Object.entries(SECTIONS)) {
        els[tab].addEventListener("shown.bs.tab", () => {
            state.settingsModal.section = section;
            if (!state.settingsModal.sectionLoaded[section]) void load();
        });
    }
}

/** Abre o modal numa secção; o `shown.bs.tab` não dispara quando o separador já é o activo. */
export async function loadSettingsModal(
    section = state.settingsModal.section,
) {
    resetSettingsModal();
    resetCapabilityCatalogView();

    state.settingsModal.section = section;
    bootstrap.Tab.getOrCreateInstance(els[(SECTIONS[section] ?? SECTIONS.models).tab]).show();
    showSettingsModal();
    void loadSettingsNavCounts();

    void SECTIONS[section]?.load();
}
