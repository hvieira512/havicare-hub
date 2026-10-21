import test from "node:test";
import assert from "node:assert/strict";

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

test("a gap runs from a disconnect to the next connect, and the last one without return lasts until now", () => {
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

test("a repeated disconnect opens no second gap, and arrival order does not matter", () => {
    const history = connectionOutages(
        [connected(30), disconnected(50), connected(100), disconnected(40)],
        { now: NOW },
    );

    assert.deepEqual(
        history.outages.map(({ from, to }) => [from, to]),
        [[NOW - 50 * MINUTE, NOW - 30 * MINUTE]],
    );
});

test("the gap list starts closed and opens on the title arrow", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, flappingEvents(3), { now: NOW });

    assert.equal(listedOutages(container), 0);
    click(container, "connectionToggle");
    assert.equal(listedOutages(container), 3);
    click(container, "connectionToggle");
    assert.equal(listedOutages(container), 0);
});

test("gaps paginate on desktop and accumulate under «Carregar mais» on the phone", () => {
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

test("without gaps there is nothing to open", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, [connected(90)], { now: NOW });

    assert.equal(container.querySelector("[data-action=\"connectionToggle\"]"), null);
    assert.match(container.textContent, /100\s?%/);
});

test("without connection events the section says there is no record", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, [], { now: NOW });

    assert.equal(container.querySelector("[data-action=\"connectionToggle\"]"), null);
    assert.match(container.textContent, /Sem registo de ligações/);
});

test("a gap of seconds in a week does not read as 100 %", () => {
    const container = document.createElement("div");
    const week = 7 * 24 * 60;
    renderConnectionHistory(
        container,
        [connected(week), disconnected(60), { type: "device.connected", occurredAt: new Date(NOW - 60 * MINUTE + 5000).toISOString() }],
        { now: NOW },
    );

    assert.match(container.textContent, /99,9\s?%/);
});

test("a device that never reports connections, like the radar, does not show the section", () => {
    const section = document.createElement("section");
    const container = document.createElement("div");
    section.appendChild(container);

    renderConnectionHistory(container, [], { now: NOW, reported: false });
    assert.equal(section.classList.contains("d-none"), true);

    renderConnectionHistory(container, [], { now: NOW, reported: true });
    assert.equal(section.classList.contains("d-none"), false);
    assert.match(container.textContent, /Sem registo de ligações/);
});

test("each stretch of the band says its state and when it started and ended", () => {
    const container = document.createElement("div");
    renderConnectionHistory(container, [connected(100), disconnected(80), connected(60)], { now: NOW });

    const titles = [...container.querySelectorAll(".connection-band [data-bs-toggle=\"tooltip\"]")]
        .map((segment) => segment.dataset.bsTitle);

    assert.equal(titles.length, 3);
    assert.match(titles[0], /^Ligado .+ → .+ · 20 min$/);
    assert.match(titles[1], /^Desligado .+ → .+ · 20 min$/);
    assert.match(titles[2], /^Ligado .+ → agora · 1 h$/);
});
