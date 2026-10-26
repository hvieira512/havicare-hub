import {
    getDevice as apiGetDevice,
    requestCapability as apiRequestCapability,
    saveConfiguration as apiSaveConfiguration,
} from "../../api/index.js";
import {
    deliveryStatusFromCommand,
    patchConfigurationDeliveryStates,
} from "./delivery.js";
import {
    readConfigPayload,
    renderDeviceConfigurationRoot,
} from "./index.js";
import { emptyPanel } from "../../components/empty-panel.js";
import { apiError, confirmDestructive, toast } from "../../core/dialogs.js";
import { resetPhoneControls } from "../phone.js";
import { state } from "../../core/state.js";
import { CONFIG_INPUTS } from "./inputs/index.js";
import { countLabel } from "../../core/i18n.js";
import { els } from "../../core/dom.js";

const configFeedbackTimers = new Map();
const configPhaseTimers = new Map();

export function dismissConfigFeedback(key) {
    clearTimeout(configFeedbackTimers.get(key));
    configFeedbackTimers.delete(key);
    clearConfigUi(key, "feedback");
}

/** O valor de um verbo de acção vem do próprio botão; `null` para o resto, que lê os campos. */
export function configActionPayload(section, actionValue) {
    if (actionValue !== "on" && actionValue !== "off") return null;

    const field = section.dataset.configActionField || "enabled";
    return { [field]: actionValue === "on" };
}

/** Durante um `syncConfigCounts` cada bloco lê-se uma vez, e não uma por contagem. */
let readsThisPass = null;

function readBlock(block) {
    if (readsThisPass === null) return readConfigPayload(block);
    if (!readsThisPass.has(block)) {
        try {
            readsThisPass.set(block, { payload: readConfigPayload(block) });
        } catch (error) {
            readsThisPass.set(block, { error });
        }
    }
    const read = readsThisPass.get(block);
    if (read.error) throw read.error;
    return read.payload;
}

const SAVEABLE_BLOCKS =
    "[data-config-row], [data-config-section]:not([data-config-transient=\"1\"])";

/** As mensagens dos leitores que recusaram o que está escrito, pela ordem do ecrã. */
function configReadErrors(container) {
    const errors = [];
    for (const row of container.querySelectorAll(SAVEABLE_BLOCKS)) {
        if (!row.dataset.configKey) continue;
        try {
            readConfigPayload(row);
        } catch (error) {
            errors.push(error instanceof Error ? error.message : "Configuração inválida");
        }
    }

    return errors;
}

/** Só viajam as definições que diferem do desenhado, sem as acções: o aparelho executa um comando de cada vez. */
export function changedConfigEntries(container) {
    const changed = {};
    const rows = container.querySelectorAll(SAVEABLE_BLOCKS);
    for (const row of rows) {
        const key = row.dataset.configKey || "";
        if (!key) continue;

        let payload;
        try {
            payload = readBlock(row);
        } catch {
            continue;
        }

        const pristine = row.dataset.configPristine ?? "";
        // Um interruptor nunca recebido viaja sem lhe tocarem: o grupo é o único caminho para a
        // primeira gravação. Um número não, que zero num intervalo quer dizer desactivar.
        const neverSent = row.dataset.configStored === "0" &&
            row.dataset.configInput === "toggle";
        if (neverSent || JSON.stringify(payload) !== pristine) {
            changed[key] = payload;
        }
    }

    return changed;
}

/** Falha aberta: uma leitura que falha conta como alteração. */
function isConfigBlockEdited(element) {
    if (!("configPristine" in element.dataset)) return false;
    try {
        return JSON.stringify(readBlock(element)) !== element.dataset.configPristine;
    } catch {
        return true;
    }
}

export function unsentConfigChanges(root) {
    const sections = [...root.querySelectorAll("[data-config-section]")]
        .filter((section) => section.dataset.configTransient !== "1" && isConfigBlockEdited(section));
    const rows = [...root.querySelectorAll("[data-config-group] [data-config-row]")]
        .filter(isConfigBlockEdited);

    return sections.length + rows.length;
}

/** Marca os blocos com edição por enviar: é por ela que o CSS mostra «Alterado». */
function markEditedConfigBlocks(root) {
    for (const block of root.querySelectorAll("[data-config-section], [data-config-row]")) {
        if (block.dataset.configTransient !== "1" && isConfigBlockEdited(block)) {
            block.dataset.configEdited = "1";
        } else {
            delete block.dataset.configEdited;
        }
    }
}

