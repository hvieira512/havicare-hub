import {
    getDevices as apiGetDevices,
    deleteModel as apiDeleteModel,
    getCapabilities as apiGetCapabilities,
    applyCapabilityDiscoveryRun as apiApplyCapabilityDiscoveryRun,
    previewCapabilityDiscovery as apiPreviewCapabilityDiscovery,
    getModel as apiGetModel,
    getModelFilters as apiGetModelFilters,
    getModelTemplate as apiGetModelTemplate,
    getSuppliers as apiGetSuppliers,
    saveModel as apiSaveModel,
} from "../api/index.js";
import {state} from "../state.js";
import {esc} from "../format.js";
import {
    filterChips,
    modelPreviewHtml,
    renderButtonGroup,
    renderDeviceTypeTiles,
} from "../widgets.js";
import {requestCardContent} from "../telemetry-cards.js";
import {
    capabilitiesGroupedBySection,
    capabilityLabelByKey,
    deviceTypeLabel,
    deviceTypeOptions,
    flattenedCapabilityKeys,
    humanizeCapabilityKey,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
    normalizeDeviceType,
} from "../domain.js";
import {
    loadSettingsModelFilters,
    resetModelForm,
    revokeModelPreviewUrl,
    loadSettingsModelsSection,
} from "./models/index.js";

// A descoberta guiada está por acabar: fica visível e desligada até o caminho de
// gerar e aplicar a proposta estar pronto de ponta a ponta.
const DISCOVERY_ENABLED = false;

let els;

async function initSettingsCapabilities(context) {
    els = context.els;
}

async function ensureCapabilityCatalog(deviceType) {
    const normalized = normalizeDeviceType(
        deviceType || state.settingsModal.capabilityDeviceType || "watch",
    );
    const cached = state.settingsModal.capabilityCatalogByType?.[normalized];
    if (cached) {
        state.settingsModal.capabilityCatalog = cached;
        return cached;
    }

    const response = await apiGetCapabilities({ deviceType: normalized });
    const catalog = response.data || [];
    state.settingsModal.capabilityCatalogByType = {
        ...(state.settingsModal.capabilityCatalogByType || {}),
        [normalized]: catalog,
    };
    state.settingsModal.capabilityCatalog = catalog;
    return catalog;
}

async function ensureCapabilityModelFilters() {
    if (
        state.settingsModal.modelFilters.length > 0 ||
        state.settingsModal.sectionLoaded.modelFilters
    ) {
        return;
    }
    const response = await apiGetModelFilters();
    state.settingsModal.modelFilters = response.data || [];
    state.settingsModal.sectionLoaded.modelFilters = true;
}

function resolveCapabilitySuppliersForDeviceType(deviceType) {
    const group = state.settingsModal.modelFilters.find(
        (g) => normalizeDeviceType(g.deviceType || "") === deviceType,
    );
    state.settingsModal.capabilitySuppliersForDeviceType =
        group?.suppliers?.filter((s) => s.enabled) || [];
}

async function loadCapabilityTemplate(supplierId, deviceType) {
    if (!supplierId || !deviceType) {
        state.settingsModal.capabilityTemplateEnabledKeys = [];
        return;
    }
    const response = await apiGetModelTemplate({ supplierId, deviceType });
    if (response.error) {
        state.settingsModal.capabilityTemplateEnabledKeys = [];
        return;
    }
    state.settingsModal.capabilityTemplateEnabledKeys = Array.isArray(
        response.enabledCapabilities,
    )
        ? response.enabledCapabilities.map(String)
        : [];
}

async function loadSettingsCapabilitiesSection(
    deviceType = state.settingsModal.capabilityDeviceType || "watch",
) {
    const normalized = normalizeDeviceType(deviceType);
    const deviceTypeChanged =
        state.settingsModal.capabilityDeviceType !== normalized;
    state.settingsModal.capabilityDeviceType = normalized;
    if (deviceTypeChanged) {
        state.settingsModal.capabilitySupplier = "";
        state.settingsModal.capabilityTemplateEnabledKeys = [];
    }
    await ensureCapabilityCatalog(normalized);
    await ensureCapabilityModelFilters();
    resolveCapabilitySuppliersForDeviceType(normalized);
    if (state.settingsModal.capabilitySupplier) {
        await loadCapabilityTemplate(
            state.settingsModal.capabilitySupplier,
            normalized,
        );
    }
    state.settingsModal.sectionLoaded.capabilities = true;
    renderCapabilitiesCatalogSection();
    renderDiscoverySection();
    if (state.settingsModal.currentCapabilitiesModel) {
        void loadDiscoveryDevices();
    }
}

