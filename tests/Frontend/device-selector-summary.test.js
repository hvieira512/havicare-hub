import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";

const { deviceSelectorSummary } =
    await import("../../src/Dashboard/dashboard/devices/list.js");

/**
 * O contador do cabeçalho do selector.
 *
 * Dizia «49 dispositivos · 26 ligados» com quatro linhas na lista: a contagem é da frota
 * toda e ignora os filtros, e os filtros sobrevivem à sessão. Quem abre o selector com dois
 * filtros herdados de ontem lê um número que não tem nada a ver com o que está a ver.
 */

const totals = { total: 49, online: 26 };

test("sem filtros diz o tamanho da frota", () => {
    assert.equal(
        deviceSelectorSummary(totals, { total: 49 }, false),
        "49 dispositivos · 26 ligados",
    );
});

test("com filtros diz quantos ficam de quantos há", () => {
    assert.equal(
        deviceSelectorSummary(totals, { total: 4 }, true),
        "4 de 49 dispositivos",
    );
});

test("um só dispositivo não leva plural", () => {
    assert.equal(
        deviceSelectorSummary({ total: 1, online: 1 }, { total: 1 }, false),
        "1 dispositivo · 1 ligado",
    );
});

test("filtrar até nenhum diz zero, e não fica em branco", () => {
    assert.equal(
        deviceSelectorSummary(totals, { total: 0 }, true),
        "0 de 49 dispositivos",
    );
});

test("sem frota não há nada para contar", () => {
    assert.equal(deviceSelectorSummary({ total: 0, online: 0 }, { total: 0 }, false), "");
});