export async function saveDeviceConfigurations(container) {
    // Uma definição inválida trava o envio todo, para não se dar por guardado o que não passou.
    const errors = configReadErrors(container);
    if (errors.length > 0) {
        toast("error", errors[0]);
        return;
    }

    const changed = changedConfigEntries(container);
    if (Object.keys(changed).length === 0) return;

    const button = container.querySelector("[data-action=\"saveConfigPane\"]");
    if (button) {
        button.dataset.configPhase = "submitting";
        button.disabled = true;
    }

    const imei = state.deviceModal.imei;
    try {
        const result = await apiSaveConfiguration(imei, { configurations: changed });
        // A resposta de um aparelho anterior não escreve no de quem está agora no ecrã.
        if (state.deviceModal.imei !== imei) return;
        if (result.error) {
            toast("error", apiError(result));
            return;
        }

        state.deviceModal.configurations = result.configurations || state.deviceModal.configurations;
        state.deviceModal.configurationSync = result.configurationSync || state.deviceModal.configurationSync;
        state.deviceModal.capabilities = result.capabilities || state.deviceModal.capabilities;
        // O aviso agregado aponta para a pastilha de cada cartão: a entrega pode nem ter começado.
        toast("success", "configHubApplied" in container.dataset
            ? "Alterações guardadas no Hub."
            : "Alterações guardadas no Hub. A entrega ao dispositivo aparece em cada cartão.");
    } catch (error) {
        toast("error", error instanceof Error ? error.message : "Não foi possível enviar as alterações");
    } finally {
        if (button) button.dataset.configPhase = "idle";
        renderDeviceConfigurationModal({ keepEditedExcept: Object.keys(changed) });
    }
}

/** A frase vem da definição do protocolo, que o mesmo comando faz coisas diferentes em cada aparelho. */
export function dangerousCommandPrompt(section, imei) {
    const text = String(section?.dataset?.configConfirm || "");
    if (text === "") {
        return null;
    }

    const label = String(section?.dataset?.configLabel || "");
    return {
        title: `${label} — ${imei}?`,
        text,
        confirmText: label,
    };
}

export async function saveDeviceConfiguration(section, actionValue = "") {
    const key = section.dataset.configKey || "";
    if (!key) return;

    // Antes de tudo o resto: cancelar não pode deixar o cartão em «a enviar».
    const prompt = dangerousCommandPrompt(section, state.deviceModal.imei);
    if (prompt) {
        const { isConfirmed } = await confirmDestructive(
            prompt.title,
            prompt.text,
            prompt.confirmText,
        );
        if (!isConfirmed) return;
    }

    let payload;
    try {
        payload = configActionPayload(section, actionValue) ?? readConfigPayload(section);
    } catch (error) {
        toast("error", error instanceof Error ? error.message : "Configuração inválida");
        return;
    }

    setConfigUi(key, { phase: "submitting" });
    renderDeviceConfigurationModal({ keepEditedExcept: [key] });

    const imei = state.deviceModal.imei;
    try {
        const isTransientAction = section.dataset.configTransient === "1";
        const capabilityKey = section.dataset.capabilityKey || section.dataset.configKey || "";
        const result = isTransientAction
            ? await apiRequestCapability(imei, capabilityKey, payload)
            : await apiSaveConfiguration(imei, {
                    configurations: {
                        [key]: payload,
                    },
                });
        // A resposta do aparelho anterior não escreve no modal de quem está agora no ecrã.
        if (state.deviceModal.imei !== imei) return;
        if (result.error) {
            setConfigUi(key, {
                phase: "idle",
                feedback: {
                    tone: "danger",
                    message: apiError(result),
                },
            });
            renderDeviceConfigurationModal({ keepEditedExcept: [key] });
            return;
        }

        if (isTransientAction) {
            // O `id` casa com esta acção a resposta do aparelho que chega pelo stream.
            const command = (result.commands || [])[0] || null;
            state.deviceModal.actionDeliveries[capabilityKey] = command
                ? {
                        id: String(command.id || ""),
                        status: deliveryStatusFromCommand(command.status),
                        error: String(command.error || ""),
                    }
                : null;
        }

        if (!isTransientAction) {
            state.deviceModal.configurations =
                result.configurations || state.deviceModal.configurations;
            state.deviceModal.configurationSync =
                result.configurationSync || state.deviceModal.configurationSync;
            state.deviceModal.capabilities =
                result.capabilities || state.deviceModal.capabilities;
        }

        // Sem mensagem: o destino do pedido conta-o a pastilha. Limpa-se o erro anterior.
        setConfigUi(key, { phase: "sent", feedback: null });
        renderDeviceConfigurationModal({ keepEditedExcept: [key] });
        transitionConfigPhase(key, "sent", 1200, () => {
            clearConfigUi(key, "phase", "sent");
            renderDeviceConfigurationModal({ keepEditedExcept: [key] });
        });
    } catch (error) {
        setConfigUi(key, {
            phase: "idle",
            feedback: {
                tone: "danger",
                message:
                    error instanceof Error
                        ? error.message
                        : "Falha ao enviar configuração",
            },
        });
        renderDeviceConfigurationModal({ keepEditedExcept: [key] });
    }
}

