import { setSelectedDetailRecent, state } from "../state.js";
import { authHeaders } from "../api/http.js";

let onRenderSelection = () => {};
let onCommandsUpdated = () => {};
let renderFramePending = false;

/**
 * Coalesce os renders do stream: um radar publica várias mensagens por segundo, e uma rajada
 * (um instantâneo com o histórico atrás) redesenhava o detalhe por inteiro uma vez por cada.
 * Guardado atrás de um `requestAnimationFrame` já agendado, a rajada dá um render só.
 */
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
let streamLive = false;
let reconnectTimer = null;
let reconnectAttempt = 0;
let connectGeneration = 0;

// O `fetch` não religa sozinho. O tecto evita que um servidor em baixo leve um pedido por
// segundo por cada dashboard aberta.
const RECONNECT_BASE_MS = 1000;
const RECONNECT_MAX_MS = 30000;

/**
 * Se o stream está a entregar. É o único caminho por onde o `recent` chega -- o
 * `GET /api/devices/{imei}` não devolve telemetria, eventos nem comandos.
 */
export function isDeviceStreamLive() {
    return streamLive;
}

/**
 * Um separador em segundo plano não drena o stream e deixa o servidor a encher buffer. Ao
 * voltar religa-se do zero, e o `snapshot` fecha o buraco no histórico.
 */
function handleVisibilityChange() {
    if (!currentImei) {
        return;
    }

    if (document.hidden) {
        closeDeviceStream();
        return;
    }

    if (
        document.body.dataset.dashboardAuthRequired === "true" &&
        !window.hubDashboardApiToken?.access_token
    ) {
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

    // Abrir o stream é assíncrono, e no meio pode trocar-se de dispositivo: só serve quem
    // ainda for a tentativa actual.
    connectGeneration += 1;
    void openStream(imei, connectGeneration);
}

/**
 * Abre o stream com a credencial no cabeçalho, como todas as outras chamadas da dashboard.
 *
 * O `fetch` obriga a cortar os frames à mão, mas dá acesso ao estado da resposta: um 401, um
 * 404 e um `503 too_many_streams` pedem tratamento diferente e o `EventSource` não os
 * distinguia.
 */
async function openStream(imei, generation) {
    const url = `/api/devices/${encodeURIComponent(imei)}/stream`;
    const controller = new AbortController();
    abortController = controller;

    try {
        const response = await fetch(url, {
            headers: { ...authHeaders(), Accept: "text/event-stream" },
            signal: controller.signal,
        });
        // Trocou-se de dispositivo enquanto isto viajava: o corpo desta resposta tem de ser
        // largado, ou fica um stream a servir para ninguém do outro lado.
        if (generation !== connectGeneration || currentImei !== imei) {
            controller.abort();
            return;
        }
        if (!response.ok || !response.body) {
            scheduleReconnect();
            return;
        }

        streamLive = true;
        await readFrames(response.body, generation, imei);
    } catch (error) {
        // Um `abort()` nosso não é falha: foi o `closeDeviceStream` a fechar de propósito.
        if (controller.signal.aborted) {
            return;
        }
    }

    if (generation === connectGeneration && currentImei === imei) {
        streamLive = false;
        scheduleReconnect();
    }
}

/**
 * Lê o corpo e corta-o em frames SSE, guardando o pedaço incompleto para o chunk seguinte.
 *
 * O `snapshot` traz até cem entradas de telemetria e cem de eventos, pelo que um frame partido
 * entre chunks é o caso normal. Descartar o resto do buffer truncava o histórico no ecrã sem
 * dar erro.
 */
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

    if (data.length === 0) {
        // Só keep-alive: prova a ligação e mais nada.
        streamLive = true;
        return;
    }

    handleStreamUpdate({ type, data: data.join("\n") });
}

export function disconnectDeviceStream() {
    currentImei = "";
    reconnectAttempt = 0;
    closeDeviceStream();
}

function closeDeviceStream() {
    streamLive = false;
    if (reconnectTimer !== null) {
        window.clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }
    if (abortController) {
        abortController.abort();
        abortController = null;
    }
}

/**
 * Volta a ligar com o atraso a crescer, e só com dispositivo escolhido. Sem credencial não
 * se tenta -- o `hub-dashboard-api-token-updated` liga quando ela chegar.
 */
function scheduleReconnect() {
    if (!currentImei || reconnectTimer !== null) {
        return;
    }
    if (
        document.body.dataset.dashboardAuthRequired === "true" &&
        !window.hubDashboardApiToken?.access_token
    ) {
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

/**
 * A espera acumulada só se apaga quando o stream **entrega**, e não quando abre. Um servidor
 * que aceita e fecha logo devolve 200, e repor o contador aí anulava o recuo.
 */
function markStreamServed() {
    streamLive = true;
    reconnectAttempt = 0;
}

function handleTokenUpdated() {
    if (!currentImei) {
        return;
    }
    if (
        document.body.dataset.dashboardAuthRequired === "true" &&
        !window.hubDashboardApiToken?.access_token
    ) {
        closeDeviceStream();
        return;
    }

    // Credencial nova é uma razão nova para tentar: a espera acumulada pelas falhas
    // anteriores não se aplica a esta.
    reconnectAttempt = 0;
    connectDeviceStream(currentImei);
}

/**
 * Junta o que chegou ao que já cá estava. O `snapshot` substitui; as actualizações trazem só
 * o que é novo e empilham-se à frente, com a lista aparada ao limite do servidor.
 *
 * Os comandos vêm sempre por inteiro: mudam de estado, e isso não se manda por diferenças.
 */
function mergeRecent(previous, data, isSnapshot) {
    const limit = Number(data.limit) > 0 ? Number(data.limit) : 100;
    const merge = (incoming, existing) => (isSnapshot
        ? incoming
        : [...incoming, ...existing].slice(0, limit));

    return {
        telemetry: merge(data.telemetry || [], previous?.telemetry || []),
        events: merge(data.events || [], previous?.events || []),
        commands: data.commands || [],
    };
}

function handleStreamUpdate(event) {
    // Uma entrega é a prova de que a ligação serve, e é ela que apaga a espera acumulada.
    markStreamServed();
    const data = JSON.parse(event.data);
    if (!state.selectedDetail) return;

    setSelectedDetailRecent(
        mergeRecent(state.selectedDetail.recent, data, event.type === "snapshot"),
    );
    onCommandsUpdated(currentImei, data.commands || []);
    scheduleSelectionRender();
}
