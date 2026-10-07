import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { connectionOutages, renderConnectionHistory, handleConnectionHistoryClick } = await import(
    "../../src/Dashboard/dashboard/devices/connection-history.js",
);

const NOW = Date.parse("2026-10-07T16:40:00Z");
const MINUTE = 60000;

const at = (minutesAgo) => new Date(NOW - minutesAgo * MINUTE).toISOString();
const connected = (minutesAgo) => ({ type: "device.connected", occurredAt: at(minutesAgo) });
const disconnected = (minutesAgo) => ({ type: "device.disconnected", occurredAt: at(minutesAgo) });

/** `count` quebras de dez minutos, uma por hora, a mais antiga primeiro. */
function flappingEvents(count) {
    const events = [connected(count * 60 + 30)];
    for (let index = count; index >= 1; index--) {
        events.push(disconnected(index * 60), connected(index * 60 - 10));
    }
    return events;
}

function click(container, action) {
    const button = container.querySelector(`[data-action="${action}"]`);
    assert.ok(button, `nenhum botão com a acção ${action}`);
    handleConnectionHistoryClick({ target: button });
}

const listedOutages = (container) => container.querySelectorAll("[data-connection-outage]").length;

test("uma quebra vai do desligar ao ligar seguinte, e a última sem regresso dura até agora", () => {
    const history = connectionOutages(
        [connected(100), disconnected(80), connected(60), disconnected(20)],
        { now: NOW },
    );

    assert.equal(history.start, NOW - 100 * MINUTE);
    assert.deepEqual(
        history.outages.map(({ from, to }) => [from, to]),
        [
            [NOW - 20 * MINUTE, null],
            [NOW - 80 * MINUTE, NOW - 60 * MINUTE],
        ],
    );
    assert.equal(history.uptime, 60 / 100);
});

test("um desligar repetido não abre segunda quebra, e a ordem de chegada não conta", () => {
    const history = connectionOutages(
        [connected(30), disconnected(50), connected(100), disconnected(40)],
        { now: NOW },
    );

    assert.deepEqual(
        history.outages.map(({ from, to }) => [from, to]),
        [[NOW - 50 * MINUTE, NOW - 30 * MINUTE]],
    );
});

test("a lista das quebras começa fechada e abre na seta do título", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, flappingEvents(3), { now: NOW });

    assert.equal(listedOutages(container), 0);
    click(container, "connectionToggle");
    assert.equal(listedOutages(container), 3);
    click(container, "connectionToggle");
    assert.equal(listedOutages(container), 0);
});

test("as quebras paginam no desktop e acumulam no «Carregar mais» do telemóvel", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, flappingEvents(12), { now: NOW });
    click(container, "connectionToggle");

    assert.equal(listedOutages(container), 5);
    click(container, "connectionNext");
    assert.equal(listedOutages(container), 5);
    click(container, "connectionMore");
    assert.equal(listedOutages(container), 12);
    assert.equal(container.querySelector("[data-action=\"connectionMore\"]"), null);
});

test("sem quebras não há nada para abrir", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, [connected(90)], { now: NOW });

    assert.equal(container.querySelector("[data-action=\"connectionToggle\"]"), null);
    assert.match(container.textContent, /100\s?%/);
});

test("sem eventos de ligação a secção diz que não há registo", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, [], { now: NOW });

    assert.equal(container.querySelector("[data-action=\"connectionToggle\"]"), null);
    assert.match(container.textContent, /Sem registo de ligações/);
});

test("uma quebra de segundos numa semana não se lê como 100 %", () => {
    const container = document.createElement("div");
    const week = 7 * 24 * 60;
    renderConnectionHistory(
        container,
        [connected(week), disconnected(60), { type: "device.connected", occurredAt: new Date(NOW - 60 * MINUTE + 5000).toISOString() }],
        { now: NOW },
    );

    assert.match(container.textContent, /99,9\s?%/);
});

test("um aparelho que nunca reporta ligações, como o radar, não mostra a secção", () => {
    const section = document.createElement("section");
    const container = document.createElement("div");
    section.appendChild(container);

    renderConnectionHistory(container, [], { now: NOW, reported: false });
    assert.equal(section.classList.contains("d-none"), true);

    renderConnectionHistory(container, [], { now: NOW, reported: true });
    assert.equal(section.classList.contains("d-none"), false);
    assert.match(container.textContent, /Sem registo de ligações/);
});

test("cada troço da faixa diz o estado e quando começou e acabou", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, [connected(100), disconnected(80), connected(60)], { now: NOW });

    const titles = [...container.querySelectorAll(".connection-band [data-bs-toggle=\"tooltip\"]")]
        .map((segment) => segment.dataset.bsTitle);

    assert.equal(titles.length, 3);
    assert.match(titles[0], /^Ligado .+ → .+ · 20 min$/);
    assert.match(titles[1], /^Desligado .+ → .+ · 20 min$/);
    assert.match(titles[2], /^Ligado .+ → agora · 1 h$/);
});
