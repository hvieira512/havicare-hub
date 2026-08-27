import {
    getDevices as apiGetDevices,
    deleteModel as apiDeleteModel,
    getModel as apiGetModel,
    getSuppliers as apiGetSuppliers,
    saveModel as apiSaveModel,
} from "../../api/index.js";
import {ensureModelTemplate} from "../../capability-catalog.js";
import {state} from "../../state.js";
import {esc} from "../../format.js";
import {modelPreviewHtml, sectionStrip} from "../../widgets.js";
import {
    capabilitiesGroupedBySection,
    capabilityLabelByKey,
    deviceTypeOptions,
    flattenedCapabilityKeys,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
} from "../../domain.js";
import {CAPABILITY_SECTION_ICONS, loadCapabilityCatalog} from "../capabilities.js";
import {getSettingsModelsRuntime, modelsCarousel} from "./shell.js";
import {backToModelList} from "./list.js";

/**
 * A ficha de um modelo: o terceiro slide do carrossel do catálogo.
 *
 * São duas metades no mesmo ecrã. Em cima a identidade -- nome comercial, modelo interno,
 * fornecedor, tipo --, que se grava por diferença. Em baixo as capacidades, que são
 * interruptores sobre o catálogo do tipo, limitados ao template do fornecedor.
 *
 * Gravam para o mesmo endpoint com corpos diferentes, e por isso são dois botões: mexer no
 * nome de um modelo não deve reescrever a lista das suas capacidades.
 */

async function openModelDetail(modelId) {
    const {els} = getSettingsModelsRuntime();
    const response = await apiGetModel(modelId);
    const model = response.data || response;
    await loadCapabilityCatalog(
        model.device_type || model.deviceType || "watch",
    );

    state.settingsModal.currentCapabilitiesModel = model;
    state.settingsModal.capabilityModelId = Number(model.id);
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
    // O template do fornecedor manda: uma capacidade gravada no modelo que ele não oferece
    // não se mostra ligada, porque não há por onde a usar.
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

    els.modelsBreadcrumb.classList.remove("d-none");
    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbCurrent.textContent = modelCommercialName(model);
    els.modelsBreadcrumbCurrent.classList.remove("d-none");
    els.modelsBreadcrumbCurrent.classList.add("active");

    await ensureModelDetailSuppliers();
    renderModelDetailInfo(model);
    renderCapabilitiesSection();

    modelsCarousel()?.to(2);
}

/* ---------- a identidade ---------- */

function renderModelDetailInfo(model) {
    const {els} = getSettingsModelsRuntime();
    const label = modelCommercialName(model);
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

/**
 * Os fornecedores com o seu id, que é o que o `supplier_id` do modelo precisa. Vêm do
 * separador dos fornecedores, ou carregam-se aqui, porque este detalhe alcança-se sem lá
 * passar.
 */
function modelDetailSuppliers() {
    return state.modelModalSuppliers || [];
}

async function ensureModelDetailSuppliers() {
    if ((state.modelModalSuppliers || []).length > 0) return;
    const response = await apiGetSuppliers({limit: 200});
    state.modelModalSuppliers = response?.error ? [] : response.data || [];
}

function renderModelDetailSelect(select, options, selected) {
    if (!select) return;
    select.innerHTML = options
        .map(
            (option) =>
                `<option value="${esc(option.value)}"${option.value === selected ? " selected" : ""}>${esc(option.label)}</option>`,
        )
        .join("");
    select.value = selected;
}

function readModelDetailFields() {
    const {els} = getSettingsModelsRuntime();
    return {
        commercialName: String(els.modelDetailCommercialName?.value || "").trim(),
        internalModel: String(els.modelDetailInternalModel?.value || "").trim(),
        supplier: String(els.modelDetailSupplierSelect?.value || ""),
        deviceType: String(els.modelDetailDeviceType?.value || ""),
    };
}

/** O "Guardar" aparece por diferença: sem alteração não há botão para premir. */
function syncModelDetailDirty() {
    const {els} = getSettingsModelsRuntime();
    const pristine = state.settingsModal.modelDetailPristine;
    if (!pristine || !els.modelDetailSaveBtn) return;
    const current = readModelDetailFields();
    const dirty = Object.keys(pristine).some((key) => pristine[key] !== current[key]);

    els.modelDetailSaveBtn.classList.toggle("d-none", !dirty);
    els.modelDetailResetBtn.classList.toggle("d-none", !dirty);
    els.modelDetailDirtyState.classList.toggle("d-none", dirty);
}

function resetModelDetailFields() {
    const {els} = getSettingsModelsRuntime();
    const pristine = state.settingsModal.modelDetailPristine;
    if (!pristine) return;
    els.modelDetailCommercialName.value = pristine.commercialName;
    els.modelDetailInternalModel.value = pristine.internalModel;
    els.modelDetailSupplierSelect.value = pristine.supplier;
    els.modelDetailDeviceType.value = pristine.deviceType;
    syncModelDetailDirty();
}

async function saveModelDetail() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) return;
    const fields = readModelDetailFields();
    if (fields.commercialName === "" || fields.internalModel === "") {
        alert("O nome comercial e o modelo interno são obrigatórios.");
        return;
    }

    const supplier = modelDetailSuppliers().find(
        (item) => String(item.name) === fields.supplier,
    );
    const body = new FormData();
    body.append("supplier_id", String(supplier?.id ?? model.supplier_id));
    body.append("internalModel", fields.internalModel);
    body.append("commercialName", fields.commercialName);
    body.append("deviceType", fields.deviceType);
    body.append("protocol", String(model.protocol || ""));

    const result = await apiSaveModel(model.id, body);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }

    // O PUT responde `{status: "ok"}`: o modelo actualizado é o que se acabou de enviar.
    const saved = {
        ...model,
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
    // O nome, o fornecedor e o tipo são o que a árvore mostra, e o que o resto da dashboard
    // usa para reconhecer o modelo: as duas listas em memória deixam de valer.
    state.settingsModal.sectionLoaded.models = false;
    state.deviceTypeSuppliersModels = [];
}

