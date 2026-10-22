import { esc, fieldUnit } from "../../format.js";
import { configText, verbLabel } from "../../i18n.js";
import { emptyPanel } from "../../components/empty-panel.js";
import { stateBadge } from "../../components/state-badge.js";
import {
    configurationDeliveryMeta,
    renderConfigurationDeliveryNotice,
    resolveConfigDelivery,
} from "./delivery.js";
import { CAPABILITY_SECTION_ICONS } from "../../capability-catalog.js";
import { configCatalogSections } from "./catalog-model.js";
import { CONFIG_INPUTS } from "./inputs/index.js";
import { loadedCellsRunoutText } from "./loaded-cells-runout.js";
import { toggleField, toggleValue } from "./inputs/generic.js";
import { jsonInput, readJson } from "./readers.js";
import { catalogForProtocol } from "./protocol-catalog.js";

// O `device-modal.js` chega ao catálogo por aqui, que é a porta do painel tardio.
export { catalogForProtocol };

/** As fases do botão: o que este ecrã fez com o clique; o que o aparelho fez está na pastilha. */
const CONFIG_ACTION_BUTTON_META = {
    idle: {
        icon: "fa-paper-plane",
        label: "Enviar",
        className: "btn-primary",
    },
    submitting: {
        icon: "fa-spinner fa-spin",
        label: "A enviar",
        className: "btn-primary",
    },
    sent: { icon: "fa-check", label: "Enviado", className: "btn-info" },
};

export function renderDeviceConfigurationRoot(context) {
    const {
        protocol,
        catalog,
        configurations = {},
        capabilities = {},
        capabilityCatalog = [],
        configurationSync = { entries: {} },
        disabled = false,
        activeCategory = "",
        uiByKey = {},
        actionDeliveries = {},
        sectionOpen = false,
    } = context;
    if (!protocol) {
        return emptyPanel(
            "Selecione fornecedor e modelo para ver as configurações.",
        );
    }

    if (!catalog.length) {
        return emptyPanel("Este protocolo não tem configurações suportadas.");
    }

    const rowsByKey = configurations;
    const groups = configCatalogSections(protocol, catalog, capabilityCatalog);
    const currentCategory = groups.some((group) => group.key === activeCategory)
        ? activeCategory
        : groups[0]?.key || "";

    return `
        <div class="config-panel d-flex flex-column" data-config-root${sectionOpen ? " data-config-section-open" : ""}>
            <div class="d-flex flex-column flex-md-row flex-grow-1 min-h-0">
                ${sectionList(groups, currentCategory)}
                <div class="config-panel-content tab-content flex-grow-1 min-w-0 min-h-0">
                ${groups
                    .map(
                        (group) => `
                    <div class="tab-pane fade ${group.key === currentCategory ? "show active" : ""}" data-config-pane="${esc(group.key)}">
                        ${sectionBack(group)}
                        ${configRuns(group.entries).map((run) => {
                            if (run.grouped) {
                                return renderConfigGroup(protocol, run.entries, {
                                    rowsByKey, capabilities, disabled, uiByKey, configurationSync,
                                });
                            }
                            return run.entries.map((entry) => {
                                const row = entry.requestOnly
                                    ? null
                                    : resolveConfigRow(entry, rowsByKey);
                                const stored = entry.requestOnly
                                    ? null
                                    : resolveConfigStored(entry, rowsByKey);
                                // O estado de uma acção é o do último pedido que disparou.
                                const delivery = entry.requestOnly
                                    ? actionDeliveries[entry.capabilityKey || entry.key] || null
                                    : resolveConfigDelivery(entry, configurationSync);
                                const uiState = uiByKey[entry.key] || null;
                                return renderConfigSection(
                                    protocol,
                                    entry,
                                    row,
                                    capabilities,
                                    disabled,
                                    uiState,
                                    stored,
                                    delivery,
                                    rowsByKey,
                                );
                            }).join("");
                        }).join("")}
                        ${sectionFooter()}
                    </div>
                `,
                    )
                    .join("")}
                </div>
            </div>
        </div>`;
}

