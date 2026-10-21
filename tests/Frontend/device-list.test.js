import test from "node:test";
import assert from "node:assert/strict";

import {
    deviceListEmptyState,
    deviceSelectorSummary,
} from "../../src/Dashboard/dashboard/devices/list.js";

/**
 * Os filtros persistem entre sessões: o vazio tem de dizer que é deles, senão lê-se como «esse
 * aparelho não existe».
 *
 * O `online` é booleano, como o `changeDeviceFilter` o guarda: `null` é não filtrar.
 */
const filters = (overrides = {}) => ({
    deviceType: [],
    supplier: [],
    model: [],
    license: [],
    online: null,
    ...overrides,
});

test("sem filtros nem procura, diz que não há nada registado", () => {
    const empty = deviceListEmptyState(filters(), "");

    assert.match(empty.message, /Não há dispositivos registados/);
    assert.equal(empty.canClear, false);
});

test("com procura e sem filtros, nomeia o que se procurou", () => {
    const empty = deviceListEmptyState(filters(), "351266770073676");

    assert.match(empty.message, /351266770073676/);
    assert.equal(empty.canClear, false);
});

test("com filtros, diz quais são e oferece limpá-los", () => {
    const empty = deviceListEmptyState(filters({ online: true }), "");

    assert.match(empty.message, /ligados/);
    assert.equal(empty.canClear, true);
});

test("com procura e filtros, diz as duas coisas", () => {
    const empty = deviceListEmptyState(
        filters({ online: false, license: [1001] }),
        "351266770073676",
    );

    assert.match(empty.message, /351266770073676/);
    assert.match(empty.message, /desligados/);
    assert.match(empty.message, /licença/);
    assert.equal(empty.canClear, true);
});

test("cada grupo de filtro aparece uma vez só", () => {
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
