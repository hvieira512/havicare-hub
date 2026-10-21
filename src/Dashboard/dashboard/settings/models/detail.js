import {
    getDevices as apiGetDevices,
    deleteModel as apiDeleteModel,
    getModel as apiGetModel,
    getSuppliers as apiGetSuppliers,
    saveModel as apiSaveModel,
} from "../../api/index.js";
import { ensureModelTemplate } from "../../capability-catalog.js";
import {
    invalidateDeviceTypeSuppliersModels,
    setModelPreviewObjectUrl,
    state,
} from "../../state.js";
import { html } from "../../html.js";
import { apiError, confirmDestructive, toast } from "../../dialogs.js";
import { allPages } from "../../api/http.js";
import { clearInvalid, markInvalid } from "../../validation.js";
import { modelPreviewHtml } from "../../components/model-image.js";
import {
    deviceTypeOptions,
    flattenedCapabilityKeys,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
} from "../../domain.js";
import { loadCapabilityCatalog } from "../capabilities.js";
import { getSettingsModelsRuntime, showModelsPane } from "./shell.js";
import { backToModelList } from "./list.js";
import { renderCapabilitiesSection } from "./capabilities-editor.js";
import { countLabel } from "../../i18n.js";

/** A ficha de um modelo: a identidade em cima e o editor de capacidades em baixo, cada um com o seu Guardar. */

async function openModelDetail(modelId) {
    const { els } = getSettingsModelsRuntime();
    const response = await apiGetModel(modelId);
    const model = response.data || response;
    await loadCapabilityCatalog(
        model.device_type || model.deviceType || "watch",
    );

    state.settingsModal.currentCapabilitiesModel = model;
    state.settingsModal.capabilityRequestableCapabilities = Array.isArray(
        model.requestableCapabilities,
    )
        ? model.requestableCapabilities.map(String)
        : [];

    const supplierId = Number(model.supplier_id || model.supplierId || 0);
    const deviceType = model.device_type || model.deviceType || "watch";
    state.settingsModal.capabilityModelTemplateKeys = [];
    if (supplierId) {
        const tmpl = await ensureModelTemplate(supplierId, deviceType);
        if (!tmpl.error && Array.isArray(tmpl.enabledCapabilities)) {
            state.settingsModal.capabilityModelTemplateKeys =
                tmpl.enabledCapabilities.map(String);
        }
    }
    // O template do fornecedor manda: o que ele não oferece não se mostra ligado.
    const templateSet = new Set(
        state.settingsModal.capabilityModelTemplateKeys || [],
    );
    state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(
        model.capabilities || {},
    ).filter((key) => (templateSet.size === 0 ? true : templateSet.has(key)));
    const enabledSet = new Set(state.settingsModal.capabilityEnabledCapabilities);
    state.settingsModal.capabilityRequestableCapabilities =
        state.settingsModal.capabilityRequestableCapabilities.filter(
            (key) => enabledSet.has(key),
        );

    els.settingsCloseBtn?.classList.remove("d-none");
    els.modelDetailActionBar?.classList.replace("d-none", "d-flex");
    els.modelsBreadcrumb.classList.remove("d-none");
    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbCurrent.textContent = modelCommercialName(model);
    els.modelsBreadcrumbCurrent.classList.remove("d-none");
    els.modelsBreadcrumbCurrent.classList.add("active");

    await ensureModelDetailSuppliers();
    renderModelDetailInfo(model);
    renderCapabilitiesSection();

    showModelsPane(2);
}

/** Um clique numa folha abre a ficha; a linha é um `div` com `role="button"`, e o teclado é tratado à mão. */
function handleModelListClick(event) {
    const row = event.target.closest("[data-action=\"modelCapabilities\"]");
    if (!row) return;
    if (event.type === "keydown") {
        if (event.key !== "Enter" && event.key !== " ") return;
        // O espaço numa linha accionável rola a página se ninguém o travar.
        event.preventDefault();
    }
    void openModelDetail(parseInt(row.dataset.id));
}

