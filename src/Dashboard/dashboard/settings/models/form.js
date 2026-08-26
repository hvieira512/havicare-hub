import {
    getModelTemplate as apiGetModelTemplate,
    saveModel as apiSaveModel,
} from "../../api/index.js";
import {state} from "../../state.js";
import {esc} from "../../format.js";
import {renderButtonGroup, renderDeviceTypeTiles} from "../../widgets.js";
import {
    deviceTypeLabel,
    deviceTypeOptions,
    normalizeDeviceType,
} from "../../domain.js";
import {getSettingsModelsRuntime} from "./shell.js";
import {
    backToModelList,
    loadSettingsModelFilters,
    showNewModelSlide,
} from "./list.js";

/**
 * O formulario de um modelo novo: o segundo slide do carrossel do catalogo.
 *
 * Um modelo nasce com as capacidades que o fornecedor declara para aquele tipo de
 * dispositivo -- o template --, e nao em branco: e por isso que escolher fornecedor ou
 * tipo vai buscar o template outra vez. Alterar um modelo que ja existe faz-se na ficha
 * dele, e nao aqui.
 */

function modelSupplierOptions(deviceType = "watch") {
    return modelSuppliersForDeviceType(deviceType).map((supplier) => ({
        value: String(supplier.id),
        label: supplier.name,
    }));
}

/** Os fornecedores que servem este tipo de dispositivo. */
function modelSuppliersForDeviceType(deviceType = "watch") {
    const group = (state.settingsModal.modelFilters || []).find(
        (entry) =>
            normalizeDeviceType(entry?.deviceType || entry?.device_type || "watch") ===
            normalizeDeviceType(deviceType),
    );
    return group?.suppliers || [];
}

function modelSupplierEntry(deviceType, supplierId) {
    return modelSuppliersForDeviceType(deviceType).find(
        (supplier) => String(supplier.id) === String(supplierId),
    );
}

function renderModelSupplierButtons(selectedSupplierId) {
    const {els} = getSettingsModelsRuntime();
    const deviceType = normalizeDeviceType(
        els.modelForm?.dataset.deviceType || "watch",
    );
    renderButtonGroup(
        els.modelSupplierButtons,
        modelSupplierOptions(deviceType),
        String(selectedSupplierId),
        "selectModelSupplier",
    );
}

function renderModelDeviceTypeButtons(selectedDeviceType) {
    const {els} = getSettingsModelsRuntime();
    renderDeviceTypeTiles(els.modelDeviceTypeButtons, deviceTypeOptions, {
        selected: selectedDeviceType,
        action: "selectModelDeviceType",
    });
}

function revokeModelPreviewUrl() {
    if (state.modelPreviewObjectUrl) {
        URL.revokeObjectURL(state.modelPreviewObjectUrl);
        state.modelPreviewObjectUrl = null;
    }
}

function updateModelProtocolAndPreview() {
    const {els} = getSettingsModelsRuntime();
    const supplier = els.modelForm.dataset.supplier || "";
    const internalModel = els.modelInternalModel.value.trim();
    const commercialName = els.modelCommercialName.value.trim();
    const image = els.modelForm.dataset.image || "";
    const label = commercialName || internalModel || supplier || "Novo modelo";

    if (!state.modelPreviewObjectUrl) {
        els.modelPreviewContent.innerHTML = image
            ? `<img src="${esc(image)}" class="object-fit-contain w-100 h-100" alt="${esc(label)}" style="max-height:180px;">`
            : `<i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div>`;
    }
}

function resetModelForm(selectedSupplierId = "") {
    const {els} = getSettingsModelsRuntime();
    revokeModelPreviewUrl();
    els.modelForm.reset();
    delete els.modelForm.dataset.modelId;
    delete els.modelForm.dataset.image;
    els.modelForm.dataset.deviceType = "watch";
    state.modelModal.enabledCapabilities = [];
    els.saveModelBtn.innerHTML =
        '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';
    els.modelImage.value = "";
    if (els.modelTemplateSummary) {
        els.modelTemplateSummary.textContent =
            "A carregar template de capacidades do fornecedor.";
    }

    renderModelDeviceTypeButtons("watch");

    const deviceType = "watch";
    const suppliers = modelSupplierOptions(deviceType);
    const supplierId = suppliers.some(
        (supplier) => supplier.value === String(selectedSupplierId),
    )
        ? String(selectedSupplierId)
        : suppliers[0]?.value || "";
    const supplier = modelSupplierEntry(deviceType, supplierId);
    els.modelForm.dataset.supplierId = supplierId;
    els.modelForm.dataset.supplier = supplier?.name || "";

    renderModelSupplierButtons(supplierId);
    updateModelProtocolAndPreview();
}

function editModel(
    id,
    supplierId,
    supplier,
    internalModel,
    commercialName,
    deviceType,
    image,
) {
    const {els} = getSettingsModelsRuntime();
    revokeModelPreviewUrl();
    els.modelForm.dataset.modelId = String(id);
    els.modelForm.dataset.supplierId = String(supplierId);
    els.modelForm.dataset.supplier = supplier;
    els.modelForm.dataset.image = image || "";
    els.modelForm.dataset.deviceType = normalizeDeviceType(deviceType);
    els.modelInternalModel.value = internalModel;
    els.modelCommercialName.value = commercialName;
    els.modelImage.value = "";
    els.saveModelBtn.innerHTML =
        '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';
    if (els.modelTemplateSummary) {
        els.modelTemplateSummary.textContent =
            "A edição deste formulário não altera as capacidades do modelo.";
    }

    renderModelDeviceTypeButtons(els.modelForm.dataset.deviceType);
    renderModelSupplierButtons(supplierId);
    updateModelProtocolAndPreview();
}

