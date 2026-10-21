import test from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

const { bindDeviceEvents } = await import("../../src/Dashboard/dashboard/wiring/devices.js");

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

    return autoEls(undefined, cache);
}

const click = (button) => button.dispatchEvent(new window.Event("click", { bubbles: true }));

test("telemetry is the tab that opens first", () => {
    const els = tabEls();

    bindDeviceEvents({ els, ui: {} });

    assert.equal(els.dashboardApp.dataset.deviceTab, "telemetry");
    assert.equal(els.deviceTabTelemetry.classList.contains("active"), true);
});

test("picking the readings switches the screen to activity, and going back to telemetry undoes it", () => {
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
