import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceDetailView, renderTelemetryList, toggleActivityRow } =
    await import("../../src/Dashboard/dashboard/devices/detail.js");

/**
 * A altura de uma linha da lista de actividade, e a gaveta que a abre.
 *
 * Media-se 59px numas linhas e 160px noutras: os detalhes de um `minute_stats` são uma frase
 * comprida que caía num contentor a embrulhar e ocupava seis linhas. Uma página de doze
 * linhas media 708px ou 1920px conforme os tipos que lhe calhassem, e comparar duas páginas
 * deixava de ser possível.
 *
 * O que se prende aqui: os detalhes cortam-se numa linha, o que fica de fora abre-se, e a
 * linha aberta continua aberta enquanto o histórico anda por baixo dela -- que num radar é
 * mais do que uma vez por segundo.
 */

function fakeElement() {
    return document.createElement("div");
}

function els() {
    return {
        telemetryCount: fakeElement(),
        telemetryList: fakeElement(),
        telemetryPager: fakeElement(),
        telemetryPagerSummary: fakeElement(),
        telemetryPagerControls: fakeElement(),
    };
}

// Um resumo por minuto de radar: os detalhes são a frase comprida que rebentava a altura.
function minuteStats(index) {
    return {
        type: "position_minute_stats",
        occurredAt: `2026-08-31T10:${String(index % 60).padStart(2, "0")}:00Z`,
        seq: 1000 + index,
        device: { id: "414D74184CBF" },
        data: {
            walking_time: 0,
            meditation_time: 0,
            in_bed_time: 0,
            standing_time: 0,
            multiplayer_time: 0,
            breathing_active: false,
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

test("os detalhes em texto cortam-se numa linha, em vez de embrulharem", () => {
    const view = els();
    initDeviceDetailView({ els: view });

    renderTelemetryList([minuteStats(0)]);

    const details = view.telemetryList.querySelector(".telemetry-row-details");
    assert.ok(details, "a linha tem de trazer detalhes");
    assert.ok(details.classList.contains("text-truncate"), "o texto corta-se");
    assert.ok(!details.classList.contains("flex-wrap"), "e não embrulha");
});

test("as pastilhas não se cortam a meio: ficam numa linha e o que sobra esconde-se", () => {
    const view = els();
    initDeviceDetailView({ els: view });

    renderTelemetryList([presence(0)]);

    const details = view.telemetryList.querySelector(".telemetry-row-details");
    assert.ok(details.classList.contains("d-flex"));
    assert.ok(details.classList.contains("overflow-hidden"));
    assert.ok(!details.classList.contains("text-truncate"), "cortar texto partia uma pastilha ao meio");
});

test("só abre quem tem mais do que mostra", () => {
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

test("a gaveta traz o texto inteiro, que na linha estava cortado", () => {
    const view = els();
    initDeviceDetailView({ els: view });

    renderTelemetryList([minuteStats(0)]);

    const row = view.telemetryList.querySelector("[data-row-toggle]");
    const panel = view.telemetryList.querySelector(`#${row.getAttribute("aria-controls")}`);

    assert.ok(panel.classList.contains("d-none"), "começa fechada");
    assert.match(panel.textContent, /Respiração ativa/, "e traz o fim da frase, que a linha não mostra");
});

test("carregar abre, e voltar a carregar fecha", () => {
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

test("um clique fora de uma linha abrível não é assunto nosso", () => {
    const view = els();
    initDeviceDetailView({ els: view });
    renderTelemetryList([heartRate(0)]);

    assert.equal(
        toggleActivityRow({ target: view.telemetryList, type: "click" }),
        false,
    );
});

test("a linha aberta continua aberta quando chega um evento novo", () => {
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

test("a chave leva o IMEI: mudar de aparelho não abre a linha do mesmo número de ordem", () => {
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
