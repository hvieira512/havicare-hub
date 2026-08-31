// O `state` entrava aqui por injecção, e passou a entrar por import como em todos os outros
// módulos. Enquanto as escritas passaram a ir pelos mutadores do módulo e a leitura ficou no
// objecto injectado, eram o mesmo objecto em produção -- mas só por o `app.js` injectar o
// verdadeiro. Um teste que injectasse outro lia de um e escrevia no outro.
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

// O `EventSource` religa-se sozinho enquanto fica em `CONNECTING`. O `CLOSED` é terminal, e
// é esse que estes atrasos cobrem. O tecto de meio minuto existe para um servidor em baixo
// não levar um pedido por segundo de cada dashboard aberta.
const RECONNECT_BASE_MS = 1000;
const RECONNECT_MAX_MS = 30000;

/**
 * Se o stream está a entregar.
 *
 * Importa saber porque este é o único caminho por onde o `recent` chega: o
 * `GET /api/devices/{imei}` devolve o dispositivo, o modelo e a configuração, e não a
 * telemetria, os eventos nem os comandos.
 */
export function isDeviceStreamLive() {
    return streamLive;
}

/**
 * Um separador em segundo plano deixa de drenar o stream, e o servidor fica a encher buffer
 * por ele -- foi assim que o processo em produção rebentou o limite de memória. O servidor
 * passou a parar de escrever quando o cliente não drena; isto é o outro lado da mesma moeda:
 * quem não está a ver não precisa de stream nenhum.
 *
 * Ao voltar, religa-se do zero, e a primeira coisa que o servidor manda é um `snapshot` --
 * por isso não há buraco no histórico.
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

    // Cada tentativa leva o seu número. Pedir o bilhete é uma ida ao servidor, e no meio dela
    // o utilizador pode trocar de dispositivo, esconder o separador ou disparar uma
    // religação: quando a resposta chega, só abre o stream quem ainda for a tentativa actual.
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
            // Antes desistia-se aqui, e o histórico do dispositivo ficava congelado até
            // alguém trocar de dispositivo ou o token ser renovado: a sondagem de 30 em 30
            // segundos preserva de propósito o `recent` que já tinha, e por isso não havia
            // nada a ir buscar telemetria nova depois de o stream cair.
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
 * Volta a ligar com o atraso a crescer, e só enquanto houver dispositivo escolhido.
 *
 * Sem credencial não se tenta: o `hub-dashboard-api-token-updated` liga quando ela chegar, e
 * insistir aqui só produzia 401 em série.
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
 * O bilhete que abre o stream, ou vazio quando não há autenticação a fazer.
 *
 * Uma falha aqui não trava a ligação: sem bilhete o pedido segue e o servidor responde 401,
 * e o caminho de religação já sabe lidar com isso. Insistir aqui era duplicar essa lógica.
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

function handleStreamUpdate(event) {
    // Uma entrega prova a ligação mesmo que o `open` se tenha perdido.
    streamLive = true;
    const data = JSON.parse(event.data);
    if (!state.selectedDetail) return;
    setSelectedDetailRecent({
        telemetry: data.telemetry || [],
        events: data.events || [],
        commands: data.commands || [],
    });
    onCommandsUpdated(currentImei, data.commands || []);
    onRenderSelection();
}
