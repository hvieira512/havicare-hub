import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

/**
 * Sem dispositivo escolhido não há coluna de atividade nenhuma para mostrar, e o arranque
 * ficava num ecrã à espera de um clique. O selector passa a abrir-se sozinho.
 */

// A listagem fica pendurada de propósito: o que aqui se prende é a abertura, que acontece
// antes do pedido, e uma resposta a sério arrastava metade da marcação da dashboard.
globalThis.fetch = () => new Promise(() => {});

const IDS = [
    "deviceList",
    "deviceListSearch",
    "deviceSelectionEmptyState",
    "selectedDevicePanel",
    "deviceDetail",
    "detailColumn",
    "deviceColumn",
    "requestCardsCard",
    "requestGrid",
    "ncsEventGrid",
    "ncsEventSection",
];

const { initDeviceList, restoreSelectedDevice } =
    await import("../../src/Dashboard/dashboard/devices/list.js");
const { cacheElements } = await import("../../src/Dashboard/dashboard/dom.js");
const { state } = await import("../../src/Dashboard/dashboard/state.js");

function boot() {
    // A lista já com uma linha dentro: cheia, o selector não desenha o esqueleto, que puxa
    // os filtros todos atrás dele.
    document.body.innerHTML = IDS.map(
        (id) => `<div id="${id}">${id === "deviceList" ? "<div></div>" : ""}</div>`,
    ).join("");
    state.selectedDetail = null;
    state.selectedImei = null;
    const opened = [];
    initDeviceList({
        els: cacheElements(),
        ui: { deviceSelectorModal: { show: () => opened.push("show") } },
    });
    return opened;
}

test("sem dispositivo guardado o selector abre-se sozinho", () => {
    const opened = boot();

    restoreSelectedDevice("");

    assert.deepEqual(opened, ["show"], "o arranque sem escolha tem de abrir o selector");
});

test("com dispositivo guardado o selector fica fechado", () => {
    const opened = boot();

    restoreSelectedDevice("351266770073676");

    assert.deepEqual(opened, [], "quem já tem escolha não leva um modal na cara");
    assert.equal(state.selectedImei, "351266770073676");
});
