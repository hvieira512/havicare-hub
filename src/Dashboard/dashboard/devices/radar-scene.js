import { areaName, areaTypeStyle, postureStyle } from "../radar-style.js";
import { fieldValue } from "../format.js";

/**
 * A planta da divisão de um radar em Konva, portada do `_js/radar/scene/radar-scene.js` do
 * cliente, com as formas já lidas pelo `LayoutParser` e os campos do hub.
 */

/** O ar entre a divisão e a borda da tela. */
const PADDING = 30;

/**
 * Converte decímetros em pixéis: escala uniforme, centra, e inverte o Y -- o radar mede com o
 * Y a crescer para cima e a tela desenha-o a crescer para baixo.
 */
function createTransform(bounds, cw, ch) {
    const scale = Math.min(
        (cw - 2 * PADDING) / (bounds.width || 1),
        (ch - 2 * PADDING) / (bounds.height || 1),
    );
    const offsetX = -bounds.minX;
    const offsetY = -bounds.minY;
    const centerOffsetX = (cw - bounds.width * scale) / 2;
    const centerOffsetY = (ch - bounds.height * scale) / 2;

    const toPixels = function (coords) {
        const result = [];
        for (let i = 0; i < coords.length; i += 2) {
            result.push(
                (coords[i] + offsetX) * scale + centerOffsetX,
                ch - ((coords[i + 1] + offsetY) * scale + centerOffsetY),
            );
        }
        return result;
    };
    // O caminho inverso, para o editor saber em que decímetro caiu o ponteiro.
    toPixels.toDm = (x, y) => [
        (x - centerOffsetX) / scale - offsetX,
        (ch - y - centerOffsetY) / scale - offsetY,
    ];
    toPixels.scale = scale;

    return toPixels;
}

/**
 * Os limites cobrem a sala **e** as áreas: é comum uma área declarada sair da sala. O editor fixa
 * o `extent`, para a escala não mudar enquanto se arrasta uma área.
 */
function boundsOf(layout) {
    const boxes = layout.extent ? [layout.extent] : [layout.room, ...layout.areas];
    const minX = Math.min(...boxes.map((box) => box.xMinDm));
    const minY = Math.min(...boxes.map((box) => box.yMinDm));
    const maxX = Math.max(...boxes.map((box) => box.xMaxDm));
    const maxY = Math.max(...boxes.map((box) => box.yMaxDm));

    return { minX, minY, width: maxX - minX, height: maxY - minY };
}

/** A tela toma a proporção da divisão; o mínimo impede uma sala comprida de virar uma tira. */
const MIN_STAGE_HEIGHT = 180;

/** Abaixo disto a planta deixa de se ler: com escala uniforme, a altura aperta também a largura. */
const COMFORTABLE_STAGE_HEIGHT = 420;

/**
 * A proporção da divisão, travada pelo que sobra do ecrã mas nunca abaixo da altura de
 * conforto; quando o ecrã não chega, é o diálogo que rola.
 */
export function stageHeightFor(bounds, width, available) {
    if (!width || !bounds.width) return null;

    const proportional =
        Math.round((width - 2 * PADDING) * (bounds.height / bounds.width)) + 2 * PADDING;

    return Math.min(
        Math.max(MIN_STAGE_HEIGHT, proportional),
        Math.max(COMFORTABLE_STAGE_HEIGHT, available),
    );
}

/**
 * O que a tela pode ocupar do que se vê, já sem a legenda. Não conta com os sinais vitais:
 * abaixo do `xl` empilham e alcançam-se a rolar.
 */
function availableHeight(container) {
    const scroller = container.closest(".modal-body") || document.documentElement;
    const box = container.getBoundingClientRect();
    const card = container.closest(".card");
    const below = card ? card.getBoundingClientRect().bottom - box.bottom : 0;
    const visibleBottom = scroller.getBoundingClientRect().top + scroller.clientHeight;

    return Math.round(visibleBottom - box.top - below - PADDING);
}

