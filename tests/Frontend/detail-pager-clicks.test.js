import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const {
    handleDownlinkPagerClick,
    handleTelemetryPagerClick,
    initDetailFilters,
} = await import("../../src/Dashboard/dashboard/devices/detail-filters.js");

/**
 * Os dois paginadores do detalhe correm sobre o mesmo algoritmo e declaram cada um o que o
 * distingue: a lista a que pertence, o tamanho de página, a página actual, o prefixo da
 * acção, quem guarda a página e quem redesenha.
 *
 * Trocar dois desses valores entre eles não parte nada que se veja em teste de render -- e
 * era o erro fácil. O que se afirma aqui é que cada clique mexe no painel certo.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) target[name] = document.createElement("div");
        return target[name];
    },
});

let drawn;

const pagerClick = (action) => {
    const button = document.createElement("button");
    button.dataset.action = action;
    document.body.appendChild(button);

    return { target: button };
};

const commandRow = (id) => ({ payload: { type: "command", feature: "battery", requestedAt: `2026-09-2${id}T10:00:00Z` } });
const telemetryRow = (id) => ({ payload: { type: "battery", occurredAt: `2026-09-2${id}T10:00:00Z`, data: { percent: id } } });

beforeEach(() => {
    drawn = { downlink: null, telemetry: null };
    initDetailFilters({
        els,
        onChange: () => {},
        renderDownlinkRequests: (rows) => { drawn.downlink = rows; },
        renderTelemetryList: (rows) => { drawn.telemetry = rows; },
    });
    state.detailFilters = { from: "", to: "", type: "all", q: "" };
    state.selectedDetail = {
        model: { deviceType: "watch" },
        recent: {
            commands: [commandRow(1), commandRow(2), commandRow(3), commandRow(4)],
            telemetry: [telemetryRow(1), telemetryRow(2), telemetryRow(3), telemetryRow(4)],
            events: [],
        },
    };
    // Tamanhos diferentes de propósito: com os dois iguais, uma troca passaria despercebida.
    state.downlinkPageSize = 2;
    state.telemetryPageSize = 3;
    state.downlinkPage = 1;
    state.telemetryPage = 1;
});

test("o paginador dos pedidos avança a página dos pedidos, e só essa", () => {
    handleDownlinkPagerClick(pagerClick("downlinkNext"));

    assert.equal(state.downlinkPage, 2);
    assert.equal(state.telemetryPage, 1, "a telemetria não se mexe");
    assert.equal(drawn.telemetry, null, "só o painel dos pedidos é redesenhado");
});

test("o paginador da telemetria avança a página da telemetria, e só essa", () => {
    handleTelemetryPagerClick(pagerClick("telemetryNext"));

    assert.equal(state.telemetryPage, 2);
    assert.equal(state.downlinkPage, 1);
    assert.equal(drawn.downlink, null);
});

/** Cada painel conta as suas linhas: quatro pedidos a dois por página dão duas páginas. */
test("cada painel usa o seu tamanho de página para saber onde pára", () => {
    handleDownlinkPagerClick(pagerClick("downlinkNext"));
    handleDownlinkPagerClick(pagerClick("downlinkNext"));

    assert.equal(state.downlinkPage, 2, "quatro pedidos a dois por página são duas páginas");

    handleTelemetryPagerClick(pagerClick("telemetryNext"));
    handleTelemetryPagerClick(pagerClick("telemetryNext"));

    assert.equal(state.telemetryPage, 2, "quatro leituras a três por página são duas páginas");
});

test("cada painel recebe as linhas do seu tipo, e não as do outro", () => {
    handleDownlinkPagerClick(pagerClick("downlinkNext"));
    assert.equal(drawn.downlink.length, 4, "os quatro pedidos, que a paginação corta a seguir");

    handleTelemetryPagerClick(pagerClick("telemetryNext"));
    assert.equal(drawn.telemetry.length, 4);
});

/** Um clique fora dos botões do paginador não mexe em nada. */
test("um clique que não é do paginador é ignorado", () => {
    handleDownlinkPagerClick(pagerClick("outraCoisa"));

    assert.equal(state.downlinkPage, 1);
    assert.equal(drawn.downlink, null);
});