function handleCapabilitySupplierClick(event) {
    const button = event.target.closest(
        '[data-action="selectCapabilitySupplier"]',
    );
    if (!button) return;
    selectCapabilitySupplier(button.dataset.value);
}

async function selectCapabilitySupplier(supplierId) {
    state.settingsModal.capabilitySupplier = supplierId || "";
    const deviceType = state.settingsModal.capabilityDeviceType || "watch";
    if (supplierId) {
        await loadCapabilityTemplate(supplierId, deviceType);
    } else {
        state.settingsModal.capabilityTemplateEnabledKeys = [];
    }
    renderCapabilitiesCatalogSection();
}

function renderCapabilitiesCatalogSection() {
    renderDeviceTypeTiles(els.capabilityDeviceTypeButtons, deviceTypeOptions, {
        selected: state.settingsModal.capabilityDeviceType || "watch",
        action: "selectCapabilityDeviceType",
    });

    const supplierId = state.settingsModal.capabilitySupplier;
    const enabledKeys = state.settingsModal.capabilityTemplateEnabledKeys;
    const enabledSet = new Set(enabledKeys);
    const hasSupplierFilter = !!supplierId && enabledKeys.length > 0;
    const supplier = supplierId
        ? state.settingsModal.capabilitySuppliersForDeviceType.find(
            (candidate) => String(candidate.id) === String(supplierId),
        )
        : null;

    renderCapabilitySupplierButtons();
    updateCapabilitySupplierSummary(hasSupplierFilter);

    const sections = capabilitiesGroupedBySection(
        state.settingsModal.capabilityCatalog,
    );
    const visibleSections = sections
        .map(({ section, label, entries }) => {
            const visibleEntries = entries
                .filter(
                    (entry) =>
                        entry.isTelemetry ||
                        entry.isConfigurable ||
                        entry.isEvent,
                )
                .filter((entry) => matchesCapabilityQuery(entry))
                // As que o fornecedor nao declara deixam de desaparecer: saber que uma
                // capacidade existe para o tipo de dispositivo e que este fornecedor nao a
                // traz e a pergunta que se vem aqui fazer.
                .map((entry) => ({
                    ...entry,
                    supported: !hasSupplierFilter || enabledSet.has(entry.key),
                }));
            if (visibleEntries.length === 0) {
                return null;
            }
            return { section, label, entries: visibleEntries };
        })
        .filter(Boolean);

    els.capabilityCatalogEmpty.classList.toggle(
        "d-none",
        visibleSections.length > 0,
    );
    // Sem a chave: quem administra o hub nao precisa do vocabulario do protocolo, e o
    // icone -- o mesmo que a capacidade ja tem nos cartoes de pedido -- passa a ser o que
    // se reconhece. O tipo tambem sai, porque era a seccao repetida em cada linha.
    const supplierName = supplier ? supplier.name : "";

    els.capabilityCatalogViewer.innerHTML = visibleSections
        .map(({ section, label, entries }) => {
            const supported = entries.filter((entry) => entry.supported).length;
            let index = 0;

            const rows = entries
                .map((entry) => {
                    const facts = entry.supported
                        ? [
                            entry.isConfigurable ? "Configurável" : null,
                            entry.isRequestable ? "Solicitável" : null,
                        ].filter(Boolean)
                        : [supplierName ? `não oferecido pela ${supplierName}` : "não oferecido"];
                    // Numera o que e suportado, para o ultimo numero da seccao dizer
                    // quantas capacidades o dispositivo tem de facto.
                    const number = entry.supported
                        ? String(++index).padStart(2, "0")
                        : "—";
                    return `
                <div class="capability-row${entry.supported ? "" : " is-unsupported"}">
                    <span class="capability-index" aria-hidden="true">${number}</span>
                    <span class="d-flex justify-content-center text-secondary"><i class="fa-solid ${esc(capabilityIcon(entry, section))}"></i></span>
                    <span class="capability-name">${esc(entry.label || humanizeCapabilityKey(entry.key))}</span>
                    <span class="capability-facts">${esc(facts.join(" · "))}</span>
                </div>`;
                })
                .join("");

            return `
        <section id="${esc(catalogSectionId(section))}" class="capability-catalog-section">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="section-label">${esc(label)}</div>
                <span class="small text-secondary">${supported === entries.length ? `${supported} ${supported === 1 ? "capacidade" : "capacidades"}` : `${supported} de ${entries.length}`}</span>
            </div>
            <div class="vstack gap-2">${rows}</div>
        </section>
    `;
        })
        .join("");

    renderCapabilityCatalogSectionNav(visibleSections);
}

