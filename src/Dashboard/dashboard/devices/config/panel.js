import {
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
 * As definições de uma secção -- ou de um grupo -- cujo valor difere do que estava desenhado.
 *
 * Só o que mudou é que viaja: enviar as oito de uma vez transformava uma alteração num lote
 * de oito comandos para a pulseira executar um a um, e o aparelho serve um de cada vez.
 *
 * As acções ficam de fora: elas não se guardam, e o botão delas é que as dispara.
 *
 * @returns {Object<string, object>} chave da definição => valor a enviar
 */
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

export function changedConfigEntries(container) {
    const changed = {};
    const rows = container.querySelectorAll(SAVEABLE_BLOCKS);
    for (const row of rows) {
        const key = row.dataset.configKey || "";
        if (!key) continue;

        let payload;
        try {
            payload = readConfigPayload(row);
        } catch {
            continue;
        }

        const pristine = row.dataset.configPristine ?? "";
        // Uma linha que o aparelho nunca recebeu viaja sem ninguém lhe ter tocado, porque o
        // grupo é o único caminho para a primeira gravação. Só vale para o interruptor: o
        // padrão de um número é zero, e num intervalo de medição zero quer dizer desactivar.
        const neverSent = row.dataset.configStored === "0" &&
            row.dataset.configInput === "toggle";
        if (neverSent || JSON.stringify(payload) !== pristine) {
            changed[key] = payload;
        }
    }

    return changed;
}

/**
 * Quantas alterações à configuração estão escritas no ecrã e por enviar ao aparelho.
 *
 * Conta só o que alguém editou: avisar por causa das que ninguém tocou era avisar em todos
 * os relógios, todas as vezes.
 */
/**
 * Se o que está escrito no bloco difere da fotografia tirada ao desenhá-lo.
 *
 * Falha aberta como a fotografia e o acender do botão: um bloco cuja leitura não se consegue
 * tirar conta como alteração, para o rodapé não se calar sobre uma validação que falhou.
 */
function isConfigBlockEdited(element) {
    if (!("configPristine" in element.dataset)) return false;
    try {
        return JSON.stringify(readConfigPayload(element)) !== element.dataset.configPristine;
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

/**
 * Marca os blocos com edição por enviar.
 *
 * É por esta marca que a pastilha «Alterado» e o valor anterior aparecem: as duas leituras
 * são desenhadas juntas, e trocá-las por marcação nova a cada tecla mexia num campo em uso.
 */
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
    // Enviar o resto e dizer que ficou guardado escondia a definição que não passou.
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
        // Trocou-se de aparelho enquanto isto viajava: a resposta é do anterior e não pode
        // escrever as configurações de quem está agora no ecrã.
        if (state.deviceModal.imei !== imei) return;
        if (result.error) {
            toast("error", result.error.message || "Não foi possível enviar as alterações");
            return;
        }

        state.deviceModal.configurations = result.configurations || state.deviceModal.configurations;
        state.deviceModal.configurationSync = result.configurationSync || state.deviceModal.configurationSync;
        state.deviceModal.capabilities = result.capabilities || state.deviceModal.capabilities;
        // Um aviso de agregado aponta, não repete: as alterações são de vários cartões e a
        // pastilha de cada um conta o que lhe aconteceu. E guardadas no Hub é o que de facto
        // aconteceu -- a entrega ao aparelho pode nem ter começado.
        toast("success", "Alterações guardadas no Hub. A entrega ao dispositivo aparece em cada cartão.");
    } catch (error) {
        toast("error", error instanceof Error ? error.message : "Não foi possível enviar as alterações");
    } finally {
        if (button) button.dataset.configPhase = "idle";
        renderDeviceConfigurationModal();
    }
}

/**
 * A caixa de uma acção que o utilizador não desfaz a partir daqui.
 *
 * A frase vem da definição do protocolo, e não de uma tabela indexada pela capacidade: o
 * `reset_device` da Wonlex repõe o relógio de fábrica e o do 4P Touch reinicia-o. Sem frase
 * declarada não leva caixa -- pedir confirmação para tudo ensina a carregar em «Sim» sem ler.
 */
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
    renderDeviceConfigurationModal();

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
            // O `id` fica guardado com o estado: é por ele que a resposta do aparelho se casa
            // com esta acção quando chega pelo stream. Sem ele a pastilha era escrita uma vez
            // no envio e ficava em «A aguardar» para sempre.
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

        // Sem mensagem: quem conta o que aconteceu ao pedido é a pastilha do cartão, que o
        // acompanha até ao fim. O clique fica acusado pelo botão, que este `phase` desactiva.
        // A `feedback` é limpa de propósito -- senão um erro anterior ficava por baixo de um
        // envio que correu bem.
        setConfigUi(key, { phase: "sent", feedback: null });
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

export function syncDeviceModalCommandStates(imei, commands) {
    if (String(state.deviceModal.imei || "") !== String(imei || "")) {
        return;
    }

    const commandsById = new Map(
        (commands || []).map((command) => [String(command?.id || ""), command]),
    );
    let changed = false;

    // As acções guardam o estado de entrega noutro mapa, e ele também tem de acompanhar o
    // comando até ao fim: sem isto o servidor dava o pedido por confirmado e o cartão
    // continuava a dizer «A aguardar» até alguém fechar e reabrir o modal.
    for (const [capabilityKey, delivery] of Object.entries(state.deviceModal.actionDeliveries || {})) {
        const command = delivery?.id ? commandsById.get(String(delivery.id)) : null;
        if (!command) {
            continue;
        }

        const commandStatus = String(command.status || "");
        const nextStatus = deliveryStatusFromCommand(commandStatus) || String(delivery.status || "");
        const nextError = ["failed", "dropped"].includes(commandStatus)
            ? String(command.lastError || command.error || commandStatus)
            : "";
        if (nextStatus !== String(delivery.status || "") || nextError !== String(delivery.error || "")) {
            state.deviceModal.actionDeliveries[capabilityKey] = {
                ...delivery,
                status: nextStatus,
                error: nextError,
            };
            changed = true;
        }
    }
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
        // Uma entrega que acaba de falhar volta a tornar a configuração enviável, sem se lhe
        // mexer no valor: é a mesma regra de quem desenha o cartão de raiz.
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
    captureConfigPristine(els.deviceConfigRoot);
    syncConfigCounts(els.deviceConfigRoot);
    armConfigFeedbackAutoClose();
}

/**
 * O rodapé conta edições por submeter, e não entregas -- de entrega fala a pastilha de cada
 * definição. As que nunca foram gravadas viajam sem ninguém lhes ter tocado, e por isso
 * contam-se à parte das alterações.
 */
function paneStatusLabel(pending, edited) {
    // Sem nada por enviar não se diz nada: os dois botões desligados já o dizem, e a frase
    // roubava a linha que o «1 alteração por enviar» precisa quando há mesmo alguma.
    if (pending === 0) return "";
    if (edited === 0) {
        return `${pending} ${pending === 1 ? "definição" : "definições"} no valor padrão, por enviar`;
    }
    return `${edited} ${edited === 1 ? "alteração" : "alterações"} por enviar`;
}

/**
 * As três contas da mesma coisa: o separador do modal, a linha de cada secção e o rodapé
 * dela. Saem todas das mesmas duas leituras, para nenhuma poder discordar das outras.
 */
export function syncConfigCounts(root) {
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
                : ` · ${edited} ${edited === 1 ? "alterada" : "alteradas"}`;
            changed.classList.toggle("text-warning-emphasis", edited > 0);
        }

        const status = pane.querySelector("[data-config-pane-status]");
        if (status) {
            status.textContent = paneStatusLabel(pending, edited);
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

/**
 * A fotografia do valor de cada bloco logo depois de desenhar, que é o que permite ao
 * "Enviar" só acender quando o valor muda.
 *
 * Falha aberta de propósito: um bloco cuja leitura não se consegue tirar fica com o botão
 * activo. É melhor um botão a mais do que uma configuração que não se consegue enviar.
 */
export function captureConfigPristine(root) {
    for (const section of root.querySelectorAll("[data-config-section]")) {
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
    // sentido: uma acção é sempre um pedido novo, e uma definição que o aparelho ainda não
    // recebeu mostra o valor por omissão do catálogo e não o que lá está.
    const neverSent = section.dataset.configStored === "0";
    // A terceira situação: a entrega falhou. O valor está guardado e é o que está no ecrã, por
    // isso não há diferença nenhuma a medir -- e era precisamente por não haver que o botão se
    // apagava, deixando a configuração sem caminho para sair a não ser mexendo-lhe no valor.
    // O que falhou foi a entrega, não o valor, e repeti-la é a única coisa que faz sentido.
    const deliveryFailed = section.dataset.configDelivery === "failed";
    // A cor do botão aceso. Uma acção que pede confirmação continua a vermelho: o peso dela
    // está aqui, e não numa faixa de aviso, e não pode ser apagado por um sincronismo.
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
        // Sem parâmetros não há valor para comparar: o payload é vazio antes e depois, e por
        // diferença o botão ficava desactivado. Um payload vazio é o payload final.
        dirty = Object.keys(payload).length === 0 ||
            JSON.stringify(payload) !== section.dataset.configPristine;
    } catch {
        dirty = true;
    }

    button.classList.toggle(active, dirty);
    button.classList.toggle("btn-outline-secondary", !dirty);
    button.disabled = !dirty;
}
