import {state} from "../../state.js";
import {esc} from "../../format.js";
import {renderButtonGroup} from "../../renderers.js";
import {
    deviceTypeOptions,
    normalizeDeviceType,
} from "../../devices/list-detail.js";
import {getSettingsModelsRuntime} from "./runtime.js";

function modelSupplierOptions() {
    return state.modelModalSuppliers.map((supplier) => ({
        value: String(supplier.id),
        label: supplier.name,
    }));
}

function renderModelSupplierButtons(selectedSupplierId) {
    const {els} = getSettingsModelsRuntime();
    renderButtonGroup(
        els.modelSupplierButtons,
        modelSupplierOptions(),
        String(selectedSupplierId),
        "selectModelSupplier",
    );
}

function renderModelDeviceTypeButtons(selectedDeviceType) {
    const {els} = getSettingsModelsRuntime();
    renderButtonGroup(
        els.modelDeviceTypeButtons,
        deviceTypeOptions,
        selectedDeviceType,
        "selectModelDeviceType",
    );
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
    const {els, callbacks} = getSettingsModelsRuntime();
    revokeModelPreviewUrl();
    els.modelForm.reset();
    delete els.modelForm.dataset.modelId;
    delete els.modelForm.dataset.image;
    els.modelForm.dataset.deviceType = "watch";
    els.saveModelBtn.innerHTML =
        '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';
    els.modelImage.value = "";
    if (els.modelTemplateSummary) {
        els.modelTemplateSummary.textContent =
            "A carregar template de capacidades do fornecedor.";
    }

    renderModelDeviceTypeButtons("watch");

    const suppliers = modelSupplierOptions();
    const supplierId = suppliers.some(
        (supplier) => supplier.value === String(selectedSupplierId),
    )
        ? String(selectedSupplierId)
        : suppliers[0]?.value || "";
    const supplier = state.modelModalSuppliers.find(
        (entry) => String(entry.id) === supplierId,
    );
    els.modelForm.dataset.supplierId = supplierId;
    els.modelForm.dataset.supplier = supplier?.name || "";

    renderModelSupplierButtons(supplierId);
    updateModelProtocolAndPreview();
    void callbacks.refreshNewModelCapabilityTemplate?.();
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
    const supplier = state.modelModalSuppliers.find(
        (entry) => String(entry.id) === String(supplierId),
    );
    els.modelForm.dataset.supplierId = String(supplierId);
    els.modelForm.dataset.supplier = supplier?.name || "";
    delete els.modelForm.dataset.image;
    renderModelSupplierButtons(supplierId);
    updateModelProtocolAndPreview();
    void callbacks.refreshNewModelCapabilityTemplate?.();
}

function selectModelDeviceType(deviceType) {
    const {els, callbacks} = getSettingsModelsRuntime();
    els.modelForm.dataset.deviceType = normalizeDeviceType(deviceType);
    renderModelDeviceTypeButtons(els.modelForm.dataset.deviceType);
    void callbacks.refreshNewModelCapabilityTemplate?.();
}

export {
    editModel,
    resetModelForm,
    revokeModelPreviewUrl,
    selectModelDeviceType,
    selectModelSupplier,
    updateModelProtocolAndPreview,
};