/** Um aparelho que só mostra o que tem quando lho perguntam pergunta-se ao abrir, uma vez por abertura. */
export function requestRadarStateOnOpen() {
    const section = els?.deviceConfigRoot?.querySelector("[data-config-section][data-config-key=\"read_device_state\"]");
    if (!section ||
        state.deviceModal.actionDeliveries[section.dataset.capabilityKey || "read_device_state"] ||
        state.deviceModal.configUi.read_device_state?.phase) return;

    void saveDeviceConfiguration(section);
}

/** O estado e o erro que um comando dá a uma entrega; o que não mudou fica. */
function deliveryAfter(delivery, command) {
    const commandStatus = String(command.status || "");

    return {
        status: deliveryStatusFromCommand(commandStatus) || String(delivery.status || ""),
        error: ["failed", "dropped"].includes(commandStatus)
            ? String(command.lastError || command.error || commandStatus)
            : "",
    };
}

/** As acções seguem o comando no cliente; uma definição relê o estado que o hub lhe calcula. */
export async function syncDeviceModalCommandStates(imei, commands) {
    if (String(state.deviceModal.imei || "") !== String(imei || "")) {
        return;
    }

    const commandsById = new Map(
        (commands || []).map((command) => [String(command?.id || ""), command]),
    );

    for (const [capabilityKey, delivery] of Object.entries(state.deviceModal.actionDeliveries || {})) {
        const command = delivery?.id ? commandsById.get(String(delivery.id)) : null;
        if (!command) {
            continue;
        }

        const next = deliveryAfter(delivery, command);
        if (next.status !== String(delivery.status || "") || next.error !== String(delivery.error || "")) {
            state.deviceModal.actionDeliveries[capabilityKey] = { ...delivery, ...next };
        }
    }

    const touchesSetting = Object.values(state.deviceModal.configurationSync?.entries || {})
        .flatMap((section) => Object.values(section || {}))
        .some((delivery) => (delivery?.operations || [])
            .some((operation) => commandsById.has(String(operation?.operationId || ""))));
    if (!touchesSetting) {
        return;
    }

    const detail = await apiGetDevice(imei);
    if (detail?.error || String(state.deviceModal.imei || "") !== String(imei || "")) {
        return;
    }
    state.deviceModal.configurationSync = detail.configurationSync || { entries: {} };

    if (
        els?.deviceConfigRoot &&
        state.deviceModal.activeTab === "config" &&
        document.getElementById("deviceModal")?.classList.contains("show")
    ) {
        // Acerta-se a pastilha no sítio, para não deitar fora o que se está a escrever.
        patchConfigurationDeliveryStates(
            els.deviceConfigRoot,
            state.deviceModal.configurationSync,
        );
        // Uma entrega que acaba de falhar volta a tornar a configuração enviável.
        for (const section of els.deviceConfigRoot.querySelectorAll("[data-config-section]")) {
            syncConfigSectionDirty(section);
        }
    }
}

function setConfigUi(key, updates) {
    state.deviceModal.configUi[key] = {
        ...(state.deviceModal.configUi[key] || {}),
        ...updates,
    };
}

/** Tira um campo do estado de um cartão; com `only`, só se ainda tiver esse valor. */
function clearConfigUi(key, field, only = undefined) {
    const current = state.deviceModal.configUi[key];
    if (!current || (only !== undefined && current[field] !== only)) {
        return;
    }

    const next = { ...current };
    delete next[field];
    if (Object.keys(next).length === 0) {
        delete state.deviceModal.configUi[key];
        return;
    }
    state.deviceModal.configUi[key] = next;
}

