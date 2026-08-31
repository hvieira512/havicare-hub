/**
 * A ligação dos ouvintes do modal de definições: modelos, capacidades, utilizadores da API e
 * empresas com as suas licenças.
 *
 * Vale aqui a mesma nota que está no `devices.js`: isto é raiz de composição e não uma
 * funcionalidade, e por isso pode importar de toda a gente. A regra de que uma funcionalidade
 * nunca importa outra continua de pé.
 */
import { setModelPreviewObjectUrl, state } from "../state.js";
import { esc } from "../format.js";
import { loadSettingsModal } from "../settings/index.js";
import { handleSettingsPaginationClick } from "../settings/shell.js";
import {
    handleApiUserListClick,
    handleCapabilityDeviceTypeClick,
    handleCapabilityGroupsChange,
    handleModelDeviceTypeClick,
    handleModelListClick,
    handleModelSupplierClick,
    jumpCapabilitySection,
    scrollCapabilityCatalogSection,
} from "../settings/clicks.js";
import {
    handleCapabilityCatalogSearch,
    handleCapabilitySupplierClick,
    loadSettingsCapabilitiesSection,
} from "../settings/capabilities.js";
import {
    loadSettingsApiUsersSection,
    resetApiUserForm,
    saveApiUser,
    syncApiUserRoleFields,
} from "../settings/api-users.js";
import {
    handleCompanyListClick,
    loadSettingsCompanySection,
    resetCompanyForm,
    resetLicenseForm,
    saveCompany,
    saveLicense,
} from "../settings/companies.js";
import {
    backToModelList,
    handleModelsListSearchInput,
    loadSettingsModelsSection,
} from "../settings/models/list.js";
import {
    openNewModelForm,
    resetModelForm,
    saveModel,
    updateModelProtocolAndPreview,
} from "../settings/models/form.js";
import {
    deleteCurrentModel,
    resetModelDetailFields,
    saveCapabilities,
    saveModelDetail,
    syncModelDetailDirty,
} from "../settings/models/detail.js";

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
}

function bindModels() {
    els.saveModelBtn.addEventListener("click", saveModel);
    els.resetModelBtn.addEventListener("click", () => resetModelForm());
    els.modelForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveModel();
    });
    els.modelInternalModel.addEventListener("input", () =>
        updateModelProtocolAndPreview(),
    );
    els.modelCommercialName.addEventListener("input", () =>
        updateModelProtocolAndPreview(),
    );
    els.modelImage.addEventListener("change", handleModelImageChange);
    els.modelSupplierButtons.addEventListener(
        "click",
        handleModelSupplierClick,
    );
    els.modelDeviceTypeButtons.addEventListener(
        "click",
        handleModelDeviceTypeClick,
    );
    els.modelsBreadcrumbModels.addEventListener("click", backToModelList);
    els.modelsNewModelBtn.addEventListener("click", openNewModelForm);
    els.modelDetailSaveBtn.addEventListener("click", saveModelDetail);
    els.modelDetailResetBtn.addEventListener("click", resetModelDetailFields);
    els.modelDetailFields.addEventListener("input", syncModelDetailDirty);
    els.modelDetailFields.addEventListener("change", syncModelDetailDirty);
    els.modelDetailDeleteBtn.addEventListener("click", () => {
        void deleteCurrentModel();
    });
    els.modelsCarousel.addEventListener("click", (event) => {
        const button = event.target.closest("[data-action=\"backToModelList\"]");
        if (button) backToModelList();
    });
    // A árvore do catálogo abre e fecha pelo `collapse` do Bootstrap: aqui escuta-se só a
    // folha. O `keydown` está ao lado do `click` porque a folha não é um `<button>`.
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
    els.saveApiUserBtn.addEventListener("click", () => {
        void saveApiUser();
    });
    els.resetApiUserBtn.addEventListener("click", resetApiUserForm);
    els.apiUserForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveApiUser();
    });
    els.apiUserRole.addEventListener("change", syncApiUserRoleFields);
    els.apiUserListBody.addEventListener("click", handleApiUserListClick);
    els.settingsApiUsersPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(
            event,
            "apiUsersPagination",
            loadSettingsApiUsersSection,
        ),
    );
}

function bindCompanies() {
    els.saveCompanyBtn.addEventListener("click", () => {
        void saveCompany();
    });
    els.resetCompanyBtn.addEventListener("click", resetCompanyForm);
    els.companyForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveCompany();
    });
    els.saveLicenseBtn.addEventListener("click", () => {
        void saveLicense();
    });
    els.resetLicenseBtn.addEventListener("click", resetLicenseForm);
    els.licenseForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveLicense();
    });
    els.companyListBody.addEventListener("click", handleCompanyListClick);
    els.settingsCompanyPagination?.addEventListener("click", (event) =>
        handleSettingsPaginationClick(event, "companyPagination", (page) =>
            loadSettingsCompanySection(page),
        ),
    );
}

function handleModelImageChange() {
    const file = els.modelImage.files[0];
    if (file) {
        setModelPreviewObjectUrl(URL.createObjectURL(file));
        const label =
            els.modelCommercialName.value.trim() ||
            els.modelInternalModel.value.trim() ||
            "Modelo";
        els.modelPreviewContent.innerHTML = `<img src="${esc(state.modelPreviewObjectUrl)}" class="object-fit-contain w-100 h-100" alt="${esc(label)}" style="max-height:180px;">`;
    } else {
        // Limpar a escolha volta à imagem gravada, e o `updateModelProtocolAndPreview` só a
        // desenha quando não há object URL por cima.
        setModelPreviewObjectUrl();
        updateModelProtocolAndPreview();
    }
}