/** A contagem do alterado nasce vazia: escreve-a o painel a partir do DOM. */
function sectionList(groups, currentCategory) {
    return `
        <div class="config-section-nav d-flex flex-column flex-shrink-0" role="group" aria-label="Secções de configuração">
            ${groups.map((group) => `
            <button type="button" class="config-section-link d-flex align-items-center text-start${group.key === currentCategory ? " selected" : ""}"
                    data-action="selectConfigCategory" data-section="${esc(group.key)}" data-config-section-link
                    aria-pressed="${group.key === currentCategory ? "true" : "false"}">
                <i class="fa-solid ${esc(CAPABILITY_SECTION_ICONS[group.key] || "fa-gear")} fa-fw" aria-hidden="true"></i>
                <span class="text-truncate flex-grow-1 min-w-0">${esc(group.label)}</span>
                <span class="count-chip flex-shrink-0" data-config-section-total>${group.entries.length}</span><span class="small flex-shrink-0 text-nowrap" data-config-section-changed></span>
                <i class="config-section-chevron fa-solid fa-chevron-right d-md-none" aria-hidden="true"></i>
            </button>`).join("")}
        </div>`;
}

/** No telemóvel a secção aberta toma o lugar da lista, e é por aqui que se volta a ela. */
function sectionBack(group) {
    return `
        <button type="button" class="config-section-back d-flex d-md-none align-items-center w-100 border-0 border-bottom bg-body text-start fw-semibold" data-action="closeConfigSection">
            <i class="fa-solid fa-chevron-left fa-fw" aria-hidden="true"></i>
            <span class="text-truncate">${esc(group.label)}</span>
            <span class="count-chip flex-shrink-0">${group.entries.length}</span>
        </button>`;
}

/** Nasce desligado; o painel acende-o contando o alterado. */
function sectionFooter() {
    return `
        <div class="config-section-footer position-sticky bottom-0 mt-auto d-flex align-items-center justify-content-between gap-2 flex-wrap border-top bg-body">
            <span class="small text-secondary flex-grow-1 min-w-0 lh-sm" data-config-pane-status></span>
            <span class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-outline-secondary" data-action="resetConfigPane" disabled>Repor</button>
                <button type="button" class="btn btn-primary" data-action="saveConfigPane" data-config-phase="idle" disabled><span class="d-lg-none">Enviar</span><span class="d-none d-lg-inline">Enviar ao dispositivo</span></button>
            </span>
        </div>`;
}

/** Um interruptor que se guarda, e não uma acção nem um campo composto: cabe numa linha. */
function isPlainToggle(entry) {
    return entry.input === "toggle" &&
        entry.transient !== true &&
        entry.requestOnly !== true;
}

/** A mesma definição repetida para grandezas diferentes: o mesmo comando nativo e o mesmo campo. */
function isRepeatedField(entry) {
    return entry.input === "number" &&
        entry.transient !== true &&
        entry.requestOnly !== true &&
        (entry.fields?.length ?? 0) === 1;
}

/** Vazia quando a entrada fica cartão. */
function configRunKind(entry) {
    if (isPlainToggle(entry)) return "toggle";
    if (isRepeatedField(entry)) return `field:${entry.command}:${entry.input}:${entry.fields[0]}`;
    return "";
}

/** Corridas, e não «todos os interruptores da secção»: a ordem do catálogo é editorial. */
function configRuns(entries) {
    const runs = [];
    for (const entry of entries) {
        const kind = configRunKind(entry);
        const last = runs[runs.length - 1];
        if (last && last.kind === kind && kind !== "") {
            last.entries.push(entry);
            continue;
        }
        runs.push({ kind, entries: [entry] });
    }

    // Um interruptor sozinho agrupa; um campo sozinho não, que o cartão magro já é uma linha.
    return runs.map((run) => ({
        ...run,
        grouped: run.kind === "toggle" || (run.kind.startsWith("field:") && run.entries.length > 1),
    }));
}

/** A unidade que o dicionário dá à configuração, a que ela declara, ou a do nome do campo. */
function unitLabel(entry, protocol) {
    const unit = typeof entry.options?.unit === "string" ? entry.options.unit : "";
    return configText(entry.capabilityKey, protocol).unit || unit || fieldUnit(entry.fields?.[0] || "");
}

function unitGroup(control, entry, protocol) {
    const unit = unitLabel(entry, protocol);
    if (control === "") return "";
    return unit === ""
        ? `<div class="config-line-control">${control}</div>`
        : `<div class="input-group flex-nowrap w-auto config-line-control">${control}<span class="input-group-text">${esc(unit)}</span></div>`;
}

