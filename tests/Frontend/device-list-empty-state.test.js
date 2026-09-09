import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { deviceListEmptyState } from "../../src/Dashboard/dashboard/devices/list.js";

/**
 * O vazio tem de dizer o que o está a causar.
 *
 * Os filtros do selector persistem entre sessões. Com «Ligados» guardado de uma vez anterior,
 * procurar o IMEI completo de um aparelho desligado devolvia «Não há dispositivos para o
 * filtro selecionado» -- que se lê como «esse aparelho não existe» -- enquanto o cabeçalho, a
 * meio palmo de distância, continuava a dizer «49 dispositivos». O único «Limpar» estava na
 * outra coluna.
 */
/** O `online` é booleano, como o `changeDeviceFilter` o guarda: `null` é não filtrar. */
const filters = (overrides = {}) => ({
    deviceType: [],
    supplier: [],
    model: [],
    license: [],
    online: null,
    ...overrides,
});

test("sem filtros nem procura, diz que não há nada registado", () => {
    const state = deviceListEmptyState(filters(), "");

    assert.match(state.message, /Não há dispositivos registados/);
    assert.equal(state.canClear, false);
});

test("com procura e sem filtros, nomeia o que se procurou", () => {
    const state = deviceListEmptyState(filters(), "351266770073676");

    assert.match(state.message, /351266770073676/);
    assert.equal(state.canClear, false);
});

test("com filtros, diz quais são e oferece limpá-los", () => {
    const state = deviceListEmptyState(filters({ online: true }), "");

    assert.match(state.message, /ligados/);
    assert.equal(state.canClear, true);
});

test("com procura e filtros, diz as duas coisas", () => {
    const state = deviceListEmptyState(
        filters({ online: false, license: [1001] }),
        "351266770073676",
    );

    assert.match(state.message, /351266770073676/);
    assert.match(state.message, /desligados/);
    assert.match(state.message, /licença/);
    assert.equal(state.canClear, true);
});

test("cada grupo de filtro aparece uma vez só", () => {
    const state = deviceListEmptyState(
        filters({ supplier: [3], model: ["Y6M"], deviceType: ["watch"] }),
        "",
    );

    assert.equal((state.message.match(/modelo/g) || []).length, 1);
    assert.match(state.message, /tipo/);
});
