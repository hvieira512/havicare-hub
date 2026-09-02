import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state, setDeviceListPage, setDeviceSort } = await import("../../src/Dashboard/dashboard/state.js");
const { withQuery } = await import("../../src/Dashboard/dashboard/api/http.js");

/**
 * A ordenação da lista de dispositivos vai ao servidor, porque a lista pagina no servidor:
 * ordenar no cliente ordenava só a página visível e parecia funcionar.
 */

beforeEach(() => {
    setDeviceSort("");
    setDeviceListPage(1);
});

test("por omissão não se pede ordenação nenhuma", () => {
    assert.equal(state.deviceSort, "");
});

test("escolher uma coluna guarda-a", () => {
    setDeviceSort("company");

    assert.equal(state.deviceSort, "company");
});

test("mudar a ordenação volta à primeira página", () => {
    // A página 4 era da lista ordenada de outra maneira, e as linhas dela já não são as
    // mesmas -- o mesmo raciocínio que já vale para os filtros.
    setDeviceListPage(4);
    setDeviceSort("-company");

    assert.equal(state.deviceListPage, 1);
});

test("sem ordenação escolhida o pedido não leva o parâmetro", () => {
    assert.equal(withQuery("/api/devices", { page: 1, sort: state.deviceSort }), "/api/devices?page=1");
});

test("com ordenação escolhida o pedido leva-a como o servidor a espera", () => {
    setDeviceSort("-company");

    assert.equal(
        withQuery("/api/devices", { page: 1, sort: state.deviceSort }),
        "/api/devices?page=1&sort=-company",
    );
});