function previousValueSummary(entry, desired, isStored, protocol) {
    if (!isStored) return "por enviar pela primeira vez";

    const value = desired?.[entry.fields?.[0] || ""];
    const unit = unitLabel(entry, protocol);
    if (typeof value !== "number" || unit === "") return "alterada e por enviar";

    const upper = entry.input === "valueRange" ? desired?.[entry.fields?.[1] || ""] : undefined;
    return typeof upper === "number" ? `era ${value}–${upper} ${unit}` : `era ${value} ${unit}`;
}

/** As duas leituras desenhadas juntas: o CSS escolhe pelo `data-config-edited`, sem redesenhar. */
function settingState(entry, desired, isStored, deliveryMeta, showBadge, protocol) {
    const previous = previousValueSummary(entry, desired, isStored, protocol);
    // A data acompanha o que se escreve, e por isso não entra no par limpo/alterado.
    const runout = entry.key === "loaded_cells"
        ? `<div class="small text-secondary" data-loaded-runout>${esc(loadedCellsRunoutText(desired?.cells))}</div>`
        : "";

    return {
        summary: `
            <div class="small text-secondary" data-config-summary>
                <span class="config-when-changed text-warning-emphasis">${esc(previous)}</span>
            </div>${runout}`,
        badge: showBadge
            ? `
            <span class="config-when-clean">${stateBadge(deliveryMeta.label, deliveryMeta.tone)}</span>
            <span class="config-when-changed">${stateBadge("Alterado", "warning")}</span>`
            : "",
    };
}

/** Tem de bater certo ao caractere com o `readConfigPayload`, contra o qual a fotografia se compara. */
export function readConfigEntryValue(entry, desired) {
    const field = entry.fields?.[0] || "value";
    const value = desired?.[field] ?? "";
    // Só o que não parece número fica texto, como um fuso com nome.
    return { [field]: Number.isNaN(Number(value)) ? value : Number(value) };
}

function renderConfigGroup(protocol, entries, ctx) {
    const { rowsByKey, capabilities, disabled, uiByKey, configurationSync } = ctx;
    // A legenda comum a toda a corrida sobe ao cabeçalho.
    const help = (entry) => configText(entry.capabilityKey, protocol).help;
    const shared = entries.length > 1 && entries.every((entry) => help(entry) === help(entries[0]))
        ? help(entries[0])
        : "";

    const rows = entries.map((entry) => {
        const capability = capabilityForEntry(entry, capabilities);
        const desired = normalizeDesired(resolveConfigRow(entry, rowsByKey), capability);
        const isToggle = isPlainToggle(entry);
        const field = toggleField(entry);
        const on = toggleValue(entry, desired);
        const stored = resolveConfigStored(entry, rowsByKey);
        const delivery = resolveConfigDelivery(entry, configurationSync);
        const deliveryMeta = configurationDeliveryMeta(stored, delivery);
        const entryHelp = shared === "" ? help(entry) : "";
        const uiState = uiByKey[entry.key] || null;
        const busy = uiState?.phase === "submitting";
        const control = isToggle
            ? `<div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           data-config-field="${esc(field)}" ${on ? "checked" : ""}
                           ${disabled || busy ? "disabled" : ""}
                           aria-label="${esc(entry.label)}">
                </div>`
            : renderConfigControl(entry, desired, { ...(capability?._meta || {}), protocol });
        const pristine = isToggle ? { [field]: on } : readConfigEntryValue(entry, desired);
        const rowState = settingState(entry, desired, stored, deliveryMeta, true, protocol);

        return `
            <div class="config-setting d-flex align-items-center gap-3 px-3 py-2 border-bottom" data-config-row
                 data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey || entry.key)}"
                 data-config-input="${esc(isToggle ? "toggle" : entry.input || "json")}" data-config-stored="${stored ? "1" : "0"}"
                 data-config-protocol="${esc(protocol)}"
                 data-config-pristine='${esc(JSON.stringify(pristine))}'>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">${esc(entry.label)}</div>
                    ${entryHelp ? `<div class="small text-secondary">${esc(entryHelp)}</div>` : ""}
                    ${rowState.summary}
                </div>
                ${rowState.badge}
                ${isToggle ? control : unitGroup(control, entry, protocol)}
            </div>`;
    }).join("");

    return `
        <section data-config-group data-config-protocol="${esc(protocol)}">
            ${shared === ""
                ? ""
                : `<div class="px-3 pt-2 small text-secondary">${esc(shared)}</div>`}
            ${rows}
        </section>`;
}

