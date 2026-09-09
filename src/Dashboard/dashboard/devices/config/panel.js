import {
    requestCapability as apiRequestCapability,
    saveConfiguration as apiSaveConfiguration,
} from "../../api/index.js";
import {
    patchConfigurationDeliveryStates,
    readConfigPayload,
    renderDeviceConfigurationRoot,
} from "./index.js";
import { emptyPanel } from "../../widgets.js";
import { confirmDestructive, toast } from "../../dialogs.js";
import { resetPhoneControls } from "../../phone.js";
import { state } from "../../state.js";

/**
 * O painel de configuração dentro do modal do dispositivo: gravar uma secção, refrescar o
 * que o dispositivo reporta, e a fase da interface de cada secção.
 *
 * É dele que são os temporizadores e a promessa de refresh em curso, e é isso que os traz
 * para aqui em vez de os deixar no `app.js`.
 */

let els;

const configFeedbackTimers = new Map();
const configPhaseTimers = new Map();

export function initDeviceConfigPanel(context) {
    els = context.els;
}

/** Chamado quando o próprio utilizador fecha um aviso. */
export function dismissConfigFeedback(key) {
    clearTimeout(configFeedbackTimers.get(key));
    configFeedbackTimers.delete(key);
    clearConfigFeedback(key);
}

/**
 * O valor que um verbo de acção envia.
 *
 * Um botão que diz «Parar» não tem formulário para ler: o que vai enviar está no próprio
 * botão. Devolve `null` para tudo o resto, que continua a ler os campos do cartão.
 */
export function configActionPayload(section, actionValue) {
    if (actionValue !== "on" && actionValue !== "off") return null;

    const field = section.dataset.configActionField || "enabled";
    return { [field]: actionValue === "on" };
}

/**
 * As linhas de um grupo cujo valor difere do que estava desenhado.
 *
 * Só o que mudou é que viaja: enviar as oito de uma vez transformava uma alteração num lote
 * de oito comandos para a pulseira executar um a um, e o aparelho serve um de cada vez.
 *
 * @returns {Object<string, object>} chave da definição => valor a enviar
 */
export function changedConfigGroupEntries(group) {
    const changed = {};
    for (const row of group.querySelectorAll("[data-config-row]")) {
        const key = row.dataset.configKey || "";
        if (!key) continue;

        let payload;
        try {
            payload = readConfigPayload(row);
        } catch {
            continue;
        }

        const pristine = row.dataset.configPristine ?? "";
        const neverSent = row.dataset.configStored === "0";
        if (neverSent || JSON.stringify(payload) !== pristine) {
            changed[key] = payload;
        }
    }

    return changed;
}

/**
 * Quantas alterações à configuração estão escritas no ecrã e por enviar ao aparelho.
 *
 * Conta só o que alguém editou. Uma acção é sempre um pedido novo, e uma definição que o
 * aparelho ainda não recebeu está por enviar sem ninguém lhe ter tocado: avisar por causa
 * delas era avisar em todos os relógios, todas as vezes, e um aviso que aparece sempre
 * deixa de se ler.
 */
export function unsentConfigChanges(root) {
    const edited = (element) => {
        if (!("configPristine" in element.dataset)) return false;
        try {
            return JSON.stringify(readConfigPayload(element)) !== element.dataset.configPristine;
        } catch {
            return false;
        }
    };

    const sections = [...root.querySelectorAll("[data-config-section]")]
        .filter((section) => section.dataset.configTransient !== "1" && edited(section));
    const rows = [...root.querySelectorAll("[data-config-group] [data-config-row]")]
        .filter(edited);

    return sections.length + rows.length;
}

