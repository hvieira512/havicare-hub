import {
    getRadarLayout as apiGetRadarLayout,
    saveConfiguration as apiSaveConfiguration,
    syncRadarLayout as apiSyncRadarLayout,
} from "../../api/index.js";
import { areaTypeStyle } from "../../radar-style.js";
import { when } from "../../format.js";
import { html } from "../../html.js";
import { apiError, confirmDestructive, toast } from "../../dialogs.js";
import { refreshTooltips } from "../tooltips.js";
import { createRadarScene } from "./radar-scene.js";
import { createAreaEditor } from "./radar-area-editor.js";
import { renderVitals } from "./radar-vitals.js";
import { loadScript } from "./load-script.js";
import { state } from "../../state.js";
import { countLabel } from "../../i18n.js";
import { els, whileBusy } from "../../dom.js";

/**
 * A planta de um radar com os sinais vitais ao lado. Posições e leituras vêm do stream que o
 * detalhe já abriu, sem sondagem; a cloud do fabricante só se consulta no «Sincronizar».
 */

let modal;
let scene = null;
let editor = null;
let editButton = null;
const reloadTimers = new Set();

/** Depois do envio a planta volta a ler-se duas vezes, à espera de o radar ter aplicado. */
const RELOAD_AFTER_SEND_MS = [5000, 20000];

/** O espaço à volta da sala no editor, para se poder puxar uma área para fora dela. */
const EDIT_MARGIN_DM = 10;

export function initRadarMapModal(context) {
    modal = context.modals.radarMap;

    editButton = document.createElement("button");
    editButton.type = "button";
    editButton.className = "btn btn-sm btn-outline-secondary flex-shrink-0";
    editButton.textContent = "Editar áreas";
    editButton.addEventListener("click", () => void toggleEditing());
    const sync = els.radarMapSyncBtn;
    if (sync) {
        const buttons = document.createElement("div");
        buttons.className = "d-flex gap-2 flex-shrink-0";
        sync.replaceWith(buttons);
        buttons.append(editButton, sync);
    }
}

/** O Konva são 175 kB que só este ecrã usa: entra quando alguém abre a planta. */
function loadKonva() {
    if (globalThis.Konva) return Promise.resolve();
    // O erro do carregador traz o caminho do ficheiro, que não é linguagem de quem lê o aviso.
    return loadScript("/assets/vendor/konva/konva.min.js").catch(() => {
        throw new Error("Não foi possível carregar a planta da divisão.");
    });
}

/** A contagem por cima da planta: o número destacado e o resto discreto. */
function renderPeopleCount(count) {
    if (!els.radarMapPeopleCount) return;

    const tone = count === 0 ? "secondary" : "primary";
    els.radarMapPeopleCount.innerHTML = html`<span class="fw-semibold text-${tone}">${count}</span> <span class="text-secondary">${count === 1 ? "pessoa" : "pessoas"}</span>`;
}

/** A legenda leva só os tipos que esta divisão tem. */
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

    scene.renderRoom(editor ? editingLayout(layout) : layout);
    renderLegend(layout.areas);
    scene.updatePeople(currentPeople());
}

/** A sala sem as áreas, que são do editor, e com a escala presa ao que a planta tinha. */
function editingLayout(layout) {
    const boxes = [layout.room, ...layout.areas];
    return {
        ...layout,
        areas: [],
        extent: {
            xMinDm: Math.min(...boxes.map((box) => box.xMinDm)) - EDIT_MARGIN_DM,
            yMinDm: Math.min(...boxes.map((box) => box.yMinDm)) - EDIT_MARGIN_DM,
            xMaxDm: Math.max(...boxes.map((box) => box.xMaxDm)) + EDIT_MARGIN_DM,
            yMaxDm: Math.max(...boxes.map((box) => box.yMaxDm)) + EDIT_MARGIN_DM,
        },
    };
}

function startEditing() {
    const layout = state.radarMap.layout;
    if (!scene || !layout?.room) {
        toast("warning", "Sincronize primeiro a planta do radar.");
        return;
    }

    editor = createAreaEditor({
        scene,
        host: els.radarMapCanvas.parentElement,
        canvas: els.radarMapCanvas,
        draft: layout,
        onSend: sendAreas,
    });
    showLayout(layout);
    editor.redraw();
    els.radarMapLegend?.classList.add("d-none");
    syncEditControls();
}

function stopEditing() {
    editor?.destroy();
    editor = null;
    syncEditControls();
    if (state.radarMap.layout) showLayout(state.radarMap.layout);
}

function syncEditControls() {
    if (editButton) editButton.textContent = editor ? "Fechar edição" : "Editar áreas";
    if (els.radarMapSyncBtn) els.radarMapSyncBtn.disabled = Boolean(editor);
}

async function toggleEditing() {
    if (!editor) {
        startEditing();
        return;
    }
    const pending = editor.pending();
    if (pending > 0) {
        const { isConfirmed } = await confirmDestructive(
            "Fechar sem enviar?",
            `${countLabel(pending, "área fica", "áreas ficam")} por enviar ao radar.`,
            "Fechar sem enviar",
        );
        if (!isConfirmed) return;
    }
    stopEditing();
}

