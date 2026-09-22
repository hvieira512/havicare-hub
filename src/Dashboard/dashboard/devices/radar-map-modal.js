import {
    getRadarLayout as apiGetRadarLayout,
    syncRadarLayout as apiSyncRadarLayout,
} from "../api/index.js";
import { areaTypeStyle } from "../radar-style.js";
import { esc, when } from "../format.js";
import { html } from "../html.js";
import { apiError, toast } from "../dialogs.js";
import { refreshTooltips } from "../tooltips.js";
import { createRadarScene } from "./radar-scene.js";
import { destroyVitals, loadCharts, renderVitals, resizeVitals } from "./radar-vitals.js";
import { state } from "../state.js";

/**
 * O modal da planta de um radar, com os sinais vitais ao lado -- é o ecrã do hitCare.
 *
 * Abre do cartão «Presença», que é o que já diz quantas pessoas lá estão. As posições e as
 * leituras vêm do stream do dispositivo, que já está aberto por causa do ecrã de detalhe: ao
 * abrir há o histórico que o stream trouxe, e a partir daí é em direto. Não há sondagem
 * nenhuma, ao contrário do hitCare, que pergunta de segundo a segundo.
 *
 * Ir buscar a planta à cloud do fabricante acontece só no botão «Sincronizar».
 */

let els;
let modal;
let scene = null;
let konvaLoading = null;

export function initRadarMapModal(context) {
    els = context.els;
    modal = context.modals.radarMap;
}

/**
 * O Konva são 175 kB que só este ecrã usa, e por isso entra quando alguém abre a planta e não
 * no `<head>`. A promessa fica guardada para a segunda abertura não voltar a descarregar.
 */
function loadKonva() {
    if (globalThis.Konva) return Promise.resolve();
    if (konvaLoading) return konvaLoading;

    konvaLoading = new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = "/assets/vendor/konva/konva.min.js";
        script.onload = () => resolve();
        script.onerror = () => {
            konvaLoading = null;
            reject(new Error("Não foi possível carregar o Konva"));
        };
        document.head.appendChild(script);
    });

    return konvaLoading;
}

/** A contagem por cima da planta, como no hitCare: o número destacado e o resto discreto. */
function renderPeopleCount(count) {
    if (!els.radarMapPeopleCount) return;

    const tone = count === 0 ? "secondary" : "success";
    els.radarMapPeopleCount.innerHTML = html`<span class="fw-semibold text-${tone}">${count}</span> <span class="text-secondary">${count === 1 ? "pessoa" : "pessoas"}</span>`;
}

/** A legenda leva só os tipos que esta divisão tem: seis entradas para três áreas explicam o fabricante. */
function renderLegend(areas) {
    if (!els.radarMapLegend) return;

    const seen = new Map();
    areas.forEach((area) => {
        const { label, color } = areaTypeStyle(area.type);
        seen.set(label, color);
    });

    els.radarMapLegend.innerHTML = [...seen]
        .map(([label, color]) => html`<span class="d-inline-flex align-items-center gap-1 text-secondary" style="font-size:.7rem"><span class="rounded-1" style="width:.6rem;height:.6rem;background:${color}"></span>${label}</span>`)
        .join("");
}

/** O que está no ecrã: sem planta é o vazio, com planta é a tela. */
function showLayout(layout) {
    const configured = Boolean(layout?.configured && layout?.room);

    els.radarMapEmpty?.classList.toggle("d-none", configured);
    els.radarMapCanvas?.classList.toggle("d-none", !configured);
    els.radarMapLegend?.classList.toggle("d-none", !configured);

    if (!configured) {
        scene?.destroy();
        scene = null;
        return;
    }

    if (!scene) {
        scene = createRadarScene({ onPeopleCountChange: renderPeopleCount });
        scene.init(els.radarMapCanvas);
    }

    scene.renderRoom(layout);
    renderLegend(layout.areas);
    scene.updatePeople(currentPeople());
}

