import { areaTypeStyle, postureStyle } from "../radar-style.js";
import { fieldValue } from "../format.js";

/**
 * A planta da divisão de um radar, em Konva.
 *
 * Portado do `_js/radar/scene/radar-scene.js` do cliente, com o mesmo desenho. As formas
 * chegam já lidas pelo `LayoutParser` em PHP, os campos são os do hub (`xPositionDm`,
 * `posture`), e o rasto do modo de reprodução não veio.
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

    return function (coords) {
        const result = [];
        for (let i = 0; i < coords.length; i += 2) {
            result.push(
                (coords[i] + offsetX) * scale + centerOffsetX,
                ch - ((coords[i + 1] + offsetY) * scale + centerOffsetY),
            );
        }
        return result;
    };
}

/**
 * Os limites cobrem a sala **e** as áreas.
 *
 * O cliente calcula-os só a partir da sala, e por isso corta o que fica fora dela -- 30 das 73
 * áreas declaradas nos radares em produção, que é o caso comum e não a excepção.
 */
function boundsOf(layout) {
    const boxes = [layout.room, ...layout.areas];
    const minX = Math.min(...boxes.map((box) => box.x_min_dm));
    const minY = Math.min(...boxes.map((box) => box.y_min_dm));
    const maxX = Math.max(...boxes.map((box) => box.x_max_dm));
    const maxY = Math.max(...boxes.map((box) => box.y_max_dm));

    return { minX, minY, width: maxX - minX, height: maxY - minY };
}

/** Uma caixa em decímetros nos quatro cantos que o `Konva.Line` fechado quer, por ordem. */
function corners(box) {
    return [
        box.x_min_dm, box.y_min_dm,
        box.x_max_dm, box.y_min_dm,
        box.x_max_dm, box.y_max_dm,
        box.x_min_dm, box.y_max_dm,
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
            (area.x_min_dm + area.x_max_dm) / 2,
            (area.y_min_dm + area.y_max_dm) / 2,
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
            text: area.name,
            fontSize: 14,
            fontFamily: "Poppins",
            fill: color,
            // O halo branco é o que deixa o nome legível quando cai por cima de uma linha.
            shadowColor: "white",
            shadowBlur: 5,
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

export function createRadarScene(options = {}) {
    const state = {
        stage: null,
        layer: null,
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

        // O cliente força aqui `height: 400px` quando o contentor mede zero. Não veio: a
        // altura é do `.radar-map-canvas`, que a faz variar com a largura do ecrã, e um
        // estilo em linha de 400px passava-lhe por cima em qualquer telemóvel.
        state.stage = new Konva.Stage({
            container,
            width: container.offsetWidth,
            height: container.offsetHeight,
        });
        state.layer = new Konva.Layer();
        state.peopleLayer = new Konva.Layer();
        state.stage.add(state.layer);
        state.stage.add(state.peopleLayer);
    }

    function renderRoom(layout) {
        if (!state.stage || !layout?.room) return;

        state.currentLayout = layout;
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

    function destroy() {
        clearPeopleNodes(state);
        state.stage?.destroy();
        state.stage = null;
        state.layer = null;
        state.peopleLayer = null;
        state.transformCoords = null;
        state.currentLayout = null;
        state.currentPeople = [];
    }

    return { init, renderRoom, updatePeople, resize, destroy };
}