export function renderConfigSection(
    protocol,
    entry,
    row,
    capabilities = {},
    disabled = false,
    uiState = null,
    stored = null,
    delivery = null,
    relatedConfigurations = {},
) {
    const capability = capabilityForEntry(entry, capabilities);
    const desired = normalizeDesired(row, capability);
    const meta = {
        ...(capability?._meta || {}),
        protocol,
        phonebookContacts: relatedConfigurations.phonebook || [],
    };
    const text = configText(entry.capabilityKey, protocol);
    const verbs = configActionVerbs(entry);
    const isStored = stored ?? (row !== null && Object.keys(row).length > 0);
    const showConfigurationBadge = !entry.requestOnly || delivery !== null;
    // Uma acção nunca guarda valor: a pastilha dela é o estado do último pedido, e não «Padrão».
    const deliveryMeta = configurationDeliveryMeta(isStored || (entry.requestOnly === true && delivery !== null), delivery);
    // Só as destrutivas pedem confirmação, com o efeito que o dicionário dá a este protocolo.
    const confirmText = entry.risk === "destructive" ? (text.confirm || "Confirma esta ação?") : "";
    const confirmAttrs = confirmText === "" ? "" : ` data-config-confirm="${esc(confirmText)}"`;
    // Sem `render` o cartão cabe numa linha; um tipo desconhecido cai no editor de JSON.
    const descriptor = CONFIG_INPUTS[entry.input || "json"];
    const drawsFields = !descriptor || typeof descriptor.render === "function";
    const control = drawsFields ? "" : renderConfigControl(entry, desired, meta);
    // Sem comando nativo, o hub aplica-a sozinho e o botão guarda em vez de enviar.
    const idleVerb = verbLabel(entry.verb || (String(entry.command || "") === "" ? "save" : "send"));
    // Uma definição sai no envio da secção; uma acção só no seu botão, e só se já tiver o que enviar.
    const isCommand = (entry.transient === true || entry.requestOnly === true) &&
        (descriptor?.ready?.() ?? true);
    const commands = verbs.length > 0
        ? renderConfigActionVerbs(verbs, disabled)
        : isCommand
            ? renderConfigActionButton(entry.key, uiState, disabled, confirmText !== "", idleVerb)
            : "";
    const state = settingState(entry, desired, isStored, deliveryMeta, showConfigurationBadge, protocol);
    const fields = drawsFields
        ? (verbs.length > 0 ? "" : renderConfigInputs(entry, desired, meta))
        : unitGroup(control, entry, protocol);
    // Sem campos, o botão sobe à linha do título.
    const inlineCommands = fields.trim() === "" && commands !== "";

    return `
        <section class="config-setting px-3 py-2 border-bottom" data-config-section data-config-stored="${isStored ? "1" : "0"}" data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey)}" data-config-label="${esc(entry.label)}"${confirmAttrs} data-config-input="${esc(entry.input || "json")}"${verbs.length > 0 ? ` data-config-action-field="${esc(entry.fields?.[0] || "enabled")}"` : ""} data-config-protocol="${esc(protocol)}" data-config-limit="${esc(String(entry.limit ?? ""))}"${entry.transient ? " data-config-transient=\"1\"" : ""} data-config-delivery="${esc(String(delivery?.status || ""))}">
            <div class="d-flex gap-2 ${inlineCommands ? "align-items-center" : "align-items-start"}">
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">${esc(entry.label)}</div>
                    ${text.help ? `<div class="small text-secondary">${esc(text.help)}</div>` : ""}
                    ${state.summary}
                </div>
                ${state.badge === "" ? "" : `<div class="flex-shrink-0">${state.badge}</div>`}
                ${inlineCommands ? `<div class="flex-shrink-0 d-flex gap-2">${commands}</div>` : ""}
            </div>
            ${renderConfigurationDeliveryNotice(deliveryMeta, delivery)}
            ${fields.trim() === ""
                ? ""
                : drawsFields
                    ? `
            <form class="mt-2" data-config-form data-config-key="${esc(entry.key)}">
                ${fields}
                ${commands === ""
                    ? ""
                    : `<div class="d-flex justify-content-end gap-2 mt-2">${commands}</div>`}
            </form>`
                    : `
            <div class="d-flex align-items-center justify-content-end gap-2 mt-2">
                ${fields}
                ${commands}
            </div>`}
            ${renderConfigFeedback(entry.key, uiState)}
        </section>`;
}