/**
 * Quantos dispositivos usam este modelo: a consequência de apagar escrita ao lado do botão,
 * e não depois de se premir. O total vem da paginação do endpoint de dispositivos.
 */
async function renderModelDetailDeleteHint(model) {
    const {els} = getSettingsModelsRuntime();
    if (!els.modelDetailDeleteHint) return;
    const internal = modelInternalName(model);
    const result = await apiGetDevices({model: internal, limit: 1});
    const total = result?.error ? null : (result?.pagination?.total ?? null);
    els.modelDetailDeleteHint.textContent = total === null
        ? "Os dispositivos que o usam ficam sem template de capacidades."
        : total === 0
            ? "Nenhum dispositivo usa este modelo."
            : `${total} ${total === 1 ? "dispositivo usa" : "dispositivos usam"} o ${internal}.`
              + " Apagar o modelo deixa-os sem template de capacidades.";
}

async function deleteCurrentModel() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) return;
    if (
        !window.confirm(
            `Tem a certeza que deseja apagar o modelo "${modelCommercialName(model)}"?`,
        )
    )
        return;

    await apiDeleteModel(model.id);
    removeModelFromCatalog(model.id);
    state.deviceTypeSuppliersModels = [];
    backToModelList();
}

/** Tira o modelo da árvore em memória, para a lista não ter de ser pedida outra vez. */
function removeModelFromCatalog(modelId) {
    for (const group of state.settingsModal.modelCatalog || []) {
        for (const supplier of group?.suppliers || []) {
            supplier.models = (supplier.models || []).filter(
                (entry) => String(entry.id) !== String(modelId),
            );
        }
    }
}

/* ---------- as capacidades do modelo ---------- */

/**
 * As secções que a ficha mostra e as chaves de cada uma. O template do fornecedor manda
 * quando existe; sem ele, a lista é a das capacidades que o modelo tem ligadas -- e então
 * desligar uma tira-lhe a linha, que é o que obriga a redesenhar em vez de acertar no sítio.
 */
function capabilitySections(enabled) {
    const templateKeys = state.settingsModal.capabilityModelTemplateKeys || [];
    const templateSet = new Set(templateKeys);

    return capabilitiesGroupedBySection(state.settingsModal.capabilityCatalog)
        .map(({ section, label, entries }) => {
            const sectionEntries = entries
                .filter(
                    (entry) =>
                        entry.isTelemetry ||
                        entry.isConfigurable ||
                        entry.isEvent,
                )
                .filter((entry) =>
                    templateKeys.length > 0
                        ? templateSet.has(entry.key)
                        : enabled.has(entry.key),
                )
                .map((entry) => entry.key);
            if (sectionEntries.length === 0) {
                return null;
            }
            return { section, label, entries: sectionEntries };
        })
        .filter(Boolean);
}