function fitStageToLayout(state, layout) {
    const container = state.stage.container();
    // Medir sem a altura da vez anterior, para o que sobra do ecrã não sair do desenho velho.
    container.style.height = "";
    const height = stageHeightFor(
        boundsOf(layout),
        state.stage.width(),
        availableHeight(container),
    );
    if (height === null) return;

    state.stage.height(height);
    container.style.height = `${height}px`;
}

/** Uma caixa em decímetros nos quatro cantos que o `Konva.Line` fechado quer, por ordem. */
function corners(box) {
    return [
        box.xMinDm, box.yMinDm,
        box.xMaxDm, box.yMinDm,
        box.xMaxDm, box.yMaxDm,
        box.xMinDm, box.yMaxDm,
    ];
}

function createPersonNode(peopleLayer, x, y, style, label) {
    const group = new Konva.Group({ x, y });
    const circle = new Konva.Circle({
        radius: 10,
        fill: "#0d6efd22",
        stroke: style.color,
        strokeWidth: 3,
    });
    const icon = new Konva.Text({
        text: style.glyph,
        fontFamily: "Font Awesome 6 Free",
        fontStyle: "900",
        fontSize: 12,
        fill: style.color,
    });
    icon.offsetX(icon.width() / 2);
    icon.offsetY(icon.height() / 2);

    const text = new Konva.Text({
        x: 14,
        y: -8,
        text: label,
        fontSize: 12,
        fontFamily: "Poppins",
        fontStyle: "bold",
        fill: style.color,
    });

    group.circle = circle;
    group.icon = icon;
    group.label = text;
    group.moveTween = null;
    group.add(circle, icon, text);
    peopleLayer.add(group);

    return group;
}

function clearPeopleNodes(state) {
    state.peopleNodes.forEach((node) => {
        if (node.moveTween) node.moveTween.destroy();
        node.destroy();
    });
    state.peopleNodes.clear();
}

function emitPeopleCount(state) {
    if (typeof state.onPeopleCountChange !== "function") return;
    state.onPeopleCountChange(state.currentPeople.length, state.currentPeople);
}

function syncPeople(state) {
    if (!state.stage || !state.peopleLayer || !state.transformCoords) return;

    const active = new Set();

    state.currentPeople.forEach((person, index) => {
        const key = Number(person?.personIndex ?? index);
        const coords = state.transformCoords([
            Number(person?.xPositionDm ?? 0),
            Number(person?.yPositionDm ?? 0),
        ]);
        const style = postureStyle(person?.posture);
        const label = fieldValue("posture", person?.posture);

        active.add(key);
        let node = state.peopleNodes.get(key);
        if (!node) {
            node = createPersonNode(state.peopleLayer, coords[0], coords[1], style, label);
            state.peopleNodes.set(key, node);
        }

        node.circle.stroke(style.color);
        node.icon.text(style.glyph);
        node.icon.fill(style.color);
        node.icon.offsetX(node.icon.width() / 2);
        node.icon.offsetY(node.icon.height() / 2);
        node.label.text(label);
        node.label.fill(style.color);

        // Sem a transição, uma pessoa a andar salta de posição a cada mensagem do radar.
        if (node.moveTween) node.moveTween.destroy();
        node.moveTween = new Konva.Tween({
            node,
            x: coords[0],
            y: coords[1],
            duration: 0.15,
            easing: Konva.Easings.Linear,
        });
        node.moveTween.play();
    });

    state.peopleNodes.forEach((node, key) => {
        if (!active.has(key)) {
            if (node.moveTween) node.moveTween.destroy();
            node.destroy();
            state.peopleNodes.delete(key);
        }
    });

    emitPeopleCount(state);
    state.peopleLayer.batchDraw();
}

