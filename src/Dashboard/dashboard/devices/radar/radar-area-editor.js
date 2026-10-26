import { areaName, areaTypeStyle } from "../../radar-style.js";
import { html, raw } from "../../html.js";
import { stateBadge } from "../../components/state-badge.js";
import {
    CREATABLE_TYPES,
    addArea,
    areaStatus,
    areaWarnings,
    draftItems,
    firstFreeKey,
    moveArea,
    resizeArea,
    toggleDelete,
} from "./radar-area-draft.js";

/**
 * O editor das áreas por cima da planta: pastilhas das áreas, inspetor flutuante ao lado da
 * selecionada, avisos e o envio. A planta e as pessoas continuam a ser da cena.
 */

const STATUS = {
    applied: ["Aplicado", "success"],
    edited: ["Alterado", "warning"],
    new: ["Nova", "warning"],
    deleted: ["A apagar", "danger"],
};

const FURNITURE = "furniture";
const HISTORY_LIMIT = 50;
const INSPECTOR_WIDTH = 250;

const clone = (areas) => areas.map((area) => ({ ...area }));

/**
 * @param {object} options
 * @param {object} options.scene      a cena da planta, já com a sala desenhada
 * @param {HTMLElement} options.host  o corpo do cartão, onde a tela está
 * @param {HTMLElement} options.canvas o contentor da tela
 * @param {{room: object, areas: Array<object>, maxAreas: number}} options.draft a planta do radar, como a API a dá
 * @param {(items: Array<object>) => Promise<object>} options.onSend devolve a resposta da API
 */