/**
 * Cinco seccoes irmas, cinco icones -- e uma cor. Eram ciano cheio, verde, navy, vermelho
 * e cinzento, como se tivessem gravidades diferentes, e o vermelho dos alarmes lia-se como
 * erro em vez de categoria.
 */
const SECTION_ICONS = {
    telemetry: "fa-chart-line",
    health: "fa-heart-pulse",
    contacts: "fa-address-book",
    alarms: "fa-bell",
    settings_system: "fa-gear",
};

/**
 * O icone de uma capacidade no catalogo.
 *
 * O mapa dos cartoes de pedido cobre o que se pede a um dispositivo, que e sobretudo
 * telemetria. Fora disso caia tudo no mesmo `fa-circle-info`, e catorze circulos iguais
 * numa seccao de alarmes dizem menos do que icone nenhum -- por isso o recurso e o icone da
 * seccao, que ao menos e verdade.
 */
function capabilityIcon(entry, section) {
    const mapped = requestCardContent(entry.key);
    if (mapped.icon && mapped.icon !== "fa-circle-info") return mapped.icon;
    return SECTION_ICONS[section] || "fa-circle-info";
}

/** O `id` da seccao no catalogo, que e o destino das pastilhas da tira. */
function catalogSectionId(section) {
    return `capabilityCatalog-${String(section).replace(/[^a-zA-Z0-9_-]/g, "-")}`;
}

/**
 * A tira de seccoes: cada pastilha aponta para o `id` da sua seccao e leva a contagem do
 * que o fornecedor suporta, para se saber o tamanho de uma seccao antes de se ir la.
 */
function renderCapabilityCatalogSectionNav(sections) {
    if (!els.capabilityCatalogSectionNav) return;

    els.capabilityCatalogSectionNav.innerHTML = sections.length > 1
        ? sections
            .map(({ section, label, entries }) => {
                const supported = entries.filter((entry) => entry.supported).length;
                return `
            <button type="button" class="capability-section-chip" data-action="scrollCapabilityCatalogSection" data-target="${esc(catalogSectionId(section))}">
                ${esc(label)}<span class="count">${supported}</span>
            </button>`;
            })
            .join("")
        : "";
}

function handleCapabilityCatalogSearch() {
    state.settingsModal.capabilityQuery = els.capabilityCatalogSearch.value;
    renderCapabilitiesCatalogSection();
}

/** A pesquisa do catalogo: compara com o nome e com a chave, que e o que a linha mostra. */
function matchesCapabilityQuery(entry) {
    const needle = String(state.settingsModal.capabilityQuery || "").trim().toLowerCase();
    if (needle === "") return true;
    const label = String(entry.label || humanizeCapabilityKey(entry.key)).toLowerCase();
    return label.includes(needle) || String(entry.key).toLowerCase().includes(needle);
}