function drawRoom(state, layout) {
    if (!state.stage || !state.layer) return;

    const cw = state.stage.width();
    const ch = state.stage.height();
    state.transformCoords = createTransform(boundsOf(layout), cw, ch);

    state.layer.add(new Konva.Line({
        points: state.transformCoords(corners(layout.room)),
        stroke: "gray",
        strokeWidth: 3,
        closed: true,
    }));

    layout.areas.forEach((area) => {
        const { color } = areaTypeStyle(area.type);
        const labelPos = state.transformCoords([
            (area.xMinDm + area.xMaxDm) / 2,
            (area.yMinDm + area.yMaxDm) / 2,
        ]);

        state.layer.add(new Konva.Line({
            points: state.transformCoords(corners(area)),
            stroke: color,
            strokeWidth: 2.5,
            dash: [10, 10],
            // O `22` no fim do hexadecimal é a opacidade: a área tinge a sala sem a tapar.
            fill: `${color}22`,
            closed: true,
        }));

        state.layer.add(new Konva.Text({
            x: labelPos[0] - 60,
            y: labelPos[1] - 10,
            width: 120,
            align: "center",
            text: areaName(area),
            fontSize: 14,
            fontFamily: "Poppins",
            fill: color,
            // Contorno branco por baixo do preenchimento, para o nome se ler sobre a parede. Sem
            // sombra: as duas juntas mandam o Konva para uma tela intermédia que sai a zero.
            stroke: "white",
            strokeWidth: 3,
            fillAfterStrokeEnabled: true,
        }));
    });

    // O radar está sempre na origem: é dele que todas as coordenadas são relativas.
    const radarPos = state.transformCoords([0, 0]);
    const radarIcon = new Konva.Text({
        text: "",
        fontFamily: "Font Awesome 6 Free",
        fontStyle: "900",
        fontSize: 18,
        fill: "#20c997",
    });
    radarIcon.offsetX(radarIcon.width() / 2);
    radarIcon.offsetY(radarIcon.height() / 2);
    radarIcon.position({ x: radarPos[0], y: radarPos[1] });
    state.layer.add(radarIcon);

    state.layer.add(new Konva.Text({
        x: radarPos[0] - 20,
        y: radarPos[1] + 12,
        text: "Radar",
        fontSize: 11,
        fontFamily: "Poppins",
        fill: "#20c997",
        fontStyle: "bold",
        align: "center",
        width: 40,
    }));

    state.layer.draw();
}

const EDIT_HANDLE = 10;

/** Uma área do rascunho do editor, já no formato do contrato. */
function drawDraftArea(state, area, { original, selected, warned }) {
    const { color } = areaTypeStyle(area.type);
    const layer = state.editLayer;

    if (original) {
        layer.add(new Konva.Line({
            points: state.transformCoords(corners(original)),
            stroke: "#6c757d",
            strokeWidth: 1,
            dash: [4, 4],
            closed: true,
            listening: false,
        }));
    }

    const shape = new Konva.Line({
        points: state.transformCoords(corners(area)),
        stroke: warned ? "#dc3545" : color,
        strokeWidth: selected ? 3 : 2,
        dash: area.deleted ? [8, 6] : undefined,
        fill: `${color}${area.deleted ? "10" : "33"}`,
        closed: true,
        areaKey: area.key,
    });
    layer.add(shape);

    const [cx, cy] = state.transformCoords([(area.xMinDm + area.xMaxDm) / 2, (area.yMinDm + area.yMaxDm) / 2]);
    const width = (area.xMaxDm - area.xMinDm) * state.transformCoords.scale;
    layer.add(new Konva.Text({
        x: cx - Math.max(width, 60) / 2,
        y: cy - 7,
        width: Math.max(width, 60),
        align: "center",
        text: areaName(area),
        fontSize: 13,
        fontFamily: "Poppins",
        fill: color,
        opacity: area.deleted ? 0.5 : 1,
        stroke: "white",
        strokeWidth: 3,
        fillAfterStrokeEnabled: true,
        listening: false,
    }));

    if (!selected || area.deleted) return;
    for (const corner of ["00", "10", "01", "11"]) {
        const [x, y] = state.transformCoords([
            corner[0] === "1" ? area.xMaxDm : area.xMinDm,
            corner[1] === "1" ? area.yMaxDm : area.yMinDm,
        ]);
        layer.add(new Konva.Rect({
            x: x - EDIT_HANDLE / 2,
            y: y - EDIT_HANDLE / 2,
            width: EDIT_HANDLE,
            height: EDIT_HANDLE,
            fill: "white",
            stroke: "#0b163f",
            strokeWidth: 1.5,
            areaKey: area.key,
            corner,
        }));
    }
}