/** Os verbos de uma acção com dois sentidos, em vez de um interruptor e um «Enviar». */
function configActionVerbs(entry) {
    if (entry.transient !== true) return [];

    const actions = entry.actions || null;
    if (!actions || typeof actions !== "object") return [];

    return ["on", "off"]
        .filter((value) => actions[value])
        .map((value) => ({ value, label: verbLabel(actions[value]) }));
}

function renderConfigActionVerbs(verbs, disabled) {
    return verbs.map((verb, index) => `
        <button type="button" class="btn btn-sm ${index === 0 ? "btn-primary" : "btn-outline-secondary"}"
                data-action="saveConfig" data-action-value="${esc(verb.value)}"
                data-config-phase="idle" ${disabled ? "disabled" : ""}>${esc(verb.label)}</button>`).join("");
}

function renderConfigActionButton(key, uiState, disabled, destructive, idleLabel) {
    const state = configButtonState(uiState);
    const isDisabled = disabled || ["submitting", "sent"].includes(state);
    const meta = CONFIG_ACTION_BUTTON_META[state] || CONFIG_ACTION_BUTTON_META.idle;
    // A partir do clique é a fase que manda na cor.
    const className = state === "idle" && destructive ? "btn-outline-danger" : meta.className;

    return `
        <button type="button" class="btn ${className} btn-sm" data-action="saveConfig" data-config-key="${esc(key)}" data-config-phase="${esc(state)}" ${isDisabled ? "disabled" : ""}>
            <i class="fa-solid ${meta.icon} me-2"></i>${state === "idle" ? esc(idleLabel) : esc(meta.label)}
        </button>`;
}

/** Só falhas: um pedido que nem chega a criar comando não tem pastilha que o diga. */
function renderConfigFeedback(key, uiState) {
    if (!uiState?.feedback?.message || uiState.feedback.tone !== "danger") {
        return "";
    }

    return `
        <div class="alert alert-danger alert-dismissible fade show small py-2 px-3 mt-3 mb-0" role="alert" data-config-feedback-key="${esc(key)}">
            ${esc(uiState.feedback.message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>`;
}

function configButtonState(uiState) {
    if (uiState?.phase === "submitting" || uiState?.phase === "sent") {
        return uiState.phase;
    }
    return "idle";
}

export function renderConfigInputs(entry, desired, meta = {}) {
    const descriptor = CONFIG_INPUTS[entry.input || "json"];
    if (!descriptor) {
        return jsonInput(desired);
    }

    return descriptor.render
        ? descriptor.render(entry, desired, meta)
        : renderConfigControl(entry, desired, meta);
}

/** O controlo nu de um campo estreito, para a linha do título; vazio para tudo o resto. */
function renderConfigControl(entry, desired, meta = {}) {
    return CONFIG_INPUTS[entry.input || "json"]?.control?.(entry, desired, meta) || "";
}

export function readConfigPayload(section) {
    const input = section.dataset.configInput || "json";
    return CONFIG_INPUTS[input]?.read?.(section) || readJson(section);
}

/** O guardado ganha ao que a API serve na capacidade, que já traz o valor de partida. */
function normalizeDesired(row, capability) {
    const desired = row && Object.keys(row).length > 0 ? row : capability?.value;
    return desired ?? {};
}

function resolveConfigRow(entry, rowsByKey) {
    return rowsByKey[entry.key] || null;
}

/** Uma definição escrita em várias linhas nativas (`configKeys`) está guardada se uma tiver valor. */
function resolveConfigStored(entry, rowsByKey) {
    if (Object.keys(rowsByKey[entry.key] || {}).length > 0) {
        return true;
    }
    if (Array.isArray(entry.configKeys) && entry.configKeys.length > 0) {
        return entry.configKeys.some((key) => Object.keys(rowsByKey[key] || {}).length > 0);
    }

    return false;
}

function capabilityForEntry(entry, capabilities) {
    return Object.values(capabilities || {})
        .map((section) => section?.[entry.capabilityKey])
        .find((capability) => capability && typeof capability === "object") ?? null;
}
