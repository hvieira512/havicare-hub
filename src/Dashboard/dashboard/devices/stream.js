import { state } from "../core/state.js";
import { authHeaders, isSignedIn } from "../api/http.js";

let onRenderSelection = () => {};
let onCommandsUpdated = () => {};
let renderFramePending = false;

/** Um radar publica várias mensagens por segundo: cada rajada dá um só render por frame. */
function scheduleSelectionRender() {
    if (renderFramePending) {
        return;
    }
    renderFramePending = true;
    const raf = globalThis.requestAnimationFrame || ((callback) => setTimeout(callback, 16));
    raf(() => {
        renderFramePending = false;
        onRenderSelection();
    });
}
let abortController = null;
let currentImei = "";
let reconnectTimer = null;
let reconnectAttempt = 0;
let connectGeneration = 0;

// O tecto evita que um servidor em baixo leve um pedido por segundo de cada dashboard aberta.
const RECONNECT_BASE_MS = 1000;
const RECONNECT_MAX_MS = 30000;

export function isDeviceStreamFor(imei) {
    return imei !== "" && imei === currentImei && abortController !== null;
}

/** Em segundo plano o stream fecha; ao voltar religa-se, e o `snapshot` fecha o buraco. */
function handleVisibilityChange() {
    if (!currentImei) {
        return;
    }

    if (document.hidden) {
        closeDeviceStream();
        return;
    }

    if (!isSignedIn()) {
        return;
    }

    reconnectAttempt = 0;
    connectDeviceStream(currentImei);
}

export function initDeviceStream(context) {
    onRenderSelection = context.renderSelection;
    onCommandsUpdated = context.onCommandsUpdated || (() => {});
    window.addEventListener("hub-dashboard-api-token-updated", handleTokenUpdated);
    document.addEventListener("visibilitychange", handleVisibilityChange);
}

export function connectDeviceStream(imei) {
    currentImei = imei;
    closeDeviceStream();
    if (!currentImei) {
        return;
    }

    // No meio de abrir pode trocar-se de dispositivo: só serve a tentativa actual.
    connectGeneration += 1;
    void openStream(imei, connectGeneration);
}

/** Por `fetch` e não `EventSource`: leva a credencial no cabeçalho e distingue os estados HTTP. */
async function openStream(imei, generation) {
    const url = `/api/devices/${encodeURIComponent(imei)}/stream`;
    const controller = new AbortController();
    abortController = controller;

    try {
        const response = await fetch(url, {
            headers: { ...authHeaders(), Accept: "text/event-stream" },
            signal: controller.signal,
        });
        // Trocou-se de dispositivo entretanto: larga-se o corpo, senão fica a servir ninguém.
        if (generation !== connectGeneration || currentImei !== imei) {
            controller.abort();
            return;
        }
        // Uma credencial recusada não melhora com tentativas: pede-se autenticação, não se religa.
        if (response.status === 401 || response.status === 403) {
            window.dispatchEvent(new Event("hub-dashboard-auth-required"));
            return;
        }
        if (!response.ok || !response.body) {
            scheduleReconnect();
            return;
        }

        await readFrames(response.body, generation, imei);
    } catch {
        // Um `abort()` nosso não é falha.
        if (controller.signal.aborted) {
            return;
        }
    }

    if (generation === connectGeneration && currentImei === imei) {
        scheduleReconnect();
    }
}

/** Corta o corpo em frames SSE e guarda o pedaço incompleto para o chunk seguinte. */
async function readFrames(body, generation, imei) {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";

    for (;;) {
        const { value, done } = await reader.read();
        if (done || generation !== connectGeneration || currentImei !== imei) {
            return;
        }

        buffer += decoder.decode(value, { stream: true });
        const parts = buffer.split("\n\n");
        buffer = parts.pop() ?? "";
        for (const frame of parts) {
            dispatchFrame(frame);
        }
    }
}

/** Um frame são linhas: o `event:` dá o nome, o `data:` o corpo, e `:` sozinho é keep-alive. */
function dispatchFrame(frame) {
    let type = "message";
    const data = [];
    for (const line of frame.split("\n")) {
        if (line.startsWith(":")) {
            continue;
        }
        if (line.startsWith("event:")) {
            type = line.slice(6).trim();
        } else if (line.startsWith("data:")) {
            data.push(line.slice(5).trim());
        }
    }

    // Só keep-alive.
    if (data.length === 0) return;

    handleStreamUpdate({ type, data: data.join("\n") });
}

export function disconnectDeviceStream() {
    currentImei = "";
    reconnectAttempt = 0;
    closeDeviceStream();
}

function closeDeviceStream() {
    if (reconnectTimer !== null) {
        window.clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }
    if (abortController) {
        abortController.abort();
        abortController = null;
    }
}

/** Religa com o atraso a crescer; sem credencial liga o `hub-dashboard-api-token-updated`. */
function scheduleReconnect() {
    if (!currentImei || reconnectTimer !== null) {
        return;
    }
    if (!isSignedIn()) {
        return;
    }

    const delay = Math.min(RECONNECT_MAX_MS, RECONNECT_BASE_MS * 2 ** reconnectAttempt);
    reconnectAttempt += 1;
    reconnectTimer = window.setTimeout(() => {
        reconnectTimer = null;
        if (currentImei) {
            connectDeviceStream(currentImei);
        }
    }, delay);
}

/** A espera só se apaga quando o stream entrega: um servidor que aceita e fecha também dá 200. */
function markStreamServed() {
    reconnectAttempt = 0;
}

function handleTokenUpdated() {
    if (!currentImei) {
        return;
    }
    if (!isSignedIn()) {
        closeDeviceStream();
        return;
    }

    // Credencial nova é uma razão nova para tentar.
    reconnectAttempt = 0;
    connectDeviceStream(currentImei);
}

/**
 * O `snapshot` substitui; as actualizações empilham-se à frente, aparadas ao limite do
 * servidor. Os comandos vêm por inteiro, porque mudam de estado.
 */
function mergeRecent(previous, data, isSnapshot) {
    const limit = Number(data.limit) > 0 ? Number(data.limit) : 100;
    const merge = (incoming, existing) => (isSnapshot
        ? incoming
        : [...incoming, ...existing].slice(0, limit));

    return {
        telemetry: merge(data.telemetry || [], previous?.telemetry || []),
        latestTelemetry: data.latestTelemetry || previous?.latestTelemetry || [],
        events: merge(data.events || [], previous?.events || []),
        connections: merge(data.connections || [], previous?.connections || []),
        commands: data.commands || [],
    };
}

function handleStreamUpdate(event) {
    markStreamServed();
    let data;
    try {
        data = JSON.parse(event.data);
    } catch {
        // Um frame partido salta-se, sem derrubar o stream.
        return;
    }
    if (!state.selectedDetail) return;

    state.selectedDetail.recent = mergeRecent(
        state.selectedDetail.recent,
        data,
        event.type === "snapshot",
    );
    onCommandsUpdated(currentImei, data.commands || []);
    scheduleSelectionRender();
}