function renderCapabilitySupplierButtons() {
    const suppliers = state.settingsModal.capabilitySuppliersForDeviceType;
    const selected = state.settingsModal.capabilitySupplier;

    if (!suppliers.length) {
        els.capabilitySupplierButtons.innerHTML =
            '<div class="small text-secondary">Sem fornecedores para este tipo de dispositivo.</div>';
        return;
    }

    const items = [
        { value: "", label: "Todos" },
        ...suppliers.map((s) => ({ value: String(s.id), label: s.name })),
    ];

    renderButtonGroup(
        els.capabilitySupplierButtons,
        items,
        selected,
        "selectCapabilitySupplier",
    );
}

function updateCapabilitySupplierSummary(hasFilter) {
    if (!els.capabilitySupplierClear || !els.capabilitySupplierSummary) return;

    const supplierId = state.settingsModal.capabilitySupplier;
    const suppliers = state.settingsModal.capabilitySuppliersForDeviceType;
    const supplier = supplierId
        ? suppliers.find((s) => String(s.id) === String(supplierId))
        : null;

    els.capabilitySupplierClear.classList.toggle("d-none", !supplierId);

    const total = state.settingsModal.capabilityCatalog.length;
    if (supplier) {
        const supported = state.settingsModal.capabilityTemplateEnabledKeys.length;
        els.capabilitySupplierSummary.textContent =
            `${supported} de ${total} capacidades suportadas por ${supplier.name}.`;
    } else {
        els.capabilitySupplierSummary.textContent =
            `${total} ${total === 1 ? "capacidade" : "capacidades"} no tipo escolhido.`;
    }

    // O filtro activo le-se na pastilha, e o contador no botao diz que ha um sem os
    // botoes de fornecedor estarem abertos.
    if (els.capabilityActiveFilters) {
        els.capabilityActiveFilters.innerHTML = supplier
            ? filterChips([{key: "supplier", label: String(supplier.name)}], "removeCapabilityFilter")
            : "";
    }
    if (els.capabilityFilterCount) {
        els.capabilityFilterCount.textContent = supplier ? "1" : "";
        els.capabilityFilterCount.classList.toggle("d-none", !supplier);
    }
}

async function loadDiscoveryDevices() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) {
        state.settingsModal.discoveryDeviceOptions = [];
        state.settingsModal.discoveryDeviceImei = "";
        state.settingsModal.discoveryError = "";
        renderDiscoverySection();
        return [];
    }

    state.settingsModal.discoveryError = "";

    const response = await apiGetDevices({
        deviceType: model.device_type || model.deviceType || "watch",
        supplier: model.supplier || "",
        model:
            model.internal_model ||
            model.internalModel ||
            model.commercial_name ||
            model.commercialName ||
            "",
        limit: 100,
    });
    if (response.error) {
        state.settingsModal.discoveryError =
            response.error.message ||
            response.error.code ||
            "Erro ao carregar os dispositivos online.";
        state.settingsModal.discoveryDeviceOptions = [];
        state.settingsModal.discoveryDeviceImei = "";
        renderDiscoverySection();
        return [];
    }

    const devices = (response.data || []).filter(
        (device) => device.online && String(device.deviceType || "watch") === String(model.device_type || model.deviceType || "watch"),
    );
    state.settingsModal.discoveryDeviceOptions = devices;
    if (
        !devices.some(
            (device) =>
                String(device.imei) === String(state.settingsModal.discoveryDeviceImei || ""),
        )
    ) {
        state.settingsModal.discoveryDeviceImei = String(devices[0]?.imei || "");
    }
    renderDiscoverySection();
    return devices;
}

