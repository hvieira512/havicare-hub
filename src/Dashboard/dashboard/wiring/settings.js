/** Os ouvintes do modal de definições; raiz de composição, pode importar de toda a gente. */
import { state } from "../state.js";
import { loadSettingsModal } from "../settings/index.js";
import { handleSettingsPaginationClick } from "../settings/shell.js";
import {
    handleCapabilityCatalogSearch,
    handleCapabilityDeviceTypeClick,
    handleCapabilitySupplierClick,
    loadSettingsCapabilitiesSection,
    scrollCapabilityCatalogSection,
} from "../settings/capabilities.js";
import {
    handleApiUserListChange,
    handleApiUserListClick,
    handleApiUserListInput,
    loadSettingsApiUsersSection,
    newApiUser,
} from "../settings/api-users.js";
import {
    handleCompanyListClick,
    loadSettingsCompanySection,
    newCompany,
} from "../settings/companies.js";
import {
    handleDenylistListClick,
    loadSettingsDenylistSection,
} from "../settings/denylist.js";
import {
    backToModelList,
    handleModelsListSearchInput,
    loadSettingsModelsSection,
} from "../settings/models/list.js";
import {
    handleModelWizardChange,
    handleModelWizardClick,
    handleModelWizardInput,
    handleModelWizardSave,
    handleModelWizardTrailClick,
    modelWizardBack,
    openNewModelForm,
} from "../settings/models/form.js";
import {
    deleteCurrentModel,
    handleModelDetailImageChange,
    handleModelListClick,
    resetModelDetailFields,
    saveModelDetail,
    syncModelDetailDirty,
} from "../settings/models/detail.js";
import {
    handleCapabilityGroupsChange,
    jumpCapabilitySection,
    saveCapabilities,
} from "../settings/models/capabilities-editor.js";

let els;

export function bindSettingsEvents(context) {
    els = context.els;

    els.manageSettingsBtn.addEventListener("click", () => {
        void loadSettingsModal("models");
    });

    bindTabs();
    bindModels();
    bindCapabilities();
    bindApiUsers();
    bindCompanies();
    bindDenylist();
}

/** Cada aba carrega a sua secção da primeira vez que é aberta, e não antes. */
function bindTabs() {
    els.settingsModelsTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "models";
        if (!state.settingsModal.sectionLoaded.models) {
            void loadSettingsModelsSection();
        }
    });
    els.settingsCapabilitiesTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "capabilities";
        if (!state.settingsModal.sectionLoaded.capabilities) {
            void loadSettingsCapabilitiesSection();
        }
    });
    els.settingsApiUsersTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "apiUsers";
        if (!state.settingsModal.sectionLoaded.apiUsers) {
            void loadSettingsApiUsersSection();
        }
    });
    els.settingsCompanyTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "company";
        if (!state.settingsModal.sectionLoaded.company) {
            void loadSettingsCompanySection();
        }
    });
    els.settingsDenylistTabBtn.addEventListener("shown.bs.tab", () => {
        state.settingsModal.section = "denylist";
        if (!state.settingsModal.sectionLoaded.denylist) {
            void loadSettingsDenylistSection();
        }
    });
}

function bindModels() {
    els.modelWizardAsk.addEventListener("click", handleModelWizardClick);
    els.modelWizardAsk.addEventListener("input", handleModelWizardInput);
    els.modelWizardAsk.addEventListener("change", handleModelWizardChange);
    els.modelWizardTrail.addEventListener("click", handleModelWizardTrailClick);
    els.modelWizardBackBtn.addEventListener("click", modelWizardBack);
    els.modelWizardSaveBtn.addEventListener("click", handleModelWizardSave);
    els.modelsBreadcrumbModels.addEventListener("click", backToModelList);
    els.modelsNewModelBtn.addEventListener("click", openNewModelForm);
    els.modelDetailSaveBtn.addEventListener("click", saveModelDetail);
    els.modelDetailResetBtn.addEventListener("click", resetModelDetailFields);
    els.modelDetailFields.addEventListener("input", syncModelDetailDirty);
    els.modelDetailFields.addEventListener("change", syncModelDetailDirty);
    els.modelDetailImageInput.addEventListener(
        "change",
        handleModelDetailImageChange,
    );
    els.modelDetailDeleteBtn.addEventListener("click", () => {
        void deleteCurrentModel();
    });
    els.modelsCarousel.addEventListener("click", (event) => {
        const button = event.target.closest("[data-action=\"backToModelList\"]");
        if (button) backToModelList();
    });
    // O `keydown` ao lado do `click` porque a folha do catálogo não é um `<button>`.
    els.modelCatalog.addEventListener("click", handleModelListClick);
    els.modelCatalog.addEventListener("keydown", handleModelListClick);
    els.modelsListSearch.addEventListener("input", handleModelsListSearchInput);
}

function bindCapabilities() {
    els.saveCapabilitiesBtn.addEventListener("click", () => {
        void saveCapabilities();
    });
    els.capabilityGroups.addEventListener(
        "change",
        handleCapabilityGroupsChange,
    );
    els.capabilitySectionNav.addEventListener("click", jumpCapabilitySection);
    els.capabilityCatalogSectionNav?.addEventListener(
        "click",
        scrollCapabilityCatalogSection,
    );
    els.capabilityDeviceTypeButtons.addEventListener(
        "click",
        handleCapabilityDeviceTypeClick,
    );
    els.capabilitySupplierButtons.addEventListener(
        "click",
        handleCapabilitySupplierClick,
    );
    els.capabilityCatalogSearch?.addEventListener(
        "input",
        handleCapabilityCatalogSearch,
    );
}

function bindApiUsers() {
    els.newApiUserBtn?.addEventListener("click", newApiUser);
    // No invólucro e não na lista: apanha o formulário de criar, os menus das linhas e os filtros.
    els.apiUserList.addEventListener("click", handleApiUserListClick);
    els.apiUserList.addEventListener("change", handleApiUserListChange);
    els.apiUserList.addEventListener("input", handleApiUserListInput);
    els.settingsApiUsersPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(
            event,
            "apiUsersPagination",
            loadSettingsApiUsersSection,
        ),
    );
}

function bindCompanies() {
    els.newCompanyBtn?.addEventListener("click", newCompany);
    els.companyListBody.addEventListener("click", handleCompanyListClick);
    els.settingsCompanyPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(event, "companyPagination", (page) =>
            loadSettingsCompanySection(page),
        ),
    );
}

function bindDenylist() {
    els.denylistListBody.addEventListener("click", handleDenylistListClick);
}
