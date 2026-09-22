import {
    saveModel as apiSaveModel,
} from "../../api/index.js";
import { state } from "../../state.js";
import { html, raw } from "../../html.js";
import { apiError, toast } from "../../dialogs.js";
import { sectionStrip } from "../../components/chips.js";
import { modelPreviewHtml } from "../../components/model-image.js";
import {
    capabilitiesGroupedBySection,
    capabilityLabelByKey,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
} from "../../domain.js";
import { CAPABILITY_SECTION_ICONS } from "../../capability-catalog.js";
import { getSettingsModelsRuntime } from "./shell.js";
import { backToModelList } from "./list.js";

/**
 * O editor das capacidades de um modelo, na metade de baixo da ficha: que capacidades o
 * modelo suporta, e quais delas se podem pedir ao aparelho em vez de só esperar por elas.
 *
 * Desenhar, acertar nos interruptores e gravar ficam juntos porque leem e escrevem o mesmo
 * estado -- as capacidades ligadas e as solicitáveis -- que não é de mais ninguém. A vista de
 * leitura do catálogo de um tipo de dispositivo é outra coisa, e vive no `settings/capabilities.js`.
 */

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
 * Acerta no sítio em vez de redesenhar a secção, que tirava o foco ao interruptor acabado de
 * premir. Sem template do fornecedor a linha desaparece ao desligar, e aí quem chama redesenha.
 */
function syncCapabilitySwitches(feature) {
    const { els } = getSettingsModelsRuntime();
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
        `${sections.reduce((total, item) => total + countOf(item.entries), 0)}` +
        `/${sections.reduce((total, item) => total + item.entries.length, 0)} ativos`;

    for (const { section, entries } of sections) {
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

/**
 * Os dois interruptores de uma linha, num ouvinte só delegado na raiz das secções. Desligar o
 * suporte desliga o pedido com ele: pedir uma leitura que o modelo não oferece não é estado
 * que se possa guardar.
 */
function handleCapabilityGroupsChange(event) {
    const checkbox = event.target.closest([
        "[data-action=\"toggleCapabilitySupport\"]",
        "[data-action=\"toggleCapabilityRequestability\"]",
    ].join(","));
    if (!checkbox) return;

    const feature = String(checkbox.dataset.feature || "");
    if (!feature) return;

    const enabled = new Set(
        state.settingsModal.capabilityEnabledCapabilities || [],
    );
    const requestable = new Set(
        state.settingsModal.capabilityRequestableCapabilities || [],
    );
    if (checkbox.dataset.action === "toggleCapabilitySupport") {
        if (checkbox.checked) {
            enabled.add(feature);
        } else {
            enabled.delete(feature);
            requestable.delete(feature);
        }
    } else {
        if (checkbox.checked && enabled.has(feature)) {
            requestable.add(feature);
        } else {
            requestable.delete(feature);
        }
    }
    state.settingsModal.capabilityEnabledCapabilities = [...enabled];
    state.settingsModal.capabilityRequestableCapabilities = [...requestable];
    if (capabilityRowsDependOnSelection()) {
        renderCapabilitiesSection();
        return;
    }
    syncCapabilitySwitches(feature);
}

/** A tira da ficha troca a secção à vista, e por isso redesenha em vez de deslocar. */
function jumpCapabilitySection(event) {
    const button = event.target.closest(
        "[data-action=\"jumpCapabilitySection\"]",
    );
    if (!button) return;

    const section = button.dataset.section;
    if (!section) return;

    state.settingsModal.activeCapabilitySection = section;
    renderCapabilitiesSection();
}

function renderCapabilitiesSection() {
    const { els } = getSettingsModelsRuntime();
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
        const rows = section.entries
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
                        ? html`${String(model?.supplier || "Protocolo")}: ${canBeRequested ? "receção e pedido" : "apenas receção"}`
                        : "";
                const noRequestNote = canBeRequested
                    ? ""
                    : html`<div class="section-label">${String(model?.supplier || "O protocolo")} não suporta pedido</div>`;
                // São sempre dois interruptores, na mesma posição. Quando o fornecedor não
                // suporta pedido, o segundo fica desligado com a razão na etiqueta, em vez
                // de trocar de tipo de controlo.
                const requestableSwitch = section.section !== "telemetry"
                    ? ""
                    : html`<div class="form-check form-switch mb-0 flex-shrink-0 text-nowrap">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilityRequestability" data-feature="${feature}" id="requestable-${feature}" ${canBeRequested && requestable.has(feature) ? "checked" : ""} ${canBeRequested && enabled.has(feature) ? "" : "disabled"}>
                                <label class="form-check-label small" for="requestable-${feature}">Solicitável</label>
                                ${raw(noRequestNote)}
                               </div>`;
                const description = protocolDescription || (!isInModelPayload
                    ? "Disponível no catálogo do tipo de dispositivo."
                    : "Suportada pelo modelo");
                return html`
                        <div class="d-flex justify-content-between align-items-start gap-3 border rounded-3 px-3 py-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilitySupport" data-feature="${feature}" id="cap-${feature}" ${enabled.has(feature) ? "checked" : ""}>
                                <label class="form-check-label" for="cap-${feature}">${labelText}</label>
                                <div class="section-label">${raw(description)}</div>
                            </div>
                            ${raw(requestableSwitch)}
                        </div>`;
            })
            .join("");

        els.capabilityGroups.innerHTML = html`
        <section class="border rounded-3 p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-label">${section.label}</div>
                <span class="small text-secondary" data-section-count>${section.entries.filter((f) => enabled.has(f)).length}/${section.entries.length} ativos</span>
            </div>
            <div class="d-flex flex-column gap-2">
                ${raw(rows)}
            </div>
        </section>`;
    } else {
        els.capabilityGroups.innerHTML = "";
    }
}

async function saveCapabilities() {
    const model = state.settingsModal.currentCapabilitiesModel;
    if (!model) {
        toast("error", "Selecione um modelo");
        return;
    }

    const body = new FormData();
    body.append("supplier_id", String(model.supplier_id));
    body.append("internalModel", String(modelInternalName(model)));
    body.append("commercialName", String(modelCommercialName(model)));
    body.append("deviceType", String(modelDeviceType(model)));
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
        toast("error", apiError(result));
        return;
    }

    backToModelList();
}

export {
    handleCapabilityGroupsChange,
    jumpCapabilitySection,
    renderCapabilitiesSection,
    saveCapabilities,
};