/** O que o stream trouxe para o dispositivo escolhido, da mais recente para a mais antiga. */
function recentTelemetry() {
    const telemetry = state.selectedDetail?.recent?.telemetry;

    return Array.isArray(telemetry) ? telemetry : [];
}

function currentPeople() {
    const presence = recentTelemetry().find((row) => row?.type === "presence");
    const people = presence?.data?.people;

    return Array.isArray(people) ? people : [];
}

function subtitle(imei, layout) {
    if (!layout?.room) return esc(imei);

    const width = ((layout.room.x_max_dm - layout.room.x_min_dm) / 10).toFixed(1);
    const height = ((layout.room.y_max_dm - layout.room.y_min_dm) / 10).toFixed(1);

    return `${esc(imei)} · ${width} × ${height} m · sincronizado ${when(layout.fetchedAt)}`;
}

async function load(imei) {
    const response = await apiGetRadarLayout(imei);
    if (response.error) {
        toast("error", apiError(response));
        return;
    }

    state.radarMap.layout = response.data;
    if (els.radarMapSubtitle) {
        els.radarMapSubtitle.textContent = subtitle(imei, response.data);
    }
    showLayout(response.data);
}

export async function openRadarMap(imei) {
    state.radarMap.imei = imei;
    state.radarMap.layout = null;

    if (els.radarMapSubtitle) els.radarMapSubtitle.textContent = esc(imei);
    renderPeopleCount(currentPeople().length);

    // As pastilhas do resumo dizem só o número: o que cada uma é vive na tooltip, e as
    // tooltips do Bootstrap são por adesão.
    refreshTooltips(els.radarMapModal);

    modal.show();

    try {
        await Promise.all([loadKonva(), loadCharts()]);
    } catch (error) {
        toast("error", error.message);
        return;
    }

    renderVitals(els, recentTelemetry());
    await load(imei);
}

/** O botão: é o único caminho por onde se vai à cloud do fabricante. */
export async function syncRadarMap() {
    const imei = state.radarMap.imei;
    if (!imei) return;

    const button = els.radarMapSyncBtn;
    button.disabled = true;
    button.innerHTML = "<i class=\"fa-solid fa-rotate fa-spin me-1\" aria-hidden=\"true\"></i>A sincronizar…";

    try {
        const response = await apiSyncRadarLayout(imei);
        if (response.error) {
            toast("error", apiError(response));
            return;
        }

        const result = response.data;
        if (result.synced > 0) {
            state.radarMap.layout = result.layout;
            els.radarMapSubtitle.textContent = subtitle(imei, result.layout);
            showLayout(result.layout);
            toast("success", "Planta sincronizada");
            return;
        }

        // O `777` do fabricante diz que a cloud não conhece este aparelho, e isso não se
        // adivinha de um "não deu": vale mais do que esconder o código.
        const code = Object.keys(result.codes || {}).find((key) => key !== "200");
        toast("warning", code === "777"
            ? "A cloud do fabricante não reconhece este radar."
            : `A cloud do fabricante não devolveu a planta${code ? ` (código ${code})` : ""}.`);
    } finally {
        button.disabled = false;
        button.innerHTML = "<i class=\"fa-solid fa-rotate me-1\" aria-hidden=\"true\"></i>Sincronizar";
    }
}

/** Chamado a cada render do detalhe, que é o que o stream despoleta. */
export function onRadarPresence(imei) {
    if (state.radarMap.imei !== imei) return;

    scene?.updatePeople(currentPeople());
    renderVitals(els, recentTelemetry());
}

/** As telas não se redimensionam sozinhas, e o modal só tem tamanho depois de aberto. */
export function resizeRadarMap() {
    if (scene && els.radarMapCanvas) scene.resize(els.radarMapCanvas);
    resizeVitals(els, recentTelemetry());
}

export function closeRadarMap() {
    scene?.destroy();
    scene = null;
    destroyVitals();
    state.radarMap.imei = "";
    state.radarMap.layout = null;
}
