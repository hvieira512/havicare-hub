import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { bindDeviceEvents } = await import("../../src/Dashboard/dashboard/wiring/devices.js");
const { initDeviceDetailView, renderDownlinkRequests } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

/**
 * A régua do telemóvel tem três separadores, e a telemetria é o que abre: os cartões do
 * aparelho são a resposta à pergunta que se faz ao abrir um dispositivo.
 */
const STRIP = `
    <div id="deviceTabs" class="nav d-none d-lg-none">
        <button id="deviceTabTelemetry" class="nav-link active" type="button" role="tab" aria-selected="true">Telemetria</button>
        <button id="deviceTabReadings" class="nav-link" type="button" role="tab" aria-selected="false" data-bs-target="#telemetryColumn">Leituras</button>
        <button id="deviceTabRequests" class="nav-link" type="button" role="tab" aria-selected="false" data-bs-target="#downlinkColumn">Pedidos</button>
    </div>`;

// O `globalThis` do node não tem o `addEventListener` da janela, e a planta do radar liga-se
// ao `resize` dela ao arrancar.
globalThis.addEventListener ??= window.addEventListener.bind(window);

function tabEls() {
    const root = document.createElement("div");
    root.innerHTML = STRIP;
    const cache = {};
    for (const element of root.querySelectorAll("[id]")) cache[element.id] = element;

    return new Proxy(cache, {
        get: (target, id) => (target[id] ??= document.createElement("div")),
    });
}

const click = (button) => button.dispatchEvent(new window.Event("click", { bubbles: true }));

test("a telemetria é o separador que abre", () => {
    const els = tabEls();

    bindDeviceEvents({ els, ui: {} });

    assert.equal(els.dashboardApp.dataset.deviceTab, "telemetry");
    assert.equal(els.deviceTabTelemetry.classList.contains("active"), true);
});

test("escolher as leituras passa o ecrã à atividade, e voltar à telemetria desfá-lo", () => {
    const els = tabEls();
    bindDeviceEvents({ els, ui: {} });

    click(els.deviceTabReadings);

    assert.equal(els.dashboardApp.dataset.deviceTab, "activity");
    assert.equal(els.deviceTabReadings.classList.contains("active"), true);
    assert.equal(els.deviceTabTelemetry.classList.contains("active"), false);
    assert.equal(els.deviceTabReadings.getAttribute("aria-selected"), "true");

    click(els.deviceTabTelemetry);

    assert.equal(els.dashboardApp.dataset.deviceTab, "telemetry");
    assert.equal(els.deviceTabReadings.classList.contains("active"), false);
});

/** Um radar não recebe pedido nenhum, e o separador apontava para uma lista sempre vazia. */
test("sem pedidos, o separador dos pedidos sai da régua", () => {
    const els = tabEls();
    initDeviceDetailView({ els });
    state.downlinkPage = 1;

    renderDownlinkRequests([]);
    assert.equal(els.deviceTabRequests.classList.contains("d-none"), true);

    renderDownlinkRequests([{
        feature: "heart_rate",
        status: "sent",
        requestedAt: "2026-08-25T11:04:00Z",
        occurredAt: "2026-08-25T11:04:00Z",
    }]);
    assert.equal(els.deviceTabRequests.classList.contains("d-none"), false);
});