/**
 * Acerta no sítio o que um interruptor mexe -- o "Solicitável" da mesma linha e as três
 * contagens --, em vez de redesenhar a secção: redesenhá-la tirava o foco ao interruptor que
 * se acabou de premir e deixava a lista impossível de percorrer com o teclado.
 *
 * Sem template do fornecedor a linha desaparece ao desligar, e aí não há como acertar: quem
 * chama redesenha.
 */
function syncCapabilitySwitches(feature) {
    const {els} = getSettingsModelsRuntime();
    const model = state.settingsModal.currentCapabilitiesModel;
    const enabled = new Set(
        state.settingsModal.capabilityEnabledCapabilities || [],
    );
    const requestable = new Set(
        state.settingsModal.capabilityRequestableCapabilities || [],
    );
    const canBeRequested = (
        Array.isArray(model?.requestableCapabilityKeys)
            ? model.requestableCapabilityKeys.map(String)
            : []
    ).includes(feature);

    const requestableInput = document.getElementById(`requestable-${feature}`);
    if (requestableInput) {
        requestableInput.checked = canBeRequested && requestable.has(feature);
        requestableInput.disabled = !(canBeRequested && enabled.has(feature));
    }

    const sections = capabilitySections(enabled);
    const countOf = (entries) => entries.filter((key) => enabled.has(key)).length;

    els.capabilitySummary.textContent =
        `${sections.reduce((total, item) => total + countOf(item.entries), 0)}`
        + `/${sections.reduce((total, item) => total + item.entries.length, 0)} ativos`;

    for (const {section, entries} of sections) {
        const badge = els.capabilitySectionNav.querySelector(
            `[data-section="${CSS.escape(section)}"] [data-section-count]`,
        );
        if (badge) badge.textContent = String(countOf(entries));
    }

    const visible = sections.find(
        (item) => item.section === state.settingsModal.activeCapabilitySection,
    );
    const groupCount = els.capabilityGroups.querySelector("[data-section-count]");
    if (visible && groupCount) {
        groupCount.textContent = `${countOf(visible.entries)}/${visible.entries.length} ativos`;
    }
}

/** Diz se desligar uma capacidade lhe tira a linha, e obriga por isso a redesenhar. */
function capabilityRowsDependOnSelection() {
    return (state.settingsModal.capabilityModelTemplateKeys || []).length === 0;
}