/** O backend compara com o que o radar tem e apaga as que faltam: vão todas as que ficam. */
async function sendAreas(items) {
    const imei = state.radarMap.imei;
    const result = await apiSaveConfiguration(imei, { configurations: { radar_areas: { items } } });
    if (state.radarMap.imei !== imei) return result;
    if (result.error) {
        // As regras recusadas, o editor mostra-as por cima da planta.
        if (result.error.code !== "invalid_radar_areas") toast("error", apiError(result));
        return result;
    }

    // O separador das configurações, por baixo, passa a mostrar o que se enviou.
    if (state.deviceModal.imei === imei) {
        state.deviceModal.configurations = result.configurations || state.deviceModal.configurations;
        state.deviceModal.configurationSync = result.configurationSync || state.deviceModal.configurationSync;
    }

    toast("success", "Áreas enviadas ao radar. A planta atualiza-se quando ele as aplicar.");
    stopEditing();
    for (const delay of RELOAD_AFTER_SEND_MS) {
        const timer = setTimeout(() => {
            reloadTimers.delete(timer);
            if (state.radarMap.imei === imei && !editor) void load(imei);
        }, delay);
        reloadTimers.add(timer);
    }
    return result;
}

/** O que o stream trouxe para o dispositivo escolhido, da mais recente para a mais antiga. */
function recentTelemetry() {
    // Aberta pelas configurações, a planta pode ser de outro radar que não o do detalhe.
    if (String(state.selectedDetail?.device?.imei ?? "") !== state.radarMap.imei) return [];
    const telemetry = state.selectedDetail?.recent?.telemetry;

    return Array.isArray(telemetry) ? telemetry : [];
}

function currentPeople() {
    const presence = recentTelemetry().find((row) => row?.type === "presence");
    const people = presence?.data?.people;

    return Array.isArray(people) ? people : [];
}

/** As medidas da divisão com uma casa decimal, à portuguesa. */
const METERS = new Intl.NumberFormat("pt-PT", { minimumFractionDigits: 1, maximumFractionDigits: 1 });

function subtitle(imei, layout) {
    if (!layout?.room) return imei;

    const width = METERS.format((layout.room.xMaxDm - layout.room.xMinDm) / 10);
    const height = METERS.format((layout.room.yMaxDm - layout.room.yMinDm) / 10);

    return `${imei} · ${width} × ${height} m · sincronizado ${when(layout.fetchedAt)}`;
}

/** Só se desenha a planta do mapa ainda aberto: depois de fechar, ninguém destruiria a cena. */
async function load(imei) {
    const response = await apiGetRadarLayout(imei);
    if (state.radarMap.imei !== imei) {
        return;
    }
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

export async function openRadarMap(imei, { editAreas = false } = {}) {
    state.radarMap.imei = imei;
    state.radarMap.layout = null;

    if (els.radarMapSubtitle) els.radarMapSubtitle.textContent = imei;
    renderPeopleCount(currentPeople().length);

    // As pastilhas do resumo dizem só o número: o que cada uma é vive na tooltip, e as
    // tooltips do Bootstrap são por adesão.
    refreshTooltips(els.radarMapModal);
    renderVitals(els, recentTelemetry());

    modal.show();

    try {
        await loadKonva();
    } catch (error) {
        toast("error", error.message);
        return;
    }

    // O Konva chega da rede, e quem fechou entretanto não quer ver nada desenhado.
    if (state.radarMap.imei !== imei) {
        return;
    }

    await load(imei);
    if (editAreas && state.radarMap.imei === imei) startEditing();
}

/** O botão: é o único caminho por onde se vai à cloud do fabricante. */
export async function syncRadarMap() {
    const imei = state.radarMap.imei;
    if (!imei) return;

    await whileBusy(els.radarMapSyncBtn, () => sync(imei),
        "<i class=\"fa-solid fa-rotate fa-spin me-1\" aria-hidden=\"true\"></i>A sincronizar…");
}

async function sync(imei) {
    const response = await apiSyncRadarLayout(imei);
    if (response.error) {
        toast("error", apiError(response));
        return;
    }

    // Quem fechou antes de a cloud responder não quer nada desenhado.
    if (state.radarMap.imei !== imei) {
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

    // O `777` do fabricante diz que a cloud não conhece este aparelho.
    const code = Object.keys(result.codes || {}).find((key) => key !== "200");
    toast("warning", code === "777"
        ? "A cloud do fabricante não reconhece este radar."
        : `A cloud do fabricante não devolveu a planta${code ? ` (código ${code})` : ""}.`);
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
    editor?.redraw();
}

export function closeRadarMap() {
    for (const timer of reloadTimers) clearTimeout(timer);
    reloadTimers.clear();
    editor?.destroy();
    editor = null;
    syncEditControls();
    scene?.destroy();
    scene = null;
    state.radarMap.imei = "";
    state.radarMap.layout = null;
}