/** Acende o «Enviar alterações» do grupo e diz quantas são. */
export function syncConfigGroupDirty(group) {
    const button = group.querySelector("[data-action=\"saveConfigGroup\"]");
    const status = group.querySelector("[data-config-group-status]");
    if (!button || button.dataset.configPhase !== "idle") return;

    const pending = changedConfigGroupEntries(group);
    const count = Object.keys(pending).length;
    button.disabled = count === 0;
    button.classList.toggle("btn-primary", count > 0);
    button.classList.toggle("btn-outline-secondary", count === 0);

    // «Alterações» só quando alguém alterou. Linhas que nunca chegaram ao aparelho estão por
    // enviar sem ninguém lhes ter tocado, e dizer-lhes alterações era mentir sobre a origem.
    const edited = Object.keys(pending).some((key) => {
        const row = group.querySelector(`[data-config-row][data-config-key="${key}"]`);
        return row?.dataset.configStored !== "0";
    });

    if (status) {
        status.textContent = count === 0
            ? "Tudo enviado ao dispositivo"
            : edited
                ? `${count} ${count === 1 ? "alteração" : "alterações"} por enviar`
                : `${count} ${count === 1 ? "definição nunca enviada" : "definições nunca enviadas"} ao dispositivo`;
    }
}

export async function saveDeviceConfigurationGroup(group) {
    const changed = changedConfigGroupEntries(group);
    if (Object.keys(changed).length === 0) return;

    const button = group.querySelector("[data-action=\"saveConfigGroup\"]");
    if (button) {
        button.dataset.configPhase = "submitting";
        button.disabled = true;
    }

    try {
        const result = await apiSaveConfiguration(state.deviceModal.imei, { configurations: changed });
        if (result.error) {
            toast("error", result.error.message || "Não foi possível enviar as alterações");
            return;
        }

        state.deviceModal.configurations = result.configurations || state.deviceModal.configurations;
        state.deviceModal.configurationSync = result.configurationSync || state.deviceModal.configurationSync;
        state.deviceModal.capabilities = result.capabilities || state.deviceModal.capabilities;
        toast("success", "Alterações enviadas. A aguardar confirmação do dispositivo.");
    } catch (error) {
        toast("error", error instanceof Error ? error.message : "Não foi possível enviar as alterações");
    } finally {
        if (button) button.dataset.configPhase = "idle";
        renderDeviceConfigurationModal();
    }
}

/**
 * Os comandos que o utilizador não desfaz a partir daqui: o aparelho fica desligado até
 * alguém lhe chegar ao botão, perde o que estava a fazer, ou liga a um número sem que quem
 * o usa dê por isso. O `find_device` e os restantes não estão aqui de propósito -- pedir
 * confirmação para tudo ensina a carregar em «Sim» sem ler.
 */
const restartPrompt = (imei) => ({
    title: `Reiniciar o dispositivo ${imei}?`,
    text: "Fica sem comunicar enquanto arranca.",
    confirmText: "Reiniciar",
});

const DANGEROUS_COMMANDS = {
    power_off: (imei) => ({
        title: `Desligar o dispositivo ${imei}?`,
        text: "Deixa de comunicar, e só volta a ligar no botão do próprio aparelho.",
        confirmText: "Desligar",
    }),
    reset_device: restartPrompt,
    restart_device: restartPrompt,
    monitor_number: (imei) => ({
        title: "Ligar para o número de monitorização?",
        text: `O dispositivo ${imei} liga em escuta silenciosa, sem avisar quem o traz.`,
        confirmText: "Ligar",
    }),
};

export function dangerousCommandPrompt(capabilityKey, imei) {
    return DANGEROUS_COMMANDS[capabilityKey]?.(imei) || null;
}