function selectModelSupplier(supplierId) {
    const {els, callbacks} = getSettingsModelsRuntime();
    revokeModelPreviewUrl();
    els.modelImage.value = "";
    const deviceType = normalizeDeviceType(
        els.modelForm.dataset.deviceType || "watch",
    );
    const supplier = modelSupplierEntry(deviceType, supplierId);
    els.modelForm.dataset.supplierId = String(supplierId);
    els.modelForm.dataset.supplier = supplier?.name || "";
    state.modelModal.enabledCapabilities = [];
    delete els.modelForm.dataset.image;
    renderModelSupplierButtons(supplierId);
    updateModelProtocolAndPreview();
    void callbacks.refreshNewModelCapabilityTemplate?.();
}

function selectModelDeviceType(deviceType) {
    const {els, callbacks} = getSettingsModelsRuntime();
    els.modelForm.dataset.deviceType = normalizeDeviceType(deviceType);
    state.modelModal.enabledCapabilities = [];
    renderModelDeviceTypeButtons(els.modelForm.dataset.deviceType);
    void callbacks.refreshNewModelCapabilityTemplate?.();
}

/** Abre o slide do formulario, com o template do fornecedor já carregado. */
async function openNewModelForm() {
    if (!state.settingsModal.sectionLoaded.modelFilters) {
        await loadSettingsModelFilters();
    }
    resetModelForm();
    await refreshNewModelCapabilityTemplate();
    showNewModelSlide();
}

/**
 * As capacidades predefinidas do fornecedor para este tipo de dispositivo.
 *
 * Só corre para um modelo novo: num que já existe as capacidades são as dele, e vivem na
 * ficha.
 */
async function refreshNewModelCapabilityTemplate() {
    const {els} = getSettingsModelsRuntime();
    if (!els?.modelForm || els.modelForm.dataset.modelId) {
        return;
    }

    const supplierId = parseInt(els.modelForm.dataset.supplierId || "0", 10);
    const deviceType = normalizeDeviceType(
        els.modelForm.dataset.deviceType || "watch",
    );

    state.modelModal.enabledCapabilities = [];
    state.modelModal.templateDeviceType = deviceType;
    state.modelModal.templateSupplier = els.modelForm.dataset.supplier || "";

    if (!supplierId) {
        state.modelModal.templateSummary =
            "Selecione um fornecedor para carregar o template de capacidades.";
        if (els.modelTemplateSummary) {
            els.modelTemplateSummary.textContent =
                state.modelModal.templateSummary;
        }
        return;
    }

    if (els.modelTemplateSummary) {
        els.modelTemplateSummary.textContent =
            "A carregar template de capacidades do fornecedor.";
    }

    const response = await apiGetModelTemplate({
        supplierId,
        deviceType,
    });
    if (response.error) {
        state.modelModal.templateSummary =
            response.error.message ||
            response.error.code ||
            "Erro ao carregar template.";
        if (els.modelTemplateSummary) {
            els.modelTemplateSummary.textContent =
                state.modelModal.templateSummary;
        }
        return;
    }

    const enabledCapabilities = Array.isArray(response.enabledCapabilities)
        ? response.enabledCapabilities.map(String)
        : [];
    state.modelModal.enabledCapabilities = enabledCapabilities;
    state.modelModal.templateSupplier = String(response.supplier || "");
    state.modelModal.templateDeviceType = String(
        response.deviceType || deviceType,
    );
    state.modelModal.templateSummary = `${enabledCapabilities.length} capacidades predefinidas para ${state.modelModal.templateSupplier} (${deviceTypeLabel(deviceType)}).`;
    if (els.modelTemplateSummary) {
        els.modelTemplateSummary.textContent = state.modelModal.templateSummary;
    }
}

async function saveModel() {
    const {els} = getSettingsModelsRuntime();
    const supplierId = parseInt(els.modelForm.dataset.supplierId || "0");
    const internalModel = els.modelInternalModel.value.trim();
    const commercialName = els.modelCommercialName.value.trim();
    const deviceType = normalizeDeviceType(
        els.modelForm.dataset.deviceType || "watch",
    );
    if (!supplierId || !internalModel || !commercialName) {
        alert("Fornecedor, modelo interno e nome comercial são obrigatórios");
        return;
    }

    const body = new FormData();
    body.append("supplier_id", String(supplierId));
    body.append("internalModel", internalModel);
    body.append("commercialName", commercialName);
    body.append("deviceType", deviceType);
    if (els.modelImage.files[0]) {
        body.append("image", els.modelImage.files[0]);
    }
    if (!els.modelForm.dataset.modelId) {
        body.append("capabilitiesConfigured", "1");
        for (const feature of state.modelModal.enabledCapabilities || []) {
            body.append("capabilities[]", String(feature));
        }
    }

    const result = await apiSaveModel(
        els.modelForm.dataset.modelId || "",
        body,
    );
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }

    backToModelList();
}

export {
    editModel,
    openNewModelForm,
    refreshNewModelCapabilityTemplate,
    resetModelForm,
    revokeModelPreviewUrl,
    saveModel,
    selectModelDeviceType,
    selectModelSupplier,
    updateModelProtocolAndPreview,
};