export function createRadarScene(options = {}) {
    const state = {
        stage: null,
        layer: null,
        editLayer: null,
        peopleLayer: null,
        transformCoords: null,
        currentLayout: null,
        currentPeople: [],
        peopleNodes: new Map(),
        onPeopleCountChange:
            typeof options.onPeopleCountChange === "function"
                ? options.onPeopleCountChange
                : null,
    };

    function init(container) {
        if (state.stage) state.stage.destroy();
        state.peopleNodes.clear();
        state.transformCoords = null;

        // A altura vem do contentor: quem a escreve é o `fitStageToLayout`, depois de saber
        // quanto mede a sala. Aqui ainda é só o chão do `.radar-map-canvas`.
        state.stage = new Konva.Stage({
            container,
            width: container.offsetWidth,
            height: container.offsetHeight,
        });
        state.layer = new Konva.Layer();
        state.editLayer = new Konva.Layer();
        // As pessoas ficam por cima das áreas sem lhes roubar o ponteiro.
        state.peopleLayer = new Konva.Layer({ listening: false });
        state.stage.add(state.layer);
        state.stage.add(state.editLayer);
        state.stage.add(state.peopleLayer);
    }

    function renderRoom(layout) {
        if (!state.stage || !layout?.room) return;

        state.currentLayout = layout;
        fitStageToLayout(state, layout);
        state.layer.destroyChildren();
        clearPeopleNodes(state);

        drawRoom(state, layout);
        syncPeople(state);
    }

    function updatePeople(people) {
        state.currentPeople = Array.isArray(people) ? people.slice() : [];

        if (!state.transformCoords) return;

        if (!state.currentPeople.length) {
            clearPeopleNodes(state);
            emitPeopleCount(state);
            state.peopleLayer?.batchDraw();
            return;
        }

        syncPeople(state);
    }

    /** A tela não se redimensiona sozinha: muda de tamanho e redesenha-se com a escala nova. */
    function resize(container) {
        if (!state.stage || !container || !state.currentLayout) return;

        state.stage.width(container.offsetWidth);
        state.stage.height(container.offsetHeight);
        renderRoom(state.currentLayout);
    }

    /**
     * As áreas do editor, por cima da sala. Cada forma leva o `areaKey`, e os cantos o `corner`:
     * é por eles que o editor sabe o que o ponteiro apanhou.
     */
    function drawAreas(areas, { originals = new Map(), selectedKey = null, warnKeys = new Set() } = {}) {
        if (!state.editLayer || !state.transformCoords) return;

        state.editLayer.destroyChildren();
        const ordered = [...areas].sort((a, b) => Number(a.key === selectedKey) - Number(b.key === selectedKey));
        for (const area of ordered) {
            const original = originals.get(area.key);
            drawDraftArea(state, area, {
                original: original && !area.deleted ? original : null,
                selected: area.key === selectedKey,
                warned: warnKeys.has(area.key),
            });
        }
        state.editLayer.batchDraw();
    }

    function toDm(x, y) {
        return state.transformCoords ? state.transformCoords.toDm(x, y) : null;
    }

    function toPixels(x, y) {
        return state.transformCoords ? state.transformCoords([x, y]) : null;
    }

    function destroy() {
        clearPeopleNodes(state);
        state.stage?.destroy();
        state.stage = null;
        state.layer = null;
        state.editLayer = null;
        state.peopleLayer = null;
        state.transformCoords = null;
        state.currentLayout = null;
        state.currentPeople = [];
    }

    return {
        init,
        renderRoom,
        updatePeople,
        resize,
        destroy,
        drawAreas,
        toDm,
        toPixels,
        stage: () => state.stage,
    };
}