function renderDiscoveryEvidence() {
    const run = state.settingsModal.discoveryRun;
    if (!els.discoveryEvidence) return;
    if (!run) {
        els.discoveryEvidence.innerHTML = '<div class="small text-secondary">Gerar uma proposta para ver a evidência recolhida.</div>';
        return;
    }

    const changes = run.changes || { add: [], remove: [] };
    const evidence = Array.isArray(run.evidence) ? run.evidence : [];
    els.discoveryEvidence.innerHTML = `
        <div class="border rounded bg-white p-3">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <div class="fw-semibold">${esc(run.model?.commercialName || "Proposta")}</div>
                    <div class="small text-secondary">IMEI ${esc(run.device?.imei || "")} · ${esc(run.device?.supplier || "")} ${esc(run.device?.model || "")}</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="config-state config-state-success"><span class="config-state-dot"></span>+${(changes.add || []).length}</span>
                    <span class="config-state config-state-secondary"><span class="config-state-dot"></span>−${(changes.remove || []).length}</span>
                    <span class="config-state ${run.status === "applied" ? "" : "config-state-warning"}"><span class="config-state-dot"></span>${esc(run.status || "draft")}</span>
                </div>
            </div>
            <div class="small text-secondary mt-2">${esc((run.currentEnabledCapabilityKeys || []).length)} capacidades atuais · ${(run.suggestedEnabledCapabilityKeys || []).length} sugeridas</div>
        </div>
        <div class="vstack gap-2">
            ${evidence.slice(0, 12).map((entry) => `
                <div class="d-flex justify-content-between align-items-center gap-3 border rounded-3 px-3 py-2">
                    <div>
                        <div class="fw-semibold">${esc(entry.label || entry.key || "")}</div>
                        <div class="section-label" style="letter-spacing:0;text-transform:none">${esc(entry.key || "")} · ${esc(entry.section || "")}</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="config-state ${entry.supported ? "config-state-success" : "config-state-secondary"}"><span class="config-state-dot"></span>${entry.supported ? "suportado" : "não suportado"}</span>
                        <span class="config-state ${entry.configured ? "" : "config-state-secondary"}"><span class="config-state-dot"></span>${entry.configured ? "no modelo" : "não configurado"}</span>
                    </div>
                </div>
            `).join("")}
        </div>
    `;
}

function renderDiscoverySection() {
    if (!els.discoveryModelSummary || !els.discoveryDeviceSelect || !els.discoveryStatus || !els.discoveryApplyBtn || !els.discoveryGenerateBtn) {
        return;
    }

    // A descoberta guiada ainda não está pronta. Os controlos ficam à vista mas
    // desligados, para se perceber o que vem a seguir em vez de a secção desaparecer.
    // A guarda vive aqui porque é por esta função que todo o desenho da secção passa.
    if (!DISCOVERY_ENABLED) {
        els.discoveryModelSummary.textContent =
            "Em breve: o hub propõe as capacidades a partir de um dispositivo online, em vez de se escreverem à mão.";
        els.discoveryDeviceSelect.innerHTML = '<option value="">Em breve</option>';
        els.discoveryDeviceSelect.disabled = true;
        els.discoveryGenerateBtn.disabled = true;
        els.discoveryApplyBtn.disabled = true;
        if (els.discoveryRefreshDevicesBtn) {
            els.discoveryRefreshDevicesBtn.disabled = true;
        }
        els.discoveryStatus.textContent = "";
        if (els.discoveryEvidence) {
            els.discoveryEvidence.innerHTML = "";
        }
        return;
    }

    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) {
        els.discoveryModelSummary.textContent = "Selecione um modelo no separador de modelos para iniciar uma descoberta.";
        els.discoveryDeviceSelect.innerHTML = '<option value="">Sem modelo selecionado</option>';
        els.discoveryDeviceSelect.disabled = true;
        els.discoveryGenerateBtn.disabled = true;
        els.discoveryApplyBtn.disabled = true;
        els.discoveryStatus.textContent = "";
        renderDiscoveryEvidence();
        return;
    }

    const label = modelCommercialName(model);
    els.discoveryModelSummary.textContent = `${label} · ${model.supplier || ""} · ${deviceTypeLabel(modelDeviceType(model))}`;
    const devices = state.settingsModal.discoveryDeviceOptions || [];
    els.discoveryDeviceSelect.innerHTML = devices.length
        ? devices.map((device) => `<option value="${esc(device.imei)}">${esc(device.imei)}${device.lastSeenAt ? ` · ${esc(device.lastSeenAt)}` : ""}</option>`).join("")
        : '<option value="">Nenhum dispositivo online encontrado</option>';
    els.discoveryDeviceSelect.disabled = devices.length === 0;
    els.discoveryDeviceSelect.value = state.settingsModal.discoveryDeviceImei || "";
    const selectedImei = state.settingsModal.discoveryDeviceImei || "";
    els.discoveryGenerateBtn.disabled = state.settingsModal.discoveryLoading || selectedImei === "";
    els.discoveryApplyBtn.disabled = state.settingsModal.discoveryLoading || !state.settingsModal.discoveryRun || state.settingsModal.discoveryRun.status !== "draft";

    if (state.settingsModal.discoveryLoading) {
        els.discoveryStatus.textContent = "A gerar proposta de capacidades...";
    } else if (state.settingsModal.discoveryError) {
        els.discoveryStatus.textContent = state.settingsModal.discoveryError;
    } else if (state.settingsModal.discoveryRun) {
        const changes = state.settingsModal.discoveryRun.changes || { add: [], remove: [] };
        els.discoveryStatus.textContent = `Proposta pronta: +${(changes.add || []).length} / -${(changes.remove || []).length}`;
    } else {
        els.discoveryStatus.textContent = "Selecione um dispositivo online e gere uma proposta.";
    }

    renderDiscoveryEvidence();
}