function transitionConfigPhase(key, phase, delayMs, callback) {
    clearTimeout(configPhaseTimers.get(key));
    configPhaseTimers.set(
        key,
        setTimeout(() => {
            const current = state.deviceModal.configUi[key];
            if (current?.phase === phase) {
                callback();
            }
            configPhaseTimers.delete(key);
        }, delayMs),
    );
}

function armConfigFeedbackAutoClose() {
    const alerts = Array.from(
        els.deviceConfigRoot.querySelectorAll("[data-config-feedback-key]"),
    );
    for (const alertEl of alerts) {
        const key = alertEl.dataset.configFeedbackKey || "";
        if (!key || configFeedbackTimers.has(key)) {
            continue;
        }

        configFeedbackTimers.set(
            key,
            setTimeout(() => {
                const liveAlert = els.deviceConfigRoot.querySelector(
                    `[data-config-feedback-key="${CSS.escape(key)}"]`,
                );
                if (liveAlert) {
                    bootstrap.Alert.getOrCreateInstance(liveAlert).close();
                } else {
                    clearConfigUi(key, "feedback");
                }
                configFeedbackTimers.delete(key);
            }, 3500),
        );
    }
}

export function resetConfigUiState() {
    for (const timer of configFeedbackTimers.values()) {
        clearTimeout(timer);
    }
    configFeedbackTimers.clear();

    for (const timer of configPhaseTimers.values()) {
        clearTimeout(timer);
    }
    configPhaseTimers.clear();
}

/**
 * Com `keepEditedExcept`, os blocos com edição por enviar sobrevivem ao redesenho, salvo os das
 * chaves indicadas: enviar uma definição não apaga o que se escreveu noutra.
 */
export function renderDeviceConfigurationModal({ keepEditedExcept = null } = {}) {
    if (!els.deviceConfigRoot) {
        return;
    }
    const kept = keepEditedExcept ? editedBlocksExcept(els.deviceConfigRoot, keepEditedExcept) : [];

    if (state.deviceModal.loading || state.deviceModal.catalogLoading) {
        els.deviceConfigRoot.innerHTML = emptyPanel(
            "A carregar configurações...",
        );
        return;
    }

    if (!state.deviceModal.imei) {
        els.deviceConfigRoot.innerHTML = emptyPanel(
            "Preencha o IMEI para gerir as configurações.",
        );
        return;
    }

    const enabledCapKeys = state.deviceModal.enabledCapabilityKeys;
    // As que vivem dentro de outra, como o «Pôr em linha» nos cartões da balança, não têm secção.
    const filteredCatalog = state.deviceModal.catalog.filter(
        (entry) =>
            entry.capabilityKey &&
            (!enabledCapKeys.length || enabledCapKeys.includes(entry.capabilityKey)) &&
            !CONFIG_INPUTS[entry.input]?.embedded,
    );

    els.deviceConfigRoot.innerHTML = renderDeviceConfigurationRoot({
        protocol: state.deviceModal.protocol,
        catalog: filteredCatalog,
        capabilityCatalog: state.deviceModal.capabilityCatalog,
        configurations: state.deviceModal.configurations,
        configurationSync: state.deviceModal.configurationSync,
        capabilities: state.deviceModal.capabilities,
        linkedDevices: state.deviceModal.linkedDevices,
        uiByKey: state.deviceModal.configUi,
        actionDeliveries: state.deviceModal.actionDeliveries,
        activeCategory: state.deviceModal.activeCategory,
        sectionOpen: state.deviceModal.configSectionOpen,
        disabled: !state.deviceModal.protocol,
    });
    resetPhoneControls(els.deviceConfigRoot);
    captureConfigPristine(els.deviceConfigRoot);
    for (const block of kept) {
        els.deviceConfigRoot.querySelector(blockSelector(block))?.replaceWith(block);
    }
    syncConfigCounts(els.deviceConfigRoot);
    armConfigFeedbackAutoClose();
}

function editedBlocksExcept(root, keys) {
    const sections = [...root.querySelectorAll("[data-config-section]")]
        .filter((section) => section.dataset.configTransient !== "1" && isConfigBlockEdited(section));
    const rows = [...root.querySelectorAll("[data-config-group] [data-config-row]")]
        .filter((row) => isConfigBlockEdited(row) && !sections.some((section) => section.contains(row)));

    return [...sections, ...rows].filter((block) => !keys.includes(block.dataset.configKey || ""));
}

