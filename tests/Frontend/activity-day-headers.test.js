import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

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

test("cada dia leva um cabeçalho, e as linhas do mesmo dia ficam debaixo dele", () => {
    const root = render([
        row("2026-09-29T15:04:00Z", "Bateria"),
        row("2026-09-29T14:54:00Z", "Atividade"),
        row("2026-09-28T16:56:00Z", "Bateria"),
    ]);

    const headers = [...root.querySelectorAll("[data-day-header]")];
    assert.equal(headers.length, 2, "esperavam-se dois dias distintos");
    assert.equal(root.querySelectorAll("tbody tr[data-row-key], tbody tr:not([data-day-header]):not(.telemetry-row-panel)").length >= 3, true);
});

test("o cabeçalho conta as linhas daquele dia", () => {
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

test("o dia de hoje diz «Hoje» e o de ontem diz «Ontem»", () => {
    const hoje = new Date();
    const ontem = new Date(hoje.getTime() - 86400000);
    const root = render([
        row(hoje.toISOString(), "Bateria"),
        row(ontem.toISOString(), "Atividade"),
    ]);

    const headers = [...root.querySelectorAll("[data-day-header]")]
        .map((el) => el.textContent.replace(/\s+/g, " ").trim());

    assert.match(headers[0], /^Hoje/i, headers[0]);
    assert.match(headers[1], /^Ontem/i, headers[1]);
});

test("uma linha sem data não inventa cabeçalho nenhum", () => {
    const root = render([{ icon: "fa-question", name: "Sem data", time: "-" }]);

    assert.equal(root.querySelectorAll("[data-day-header]").length, 0);
});

test("a tabela leva um colgroup: com layout fixo as larguras saem dele e não da primeira linha", () => {
    const root = render([row("2026-09-29T15:04:00Z", "Bateria")]);
    const cols = [...root.querySelectorAll("colgroup col")];

    assert.equal(cols.length, 4, "quatro colunas, quatro <col>");
    assert.deepEqual(
        cols.map((col) => col.className),
        ["telemetry-col-icon", "", "telemetry-col-value", "telemetry-col-time"],
        "o nome mede o que sobra e por isso é o único <col> sem classe",
    );
});