async function generateDiscoveryPreview() {
    const model = state.settingsModal.currentCapabilitiesModel;
    const imei = state.settingsModal.discoveryDeviceImei || "";
    if (!model || !imei) {
        state.settingsModal.discoveryError = "Selecione um modelo e um dispositivo online.";
        renderDiscoverySection();
        return;
    }

    state.settingsModal.discoveryError = "";
    state.settingsModal.discoveryLoading = true;
    renderDiscoverySection();

    const response = await apiPreviewCapabilityDiscovery({
        modelId: Number(model.id || 0),
        imei,
    });
    state.settingsModal.discoveryLoading = false;
    if (response.error) {
        state.settingsModal.discoveryRun = null;
        state.settingsModal.discoveryError = response.error.message || response.error.code || "Erro ao gerar a proposta.";
        renderDiscoverySection();
        return;
    }

    state.settingsModal.discoveryRun = response;
    state.settingsModal.discoveryError = "";
    renderDiscoverySection();
}

async function applyDiscoveryPreview() {
    const model = state.settingsModal.currentCapabilitiesModel;
    const run = state.settingsModal.discoveryRun;
    if (!model || !run?.id) {
        return;
    }

    state.settingsModal.discoveryLoading = true;
    renderDiscoverySection();

    const response = await apiApplyCapabilityDiscoveryRun(run.id);
    state.settingsModal.discoveryLoading = false;
    if (response.error) {
        state.settingsModal.discoveryError = response.error.message || response.error.code || "Erro ao aplicar a proposta.";
        renderDiscoverySection();
        return;
    }

    state.settingsModal.discoveryRun = response;
    state.settingsModal.discoveryError = "";
    const refreshed = await apiGetModel(model.id);
    state.settingsModal.currentCapabilitiesModel = refreshed.data || refreshed;
    state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(
        state.settingsModal.currentCapabilitiesModel.capabilities || {},
    );
    state.settingsModal.capabilityRequestableCapabilities = Array.isArray(
        state.settingsModal.currentCapabilitiesModel.requestableCapabilities,
    )
        ? state.settingsModal.currentCapabilitiesModel.requestableCapabilities.map(String)
        : [];
    renderCapabilitiesSection();
    renderDiscoverySection();
}

function handleDiscoveryDeviceChange(event) {
    state.settingsModal.discoveryDeviceImei = String(event.target.value || "");
    renderDiscoverySection();
}

