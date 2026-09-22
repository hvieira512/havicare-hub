import { esc, fieldUnit } from "../../format.js";
import { emptyPanel } from "../../components/empty-panel.js";
import { settingRow } from "../../components/setting-row.js";
import { stateBadge } from "../../components/state-badge.js";
import {
    configurationDeliveryMeta,
    renderConfigurationDeliveryNotice,
    resolveConfigDelivery,
} from "./delivery.js";
// Os mesmos cinco ícones do catálogo de capacidades: as secções são as mesmas, e um separador
// com outro ícone para a mesma secção lia-se como sendo outra coisa.
import { CAPABILITY_SECTION_ICONS } from "../../capability-catalog.js";
import { configCatalogSections } from "./catalog-model.js";
import { CONFIG_INPUTS } from "./inputs/index.js";
import { toggleField, toggleValue } from "./inputs/generic.js";
import { jsonInput, readJson } from "./readers.js";
import {
    catalogForProtocol,
    protocolFieldConstraints,
} from "./protocol-catalog.js";

// O `device-modal.js` chega ao catálogo por aqui, que é a porta do painel tardio.
export { catalogForProtocol };

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
    queued: { icon: "fa-list-check", label: "Em fila", className: "btn-secondary" },
    waiting: { icon: "fa-hourglass-half", label: "À espera", className: "btn-warning" },
    acked: { icon: "fa-circle-check", label: "Confirmado", className: "btn-success" },
    failed: {
        icon: "fa-triangle-exclamation",
        label: "Falhou",
        className: "btn-danger",
    },
    dropped: {
        icon: "fa-triangle-exclamation",
        label: "Falhou",
        className: "btn-danger",
    },
};

/** O prazo na unidade em que é redondo: 300 são cinco minutos, e 90 são noventa segundos. */
function queueDeadline(seconds) {
    for (const [size, one, many] of [[3600, "1 hora", "horas"], [60, "1 minuto", "minutos"]]) {
        if (seconds % size !== 0) continue;
        const count = seconds / size;
        return count === 1 ? one : `${count} ${many}`;
    }
    return `${seconds} segundos`;
}

/**
 * O aviso de que o aparelho não está a ouvir.
 *
 * O painel apresentava os blocos e os «Enviar» de um aparelho desligado exactamente como os
 * de um ligado. O comando não se perde -- o `submitDownlink` mete-o em fila quando não há
 * ligação --, mas a fila tem prazo, e nada disso estava no ecrã.
 */
export function offlineQueueNotice(online, ttlSeconds) {
    if (online) return "";

    const seconds = Math.max(0, Number(ttlSeconds) || 0);
    const deadline = seconds > 0
        ? ` Ao fim de ${queueDeadline(seconds)} sem ligação, é descartado.`
        : "";

    return `Este dispositivo está desligado. O que enviar fica em fila e sai quando ele voltar.${deadline}`;
}

