import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import {
    initDeviceList,
    openDeviceSelector,
} from "../../src/Dashboard/dashboard/devices/list.js";

/**
 * O modal de escolha abre antes de a resposta chegar. O pedido fica pendurado de propósito:
 * com o `show()` atrás do `await loadSummary()`, é o caso que deixa o ecrã sem modal e o
 * botão clicável, a pedir outra vez a cada clique.
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
    initDeviceList({
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
    // Os filtros não ficam uma coluna vazia enquanto se espera.
    assert.ok(els.deviceSupplierFilter.querySelectorAll(".placeholder").length > 0);
});

test("o esqueleto tem a geometria do cartão, para a lista não saltar quando chega", async () => {
    const {els} = setUpSelector();

    void openDeviceSelector();

    // As mesmas classes do cartão a sério: a altura, o raio e as colunas vêm do mesmo CSS, e
    // não de medidas copiadas para o esqueleto que depois divergem.
    const skeleton = els.deviceList.querySelector(".device-card-skeleton");
    assert.ok(skeleton.classList.contains("device-card"));
    assert.ok(skeleton.querySelector(".device-card-identity"));
    assert.equal(skeleton.querySelectorAll(".device-card-field").length, 2);

    // Enche a moldura: uma linha por dispositivo que a página pode trazer, até ao máximo que
    // a moldura mais alta leva.
    const rows = els.deviceList.querySelectorAll(".device-card-skeleton");
    assert.equal(rows.length, 12);
    assert.ok(els.deviceList.querySelector(".device-card-skeleton-list.placeholder-wave"));
});