function renderModelDetailInfo(model) {
    const { els } = getSettingsModelsRuntime();
    const label = modelCommercialName(model);
    if (els.modelDetailImageInput) els.modelDetailImageInput.value = "";
    els.modelDetailImage.innerHTML = modelPreviewHtml(model, label);
    els.modelDetailName.textContent = label;

    els.modelDetailCommercialName.value = label;
    els.modelDetailInternalModel.value = modelInternalName(model);
    renderModelDetailSelect(
        els.modelDetailSupplierSelect,
        modelDetailSuppliers().map((supplier) => ({
            value: String(supplier.name),
            label: String(supplier.name),
        })),
        String(model.supplier || ""),
    );
    renderModelDetailSelect(
        els.modelDetailDeviceType,
        deviceTypeOptions.map((option) => ({
            value: option.value,
            label: option.label,
        })),
        modelDeviceType(model),
    );

    // A fotografia do estado limpo, para se saber se algo mudou sem comparar campo a campo.
    state.settingsModal.modelDetailPristine = readModelDetailFields();
    syncModelDetailDirty();
    void renderModelDetailDeleteHint(model);
}

/** Os fornecedores com o seu id, para o `supplier_id`; a ficha alcança-se sem passar pelo separador deles. */
function modelDetailSuppliers() {
    return state.modelModalSuppliers || [];
}

async function ensureModelDetailSuppliers() {
    if ((state.modelModalSuppliers || []).length > 0) return;
    const response = await allPages(apiGetSuppliers);
    if (response.error) toast("error", apiError(response));
    state.modelModalSuppliers = response.data || [];
}

function renderModelDetailSelect(select, options, selected) {
    if (!select) return;
    select.innerHTML = options
        .map(
            (option) =>
                html`<option value="${option.value}"${option.value === selected ? " selected" : ""}>${option.label}</option>`,
        )
        .join("");
    select.value = selected;
}

function readModelDetailFields() {
    const { els } = getSettingsModelsRuntime();
    return {
        commercialName: String(els.modelDetailCommercialName?.value || "").trim(),
        internalModel: String(els.modelDetailInternalModel?.value || "").trim(),
        supplier: String(els.modelDetailSupplierSelect?.value || ""),
        deviceType: String(els.modelDetailDeviceType?.value || ""),
        image: String(els.modelDetailImageInput?.files?.[0]?.name || ""),
    };
}

function handleModelDetailImageChange() {
    const { els } = getSettingsModelsRuntime();
    const model = state.settingsModal.currentCapabilitiesModel;
    const file = els.modelDetailImageInput?.files?.[0];
    setModelPreviewObjectUrl(file ? URL.createObjectURL(file) : null);
    els.modelDetailImage.innerHTML = modelPreviewHtml(
        file ? { ...model, image: state.modelPreviewObjectUrl } : model,
        modelCommercialName(model),
    );
    syncModelDetailDirty();
}

function syncModelDetailDirty() {
    const { els } = getSettingsModelsRuntime();
    const pristine = state.settingsModal.modelDetailPristine;
    if (!pristine || !els.modelDetailSaveBtn) return;
    const current = readModelDetailFields();
    const dirty = Object.keys(pristine).some((key) => pristine[key] !== current[key]);

    els.modelDetailSaveBtn.classList.toggle("d-none", !dirty);
    els.modelDetailResetBtn.classList.toggle("d-none", !dirty);
    els.modelDetailDirtyState.classList.toggle("d-none", dirty);
}

function resetModelDetailFields() {
    const { els } = getSettingsModelsRuntime();
    const pristine = state.settingsModal.modelDetailPristine;
    if (!pristine) return;
    clearInvalid(els.modelDetailFields);
    els.modelDetailCommercialName.value = pristine.commercialName;
    els.modelDetailInternalModel.value = pristine.internalModel;
    els.modelDetailSupplierSelect.value = pristine.supplier;
    els.modelDetailDeviceType.value = pristine.deviceType;
    if (els.modelDetailImageInput) els.modelDetailImageInput.value = "";
    handleModelDetailImageChange();
}

