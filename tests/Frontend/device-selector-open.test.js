import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import {
    initDeviceListDetail,
    openDeviceSelector,
} from "../../src/Dashboard/dashboard/devices/list-detail.js";

/**
 * O modal de escolha abre antes de a resposta chegar.
 *
 * Antes, o `show()` estava atras do `await loadSummary()`: com o `/api/devices` lento, clicar
 * no botao nao dava sinal nenhum -- e o botao continuava clicavel, logo cada clique novo
 * pedia outra vez. O pedido fica pendurado de proposito neste teste: e exactamente o caso que
 * antes deixava o ecra sem modal.
 */
function setUpSelector() {
    document.body.innerHTML = `
        <div id="deviceList" class="device-card-list"></div>
        <div id="deviceSupplierFilter"></div>
        <div id="deviceModelFilter"></div>
        <div id="deviceLicenseFilter"></div>`;

    const byId = (id) => document.getElementById(id);
    const els = {
        deviceList: byId("deviceList"),
        deviceSupplierFilter: byId("deviceSupplierFilter"),
        deviceModelFilter: byId("deviceModelFilter"),
        deviceLicenseFilter: byId("deviceLicenseFilter"),
    };

    let shown = false;
    globalThis.fetch = () => new Promise(() => {});
    initDeviceListDetail({
        els,
        ui: {deviceSelectorModal: {show: () => {shown = true;}}},
        services: {},
    });

    return {els, wasShown: () => shown};
}

test("o modal aparece antes de a lista chegar, com esqueleto no lugar dela", async () => {
    const {els, wasShown} = setUpSelector();

    void openDeviceSelector();

    assert.equal(wasShown(), true);
    assert.ok(els.deviceList.querySelectorAll(".device-card-skeleton").length > 0);
    assert.equal(els.deviceList.getAttribute("aria-busy"), "true");
    // Os filtros nao ficam uma coluna vazia enquanto se espera.
    assert.ok(els.deviceSupplierFilter.querySelectorAll(".placeholder").length > 0);
});

test("o esqueleto tem a geometria do cartao, para a lista nao saltar quando chega", async () => {
    const {els} = setUpSelector();

    void openDeviceSelector();

    // As mesmas classes do cartao a serio: a altura, o raio e as colunas vem do mesmo CSS, e
    // nao de medidas copiadas para o esqueleto que depois divergem.
    const skeleton = els.deviceList.querySelector(".device-card-skeleton");
    assert.ok(skeleton.classList.contains("device-card"));
    assert.ok(skeleton.querySelector(".device-card-identity"));
    assert.equal(skeleton.querySelectorAll(".device-card-field").length, 2);

    // Enche a moldura: uma linha por dispositivo que a pagina pode trazer, ate ao maximo que
    // a moldura mais alta leva. Uma linha unica no topo era o que se via antes.
    const rows = els.deviceList.querySelectorAll(".device-card-skeleton");
    assert.equal(rows.length, 12);
    assert.ok(els.deviceList.querySelector(".device-card-skeleton-list.placeholder-wave"));
});
