import {
    getDevice as apiGetDevice,
    requestCapability as apiRequestCapability,
    saveConfiguration as apiSaveConfiguration,
} from "../../api/index.js";
import {readConfigPayload, renderDeviceConfigurationRoot} from "./index.js";
import {emptyPanel} from "../../widgets.js";
import {resetPhoneControls} from "../../phone.js";
import {hasHubRules, renderHubRules} from "../hub-rules/index.js";
import {state} from "../../state.js";

/**
 * The configuration panel inside the device modal: saving a section,
 * refreshing what the device reports, and the per-section UI phase.
 *
 * It owns the timers and the in-flight refresh promise, which is what kept it
 * inside bootstrap.js -- they are its state, not the dashboard's.
 */

let els;

const configFeedbackTimers = new Map();
const configPhaseTimers = new Map();
let deviceConfigRefreshPromise = null;

export function initDeviceConfigPanel(context) {
    els = context.els;
}

/** Called when the user dismisses a feedback alert themselves. */
export function dismissConfigFeedback(key) {
    clearTimeout(configFeedbackTimers.get(key));
    configFeedbackTimers.delete(key);
    clearConfigFeedback(key);
}

export async function saveDeviceConfiguration(section) {
    const key = section.dataset.configKey || "";
    if (!key) return;

    let payload;
    try {
        payload = readConfigPayload(section);
    } catch (error) {
        alert(error instanceof Error ? error.message : "Configuração inválida");
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

export async function refreshDeviceModalConfigurations(shouldRender = true) {
    if (
        !state.deviceModal.imei ||
        !state.deviceModal.supplier ||
        !state.deviceModal.model
    ) {
        return null;
    }

    if (deviceConfigRefreshPromise) {
        return deviceConfigRefreshPromise;
    }

    const snapshot = [
        state.deviceModal.imei,
        state.deviceModal.supplier,
        state.deviceModal.model,
    ].join("|");

    deviceConfigRefreshPromise = apiGetDevice(state.deviceModal.imei)
        .then((result) => {
            const current = [
                state.deviceModal.imei,
                state.deviceModal.supplier,
                state.deviceModal.model,
            ].join("|");
            if (snapshot !== current) {
                return result;
            }

            state.deviceModal.configurations = result?.configurations || {};
            state.deviceModal.configurationSync =
                result?.configurationSync || {entries: {}};
            state.deviceModal.capabilities = result?.capabilities || {};
            if (shouldRender) {
                renderDeviceConfigurationModal();
            }
            return result;
        })
        .finally(() => {
            deviceConfigRefreshPromise = null;
        });

    return deviceConfigRefreshPromise;
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
            const nextStatus = ["failed", "dropped"].includes(commandStatus)
                ? "failed"
                : commandStatus === "acked"
                    ? (String(operation?.confirmationMode || "") === "ack_only"
                        ? "confirmation_unavailable"
                        : "confirmed")
                    : ["queued", "waiting", "sent"].includes(commandStatus)
                        ? (commandStatus === "queued" ? "pending_delivery" : "awaiting_ack")
                        : String(delivery.status || "");
            const nextError = ["failed", "dropped"].includes(commandStatus)
                ? String(command.lastError || command.error || commandStatus)
                : "";
            if (
                nextStatus !== String(delivery.status || "")
                || nextError !== String(delivery.error || "")
            ) {
                delivery.status = nextStatus;
                delivery.error = nextError;
                changed = true;
            }
        }
    }

    if (
        changed
        && state.deviceModal.activeTab === "config"
        && document.getElementById("deviceModal")?.classList.contains("show")
    ) {
        renderDeviceConfigurationModal();
    }
}

export function setConfigUi(key, updates) {
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

export function armConfigFeedbackAutoClose() {
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

    deviceConfigRefreshPromise = null;
}

export function renderDeviceConfigurationModal() {
    if (!els.deviceConfigRoot) {
        return;
    }

    if (state.deviceModal.loading) {
        els.deviceConfigRoot.innerHTML = emptyPanel(
            "A carregar configurações...",
        );
        return;
    }

    if (state.deviceModal.catalogLoading) {
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
                  entry.capabilityKey
                  && enabledCapKeys.includes(entry.capabilityKey),
          )
        : state.deviceModal.catalog.filter((entry) => entry.capabilityKey);

    // As regras do hub sao configuracoes como as outras e vivem no mesmo separador. Vem
    // primeiro porque valem de imediato: nao ha nada a aguardar entrega.
    const deviceType = state.deviceModal.deviceType;
    const hubRules = renderHubRules(
        deviceType,
        state.deviceModal.hubRules || {},
        state.deviceModal.hubRuleFeedback || {},
    );

    els.deviceConfigRoot.innerHTML = hubRules + renderDeviceConfigurationRoot({
        // Sem downlinks E sem regras do hub e que o separador esta vazio.
        quietWhenEmpty: hasHubRules(deviceType),
        protocol: state.deviceModal.protocol,
        catalog: filteredCatalog,
        capabilityCatalog: state.deviceModal.capabilityCatalog,
        configurations: state.deviceModal.configurations,
        configurationSync: state.deviceModal.configurationSync,
        capabilities: state.deviceModal.capabilities,
        uiByKey: state.deviceModal.configUi,
        supplier: state.deviceModal.supplier,
        model: state.deviceModal.model,
        activeCategory: state.deviceModal.activeCategory,
        disabled: !state.deviceModal.protocol,
    });
    resetPhoneControls(els.deviceConfigRoot);
    captureConfigSectionPristine();
    armConfigFeedbackAutoClose();
}

/**
 * A fotografia do valor de cada bloco logo depois de desenhar.
 *
 * E o que permite ao "Enviar" so acender quando o valor muda: nove blocos com nove
 * primarios escuros davam nove accoes igualmente urgentes num separador onde a maior
 * parte das vezes nao se muda nada.
 *
 * Falha aberta de proposito: um bloco cuja leitura nao se consegue tirar fica com o
 * botao activo, que e o comportamento antigo. E melhor um botao a mais do que uma
 * configuracao que nao se consegue enviar.
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

/** Acende o "Enviar" do bloco quando o valor difere do que estava desenhado. */
export function syncConfigSectionDirty(section) {
    const button = section.querySelector('[data-action="saveConfig"]');
    // Só o estado inactivo é que se gere por diferença: a enviar, enviado ou falhado, o
    // botão está a dizer outra coisa e não se lhe mexe.
    if (!button || button.dataset.configPhase !== "idle") return;
    if (!("configPristine" in section.dataset)) return;

    let dirty = true;
    try {
        dirty = JSON.stringify(readConfigPayload(section)) !== section.dataset.configPristine;
    } catch {
        dirty = true;
    }

    button.classList.toggle("btn-primary", dirty);
    button.classList.toggle("btn-outline-secondary", !dirty);
    button.classList.toggle("row-action", !dirty);
    button.disabled = !dirty;
}