async function saveModelDetail() {
    const { els } = getSettingsModelsRuntime();
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) return;
    const fields = readModelDetailFields();
    clearInvalid(els.modelDetailFields);
    if (fields.commercialName === "") {
        markInvalid(els.modelDetailCommercialName, "O nome comercial é obrigatório");
    }
    if (fields.internalModel === "") {
        markInvalid(els.modelDetailInternalModel, "O modelo interno é obrigatório");
    }
    if (els.modelDetailFields?.querySelector(".is-invalid")) return;

    const supplier = modelDetailSuppliers().find(
        (item) => String(item.name) === fields.supplier,
    );
    const body = new FormData();
    body.append("supplier_id", String(supplier?.id ?? model.supplier_id));
    body.append("internalModel", fields.internalModel);
    body.append("commercialName", fields.commercialName);
    body.append("deviceType", fields.deviceType);
    const image = els.modelDetailImageInput?.files?.[0];
    if (image) {
        body.append("image", image);
    }

    const result = await apiSaveModel(model.id, body);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }

    // O PUT responde `{status: "ok"}`: o modelo actualizado é o que se acabou de enviar.
    const saved = {
        ...model,
        image: image ? state.modelPreviewObjectUrl : model.image,
        supplier_id: supplier?.id ?? model.supplier_id,
        supplier: fields.supplier,
        internalModel: fields.internalModel,
        internal_model: fields.internalModel,
        commercialName: fields.commercialName,
        commercial_name: fields.commercialName,
        deviceType: fields.deviceType,
        device_type: fields.deviceType,
    };
    state.settingsModal.currentCapabilitiesModel = saved;
    renderModelDetailInfo(saved);
    // O nome, o fornecedor e o tipo são o que a árvore mostra: as listas em memória deixam de valer.
    state.settingsModal.sectionLoaded.models = false;
    invalidateDeviceTypeSuppliersModels();

    // Trocar o fornecedor ou o tipo faz o servidor substituir as capacidades pelo template novo.
    const supplierChanged = Number(supplier?.id ?? 0) !== Number(model.supplier_id || 0);
    const typeChanged = fields.deviceType !== (model.device_type || model.deviceType);
    if (supplierChanged || typeChanged) {
        await openModelDetail(model.id);
    }
}

/** Quantos dispositivos usam o modelo, ao lado do botão de apagar. */
async function renderModelDetailDeleteHint(model) {
    const { els } = getSettingsModelsRuntime();
    if (!els.modelDetailDeleteHint) return;
    const internal = modelInternalName(model);
    const result = await apiGetDevices({ model: internal, limit: 1 });
    const total = result?.error ? null : (result?.pagination?.total ?? null);
    els.modelDetailDeleteHint.textContent = total === null
        ? "Os dispositivos que o usam ficam sem template de capacidades."
        : total === 0
            ? "Nenhum dispositivo usa este modelo."
            : `${countLabel(total, "dispositivo usa", "dispositivos usam")} o ${internal}.` +
                ` Apagar o modelo deixa-${total === 1 ? "o" : "os"} sem template de capacidades.`;
}

async function deleteCurrentModel() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) return;
    const { els } = getSettingsModelsRuntime();
    const { isConfirmed } = await confirmDestructive(
        `Apagar o modelo ${modelCommercialName(model)}?`,
        els.modelDetailDeleteHint?.textContent || "",
    );
    if (!isConfirmed) return;

    const result = await apiDeleteModel(model.id);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }
    removeModelFromCatalog(model.id);
    invalidateDeviceTypeSuppliersModels();
    backToModelList();
}

function removeModelFromCatalog(modelId) {
    for (const group of state.settingsModal.modelCatalog || []) {
        for (const supplier of group?.suppliers || []) {
            supplier.models = (supplier.models || []).filter(
                (entry) => String(entry.id) !== String(modelId),
            );
        }
    }
}

export {
    deleteCurrentModel,
    handleModelDetailImageChange,
    handleModelListClick,
    renderModelDetailInfo,
    resetModelDetailFields,
    saveModelDetail,
    syncModelDetailDirty,
};