export function renderDeviceConfigurationRoot(context) {
    const {
        protocol,
        catalog,
        configurations = {},
        capabilities = {},
        capabilityCatalog = [],
        configurationSync = { entries: {} },
        supplier = "",
        model = "",
        disabled = false,
        activeCategory = "",
        uiByKey = {},
        actionDeliveries = {},
        quietWhenEmpty = false,
        online = true,
        queueTtlSeconds = 0,
    } = context;
    if (!protocol) {
        return emptyPanel(
            "Selecione fornecedor e modelo para ver as configurações.",
        );
    }

    if (!catalog.length) {
        // Calado quando quem chama já mostrou uma configuração decidida no hub: "este
        // protocolo não tem configurações suportadas" é verdade sobre downlinks e mentira
        // sobre o ecrã, que tem uma configuração logo acima.
        return quietWhenEmpty
            ? ""
            : emptyPanel("Este protocolo não tem configurações suportadas.");
    }

    const rowsByKey = configurations;
    const groups = configCatalogSections(protocol, catalog, capabilityCatalog);
    const currentCategory = groups.some((group) => group.key === activeCategory)
        ? activeCategory
        : groups[0]?.key || "";

    const offlineNotice = offlineQueueNotice(online, queueTtlSeconds);

    return `
        <div class="vstack gap-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">Configurações do dispositivo</div>
                    <div class="small text-secondary">${supplier || model ? `${esc(supplier)} ${esc(model)}` : ""}</div>
                </div>
            </div>
            ${offlineNotice === ""
                ? ""
                : `
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 small mb-0" role="status">
                <i class="fa-solid fa-clock mt-1" aria-hidden="true"></i>
                <span>${esc(offlineNotice)}</span>
            </div>`}
            <div class="nav nav-underline flex-wrap gap-3" role="tablist">
                ${groups
                    .map(
                        (group) => `
                    <button type="button" class="nav-link d-inline-flex align-items-center gap-2 ${group.key === currentCategory ? "active" : ""}" data-config-category="${esc(group.key)}">
                        <i class="fa-solid ${esc(CAPABILITY_SECTION_ICONS[group.key] || "fa-gear")} fa-fw" aria-hidden="true"></i>
                        ${esc(group.label)}
                        <span class="badge rounded-pill text-bg-secondary">${group.entries.length}</span>
                    </button>
                `,
                    )
                    .join("")}
            </div>
            <div class="tab-content">
                ${groups
                    .map(
                        (group) => `
                    <div class="tab-pane fade ${group.key === currentCategory ? "show active" : ""}">
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
                                // Uma acção não tem entrada no `configurationSync` -- não é
                                // uma configuração guardada. O estado que ela tem é o do
                                // último pedido que disparou.
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
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

/**
 * Um interruptor que se guarda, e não uma acção nem um campo composto.
 *
 * É o único caso em que uma linha diz tudo o que há a dizer: um nome, o que faz, e ligado ou
 * desligado. Tudo o resto -- alarmes, agendas, listas, intervalos -- precisa do espaço do
 * cartão, e agrupá-lo espremia-o.
 */
function isPlainToggle(entry) {
    return entry.input === "toggle" &&
        entry.transient !== true &&
        entry.requestOnly !== true;
}

/**
 * A mesma definição repetida para grandezas diferentes: o mesmo comando nativo e a mesma
 * legenda declarada.
 *
 * É o que distingue as dez medições da Wonlex de dois números que por acaso ficaram
 * vizinhos. A chave inclui o comando e a legenda de propósito -- agrupar por tipo de campo
 * juntava o limiar de bateria com o intervalo de localização, que não têm nada a ver.
 */
function isRepeatedField(entry) {
    return entry.input === "number" &&
        entry.transient !== true &&
        entry.requestOnly !== true &&
        (entry.fields?.length ?? 0) === 1 &&
        String(entry.help || "").trim() !== "";
}

/** A assinatura de uma entrada para efeitos de agrupamento. Vazia quando fica cartão. */
function configRunKind(entry) {
    if (isPlainToggle(entry)) return "toggle";
    if (isRepeatedField(entry)) return `field:${entry.command}:${entry.help}`;
    return "";
}

/**
 * As entradas por ordem, com as corridas marcadas para agrupar.
 *
 * Corridas e não «todos os interruptores da secção»: a ordem do catálogo é editorial, e
 * juntar interruptores separados por um alarme trocava-a por uma arrumação que o autor do
 * catálogo não pediu.
 *
 * @returns {Array<{kind: string, grouped: boolean, entries: Array<object>}>}
 */
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

    // Um interruptor sozinho continua a agrupar -- em cartão gastava quatro linhas para um
    // bit. Um campo sozinho não: o cartão magro dele já é uma linha, e um grupo de um só
    // acrescentava um cabeçalho e um rodapé para a mesma coisa.
    return runs.map((run) => ({
        ...run,
        grouped: run.kind === "toggle" || (run.kind.startsWith("field:") && run.entries.length > 1),
    }));
}