async function openModelDetail(modelId) {
    const response = await apiGetModel(modelId);
    const model = response.data || response;
    await ensureCapabilityCatalog(
        model.device_type || model.deviceType || "watch",
    );

    state.settingsModal.currentCapabilitiesModel = model;
    state.settingsModal.capabilityModelId = Number(model.id);
    state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(
        model.capabilities || {},
    );
    state.settingsModal.capabilityRequestableCapabilities = Array.isArray(
        model.requestableCapabilities,
    )
        ? model.requestableCapabilities.map(String)
        : [];
    state.settingsModal.discoveryRun = null;
    state.settingsModal.discoveryError = "";
    state.settingsModal.discoveryDeviceOptions = [];
    state.settingsModal.discoveryDeviceImei = "";

    const supplierId = Number(model.supplier_id || model.supplierId || 0);
    const deviceType = model.device_type || model.deviceType || "watch";
    state.settingsModal.capabilityModelTemplateKeys = [];
    if (supplierId) {
        const tmpl = await apiGetModelTemplate({ supplierId, deviceType });
        if (!tmpl.error && Array.isArray(tmpl.enabledCapabilities)) {
            state.settingsModal.capabilityModelTemplateKeys =
                tmpl.enabledCapabilities.map(String);
        }
    }
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

    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbCurrent.textContent = modelCommercialName(model);
    els.modelsBreadcrumbCurrent.classList.remove("d-none");
    els.modelsBreadcrumbCurrent.classList.add("active");

    await ensureModelDetailSuppliers();
    renderModelDetailInfo(model);
    renderCapabilitiesSection();
    renderDiscoverySection();
    void loadDiscoveryDevices();

    if (state.settingsModal.modelsCarousel) {
        state.settingsModal.modelsCarousel.to(2);
    }
}

function renderModelDetailInfo(model) {
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

    // A fotografia do estado limpo, para se saber se algo mudou sem comparar campo a
    // campo espalhado por quem trata cada evento.
    state.settingsModal.modelDetailPristine = readModelDetailFields();
    syncModelDetailDirty();
    void renderModelDetailDeleteHint(model);
}

/**
 * Os fornecedores com o seu id, que e o que o `supplier_id` do modelo precisa.
 *
 * Vem do separador dos fornecedores quando esse ja foi aberto; caso contrario carrega-se
 * aqui, porque o detalhe de um modelo alcanca-se sem passar por lá.
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
    return {
        commercialName: String(els.modelDetailCommercialName?.value || "").trim(),
        internalModel: String(els.modelDetailInternalModel?.value || "").trim(),
        supplier: String(els.modelDetailSupplierSelect?.value || ""),
        deviceType: String(els.modelDetailDeviceType?.value || ""),
    };
}

/** O "Guardar" aparece por diferença: sem alteração nao ha botao para premir. */
function syncModelDetailDirty() {
    const pristine = state.settingsModal.modelDetailPristine;
    if (!pristine || !els.modelDetailSaveBtn) return;
    const current = readModelDetailFields();
    const dirty = Object.keys(pristine).some((key) => pristine[key] !== current[key]);

    els.modelDetailSaveBtn.classList.toggle("d-none", !dirty);
    els.modelDetailResetBtn.classList.toggle("d-none", !dirty);
    els.modelDetailDirtyState.classList.toggle("d-none", dirty);
}

function resetModelDetailFields() {
    const pristine = state.settingsModal.modelDetailPristine;
    if (!pristine) return;
    els.modelDetailCommercialName.value = pristine.commercialName;
    els.modelDetailInternalModel.value = pristine.internalModel;
    els.modelDetailSupplierSelect.value = pristine.supplier;
    els.modelDetailDeviceType.value = pristine.deviceType;
    syncModelDetailDirty();
}

/**
 * Quantos dispositivos usam este modelo.
 *
 * A consequencia de apagar escrita ao lado do botao, e nao depois de se premir: o
 * endpoint de dispositivos ja filtra por modelo, por isso e a paginacao que da o total.
 */
async function renderModelDetailDeleteHint(model) {
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

    const refreshed = await apiGetModel(model.id);
    if (!refreshed?.error && refreshed?.model) {
        state.settingsModal.currentCapabilitiesModel = refreshed.model;
        renderModelDetailInfo(refreshed.model);
    }
    state.settingsModal.sectionLoaded.models = false;
}

