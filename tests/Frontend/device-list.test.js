import test from "node:test";
import assert from "node:assert/strict";

import {
    deviceListEmptyState,
    deviceSelectorSummary,
} from "../../src/Dashboard/dashboard/devices/list.js";

/**
 * Os filtros persistem entre sessões: o vazio tem de dizer que é deles, senão lê-se como «esse
 * aparelho não existe». No `online`, `null` é não filtrar.
 */
const filters = (overrides = {}) => ({
    deviceType: [],
    supplier: [],
    model: [],
    license: [],
    online: null,
    ...overrides,
});

test("with no filters and no search it says nothing is registered", () => {
    const empty = deviceListEmptyState(filters(), "");

    assert.match(empty.message, /Não há dispositivos registados/);
    assert.equal(empty.canClear, false);
});

test("with a search and no filters it names what was searched", () => {
    const empty = deviceListEmptyState(filters(), "351266770073676");

    assert.match(empty.message, /351266770073676/);
    assert.equal(empty.canClear, false);
});

test("with filters it says which and offers to clear them", () => {
    const empty = deviceListEmptyState(filters({ online: true }), "");

    assert.match(empty.message, /ligados/);
    assert.equal(empty.canClear, true);
});

test("with a search and filters it says both", () => {
    const empty = deviceListEmptyState(
        filters({ online: false, license: [1001] }),
        "351266770073676",
    );

    assert.match(empty.message, /351266770073676/);
    assert.match(empty.message, /desligados/);
    assert.match(empty.message, /licença/);
    assert.equal(empty.canClear, true);
});

test("each filter group appears only once", () => {
    const empty = deviceListEmptyState(
        filters({ supplier: [3], model: ["Y6M"], deviceType: ["watch"] }),
        "",
    );

    assert.equal((empty.message.match(/modelo/g) || []).length, 1);
    assert.match(empty.message, /tipo/);
});

/**
 * O contador do cabeçalho segue os filtros, que sobrevivem à sessão: a contagem da frota toda
 * não tem nada a ver com o que está na lista.
 */

const totals = { total: 49, online: 26 };

test("without filters it says the fleet size", () => {
    assert.equal(
        deviceSelectorSummary(totals, { total: 49 }, false),
        "49 dispositivos · 26 ligados",
    );
});

test("with filters it says how many remain out of how many", () => {
    assert.equal(
        deviceSelectorSummary(totals, { total: 4 }, true),
        "4 de 49 dispositivos",
    );
});

test("a single device takes no plural", () => {
    assert.equal(
        deviceSelectorSummary({ total: 1, online: 1 }, { total: 1 }, false),
        "1 dispositivo · 1 ligado",
    );
});

test("filtering down to none says zero instead of staying blank", () => {
    assert.equal(
        deviceSelectorSummary(totals, { total: 0 }, true),
        "0 de 49 dispositivos",
    );
});

test("without a fleet there is nothing to count", () => {
    assert.equal(deviceSelectorSummary({ total: 0, online: 0 }, { total: 0 }, false), "");
});
