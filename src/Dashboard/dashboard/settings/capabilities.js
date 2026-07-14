import {
    getDevices as apiGetDevices,
    deleteModel as apiDeleteModel,
    getCapabilities as apiGetCapabilities,
    applyCapabilityDiscoveryRun as apiApplyCapabilityDiscoveryRun,
    previewCapabilityDiscovery as apiPreviewCapabilityDiscovery,
    getModel as apiGetModel,
    getModelFilters as apiGetModelFilters,
    getModelTemplate as apiGetModelTemplate,
    saveModel as apiSaveModel,
} from "../api/index.js";
import {state} from "../state.js";
import {esc} from "../format.js";
import {modelImageHtml, renderButtonGroup} from "../renderers.js";
import {
    capabilitiesGroupedBySection,
    capabilityCatalogEntryByKey,
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
    editModel,
    resetModelForm,
    revokeModelPreviewUrl,
    loadSettingsModelsSection,
} from "./models/index.js";

let els;

const SECTION_TRANSLATIONS = {
    telemetry: "Telemetria",
    health: "Saúde",
    contacts: "Contactos",
    alarms: "Alertas",
    settings_system: "Sistema",
};

const CAPABILITY_LABEL_TRANSLATIONS = {
    positions: "Posições",
    vitals: "Sinais vitais",
    position_minute_stats: "Estatísticas de posições por minuto",
    vitals_minute_stats: "Estatísticas de sinais vitais por minuto",
    location: "Localização",
    heart_rate: "Frequência cardíaca",
    blood_pressure: "Pressão arterial",
    blood_oxygen: "Oxigénio no sangue",
    temperature: "Temperatura",
    breath_rate: "Frequência respiratória",
    sleep: "Sono",
    ecg: "ECG",
    hrv: "VFC",
    ppg: "PPG",
    rr_interval: "Intervalo RR",
    auto_vitals_interval: "Vitais automáticos",
    heart_rate_measurement_interval: "Frequência cardíaca",
    blood_pressure_measurement_interval: "Pressão arterial",
    blood_oxygen_measurement_interval: "Oxigénio no sangue",
    temperature_measurement_interval: "Temperatura",
    breath_rate_measurement_interval: "Frequência respiratória",
    ecg_measurement_interval: "ECG",
    hrv_measurement_interval: "VFC",
    ppg_measurement_interval: "PPG",
    rr_interval_measurement_interval: "Intervalo RR",
    heart_rate_continuous: "Frequência cardíaca contínua",
    blood_oxygen_continuous: "Oxigénio no sangue contínuo",
    blood_pressure_trend: "Tendência de pressão arterial",
    temperature_continuous: "Temperatura contínua",
    step_goal: "Meta de passos",
    sleep_monitoring: "Sono",
    blood_pressure_calibration: "Calibração de pressão arterial",
    step_reporting_interval: "Passos",
    pedometer_schedule: "Pedómetro",
    sos_contacts: "Contactos SOS",
    phonebook: "Contactos",
    call_whitelist: "Chamadas permitidas",
    monitor_number: "Número de monitorização",
    alarm_clock: "Alarmes",
    medication_reminders: "Lembretes de medicação",
    low_battery_alert: "Bateria fraca",
    fall_detection: "Quedas",
    fall_sensitivity: "Sensibilidade de queda",
    sos_sms_alert: "SOS SMS",
    blood_oxygen_alert: "Oxigénio no sangue",
    temperature_high_alert: "Temperatura alta",
    temperature_low_alert: "Temperatura baixa",
    blood_pressure_alert: "Pressão arterial",
    heart_rate_high_alert: "Frequência cardíaca alta",
    heart_rate_low_alert: "Frequência cardíaca baixa",
    remove_watch_alarm: "Remoção do relógio",
    remove_watch_sms_alert: "SMS de remoção do relógio",
    location_reporting_interval: "Localização",
    working_mode: "Modo de funcionamento",
    device_binding: "Vincular dispositivo",
    call_in_restriction: "Chamadas recebidas",
    device_settings_sync: "Sincronizar definições",
    device_password: "Palavra-passe",
    language_timezone: "Idioma e fuso horário",
};

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
    renderButtonGroup(
        els.capabilityDeviceTypeButtons,
        deviceTypeOptions,
        state.settingsModal.capabilityDeviceType || "watch",
        "selectCapabilityDeviceType",
    );

    const supplierId = state.settingsModal.capabilitySupplier;
    const enabledKeys = state.settingsModal.capabilityTemplateEnabledKeys;
    const enabledSet = new Set(enabledKeys);
    const hasSupplierFilter = !!supplierId && enabledKeys.length > 0;

    renderCapabilitySupplierButtons();
    updateCapabilitySupplierSummary(hasSupplierFilter);

    const sections = capabilitiesGroupedBySection(
        state.settingsModal.capabilityCatalog,
    );
    els.capabilityCatalogEmpty.classList.toggle("d-none", sections.length > 0);
    els.capabilityCatalogViewer.innerHTML = sections
        .map(
            ({ section, label, entries }) => `
        <section class="border rounded bg-body-tertiary p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h6 mb-0">${esc(label)}</h3>
                <span class="small text-secondary">${entries.length} capacidades</span>
            </div>
            <div class="vstack gap-2">
                ${entries
                    .map((entry) => {
                        const supported = hasSupplierFilter
                            ? enabledSet.has(entry.key)
                            : true;
                        return `
                    <div class="border rounded px-3 py-2 bg-white ${!supported ? "opacity-50" : ""}">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">${esc(entry.label || humanizeCapabilityKey(entry.key))}</div>
                                <div class="small text-secondary">${esc(entry.key)}</div>
                            </div>
                            ${hasSupplierFilter ? `<span class="badge ${supported ? "text-bg-success" : "text-bg-secondary"} mt-1 flex-shrink-0">${supported ? "suportado" : "não suportado"}</span>` : ""}
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <span class="badge text-bg-${entry.isTelemetry ? "info" : "secondary"}">${entry.isTelemetry ? "telemetria" : "configuração"}</span>
                            <span class="badge text-bg-${entry.isConfigurable ? "primary" : "secondary"}">${entry.isConfigurable ? "configurável" : "não configurável"}</span>
                            <span class="badge text-bg-${entry.isRequestable ? "success" : "secondary"}">${entry.isRequestable ? "solicitável" : "não solicitável"}</span>
                        </div>
                    </div>
                `;
                    })
                    .join("")}
            </div>
        </section>
    `,
        )
        .join("");
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

    if (supplier) {
        const total = state.settingsModal.capabilityCatalog.length;
        const supported = state.settingsModal.capabilityTemplateEnabledKeys.length;
        els.capabilitySupplierSummary.textContent =
            `A mostrar capacidades suportadas por ${supplier.name}: ${supported} de ${total} capacidades.`;
    } else {
        els.capabilitySupplierSummary.textContent = "";
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
                    <span class="badge text-bg-success">+${(changes.add || []).length}</span>
                    <span class="badge text-bg-secondary">-${(changes.remove || []).length}</span>
                    <span class="badge ${run.status === "applied" ? "text-bg-primary" : "text-bg-warning"}">${esc(run.status || "draft")}</span>
                </div>
            </div>
            <div class="small text-secondary mt-2">${esc((run.currentEnabledCapabilityKeys || []).length)} capacidades atuais · ${(run.suggestedEnabledCapabilityKeys || []).length} sugeridas</div>
        </div>
        <div class="vstack gap-2">
            ${evidence.slice(0, 12).map((entry) => `
                <div class="d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-2 bg-white">
                    <div>
                        <div class="fw-semibold">${esc(entry.label || entry.key || "")}</div>
                        <div class="small text-secondary">${esc(entry.key || "")} · ${esc(entry.section || "")}</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge ${entry.supported ? "text-bg-success" : "text-bg-secondary"}">${entry.supported ? "suportado" : "não suportado"}</span>
                        <span class="badge ${entry.configured ? "text-bg-primary" : "text-bg-light border"}">${entry.configured ? "no modelo" : "não configurado"}</span>
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

    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.classList.add("d-none");
    els.modelsBreadcrumbCurrent.textContent = modelCommercialName(model);
    els.modelsBreadcrumbCurrent.classList.remove("d-none");
    els.modelsBreadcrumbCurrent.classList.add("active");

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
    els.modelDetailImage.innerHTML = modelImageHtml(model)
        ? modelImageHtml(model).replace(
              'style="width:40px;height:40px;"',
              'style="max-height:120px;" class="object-fit-contain"',
          )
        : `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
    els.modelDetailName.textContent = label;
    els.modelDetailTitle.textContent = label;
    els.modelDetailSupplier.textContent = String(model.supplier || "");
    els.modelDetailSupplierValue.textContent = String(model.supplier || "");
    els.modelDetailTypeValue.textContent = deviceTypeLabel(modelDeviceType(model));
    els.modelDetailInternalModelValue.textContent = modelInternalName(model);
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

async function editCurrentModel() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) return;
    if (!state.settingsModal.sectionLoaded.modelFilters) {
        await loadSettingsModelFilters();
    }

    editModel(
        Number(model.id),
        Number(model.supplier_id || model.supplierId || 0),
        model.supplier || "",
        model.internal_model || model.internalModel || "",
        model.commercial_name || model.commercialName || "",
        model.device_type || model.deviceType || "watch",
        model.image || "",
    );

    els.modelsBreadcrumbModels.classList.remove("active");
    els.modelsBreadcrumbNew.textContent = `Editar: ${modelCommercialName(model)}`;
    els.modelsBreadcrumbNew.classList.remove("d-none");
    els.modelsBreadcrumbNew.classList.add("active");
    els.modelsBreadcrumbCurrent.classList.add("d-none");
    els.modelsBreadcrumbCurrent.classList.remove("active");

    if (state.settingsModal.modelsCarousel) {
        state.settingsModal.modelsCarousel.to(1);
    }
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
    backToModelList();
}

function renderCapabilitiesSection() {
    const model = state.settingsModal.currentCapabilitiesModel;
    const enabled = new Set(
        state.settingsModal.capabilityEnabledCapabilities || [],
    );
    const catalogSections = capabilitiesGroupedBySection(
        state.settingsModal.capabilityCatalog,
    );

    if (model) {
        const label = modelCommercialName(model);
        els.modelDetailImage.innerHTML = modelImageHtml(model)
            ? modelImageHtml(model).replace(
                  'style="width:40px;height:40px;"',
                  'style="max-height:120px;" class="object-fit-contain"',
              )
            : `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
        els.modelDetailName.textContent = label;
    } else {
        els.modelDetailImage.innerHTML = `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">Modelo</div></div>`;
        els.modelDetailName.textContent = "Modelo";
    }

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
                .filter((entry) => entry.isTelemetry || entry.isConfigurable)
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
    els.capabilitySummary.textContent = `${enabled.size}/${totalCapabilities} ativos`;

    const sectionButtonConfig = {
        telemetry: { icon: "fa-chart-line", color: "btn-outline-info" },
        health: { icon: "fa-heart-pulse", color: "btn-outline-success" },
        contacts: { icon: "fa-address-book", color: "btn-outline-primary" },
        alarms: { icon: "fa-bell", color: "btn-outline-danger" },
        settings_system: { icon: "fa-gear", color: "btn-outline-secondary" },
    };

    let activeSection = state.settingsModal.activeCapabilitySection;
    if (!activeSection || !sections.some((s) => s.section === activeSection)) {
        activeSection = sections[0]?.section || "";
        state.settingsModal.activeCapabilitySection = activeSection;
    }

    els.capabilitySectionNav.innerHTML = sections
        .map(({ section, label }) => {
            const cfg = sectionButtonConfig[section] || {
                icon: "fa-gear",
                color: "btn-secondary",
            };
            const isActive = section === activeSection;
            const ptLabel = SECTION_TRANSLATIONS[section] || label;
            return `
        <button type="button" class="btn btn-sm flex-fill ${cfg.color} ${isActive ? "active" : ""} d-flex align-items-center justify-content-center gap-2" data-action="jumpCapabilitySection" data-section="${esc(section)}">
            <i class="fa-solid ${cfg.icon}"></i> ${esc(ptLabel)}
        </button>`;
        })
        .join("");

    const section = sections.find((s) => s.section === activeSection);
    if (section) {
        const sectionLabel = SECTION_TRANSLATIONS[section.section] || section.label;
        els.capabilityGroups.innerHTML = `
        <section class="border rounded bg-body-tertiary p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h6 mb-0">${esc(sectionLabel)}</h3>
                <span class="small text-secondary">${section.entries.filter((f) => enabled.has(f)).length}/${section.entries.length} ativos</span>
            </div>
            <div class="d-flex flex-column gap-2">
                ${section.entries
                    .map((feature) => {
                        const labelText =
                            CAPABILITY_LABEL_TRANSLATIONS[feature] ||
                            capabilityLabelByKey(feature);
                        const sectionState = capabilities[section.section] || {};
                        const isInModelPayload = Object.prototype.hasOwnProperty.call(
                            sectionState,
                            feature,
                        );
                        return `
                        <div class="d-flex justify-content-between align-items-start gap-3 border rounded px-3 py-2 bg-white">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilityRequest" data-feature="${esc(feature)}" id="cap-${esc(feature)}" ${enabled.has(feature) ? "checked" : ""}>
                                <label class="form-check-label" for="cap-${esc(feature)}">${esc(labelText)}</label>
                                ${!isInModelPayload ? '<div class="small text-secondary">Disponível no catálogo do tipo de dispositivo.</div>' : ""}
                            </div>
                            ${
                                section.section === "telemetry" &&
                                capabilityCatalogEntryByKey(feature)?.isRequestable
                                    ? `<span class="badge bg-success bg-opacity-10 text-success px-3 py-2 fw-medium">${esc("Solicitável")}</span>`
                                    : ""
                            }
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
    handleDiscoveryDeviceChange,
    loadDiscoveryDevices,
    generateDiscoveryPreview,
    applyDiscoveryPreview,
    renderDiscoverySection,
    selectCapabilitySupplier,
    openModelDetail,
    backToModelList,
    openNewModelForm,
    editCurrentModel,
    deleteCurrentModel,
    renderCapabilitiesSection,
    revokeModelPreviewUrl,
    saveCapabilities,
    refreshNewModelCapabilityTemplate,
};