function backToModelList() {
    if (!state.settingsModal.modelsCarousel) return;

    if (
        state.settingsModal.modelsCarousel._element.querySelector(
            ".carousel-item.active",
        ) ===
        state.settingsModal.modelsCarousel._element.firstElementChild
            ?.firstElementChild
    ) {
        return;
    }

    els.modelsBreadcrumbModels.classList.add("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbNew.classList.remove("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");
    els.modelsBreadcrumbCurrent.textContent = "";

    state.settingsModal.modelsCarousel.to(0);

    state.settingsModal.currentCapabilitiesModel = null;
    state.settingsModal.capabilityModelTemplateKeys = [];
    state.settingsModal.discoveryDeviceOptions = [];
    state.settingsModal.discoveryDeviceImei = "";
    state.settingsModal.discoveryRun = null;
    state.settingsModal.discoveryError = "";
    state.settingsModal.sectionLoaded.models = false;
    state.settingsModal.sectionLoaded.modelFilters = false;
    void loadSettingsModelsSection();
    renderDiscoverySection();
}

async function openNewModelForm() {
    if (!state.settingsModal.sectionLoaded.modelFilters) {
        await loadSettingsModelFilters();
    }
    resetModelForm();
    await refreshNewModelCapabilityTemplate();

    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.textContent = "Novo modelo";
    els.modelsBreadcrumbNew.classList.remove("d-none");
    els.modelsBreadcrumbNew.classList.add("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");

    if (state.settingsModal.modelsCarousel) {
        state.settingsModal.modelsCarousel.to(1);
    }
}

async function refreshNewModelCapabilityTemplate() {
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

// O `editCurrentModel` saiu com o botao "Editar": os campos do detalhe sao os controlos,
// e guardam-se ali. O formulario do carrossel fica so para criar um modelo novo.

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
    backToModelList();
}

function renderCapabilitiesSection() {
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
    const catalogSections = capabilitiesGroupedBySection(
        state.settingsModal.capabilityCatalog,
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
    const templateSet = new Set(templateKeys);

    els.capabilitySubtitle.textContent =
        String(model?.supplier || "") +
        (templateKeys.length > 0
            ? ` — ${templateKeys.length} capacidades do template`
            : "");

    const sections = catalogSections
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

    els.capabilitySectionNav.innerHTML = sections
        .map(({ section, label, entries }) => {
            const icon = SECTION_ICONS[section] || "fa-gear";
            const isActive = section === activeSection;
            const active = (entries || []).filter((feature) => enabled.has(feature)).length;
            return `
        <button type="button" class="btn btn-sm ${isActive ? "btn-primary" : "btn-outline-secondary row-action"} d-inline-flex align-items-center gap-2" data-action="jumpCapabilitySection" data-section="${esc(section)}">
            <i class="fa-solid ${icon}"></i>${esc(label)}
            <span class="badge rounded-pill ${isActive ? "text-bg-light" : "text-bg-secondary"}">${active}</span>
        </button>`;
        })
        .join("");

    const section = sections.find((s) => s.section === activeSection);
    if (section) {
        els.capabilityGroups.innerHTML = `
        <section class="border rounded-3 p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-label">${esc(section.label)}</div>
                <span class="small text-secondary">${section.entries.filter((f) => enabled.has(f)).length}/${section.entries.length} ativos</span>
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
                        // "Apenas receção" era um badge e "Solicitável" um interruptor,
                        // para a mesma pergunta: o modelo aceita pedido? Sao sempre dois
                        // interruptores, na mesma posicao. Quando o fornecedor nao suporta
                        // pedido, o segundo fica desligado com a razao na etiqueta -- em
                        // vez de trocar de tipo de controlo.
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
    ensureCapabilityCatalog,
    initSettingsCapabilities,
    loadSettingsCapabilitiesSection,
    handleCapabilitySupplierClick,
    handleCapabilityCatalogSearch,
    handleDiscoveryDeviceChange,
    loadDiscoveryDevices,
    generateDiscoveryPreview,
    applyDiscoveryPreview,
    renderDiscoverySection,
    selectCapabilitySupplier,
    openModelDetail,
    backToModelList,
    openNewModelForm,
    deleteCurrentModel,
    saveModelDetail,
    syncModelDetailDirty,
    resetModelDetailFields,
    renderCapabilitiesSection,
    revokeModelPreviewUrl,
    saveCapabilities,
    refreshNewModelCapabilityTemplate,
};