/** A unidade ao lado do campo de uma linha, quando o nome nativo a declara. */
function unitOf(entry) {
    const unit = fieldUnit(entry.fields?.[0] || "");
    return unit === "" ? "" : `<span class="small text-secondary flex-shrink-0">${esc(unit)}</span>`;
}

/**
 * O valor de uma linha na forma em que o leitor do campo o devolve.
 *
 * Tem de bater certo ao caractere com o `readConfigPayload`, porque é contra ele que a
 * fotografia é comparada: um `"0"` onde o leitor devolve `0` contava uma alteração a quem
 * não tinha mexido em nada.
 */
function readConfigEntryValue(entry, desired) {
    const field = entry.fields?.[0] || "value";
    // O mesmo recuo do controlo: quem desenha parte do mínimo declarado, e a fotografia tem
    // de partir de lá também.
    return { [field]: Number(desired?.[field] ?? entry.options?.min ?? 0) };
}

function renderConfigGroup(protocol, entries, ctx) {
    const { rowsByKey, capabilities, disabled, uiByKey, configurationSync } = ctx;
    // Numa corrida de campos repetidos a legenda é a mesma nas dez, e sobe ao cabeçalho: dita
    // por linha, era a única coisa que se lia dez vezes seguidas.
    const shared = entries.length > 1 && entries.every((entry) => configHelp(entry) === configHelp(entries[0]))
        ? configHelp(entries[0])
        : "";

    const rows = entries.map((entry) => {
        const capability = capabilityForEntry(entry, capabilities);
        const desired = normalizeDesired(entry, resolveConfigRow(entry, rowsByKey), capability ? extractCapabilityValue(capability) : null, protocol);
        const isToggle = isPlainToggle(entry);
        const field = toggleField(entry, protocol);
        const on = toggleValue(entry, desired, protocol);
        const stored = resolveConfigStored(entry, rowsByKey);
        const delivery = resolveConfigDelivery(entry, configurationSync);
        const deliveryMeta = configurationDeliveryMeta(stored, delivery);
        const help = shared === "" ? configHelp(entry) : "";
        const uiState = uiByKey[entry.key] || null;
        const busy = uiState?.phase === "submitting";
        const control = isToggle
            ? `<div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           data-config-field="${esc(field)}" ${on ? "checked" : ""}
                           ${disabled || busy ? "disabled" : ""}
                           aria-label="${esc(entry.label || entry.key)}">
                </div>`
            : renderConfigControl(entry, desired, { protocol });
        // A fotografia do valor tem de bater certo com o que o leitor devolve, ou o rodapé
        // conta uma alteração a quem não mexeu em nada.
        const pristine = isToggle ? { [field]: on } : readConfigEntryValue(entry, desired);

        return `
            <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" data-config-row
                 data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey || entry.key)}"
                 data-config-input="${esc(isToggle ? "toggle" : entry.input || "json")}" data-config-stored="${stored ? "1" : "0"}"
                 data-config-protocol="${esc(protocol)}"
                 data-config-pristine='${esc(JSON.stringify(pristine))}'>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">${esc(entry.label || entry.key)}</div>
                    ${help ? `<div class="small text-secondary">${esc(help)}</div>` : ""}
                </div>
                ${stateBadge(deliveryMeta.label, deliveryMeta.tone)}
                ${isToggle ? control : `<div class="d-flex align-items-center gap-2">${control}${unitOf(entry)}</div>`}
            </div>`;
    }).join("");

    return `
        <section class="border rounded-3 mb-3" data-config-group data-config-protocol="${esc(protocol)}">
            ${shared === ""
                ? ""
                : `<div class="px-3 py-2 border-bottom small text-secondary">${esc(shared)}</div>`}
            ${rows}
            <div class="d-flex align-items-center justify-content-between gap-3 px-3 py-2 bg-body-tertiary rounded-bottom-3">
                <span class="small text-secondary" data-config-group-status>Sem alterações por enviar</span>
                <span class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="resetConfigGroup" ${disabled ? "disabled" : ""}>Repor</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="saveConfigGroup" data-config-phase="idle" disabled>Enviar alterações</button>
                </span>
            </div>
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
    const desired = normalizeDesired(entry, row, capability ? extractCapabilityValue(capability) : null, protocol);
    const meta = {
        ...(capability?._meta || {}),
        protocol,
        phonebookContacts: relatedConfigurations.phonebook || [],
    };
    const help = configHelp(entry);
    // Uma acção com dois sentidos mostra os dois verbos em vez de um interruptor e um
    // «Enviar»: o botão passa a dizer o que vai acontecer, e parar deixa de ser uma
    // descoberta. Sem verbos declarados, o cartão fica como estava.
    const verbs = configActionVerbs(entry);
    const isStored = stored ?? (row !== null && Object.keys(row).length > 0);
    // Uma acção não tem valor guardado, mas o pedido que ela dispara tem estado: em fila, à
    // espera, confirmado ou falhado. Sem o mostrar, quem carrega no botão fica sem saber se a
    // ordem chegou sequer a sair do hub.
    const showConfigurationBadge = !entry.requestOnly || delivery !== null;
    // «Padrão» quer dizer que o hub ainda não guardou valor nenhum, e uma acção nunca guarda:
    // o que ela tem é o estado do último pedido, e é esse que a pastilha mostra.
    const deliveryMeta = configurationDeliveryMeta(isStored || (entry.requestOnly === true && delivery !== null), delivery);
    // Sem comando nativo, o hub aplica-a sozinho, e então não há vocabulário de protocolo
    // para mostrar: o comando não existe, e o tipo de campo sozinho é ruído.
    const hideNativeCommand = (entry.configKind === "capability" && entry.key === "alarm_clock") ||
        String(entry.command || "") === "";
    const configSectionName = entry.configSectionName || entry.configSection || "";
    const phonebookConstraints = protocolFieldConstraints(protocol).phonebook || {};
    const isPhonebookLike = String(entry.key || "") === "phonebook" || String(entry.key || "") === "call_whitelist";
    const phonebookNameMaxLength = isPhonebookLike
        ? parseInt(String(meta.name?.maxLength ?? phonebookConstraints.name?.maxLength ?? 0), 10) || 0
        : 0;
    const phonebookPhoneMaxLength = isPhonebookLike
        ? parseInt(String(meta.phone?.maxLength ?? phonebookConstraints.phone?.maxLength ?? 0), 10) || 0
        : 0;
    const phonebookMetaAttrs = isPhonebookLike
        ? `${phonebookNameMaxLength > 0 ? ` data-phonebook-name-max-length="${esc(String(phonebookNameMaxLength))}"` : ""}${phonebookPhoneMaxLength > 0 ? ` data-phonebook-phone-max-length="${esc(String(phonebookPhoneMaxLength))}"` : ""}`
        : "";
    // O cartão diz o que a definição faz, e mais nada. O nome do comando e o tipo de campo
    // são vocabulário de protocolo: servem os registos e as ferramentas de diagnóstico, não
    // quem gere dispositivos, e estavam a ser a única coisa que se lia em cada cartão.
    const details = [help || ""].filter((part) => part !== "");
    // O que a confirmação vai dizer, tal como a definição do protocolo o declara. Só as
    // destrutivas o trazem, e é a presença dele que decide se há caixa.
    const confirmText = String(entry.confirm || "");
    const confirmAttrs = confirmText === "" ? "" : ` data-config-confirm="${esc(confirmText)}"`;
    // Um descritor conhecido que não declara `render` não tem campos para desenhar, e o
    // cartão dele cabe numa linha. Um tipo que o registo não conhece cai no editor de JSON,
    // que continua a ser um formulário.
    const descriptor = CONFIG_INPUTS[entry.input || "json"];
    const drawsFields = !descriptor || typeof descriptor.render === "function";
    const control = drawsFields ? "" : renderConfigControl(entry, desired, { ...meta, protocol });
    // O verbo é das acções. Uma definição guarda-se, e o que o botão dela faz é enviá-la.
    //
    // Sem verbo declarado o botão não repete o título: das vinte e uma acções do catálogo,
    // vinte têm o rótulo como frase do verbo, e pô-lo também no botão dava a mesma frase
    // duas vezes na mesma linha. Pior nas que são nomes -- «Versão de firmware», «Estado do
    // dispositivo» --, onde o botão deixava de dizer o que o clique faz.
    const verb = drawsFields || control !== "" ? "" : String(entry.verb || "");

    // O bloco do título leva `min-w-0` para encolher em vez de empurrar a pastilha de estado
    // para a linha de baixo: com uma descrição comprida ela saltava para o canto esquerdo,
    // que é o oposto do que o `justify-content-between` promete.
    return `
        <section class="border rounded-3 p-3 mb-3" data-config-section data-config-kind="${esc(entry.configKind || "configuration")}" data-config-stored="${isStored ? "1" : "0"}" data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey || entry.key)}" data-config-label="${esc(entry.label || entry.key)}"${confirmAttrs}${configSectionName !== "" ? ` data-config-section-name="${esc(configSectionName)}"` : ""}${phonebookMetaAttrs} data-config-input="${esc(entry.input || "json")}"${verbs.length > 0 ? ` data-config-action-field="${esc(entry.fields?.[0] || "enabled")}"` : ""} data-config-protocol="${esc(protocol)}" data-config-limit="${esc(String(entry.limit ?? ""))}"${entry.transient ? " data-config-transient=\"1\"" : ""} data-config-delivery="${esc(String(delivery?.status || ""))}">
            ${drawsFields
                ? `
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">${esc(entry.label || entry.key)}</div>
                    ${details.length > 0 ? `<div class="small text-secondary">${details.map((part) => esc(part)).join(" · ")}</div>` : ""}
                </div>
                ${showConfigurationBadge
                    ? `<div class="flex-shrink-0">${stateBadge(deliveryMeta.label, deliveryMeta.tone)}</div>`
                    : ""}
            </div>
            ${renderConfigurationDeliveryNotice(deliveryMeta, delivery)}
            <form class="mt-3" data-config-form data-config-key="${esc(entry.key)}">
                ${verbs.length > 0 ? "" : renderConfigInputs(entry, desired, { ...meta, protocol })}
                <div class="d-flex justify-content-end gap-2 mt-3">
                    ${verbs.length > 0
                        ? renderConfigActionVerbs(verbs, disabled)
                        : `${renderConfigActionButton(entry.key, row, uiState, disabled, hideNativeCommand, confirmText !== "")}
                    <button type="reset" class="btn btn-outline-secondary btn-sm" title="Repor" aria-label="Repor" ${disabled ? "disabled" : ""}>
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>`}
                </div>
            </form>`
                : `
            ${settingRow({
                title: entry.label || entry.key,
                note: details.join(" · "),
                badge: showConfigurationBadge ? stateBadge(deliveryMeta.label, deliveryMeta.tone) : "",
                control,
                unit: control === "" ? "" : fieldUnit(entry.fields?.[0] || ""),
                actions: verbs.length > 0
                    ? renderConfigActionVerbs(verbs, disabled)
                    : renderConfigActionButton(entry.key, row, uiState, disabled, hideNativeCommand, confirmText !== "", verb),
            })}
            ${renderConfigurationDeliveryNotice(deliveryMeta, delivery)}`}
            ${renderConfigFeedback(entry.key, uiState)}
        </section>`;
}

/**
 * Os verbos de uma acção com dois sentidos, na ordem em que se lêem.
 *
 * Só para acções: uma definição guarda-se, e por isso o que ela precisa é do interruptor com
 * o estado desejado, não de dois botões que disparam.
 *
 * @returns {Array<{value: string, label: string}>}
 */
function configActionVerbs(entry) {
    if (entry.transient !== true) return [];

    const actions = entry.actions || null;
    if (!actions || typeof actions !== "object") return [];

    return ["on", "off"]
        .filter((value) => String(actions[value] || "").trim() !== "")
        .map((value) => ({ value, label: String(actions[value]).trim() }));
}

function renderConfigActionVerbs(verbs, disabled) {
    return verbs.map((verb, index) => `
        <button type="button" class="btn btn-sm ${index === 0 ? "btn-primary" : "btn-outline-secondary"}"
                data-action="saveConfig" data-action-value="${esc(verb.value)}"
                data-config-phase="idle" ${disabled ? "disabled" : ""}>${esc(verb.label)}</button>`).join("");
}

function renderConfigActionButton(key, row, uiState, disabled = false, appliedByHub = false, destructive = false, verb = "") {
    const state = configButtonState(row, uiState);
    // O verbo só se declara onde vale a pena dizê-lo outra vez no botão -- a reposição de
    // fábrica é o caso: o rótulo é um nome e o botão tem de dizer o que o clique faz.
    // "Guardar" e não "Enviar" quando não há nada a caminho do dispositivo: o botão não deve
    // prometer um envio que não acontece.
    const idleLabel = verb !== ""
        ? verb
        : appliedByHub
            ? "Guardar"
            : (["pushMessage", "push_message"].includes(key) ? "Enviar mensagem" : "Enviar");
    const isDisabled =
        disabled || ["submitting", "sent", "queued", "waiting"].includes(state);
    const meta = CONFIG_ACTION_BUTTON_META[state] || CONFIG_ACTION_BUTTON_META.idle;
    // O peso de uma acção que não se desfaz fica no botão, e não numa faixa de aviso sempre
    // acesa por cima dele. A partir do clique a fase manda na cor: ela conta o que aconteceu
    // ao pedido, que é outra coisa.
    const className = state === "idle" && destructive ? "btn-outline-danger" : meta.className;

    return `
        <button type="button" class="btn ${className} btn-sm" data-action="saveConfig" data-config-key="${esc(key)}" data-config-phase="${esc(state)}" ${isDisabled ? "disabled" : ""}>
            <i class="fa-solid ${meta.icon} me-2"></i>${state === "idle" ? esc(idleLabel) : esc(meta.label)}
        </button>`;
}

/**
 * A caixa de mensagem do cartão, que é para o que a pastilha não sabe dizer.
 *
 * Só falhas. A pastilha conta a história de um pedido do princípio ao fim -- em envio, a
 * aguardar, aplicado, falhou -- e vai mudando com ela; uma caixa de sucesso congelava um
 * instante e ficava até alguém a fechar, a dizer «enviado» por cima de um pedido já aplicado.
 * E contradizia a barra do mesmo cartão, que dizia, correctamente, que o hub ainda só o tinha
 * em fila.
 *
 * Uma falha é outra coisa: um pedido que nem chega a criar comando não tem pastilha nenhuma, e
 * sem isto o clique morria em silêncio.
 */
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

function configButtonState(_row, uiState) {
    if (uiState?.phase === "submitting" || uiState?.phase === "sent") {
        return uiState.phase;
    }
    return "idle";
}

export function renderConfigInputs(entry, desired, meta = {}) {
    // Perguntado ao descritor e não ao resultado: um renderizador que devolva vazio de
    // propósito -- uma acção, que não tem campos -- caía no editor de JSON.
    const descriptor = CONFIG_INPUTS[entry.input || "json"];
    if (!descriptor) {
        return jsonInput(desired);
    }

    return descriptor.render
        ? descriptor.render(entry, desired, meta)
        : renderConfigControl(entry, desired, meta);
}

/**
 * O controlo nu de uma definição de campo estreito -- sem rótulo e sem linha de ajuda --
 * para o cartão que o põe na linha do título. Vazio para tudo o resto.
 */
function renderConfigControl(entry, desired, meta = {}) {
    return CONFIG_INPUTS[entry.input || "json"]?.control?.(entry, desired, meta) || "";
}

export function readConfigPayload(section) {
    const input = section.dataset.configInput || "json";
    return CONFIG_INPUTS[input]?.read?.(section) || readJson(section);
}

export function defaultConfigPayload(entry, protocol = "") {
    const input = entry.input || "json";
    return CONFIG_INPUTS[input]?.defaults?.(entry, protocol) || {};
}

function normalizeDesired(entry, desired, capabilityDesired = null, protocol = "") {
    const effectiveDesired = desired ?? capabilityDesired;
    if (effectiveDesired && Object.keys(effectiveDesired).length) {
        return extractCapabilityValue(effectiveDesired);
    }
    return defaultConfigPayload(entry, protocol);
}

function resolveConfigRow(entry, rowsByKey) {
    return rowsByKey[entry.key] || null;
}

/**
 * Se já há valor guardado para esta entrada.
 *
 * O `configKeys` existe para as definições que o hub escreve em mais do que uma linha nativa:
 * basta uma delas ter valor. A chave própria é testada primeiro porque é o caso comum.
 */
function resolveConfigStored(entry, rowsByKey) {
    if (Object.keys(rowsByKey[entry.key] || {}).length > 0) {
        return true;
    }
    if (Array.isArray(entry.configKeys) && entry.configKeys.length > 0) {
        return entry.configKeys.some((key) => Object.keys(rowsByKey[key] || {}).length > 0);
    }

    return false;
}

function configHelp(entry) {
    // A legenda declarada na definição descreve *esta* definição; a tabela por tipo de campo
    // só sabe falar da forma do campo. Quando existe, é a que serve quem está a decidir.
    const declared = String(entry.help || "").trim();
    if (declared !== "") {
        return declared;
    }

    const input = entry.input || "json";
    const key = entry.key || "";
    if (CONFIG_INPUTS[input]?.help) {
        return CONFIG_INPUTS[input].help(entry);
    }
    return CONFIG_INPUTS[key]?.help?.(entry) || "";
}

function capabilityForEntry(entry, capabilities) {
    const key = entry.capabilityKey || entry.key;
    if (!key) {
        return null;
    }

    const sectionKeys = capabilitySectionCandidates(entry);
    for (const sectionKey of sectionKeys) {
        const section = capabilities?.[sectionKey];
        if (!section || typeof section !== "object") {
            continue;
        }

        const capability = section[key];
        if (capability && typeof capability === "object") {
            return capability;
        }
    }

    for (const sectionKey of ["telemetry", "health", "contacts", "alarms", "settings_system"]) {
        if (sectionKeys.includes(sectionKey)) {
            continue;
        }
        const section = capabilities?.[sectionKey];
        if (!section || typeof section !== "object") {
            continue;
        }

        const capability = section[key];
        if (capability && typeof capability === "object") {
            return capability;
        }
    }

    return null;
}

function capabilitySectionCandidates(entry) {
    const category = String(entry.category || "");
    const configSection = String(entry.configSectionName || entry.configSection || "");
    const sections = [];

    if (configSection !== "") {
        sections.push(configSection);
    }

    if (category !== "") {
        sections.push(category);
    }

    return [...new Set(sections)];
}

function extractCapabilityValue(value) {
    if (
        value &&
        typeof value === "object" &&
        !Array.isArray(value) &&
        Object.prototype.hasOwnProperty.call(value, "value")
    ) {
        return value.value;
    }

    return value;
}
