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
 * O editor das capacidades de um modelo: que capacidades suporta, e quais se podem pedir ao
 * aparelho. A vista de leitura do catálogo de um tipo vive no `settings/capabilities.js`.
 */

/**
 * As secções da ficha e as chaves de cada uma: as do template do fornecedor, ou sem ele as que
 * o modelo tem ligadas -- e então desligar uma tira-lhe a linha.
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

/** Quantas destas estão ligadas: é o número da pastilha de cada secção. */
const activeCount = (entries, enabled) =>
    (entries || []).filter((feature) => enabled.has(feature)).length;

/** O resumo do topo é a soma das pastilhas todas, e tem de dar o mesmo que elas. */
const capabilitySummaryText = (sections, enabled) =>
    `${sections.reduce((total, item) => total + activeCount(item.entries, enabled), 0)}` +
    `/${sections.reduce((total, item) => total + item.entries.length, 0)} ativos`;

/** O template traz também as acções, que só se pedem ao aparelho e não se ligam aqui. */
function capabilitySubtitleText(supplier, templateCount, shownCount) {
    if (templateCount === 0) return supplier;
    const actions = templateCount - shownCount;
    if (actions <= 0) {
        return `${supplier} — ${templateCount} capacidades no template.`;
    }
    return `${supplier} — ${templateCount} capacidades no template: ${shownCount} ligam-se aqui e` +
        ` ${actions} ${actions === 1 ? "é uma ação, que só se pede" : "são ações, que só se pedem"} ao aparelho.`;
}

/**
 * O que o protocolo do fornecedor permite pedir, dito uma vez para a secção inteira em vez
 * de repetido por baixo de cada capacidade.
 */
function telemetryProtocolNote(supplier, entries, protocolRequestable) {
    const requestable = entries.filter((feature) => protocolRequestable.has(feature)).length;
    if (requestable === 0) {
        return `O ${supplier} envia estas leituras, mas não aceita que lhe sejam pedidas.`;
    }
    if (requestable === entries.length) {
        return `O ${supplier} envia estas leituras, e todas podem também ser pedidas.`;
    }
    return `O ${supplier} envia estas leituras; ${requestable} ${requestable === 1 ? "delas também pode ser pedida" : "delas também podem ser pedidas"}.`;
}

/**
 * Acerta no sítio em vez de redesenhar, para não tirar o foco ao interruptor. Sem template a
 * linha desaparece ao desligar, e aí quem chama redesenha.
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

    els.capabilitySummary.textContent = capabilitySummaryText(sections, enabled);

    for (const { section, entries } of sections) {
        const badge = els.capabilitySectionNav.querySelector(
            `[data-section="${CSS.escape(section)}"] [data-section-count]`,
        );
        if (badge) badge.textContent = String(activeCount(entries, enabled));
    }

    const visible = sections.find(
        (item) => item.section === state.settingsModal.activeCapabilitySection,
    );
    const groupCount = els.capabilityGroups.querySelector("[data-section-count]");
    if (visible && groupCount) {
        groupCount.textContent =
            `${activeCount(visible.entries, enabled)}/${visible.entries.length} ativos`;
    }
}

/** Diz se desligar uma capacidade lhe tira a linha, e obriga por isso a redesenhar. */
function capabilityRowsDependOnSelection() {
    return (state.settingsModal.capabilityModelTemplateKeys || []).length === 0;
}

/**
 * Os dois interruptores de uma linha, num ouvinte delegado na raiz das secções. Desligar o
 * suporte desliga o pedido com ele.
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

    const sections = capabilitySections(enabled);
    els.capabilitySubtitle.textContent = capabilitySubtitleText(
        String(model?.supplier || ""),
        templateKeys.length,
        sections.reduce((total, item) => total + item.entries.length, 0),
    );

    els.capabilitySummary.textContent = capabilitySummaryText(sections, enabled);

    let activeSection = state.settingsModal.activeCapabilitySection;
    if (!activeSection || !sections.some((s) => s.section === activeSection)) {
        activeSection = sections[0]?.section || "";
        state.settingsModal.activeCapabilitySection = activeSection;
    }

    els.capabilitySectionNav.innerHTML = sectionStrip(
        sections.map(({ section, label, entries }) => ({
            key: section,
            label,
            count: activeCount(entries, enabled),
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
                // São sempre dois interruptores, na mesma posição. Quando o fornecedor não
                // suporta pedido, o segundo fica desligado; porquê está no cabeçalho.
                const requestableSwitch = section.section !== "telemetry"
                    ? ""
                    : html`<div class="form-check form-switch mb-0 flex-shrink-0 text-nowrap ms-4 ms-sm-0">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilityRequestability" data-feature="${feature}" id="requestable-${feature}" ${canBeRequested && requestable.has(feature) ? "checked" : ""} ${canBeRequested && enabled.has(feature) ? "" : "disabled"}>
                                <label class="form-check-label small" for="requestable-${feature}">Solicitável</label>
                               </div>`;
                // Só a linha que diz algo que a posição não diz: esta capacidade ainda não
                // está no modelo, e vem do catálogo do tipo.
                const description = isInModelPayload
                    ? ""
                    : html`<div class="section-label">Disponível no catálogo do tipo de dispositivo.</div>`;
                // Num telefone os dois interruptores empilham-se: lado a lado, «Solicitável»
                // e o nome da capacidade não cabem numa calha de 330px.
                return html`
                        <div class="capability-model-row d-flex flex-column flex-sm-row justify-content-sm-between align-items-start gap-2 gap-sm-3 border rounded-3 px-3 py-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilitySupport" data-feature="${feature}" id="cap-${feature}" ${enabled.has(feature) ? "checked" : ""}>
                                <label class="form-check-label" for="cap-${feature}">${labelText}</label>
                                ${raw(description)}
                            </div>
                            ${raw(requestableSwitch)}
                        </div>`;
            })
            .join("");

        const note = section.section === "telemetry"
            ? html`<div class="small text-secondary mb-3" data-section-note>${telemetryProtocolNote(String(model?.supplier || "protocolo"), section.entries, protocolRequestable)}</div>`
            : "";

        els.capabilityGroups.innerHTML = html`
        <section class="border rounded-3 p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-label">${section.label}</div>
                <span class="small text-secondary" data-section-count>${section.entries.filter((f) => enabled.has(f)).length}/${section.entries.length} ativos</span>
            </div>
            ${raw(note)}
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