function blockSelector(block) {
    const kind = "configSection" in block.dataset ? "[data-config-section]" : "[data-config-group] [data-config-row]";
    return `${kind}[data-config-key="${CSS.escape(block.dataset.configKey || "")}"]`;
}

/** Conta edições por submeter, não entregas; as nunca gravadas à parte, que viajam sem lhes tocarem. */
function paneStatusLabel(pending, edited, verb = "enviar") {
    if (pending === 0) return "";
    if (edited === 0) {
        return `${countLabel(pending, "definição", "definições")} no valor padrão, por ${verb}`;
    }
    return `${countLabel(edited, "alteração", "alterações")} por ${verb}`;
}

/** O separador, a linha de cada secção e o rodapé saem das mesmas leituras, para não discordarem. */
export function syncConfigCounts(root) {
    readsThisPass = new Map();
    try {
        countConfigChanges(root);
    } finally {
        readsThisPass = null;
    }
}

function countConfigChanges(root) {
    markEditedConfigBlocks(root);

    for (const pane of root.querySelectorAll("[data-config-pane]")) {
        const pending = Object.keys(changedConfigEntries(pane)).length;
        const edited = unsentConfigChanges(pane);
        const key = pane.dataset.configPane || "";

        const changed = root.querySelector(
            `[data-config-section-link][data-section="${CSS.escape(key)}"] [data-config-section-changed]`,
        );
        if (changed) {
            changed.textContent = edited === 0
                ? ""
                : ` · ${countLabel(edited, "alterada", "alteradas")}`;
            changed.classList.toggle("text-warning-emphasis", edited > 0);
        }

        const status = pane.querySelector("[data-config-pane-status]");
        if (status) {
            status.textContent = paneStatusLabel(pending, edited, "configHubApplied" in pane.dataset ? "guardar" : "enviar");
            status.classList.toggle("text-warning-emphasis", edited > 0);
            status.classList.toggle("text-secondary", edited === 0);
        }

        for (const button of pane.querySelectorAll("[data-action=\"saveConfigPane\"], [data-action=\"resetConfigPane\"]")) {
            if (button.dataset.configPhase === "submitting") continue;
            button.disabled = pending === 0;
        }
    }

    const badge = els?.deviceConfigCount;
    if (badge) {
        const total = unsentConfigChanges(root);
        badge.textContent = String(total);
        badge.classList.toggle("d-none", total === 0);
    }
}

/** A fotografia de cada bloco ao desenhar; falha aberta: sem leitura, o botão fica activo. */
function drawnFieldValue(input) {
    return input.type === "checkbox" || input.type === "radio" ? input.checked : input.value;
}

export function captureConfigPristine(root) {
    for (const section of root.querySelectorAll("[data-config-section]")) {
        try {
            section.dataset.configPristine = JSON.stringify(readConfigPayload(section));
        } catch {
            delete section.dataset.configPristine;
        }
        // O Repor devolve cada campo como foi desenhado, que nem sempre é o formato guardado.
        section.dataset.configPristineFields = JSON.stringify(
            [...section.querySelectorAll("[data-config-field]")].map(drawnFieldValue),
        );
        syncConfigSectionDirty(section);
    }
}

/** Acende o «Enviar» quando o valor difere do desenhado, ou quando não há valor nenhum. */
export function syncConfigSectionDirty(section) {
    const button = section.querySelector("[data-action=\"saveConfig\"]");
    // Fora do estado inactivo, o botão está a dizer outra coisa e não se lhe mexe.
    if (!button || button.dataset.configPhase !== "idle") return;
    if (!("configPristine" in section.dataset)) return;

    // Sem diferença, envia-se uma definição nunca recebida ou uma entrega falhada.
    const neverSent = section.dataset.configStored === "0";
    const deliveryFailed = section.dataset.configDelivery === "failed";
    const active = section.dataset.configConfirm ? "btn-outline-danger" : "btn-primary";
    if (section.dataset.configTransient === "1" || neverSent || deliveryFailed) {
        button.classList.add(active);
        button.classList.remove("btn-outline-secondary");
        button.disabled = false;
        return;
    }

    let dirty = true;
    try {
        const payload = readConfigPayload(section);
        // Sem parâmetros o payload é vazio antes e depois, e vazio já é o payload final.
        dirty = Object.keys(payload).length === 0 ||
            JSON.stringify(payload) !== section.dataset.configPristine;
    } catch {
        dirty = true;
    }

    button.classList.toggle(active, dirty);
    button.classList.toggle("btn-outline-secondary", !dirty);
    button.disabled = !dirty;
}
