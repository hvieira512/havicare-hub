import {
    getModelFilters as apiGetModelFilters,
    getModels as apiGetModels,
} from "../../api/index.js";
import {state} from "../../state.js";
import {esc} from "../../format.js";
import {modelImageHtml} from "../../renderers.js";
import {
    deviceTypeLabel,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
} from "../../domain.js";
import {renderModelsFilterButtons} from "./filters.js";
import {resetModelForm} from "./form.js";
import {
    getSettingsModelsRuntime,
    scheduleModelsSearch,
} from "./runtime.js";

async function loadSettingsModelFilters() {
    if (state.settingsModal.sectionLoaded.modelFilters) {
        return state.settingsModal.modelFilters;
    }

    const response = await apiGetModelFilters();
    const filters = response.data || [];
    state.settingsModal.modelFilters = filters;
    state.settingsModal.sectionLoaded.modelFilters = true;
    return filters;
}

function renderModelsSection(models) {
    const {els} = getSettingsModelsRuntime();
    resetModelForm();
    els.modelListBody.innerHTML = (models || [])
        .map(
            (model) => `
        <tr data-action="modelCapabilities" data-id="${model.id}" role="button" tabindex="0">
        <td>${modelImageHtml(model)}</td>
        <td>${esc(model.supplier)}</td>
        <td>${esc(modelCommercialName(model))}</td>
        <td>${esc(modelInternalName(model))}</td>
        <td>${esc(deviceTypeLabel(modelDeviceType(model)))}</td>
        </tr>`,
        )
        .join("");
}

async function loadSettingsModelsSection(page = 1) {
    const {els, callbacks} = getSettingsModelsRuntime();
    if (!state.settingsModal.sectionLoaded.modelFilters) {
        await loadSettingsModelFilters();
    }
    const params = {
        page,
        limit: state.settingsModal.modelsPageSize || 20,
    };
    if (state.settingsModal.modelsDeviceType) {
        params.deviceType = state.settingsModal.modelsDeviceType;
    }
    if (state.settingsModal.modelsSupplier) {
        params.supplier = state.settingsModal.modelsSupplier;
    }
    if (state.settingsModal.modelsSearchQuery) {
        params.q = state.settingsModal.modelsSearchQuery;
    }
    const response = await apiGetModels(params);
    const models = response.data || [];
    state.settingsModal.modelsPage = page;
    state.settingsModal.modelsPagination = response.pagination || null;
    state.settingsModal.sectionLoaded.models = true;
    renderModelsSection(models);
    renderModelsFilterButtons();
    callbacks.renderSettingsPagination(
        state.settingsModal.modelsPagination,
        els.settingsModelsPagination,
        els.settingsModelsPaginationSummary,
        els.settingsModelsPaginationControls,
        "settingsModelsPage",
    );
    if (els.modelsListLimit) {
        els.modelsListLimit.value = String(state.settingsModal.modelsPageSize);
    }
    if (els.modelsListSearch) {
        els.modelsListSearch.value = state.settingsModal.modelsSearchQuery;
    }
    if (els.modelsTabSummary) {
        const total = state.settingsModal.modelsPagination?.total ?? models.length;
        els.modelsTabSummary.textContent = `${total} ${total === 1 ? "modelo" : "modelos"}`;
    }
}

function handleModelsListLimitChange() {
    const {els} = getSettingsModelsRuntime();
    const nextLimit = parseInt(els.modelsListLimit.value || "20", 10) || 20;
    if (state.settingsModal.modelsPageSize === nextLimit) {
        return;
    }
    state.settingsModal.modelsPageSize = nextLimit;
    void loadSettingsModelsSection(1);
}

function handleModelsListSearchInput() {
    const {els} = getSettingsModelsRuntime();
    state.settingsModal.modelsSearchQuery = els.modelsListSearch.value.trim();
    scheduleModelsSearch(() => {
        void loadSettingsModelsSection(1);
    });
}

export {
    handleModelsListLimitChange,
    handleModelsListSearchInput,
    loadSettingsModelFilters,
    loadSettingsModelsSection,
    renderModelsSection,
};