function renderCapabilitiesSection() {
    const {els} = getSettingsModelsRuntime();
    const model = state.settingsModal.currentCapabilitiesModel;
    const enabled = new Set(
        state.settingsModal.capabilityEnabledCapabilities || [],
    );
    const requestable = new Set(
        state.settingsModal.capabilityRequestableCapabilities || [],
    );
    const protocolRequestable = new Set(
        Array.isArray(model?.requestableCapabilityKeys)
            ? model.requestableCapabilityKeys.map(String)
            : [],
    );

    const detailLabel = model ? modelCommercialName(model) : "Modelo";
    els.modelDetailImage.innerHTML = modelPreviewHtml(model, detailLabel);
    els.modelDetailName.textContent = detailLabel;

    const capabilities =
        model?.capabilities && typeof model.capabilities === "object"
            ? model.capabilities
            : {};

    els.capabilityTitle.textContent = model
        ? modelCommercialName(model)
        : "Capacidades";
    const templateKeys = state.settingsModal.capabilityModelTemplateKeys || [];

    els.capabilitySubtitle.textContent =
        String(model?.supplier || "") +
        (templateKeys.length > 0
            ? ` — ${templateKeys.length} capacidades do template`
            : "");

    const sections = capabilitySections(enabled);

    const totalCapabilities = sections.reduce(
        (count, item) => count + item.entries.length,
        0,
    );
    const activeCapabilities = sections.reduce(
        (count, item) =>
            count + item.entries.filter((feature) => enabled.has(feature)).length,
        0,
    );
    els.capabilitySummary.textContent = `${activeCapabilities}/${totalCapabilities} ativos`;

    let activeSection = state.settingsModal.activeCapabilitySection;
    if (!activeSection || !sections.some((s) => s.section === activeSection)) {
        activeSection = sections[0]?.section || "";
        state.settingsModal.activeCapabilitySection = activeSection;
    }

    els.capabilitySectionNav.innerHTML = sectionStrip(
        sections.map(({ section, label, entries }) => ({
            key: section,
            label,
            count: (entries || []).filter((feature) => enabled.has(feature)).length,
            icon: CAPABILITY_SECTION_ICONS[section] || "fa-gear",
        })),
        "jumpCapabilitySection",
        activeSection,
    );

    const section = sections.find((s) => s.section === activeSection);
    if (section) {
        els.capabilityGroups.innerHTML = `
        <section class="border rounded-3 p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-label">${esc(section.label)}</div>
                <span class="small text-secondary" data-section-count>${section.entries.filter((f) => enabled.has(f)).length}/${section.entries.length} ativos</span>
            </div>
            <div class="d-flex flex-column gap-2">
                ${section.entries
                    .map((feature) => {
                        const labelText = capabilityLabelByKey(
                            feature,
                            state.settingsModal.capabilityCatalog,
                        );
                        const sectionState = capabilities[section.section] || {};
                        const isInModelPayload = Object.prototype.hasOwnProperty.call(
                            sectionState,
                            feature,
                        );
                        const canBeRequested =
                            section.section === "telemetry" &&
                            protocolRequestable.has(feature);
                        const protocolDescription =
                            section.section === "telemetry"
                                ? `${esc(String(model?.supplier || "Protocolo"))}: ${canBeRequested ? "receção e pedido" : "apenas receção"}`
                                : "";
                        // São sempre dois interruptores, na mesma posição. Quando o
                        // fornecedor não suporta pedido, o segundo fica desligado com a
                        // razão na etiqueta, em vez de trocar de tipo de controlo.
                        const requestableSwitch = section.section !== "telemetry"
                            ? ""
                            : `<div class="form-check form-switch mb-0 flex-shrink-0 text-nowrap">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilityRequestability" data-feature="${esc(feature)}" id="requestable-${esc(feature)}" ${canBeRequested && requestable.has(feature) ? "checked" : ""} ${canBeRequested && enabled.has(feature) ? "" : "disabled"}>
                                <label class="form-check-label small" for="requestable-${esc(feature)}">Solicitável</label>
                                ${canBeRequested ? "" : `<div class="section-label">${esc(String(model?.supplier || "O protocolo"))} não suporta pedido</div>`}
                               </div>`;
                        return `
                        <div class="d-flex justify-content-between align-items-start gap-3 border rounded-3 px-3 py-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilitySupport" data-feature="${esc(feature)}" id="cap-${esc(feature)}" ${enabled.has(feature) ? "checked" : ""}>
                                <label class="form-check-label" for="cap-${esc(feature)}">${esc(labelText)}</label>
                                <div class="section-label">${protocolDescription || (!isInModelPayload ? "Disponível no catálogo do tipo de dispositivo." : "Suportada pelo modelo")}</div>
                            </div>
                            ${requestableSwitch}
                        </div>`;
                    })
                    .join("")}
            </div>
        </section>`;
    } else {
        els.capabilityGroups.innerHTML = "";
    }
}

async function saveCapabilities() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) {
        alert("Selecione um modelo");
        return;
    }

    const body = new FormData();
    body.append("supplier_id", String(model.supplier_id));
    body.append("internalModel", String(modelInternalName(model)));
    body.append("commercialName", String(modelCommercialName(model)));
    body.append("deviceType", String(modelDeviceType(model)));
    body.append("protocol", String(model.protocol || ""));
    body.append("capabilitiesConfigured", "1");
    for (const feature of state.settingsModal.capabilityEnabledCapabilities || []) {
        body.append("capabilities[]", String(feature));
    }
    body.append("requestableCapabilitiesConfigured", "1");
    for (const feature of state.settingsModal.capabilityRequestableCapabilities || []) {
        body.append("requestableCapabilities[]", String(feature));
    }

    const result = await apiSaveModel(model.id, body);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }

    backToModelList();
}

export {
    capabilityRowsDependOnSelection,
    deleteCurrentModel,
    openModelDetail,
    renderCapabilitiesSection,
    resetModelDetailFields,
    saveCapabilities,
    saveModelDetail,
    syncCapabilitySwitches,
    syncModelDetailDirty,
};