export function createAreaEditor({ scene, host, canvas, draft, onSend }) {
    const originals = clone(draft.areas);
    const originalByKey = new Map(originals.map((area) => [area.key, area]));
    const room = draft.room;
    const maxAreas = draft.maxAreas;
    // O que a API recusou no último envio; mexer na planta apaga-o.
    let rejected = [];
    let areas = clone(originals);
    let history = [];
    let selectedKey = areas[0]?.key ?? null;
    let drag = null;
    let sending = false;

    const tools = document.createElement("div");
    tools.className = "radar-editor-tools d-flex gap-2 align-items-start mb-2";
    const inspector = document.createElement("div");
    inspector.className = "radar-area-inspector card shadow position-absolute p-3 vstack gap-2 small";
    inspector.hidden = true;
    const bottom = document.createElement("div");
    bottom.className = "radar-editor-bottom mt-3 vstack gap-2";

    host.classList.add("position-relative");
    canvas.before(tools);
    host.append(inspector, bottom);
    canvas.tabIndex = 0;
    canvas.setAttribute("aria-label", "Planta editável: setas mexem a área, Delete apaga");

    const selected = () => areas.find((area) => area.key === selectedKey) ?? null;
    const pendingCount = () => areas.filter((area) => areaStatus(area, originals) !== "applied").length;
    const liveCount = () => areas.filter((area) => !area.deleted).length;

    function remember() {
        history.push(clone(areas));
        if (history.length > HISTORY_LIMIT) history.shift();
    }

    function commit(change) {
        rejected = [];
        remember();
        change();
        render();
    }

    function undo() {
        if (history.length === 0) return;
        areas = history.pop();
        if (!selected()) selectedKey = areas[0]?.key ?? null;
        render();
    }

    function drawPlan() {
        scene.drawAreas(areas, {
            originals: new Map(areas
                .filter((area) => areaStatus(area, originals) === "edited")
                .map((area) => [area.key, originalByKey.get(area.key)])),
            selectedKey,
            warnKeys: new Set(rejected.map((warning) => warning.key)),
        });
    }

    function chip(area) {
        const status = areaStatus(area, originals);
        const { label, color } = areaTypeStyle(area.type);
        const on = area.key === selectedKey;
        return html`
            <button type="button" class="radar-area-chip btn btn-sm text-start d-grid border${on ? " active" : ""}${area.deleted ? " deleted" : ""}"
                    style="border-left: 4px solid ${color} !important" data-area-key="${area.key}" aria-pressed="${on ? "true" : "false"}"
                    aria-label="${areaName(area)}, ${label}${status === "applied" ? "" : `, ${STATUS[status][0]}`}">
                <span class="d-flex align-items-center gap-1"><span class="radar-area-chip-name">${areaName(area)}</span>${status === "applied" ? "" : raw("<span class=\"radar-area-chip-dot\" aria-hidden=\"true\"></span>")}</span>
                <span class="radar-area-chip-type fw-semibold text-secondary">${label}</span>
            </button>`;
    }

    function renderTools() {
        const full = firstFreeKey(areas, maxAreas) === null;
        tools.innerHTML = html`
            <div class="d-flex flex-wrap gap-2 flex-grow-1 min-w-0">${areas.map(chip)}</div>
            <div class="d-flex gap-2 flex-shrink-0">
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"${full ? raw(" disabled") : ""}>Nova área</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        ${CREATABLE_TYPES.map(({ type }) => {
                            const { label, color } = areaTypeStyle(type);
                            return html`<li><button type="button" class="dropdown-item d-flex align-items-center gap-2" data-area-add="${type}"><span class="rounded-1" style="width:.65rem;height:.65rem;background:${color}"></span>${label}</button></li>`;
                        })}
                    </ul>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-area-undo aria-label="Desfazer" title="Desfazer (Ctrl+Z)"${history.length === 0 ? raw(" disabled") : ""}><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
            </div>`;
    }

    function typeOptions(area) {
        if (area.type === FURNITURE) {
            return html`<select class="form-select form-select-sm" disabled><option>${areaTypeStyle(FURNITURE).label}</option></select>`;
        }
        return html`<select class="form-select form-select-sm" data-area-field="type">
            ${CREATABLE_TYPES.map(({ type }) => html`<option value="${type}"${type === area.type ? raw(" selected") : ""}>${areaTypeStyle(type).label}</option>`)}
        </select>`;
    }

    const dimension = (label, field, dm) => html`
        <label class="form-label-sm mb-0 flex-fill min-w-0">${label}
            <span class="input-group input-group-sm flex-nowrap">
                <input class="form-control" type="number" min="0.1" step="0.1" data-area-field="${field}" value="${(dm / 10).toFixed(1)}">
                <span class="input-group-text">m</span>
            </span>
        </label>`;

    function renderInspector() {
        const area = selected();
        inspector.hidden = !area;
        if (!area) return;

        const [label, tone] = STATUS[areaStatus(area, originals)];
        inspector.innerHTML = html`
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="min-w-0 lh-sm">
                    <div class="fw-semibold text-break">${areaName(area)}</div>
                    <div class="text-secondary" style="font-size:.75rem">Região ${area.key}</div>
                </div>
                ${stateBadge(label, tone)}
            </div>
            <label class="form-label-sm mb-0">Tipo${typeOptions(area)}</label>
            <label class="form-label-sm mb-0">Nome<input class="form-control form-control-sm" type="text" data-area-field="name" value="${area.name ?? ""}"></label>
            <div class="d-flex gap-2">
                ${dimension("Largura", "width", area.xMaxDm - area.xMinDm)}
                ${dimension("Comprimento", "length", area.yMaxDm - area.yMinDm)}
            </div>
            <button type="button" class="btn btn-sm ${area.deleted ? "btn-outline-secondary" : "btn-outline-danger btn-quiet-danger"}" data-area-delete>${area.deleted ? "Não apagar" : "Apagar"}</button>`;
        placeInspector();
    }

    /** Ao lado direito da área, ou do esquerdo quando à direita não cabe. */
    function placeInspector() {
        const area = selected();
        if (!area || inspector.hidden) return;

        const hostBox = host.getBoundingClientRect();
        const canvasBox = canvas.getBoundingClientRect();
        const [left, top] = scene.toPixels(area.xMinDm, area.yMaxDm) ?? [0, 0];
        const [right] = scene.toPixels(area.xMaxDm, area.yMaxDm) ?? [0, 0];
        const originX = canvasBox.left - hostBox.left;
        const originY = canvasBox.top - hostBox.top;
        let x = originX + right + 12;
        if (x + INSPECTOR_WIDTH > hostBox.width - 8) x = Math.max(8, originX + left - INSPECTOR_WIDTH - 12);
        inspector.style.left = `${x}px`;
        inspector.style.top = `${Math.max(8, originY + top)}px`;
    }

    function renderBottom() {
        const pending = pendingCount();
        bottom.innerHTML = html`
            ${rejected.map((warning) => html`<div class="alert alert-danger small py-2 px-3 mb-0">${warning.text}</div>`)}
            <div class="d-flex align-items-center gap-2 flex-wrap border-top pt-3">
                <span class="small text-secondary me-auto">${liveCount()} de ${maxAreas} áreas · ${pending ? `${pending} por enviar` : "planta igual à do radar"}</span>
                <button type="button" class="btn btn-outline-secondary" data-area-reset${pending ? "" : raw(" disabled")}>Repor</button>
                <button type="button" class="btn btn-primary" data-area-send${pending && !sending ? "" : raw(" disabled")}>${sending ? "A enviar" : "Enviar ao dispositivo"}</button>
            </div>`;
    }

    function render() {
        drawPlan();
        renderTools();
        renderInspector();
        renderBottom();
    }

    function updateSelected(change) {
        const area = selected();
        if (!area) return;
        commit(() => {
            areas = areas.map((candidate) => candidate.key === area.key ? change({ ...candidate }) : candidate);
        });
    }

    function handleInspectorChange(event) {
        const field = event.target.dataset.areaField;
        if (!field) return;
        const value = event.target.value;
        const dm = Math.max(1, Math.round(Number(value) * 10) || 1);
        updateSelected((area) => {
            if (field === "type") area.type = value;
            if (field === "name") area.name = value.trim() || areaTypeStyle(area.type).label;
            if (field === "width") area.xMaxDm = area.xMinDm + dm;
            if (field === "length") area.yMaxDm = area.yMinDm + dm;
            return area;
        });
    }

    async function send() {
        sending = true;
        renderBottom();
        const items = draftItems(areas);
        try {
            rejected = areaWarnings(await onSend(items), items, maxAreas);
        } finally {
            sending = false;
            if (bottom.isConnected) {
                drawPlan();
                renderBottom();
            }
        }
    }

    function handleClick(event) {
        const target = event.target.closest("button");
        if (!target || !host.contains(target)) return;

        if (target.dataset.areaKey !== undefined) {
            selectedKey = Number(target.dataset.areaKey);
            render();
        } else if (target.dataset.areaAdd !== undefined) {
            const center = [(room.xMinDm + room.xMaxDm) / 2, (room.yMinDm + room.yMaxDm) / 2];
            commit(() => {
                areas = addArea(areas, target.dataset.areaAdd, center, maxAreas);
                selectedKey = areas.at(-1).key;
            });
        } else if ("areaUndo" in target.dataset) {
            undo();
        } else if ("areaDelete" in target.dataset) {
            deleteSelected();
        } else if ("areaReset" in target.dataset) {
            commit(() => {
                areas = clone(originals);
                if (!selected()) selectedKey = areas[0]?.key ?? null;
            });
        } else if ("areaSend" in target.dataset) {
            void send();
        }
    }

    function deleteSelected() {
        if (selectedKey === null) return;
        commit(() => {
            areas = toggleDelete(areas, selectedKey, originals);
            if (!selected()) selectedKey = null;
        });
    }

    const ARROWS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, 1], ArrowDown: [0, -1] };

    function handleCanvasKey(event) {
        const area = selected();
        if (area && !area.deleted && ARROWS[event.key]) {
            event.preventDefault();
            const step = event.shiftKey ? 5 : 1;
            updateSelected((candidate) => moveArea(candidate, ARROWS[event.key][0] * step, ARROWS[event.key][1] * step));
        } else if (area && (event.key === "Delete" || event.key === "Backspace")) {
            event.preventDefault();
            deleteSelected();
        }
    }

    /** O Ctrl+Z dos campos de texto é deles; fora deles desfaz a planta. */
    function handleUndoKey(event) {
        if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== "z") return;
        if (event.target.closest?.("input, select, textarea")) return;
        event.preventDefault();
        undo();
    }

    function pointerPosition(event) {
        const box = canvas.getBoundingClientRect();
        return scene.toDm(event.clientX - box.left, event.clientY - box.top);
    }

    function handlePointerMove(event) {
        if (!drag) return;
        const [x, y] = pointerPosition(event);
        const dx = x - drag.start[0];
        const dy = y - drag.start[1];
        const next = drag.corner
            ? resizeArea(drag.from, drag.corner, dx, dy)
            : moveArea(drag.from, dx, dy);
        areas = areas.map((area) => area.key === next.key ? next : area);
        drawPlan();
        placeInspector();
    }

    function handlePointerUp() {
        if (!drag) return;
        const moved = JSON.stringify(selected()) !== JSON.stringify(drag.from);
        if (!moved) history.pop();
        drag = null;
        globalThis.removeEventListener("pointermove", handlePointerMove);
        globalThis.removeEventListener("pointerup", handlePointerUp);
        render();
    }

    function handleStagePointerDown(konvaEvent) {
        const key = konvaEvent.target.getAttr("areaKey");
        if (key === undefined) return;
        canvas.focus({ preventScroll: true });
        const area = areas.find((candidate) => candidate.key === key);
        const wasSelected = selectedKey === key;
        selectedKey = key;
        if (!area || area.deleted) {
            render();
            return;
        }
        remember();
        drag = { from: { ...area }, corner: konvaEvent.target.getAttr("corner") ?? null, start: pointerPosition(konvaEvent.evt) };
        globalThis.addEventListener("pointermove", handlePointerMove);
        globalThis.addEventListener("pointerup", handlePointerUp);
        if (!wasSelected) render();
    }

    host.addEventListener("click", handleClick);
    inspector.addEventListener("change", handleInspectorChange);
    canvas.addEventListener("keydown", handleCanvasKey);
    host.addEventListener("keydown", handleUndoKey);
    scene.stage()?.on("pointerdown.areaEditor", handleStagePointerDown);
    render();

    return {
        /** A cena redesenhou a sala (ao redimensionar): as áreas e o inspetor voltam por cima. */
        redraw() {
            drawPlan();
            placeInspector();
        },
        pending: pendingCount,
        destroy() {
            handlePointerUp();
            scene.stage()?.off("pointerdown.areaEditor");
            host.removeEventListener("click", handleClick);
            host.removeEventListener("keydown", handleUndoKey);
            canvas.removeEventListener("keydown", handleCanvasKey);
            canvas.removeAttribute("tabindex");
            canvas.removeAttribute("aria-label");
            host.classList.remove("position-relative");
            tools.remove();
            inspector.remove();
            bottom.remove();
            scene.drawAreas([]);
        },
    };
}