export async function saveDeviceConfiguration(section, actionValue = "") {
    const key = section.dataset.configKey || "";
    if (!key) return;

    // Antes de tudo o resto: cancelar não pode deixar o cartão em «a enviar».
    const prompt = dangerousCommandPrompt(
        section.dataset.capabilityKey || key,
        state.deviceModal.imei,
    );
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
    renderDeviceConfigurationModal();

    try {
        const isTransientAction = section.dataset.configTransient === "1";
        const capabilityKey = section.dataset.capabilityKey || section.dataset.configKey || "";
        const result = isTransientAction
            ? await apiRequestCapability(state.deviceModal.imei, capabilityKey, payload)
            : await apiSaveConfiguration(state.deviceModal.imei, {
                    configurations: {
                        [key]: payload,
                    },
                });
        if (result.error) {
            setConfigUi(key, {
                phase: "idle",
                feedback: {
                    tone: "danger",
                    message:
                        result.error.message ||
                        result.error.code ||
                        "Falha ao enviar configuração",
                },
            });
            renderDeviceConfigurationModal();
            return;
        }

        if (isTransientAction) {
            // O pedido disparado guarda o seu estado: é o que a pastilha do cartão mostra até
            // o dispositivo confirmar ou falhar.
            const command = (result.commands || [])[0] || null;
            state.deviceModal.actionDeliveries[capabilityKey] = command
                ? { status: deliveryStatusFromCommand(command.status), error: String(command.error || "") }
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

        setConfigUi(key, {
            phase: "sent",
            feedback: {
                tone: "success",
                message: isTransientAction
                    ? "Pedido enviado ao dispositivo."
                    : "Valor guardado no Hub e enviado. A aguardar confirmação do dispositivo.",
            },
        });
        renderDeviceConfigurationModal();
        transitionConfigPhase(key, "sent", 1200, () => {
            clearConfigUiPhase(key, "sent");
            renderDeviceConfigurationModal();
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
        renderDeviceConfigurationModal();
    }
}

/**
 * O estado de entrega correspondente ao estado de um comando.
 *
 * É a mesma tradução para configurações e para acções: ambas viajam pela mesma fila e o
 * operador não tem por que ler dois vocabulários para a mesma coisa.
 */
export function deliveryStatusFromCommand(commandStatus, confirmationMode = "") {
    const status = String(commandStatus || "");
    if (["failed", "dropped"].includes(status)) return "failed";
    if (status === "acked") {
        return String(confirmationMode) === "ack_only" ? "confirmation_unavailable" : "confirmed";
    }
    if (status === "queued") return "pending_delivery";
    if (["waiting", "sent"].includes(status)) return "awaiting_ack";
    return "";
}

export function syncDeviceModalCommandStates(imei, commands) {
    if (String(state.deviceModal.imei || "") !== String(imei || "")) {
        return;
    }

    const commandsById = new Map(
        (commands || []).map((command) => [String(command?.id || ""), command]),
    );
    let changed = false;
    for (const section of Object.values(state.deviceModal.configurationSync?.entries || {})) {
        for (const delivery of Object.values(section || {})) {
            const operation = (delivery?.operations || []).find((item) =>
                commandsById.has(String(item?.operationId || "")),
            );
            const command = operation
                ? commandsById.get(String(operation.operationId || ""))
                : null;
            if (!command) {
                continue;
            }

            const commandStatus = String(command.status || "");
            const nextStatus = deliveryStatusFromCommand(commandStatus, operation?.confirmationMode) ||
                String(delivery.status || "");
            const nextError = ["failed", "dropped"].includes(commandStatus)
                ? String(command.lastError || command.error || commandStatus)
                : "";
            if (
                nextStatus !== String(delivery.status || "") ||
                nextError !== String(delivery.error || "")
            ) {
                delivery.status = nextStatus;
                delivery.error = nextError;
                changed = true;
            }
        }
    }

    if (
        changed &&
        els?.deviceConfigRoot &&
        state.deviceModal.activeTab === "config" &&
        document.getElementById("deviceModal")?.classList.contains("show")
    ) {
        // Só o estado de entrega mudou: acerta-se a pastilha de cada bloco no sítio, para não
        // se deitar fora o que estiver a ser escrito nos outros.
        patchConfigurationDeliveryStates(
            els.deviceConfigRoot,
            state.deviceModal.configurationSync,
        );
    }
}

function setConfigUi(key, updates) {
    state.deviceModal.configUi[key] = {
        ...(state.deviceModal.configUi[key] || {}),
        ...updates,
    };
}

function clearConfigUiPhase(key, phase) {
    const current = state.deviceModal.configUi[key];
    if (!current || current.phase !== phase) {
        return;
    }

    const next = { ...current };
    delete next.phase;
    if (Object.keys(next).length === 0) {
        delete state.deviceModal.configUi[key];
        return;
    }
    state.deviceModal.configUi[key] = next;
}

function clearConfigFeedback(key) {
    const current = state.deviceModal.configUi[key];
    if (!current) {
        return;
    }

    const next = { ...current };
    delete next.feedback;
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
                    clearConfigFeedback(key);
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

export function renderDeviceConfigurationModal() {
    if (!els.deviceConfigRoot) {
        return;
    }

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
    const filteredCatalog = enabledCapKeys.length
        ? state.deviceModal.catalog.filter(
                (entry) =>
                    entry.capabilityKey &&
                    enabledCapKeys.includes(entry.capabilityKey),
            )
        : state.deviceModal.catalog.filter((entry) => entry.capabilityKey);

    els.deviceConfigRoot.innerHTML = renderDeviceConfigurationRoot({
        protocol: state.deviceModal.protocol,
        catalog: filteredCatalog,
        capabilityCatalog: state.deviceModal.capabilityCatalog,
        configurations: state.deviceModal.configurations,
        configurationSync: state.deviceModal.configurationSync,
        capabilities: state.deviceModal.capabilities,
        uiByKey: state.deviceModal.configUi,
        actionDeliveries: state.deviceModal.actionDeliveries,
        supplier: state.deviceModal.supplier,
        model: state.deviceModal.model,
        activeCategory: state.deviceModal.activeCategory,
        disabled: !state.deviceModal.protocol,
    });
    resetPhoneControls(els.deviceConfigRoot);
    captureConfigSectionPristine();
    // O rodapé de um grupo tem de dizer a verdade ao ser desenhado, e não só quando alguém
    // mexe num interruptor: se nada foi ainda enviado ao aparelho, é isso que está lá.
    for (const group of els.deviceConfigRoot.querySelectorAll("[data-config-group]")) {
        syncConfigGroupDirty(group);
    }
    armConfigFeedbackAutoClose();
}

/**
 * A fotografia do valor de cada bloco logo depois de desenhar, que é o que permite ao
 * "Enviar" só acender quando o valor muda.
 *
 * Falha aberta de propósito: um bloco cuja leitura não se consegue tirar fica com o botão
 * activo. É melhor um botão a mais do que uma configuração que não se consegue enviar.
 */
function captureConfigSectionPristine() {
    for (const section of els.deviceConfigRoot.querySelectorAll("[data-config-section]")) {
        try {
            section.dataset.configPristine = JSON.stringify(readConfigPayload(section));
        } catch {
            delete section.dataset.configPristine;
        }
        syncConfigSectionDirty(section);
    }
}

/**
 * Acende o "Enviar" do bloco quando o valor difere do que estava desenhado, ou quando não há
 * valor nenhum -- uma acção sem parâmetros envia-se tal como está.
 */
export function syncConfigSectionDirty(section) {
    const button = section.querySelector("[data-action=\"saveConfig\"]");
    // Só o estado inactivo é que se gere por diferença: a enviar, enviado ou falhado, o
    // botão está a dizer outra coisa e não se lhe mexe.
    if (!button || button.dataset.configPhase !== "idle") return;
    if (!("configPristine" in section.dataset)) return;

    // Duas situações em que não há diferença nenhuma a medir, e enviar continua a fazer
    // sentido. Uma acção é sempre um pedido novo -- mandar a pulseira vibrar outra vez, ou
    // mandá-la parar. E uma definição que o aparelho ainda não recebeu mostra o valor por
    // omissão do catálogo, não o que lá está: comparando-o consigo próprio o botão ficava
    // apagado, e a primeira configuração não tinha caminho nenhum para sair do ecrã.
    const neverSent = section.dataset.configStored === "0";
    if (section.dataset.configTransient === "1" || neverSent) {
        button.classList.add("btn-primary");
        button.classList.remove("btn-outline-secondary");
        button.disabled = false;
        return;
    }

    let dirty = true;
    try {
        const payload = readConfigPayload(section);
        // Sem parâmetros não há valor para comparar: o payload é vazio antes e depois, e por
        // diferença o botão ficava desactivado. Um payload vazio é o payload final.
        dirty = Object.keys(payload).length === 0 ||
            JSON.stringify(payload) !== section.dataset.configPristine;
    } catch {
        dirty = true;
    }

    button.classList.toggle("btn-primary", dirty);
    button.classList.toggle("btn-outline-secondary", !dirty);
    button.disabled = !dirty;
}
