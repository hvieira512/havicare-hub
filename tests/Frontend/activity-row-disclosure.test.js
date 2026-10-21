import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceDetailView, renderTelemetryList } =
    await import("../../src/Dashboard/dashboard/devices/detail.js");
const { toggleActivityRow } =
    await import("../../src/Dashboard/dashboard/devices/activity-table.js");

/**
 * Os detalhes cortam-se numa linha, o que fica de fora abre-se, e a linha aberta continua
 * aberta enquanto o histórico anda por baixo dela.
 */

function fakeElement() {
    return document.createElement("div");
}

function els() {
    return {
        telemetryCount: fakeElement(),
        telemetryTabCount: fakeElement(),
        deviceTabReadingsCount: fakeElement(),
        telemetryList: fakeElement(),
        telemetryPager: fakeElement(),
        telemetryPagerSummary: fakeElement(),
        telemetryPagerControls: fakeElement(),
    };
}

// Um resumo por minuto de radar: os detalhes são uma frase comprida.
function minuteStats(index) {
    return {
        type: "position_minute_stats",
        occurredAt: `2026-08-31T10:${String(index % 60).padStart(2, "0")}:00Z`,
        seq: 1000 + index,
        device: { id: "414D74184CBF" },
        data: {
            walkingTimeS: 0,
            meditationTimeS: 0,
            inBedTimeS: 0,
            standingTimeS: 0,
            multiplayerTimeS: 0,
            breathingActive: false,
        },
        source: { protocol: "qinglanst-radar", nativeType: "posstatics" },
    };
}

// Uma frequência cardíaca não tem detalhes nenhuns: não há gaveta para abrir.
function heartRate(index) {
    return {
        type: "heart_rate",
        occurredAt: `2026-08-31T10:${String(index % 60).padStart(2, "0")}:30Z`,
        seq: 2000 + index,
        device: { id: "414D74184CBF" },
        data: { bpm: 62 },
        source: { protocol: "qinglanst-radar", nativeType: "heart_rate" },
    };
}

// A presença devolve pastilhas, e não texto.
function presence(index) {
    return {
        type: "presence",
        occurredAt: `2026-08-31T10:${String(index % 60).padStart(2, "0")}:45Z`,
        seq: 3000 + index,
        device: { id: "414D74184CBF" },
        data: {
            people: [{ personIndex: 0, posture: "lying_down", xPositionDm: 3, yPositionDm: 4, zPositionCm: 50 }],
        },
        source: { protocol: "qinglanst-radar", nativeType: "presence" },
    };
}

beforeEach(() => {
    state.telemetryPage = 1;
    state.selectedImei = "414D74184CBF";
});

test("only a row with more than it shows opens", () => {
    const view = els();
    initDeviceDetailView({ els: view });

    renderTelemetryList([minuteStats(0), heartRate(1)]);

    const expandable = view.telemetryList.querySelectorAll("[data-row-toggle]");
    assert.equal(expandable.length, 1, "a frequência cardíaca é o valor e mais nada");
    assert.equal(expandable[0].getAttribute("role"), "button");
    assert.equal(expandable[0].getAttribute("tabindex"), "0");
    assert.equal(expandable[0].getAttribute("aria-expanded"), "false");
    assert.ok(expandable[0].getAttribute("aria-controls"));
});

test("the drawer holds the full text the row cut short", () => {
    const view = els();
    initDeviceDetailView({ els: view });

    renderTelemetryList([minuteStats(0)]);

    const row = view.telemetryList.querySelector("[data-row-toggle]");
    const panel = view.telemetryList.querySelector(`#${row.getAttribute("aria-controls")}`);

    assert.ok(panel.classList.contains("d-none"), "começa fechada");
    assert.match(panel.textContent, /Respiração ativa/, "e traz o fim da frase, que a linha não mostra");
});

test("clicking opens, and clicking again closes", () => {
    const view = els();
    initDeviceDetailView({ els: view });
    renderTelemetryList([minuteStats(0)]);

    const row = view.telemetryList.querySelector("[data-row-toggle]");
    const panel = view.telemetryList.querySelector(`#${row.getAttribute("aria-controls")}`);

    assert.equal(toggleActivityRow({ target: row, type: "click" }), true);
    assert.equal(row.getAttribute("aria-expanded"), "true");
    assert.equal(panel.classList.contains("d-none"), false);

    toggleActivityRow({ target: row, type: "click" });
    assert.equal(row.getAttribute("aria-expanded"), "false");
    assert.equal(panel.classList.contains("d-none"), true);
});

test("a click outside an openable row is ignored", () => {
    const view = els();
    initDeviceDetailView({ els: view });
    renderTelemetryList([heartRate(0)]);

    assert.equal(
        toggleActivityRow({ target: view.telemetryList, type: "click" }),
        false,
    );
});

test("an open row stays open when a new event arrives", () => {
    const view = els();
    initDeviceDetailView({ els: view });
    renderTelemetryList([minuteStats(0), heartRate(1)]);

    const row = view.telemetryList.querySelector("[data-row-toggle]");
    const key = row.dataset.rowKey;
    toggleActivityRow({ target: row, type: "click" });

    // Chega um evento mais recente: tudo desce uma casa e a lista volta a desenhar-se.
    renderTelemetryList([presence(2), minuteStats(0), heartRate(1)]);

    const expanded = view.telemetryList.querySelector("[aria-expanded='true']");
    assert.ok(expanded, "alguma linha tem de continuar aberta");
    assert.equal(expanded.dataset.rowKey, key, "e tem de ser a mesma, não a que ficou naquela posição");
    const panel = view.telemetryList.querySelector(`#${expanded.getAttribute("aria-controls")}`);
    assert.equal(panel.classList.contains("d-none"), false);
});

test("the key carries the IMEI: switching device does not open the row with the same sequence number", () => {
    const view = els();
    initDeviceDetailView({ els: view });
    renderTelemetryList([minuteStats(0)]);

    const row = view.telemetryList.querySelector("[data-row-toggle]");
    toggleActivityRow({ target: row, type: "click" });

    state.selectedImei = "OUTRO-APARELHO";
    renderTelemetryList([minuteStats(0)]);

    assert.equal(
        view.telemetryList.querySelector("[aria-expanded='true']"),
        null,
        "o `seq` recomeça em cada aparelho, e sem o IMEI a linha abria-se sozinha",
    );
});
