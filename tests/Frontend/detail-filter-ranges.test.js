import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { applyDetailRange, clearDetailFilters, detailFilterChipLabels, initDetailFilters } =
    await import("../../src/Dashboard/dashboard/devices/detail-filters.js");

/**
 * Ninguém escreve uma data para ver o dia de hoje: os alcances prontos aplicam-se ao toque e
 * o intervalo à mão fica para quem precisa mesmo dele.
 */
let changes;

function filterEls() {
    const element = (tag) => document.createElement(tag);
    return {
        detailFilterFrom: element("input"),
        detailFilterTo: element("input"),
        detailFilterType: element("select"),
        detailSearch: element("input"),
        detailRangePresets: element("div"),
        detailActiveFilters: element("div"),
        detailActiveFiltersRow: element("div"),
        detailFilterCount: element("span"),
        clearDetailFiltersBtn: element("button"),
    };
}

const startOfToday = () => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today.getTime();
};

beforeEach(() => {
    changes = 0;
    state.detailFilters = { from: "", to: "", type: "all", q: "" };
    initDetailFilters({
        els: filterEls(),
        onChange: () => {
            changes += 1;
        },
        renderDownlinkRequests: () => {},
        renderTelemetryList: () => {},
    });
});

test("o hoje começa à meia-noite de cá e não vinte e quatro horas atrás", () => {
    applyDetailRange("today");

    assert.equal(Date.parse(state.detailFilters.from), startOfToday());
    assert.equal(state.detailFilters.to, "");
});

test("sete dias conta sete dias para trás", () => {
    applyDetailRange("7d");

    const days = (Date.now() - Date.parse(state.detailFilters.from)) / 86400000;
    assert.ok(Math.abs(days - 7) < 0.01, `foram ${days} dias`);
});

test("o alcance aplica-se ao toque, sem passar pelo Aplicar", () => {
    applyDetailRange("30d");

    assert.equal(changes, 1);
    assert.equal(state.telemetryPage, 1);
});

test("a pastilha do alcance diz o nome do botão e não as datas", () => {
    applyDetailRange("7d");

    assert.deepEqual(detailFilterChipLabels(state.detailFilters), [
        { key: "range", label: "7 dias" },
    ]);
});

/** Um intervalo escrito à mão não é nenhum dos alcances, e a pastilha volta às datas. */
test("um intervalo à mão continua a mostrar as datas", () => {
    const labels = detailFilterChipLabels({
        from: "2026-09-01T00:00",
        to: "2026-09-02T00:00",
        type: "all",
        q: "",
    });

    assert.equal(labels.length, 1);
    assert.match(labels[0].label, /→/);
});

test("limpar os filtros larga o alcance escolhido", () => {
    applyDetailRange("today");
    clearDetailFilters();

    assert.deepEqual(detailFilterChipLabels(state.detailFilters), []);
});
