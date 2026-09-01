import { setSelectedDetailRecent, state } from "../state.js";
import { getStreamTicket } from "../api/auth.js";

let onRenderSelection = () => {};
let onCommandsUpdated = () => {};
let eventSource = null;
let currentImei = "";
let streamLive = false;
let reconnectTimer = null;
let reconnectAttempt = 0;
let connectGeneration = 0;

// O `EventSource` religa-se sozinho em `CONNECTING`; estes atrasos cobrem o `CLOSED`, que é
// terminal. O tecto evita que um servidor em baixo leve um pedido por segundo por dashboard.
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

    // Pedir o bilhete é uma ida ao servidor, e no meio dela pode trocar-se de dispositivo:
    // só abre o stream quem ainda for a tentativa actual.
    connectGeneration += 1;
    void openStream(imei, connectGeneration);
}

async function openStream(imei, generation) {
    const url = new URL(
        `/api/devices/${encodeURIComponent(imei)}/stream`,
        window.location.origin,
    );

    const ticket = await streamTicket();
    if (generation !== connectGeneration || currentImei !== imei) {
        return;
    }
    if (ticket !== "") {
        url.searchParams.set("ticket", ticket);
    }

    eventSource = new EventSource(url);
    eventSource.addEventListener("open", handleStreamOpen);
    eventSource.addEventListener("snapshot", handleStreamUpdate);
    eventSource.addEventListener("update", handleStreamUpdate);
    eventSource.onerror = function () {
        if (eventSource?.readyState === EventSource.CLOSED) {
            closeDeviceStream();
            scheduleReconnect();
        }
    };
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
    if (eventSource) {
        eventSource.close();
        eventSource = null;
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
 * O bilhete que abre o stream, ou vazio quando não há autenticação. Uma falha aqui não trava
 * a ligação: o pedido segue, o servidor responde 401, e a religação trata do resto.
 */
async function streamTicket() {
    if (
        document.body.dataset.dashboardAuthRequired !== "true" &&
        !window.hubDashboardApiToken?.access_token
    ) {
        return "";
    }

    const result = await getStreamTicket();

    return String(result?.data?.ticket || "");
}

function handleStreamOpen() {
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
    // Uma entrega prova a ligação mesmo que o `open` se tenha perdido.
    streamLive = true;
    const data = JSON.parse(event.data);
    if (!state.selectedDetail) return;

    setSelectedDetailRecent(
        mergeRecent(state.selectedDetail.recent, data, event.type === "snapshot"),
    );
    onCommandsUpdated(currentImei, data.commands || []);
    onRenderSelection();
}
