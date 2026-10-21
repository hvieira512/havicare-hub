import test from "node:test";
import assert from "node:assert/strict";

const { activityTable } =
    await import("../../src/Dashboard/dashboard/devices/activity-table.js");

/**
 * A data sobe para um cabeçalho por dia e a linha fica só com a hora: a largura que sobra vai
 * para a pastilha de estado.
 */

const row = (at, name, extra = {}) => ({
    icon: "fa-battery-full",
    name,
    at,
    time: at.slice(11, 16),
    ...extra,
});

function render(rows) {
    const root = document.createElement("div");
    activityTable(root, rows, "Sem nada.", "t");
    return root;
}

test("each day gets a header, and the rows of that day sit under it", () => {
    const root = render([
        row("2026-09-29T15:04:00Z", "Bateria"),
        row("2026-09-29T14:54:00Z", "Atividade"),
        row("2026-09-28T16:56:00Z", "Bateria"),
    ]);

    const headers = [...root.querySelectorAll("[data-day-header]")];
    assert.equal(headers.length, 2, "esperavam-se dois dias distintos");
    assert.equal(root.querySelectorAll("tbody tr:not([data-day-header]):not(.telemetry-row-panel)").length, 3);
});

test("the header counts the rows of that day", () => {
    const root = render([
        row("2026-09-29T15:04:00Z", "Bateria"),
        row("2026-09-29T14:54:00Z", "Atividade"),
        row("2026-09-28T16:56:00Z", "Bateria"),
    ]);

    const [first, second] = [...root.querySelectorAll("[data-day-header]")]
        .map((el) => el.textContent.replace(/\s+/g, " ").trim());

    assert.match(first, /2$/, `o primeiro dia tem duas linhas: ${first}`);
    assert.match(second, /1$/, `o segundo dia tem uma linha: ${second}`);
});

test("today reads «Hoje» and yesterday reads «Ontem»", () => {
    const today = new Date();
    const yesterday = new Date(today.getTime() - 86400000);
    const root = render([
        row(today.toISOString(), "Bateria"),
        row(yesterday.toISOString(), "Atividade"),
    ]);

    const headers = [...root.querySelectorAll("[data-day-header]")]
        .map((el) => el.textContent.replace(/\s+/g, " ").trim());

    assert.match(headers[0], /^Hoje/i, headers[0]);
    assert.match(headers[1], /^Ontem/i, headers[1]);
});

test("a row without a date invents no header", () => {
    const root = render([{ icon: "fa-question", name: "Sem data", time: "-" }]);

    assert.equal(root.querySelectorAll("[data-day-header]").length, 0);
});
