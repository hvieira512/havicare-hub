import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { flush, installDeferredFetch } from "./support/deferred-fetch.js";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { blankDeviceModal, state } = await import("../../src/Dashboard/dashboard/state.js");
const { editDevice, initDeviceModal, saveDevice } =
    await import("../../src/Dashboard/dashboard/devices/device-modal.js");

// Tudo `input`: os divs servem na mesma para `innerHTML`, e só o input tem `value`.
const els = autoEls(() => "input");

const detailFor = (imei) => ({
    device: { imei, deviceType: "watch", company: "havicare", licenseId: 1, deviceId: imei },
    model: { deviceType: "watch", supplier: "wonlex", internalModel: "D41" },
    linkedDevices: [],
    configurations: { [imei]: {} },
});

const errorResponse = () => Response.json({ error: { message: "Servidor indisponível." } });

beforeEach(() => {
    cacheElements(els);
    initDeviceModal({ deviceModal: { show() {}, hide() {} } });
    cacheElements(els);
    // Os dois catálogos em cache tiram do caminho pedidos que não são os desta corrida.
    state.deviceTypeSuppliersModels = [
        { deviceType: "watch", suppliers: [{ name: "wonlex", models: [{ internalModel: "D41" }] }] },
    ];
    state.licenses = [{ licenseId: 1, company: "havicare" }];
    state.capabilityCatalogByType.watch = [];
});

test("a late device response does not paint another device's modal", async () => {
    const fetches = installDeferredFetch();

    const first = editDevice("aaa111", "wonlex", "D41");
    await flush();
    const second = editDevice("bbb222", "wonlex", "D41");
    await flush();

    await fetches.respond("/api/devices/bbb222", detailFor("bbb222"));
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await Promise.all([first, second]);

    assert.equal(state.deviceModal.imei, "bbb222");
    assert.deepEqual(Object.keys(state.deviceModal.configurations), ["bbb222"]);
});

/**
 * Com o detalhe recusado o formulário fica com os valores de arranque, e o `saveDevice` lê só o
 * DOM: «Guardar» escreveria isso por cima do registo real.
 */
test("the modal whose detail the server refused cannot be saved", async () => {
    globalThis.fetch = async () => errorResponse();

    await editDevice("aaa111", "wonlex", "D41");
    await flush();

    // O que o modal mostra depois do erro: a classificação da linha da lista, e os valores
    // de arranque no resto. É com isto à frente que alguém carrega em «Guardar».
    els.deviceForm.dataset.deviceType = "watch";
    els.deviceForm.dataset.supplier = "wonlex";
    els.deviceForm.dataset.model = "D41";
    els.deviceImei.value = "861265061009822";
    els.deviceLicenseId.value = "";

    let posted = false;
    globalThis.fetch = async () => {
        posted = true;
        return errorResponse();
    };
    await saveDevice();

    assert.equal(posted, false, "o Guardar não pode chegar ao servidor");
    assert.match(state.deviceModal.errorMessage, /não chegou a carregar/i);
});

/**
 * O `editDevice` substitui o `state.deviceModal` inteiro, e o `panel.js` escreve sem guarda no
 * `actionDeliveries`.
 */
test("the blank shape carries every key of the initial state", () => {
    assert.deepEqual(
        Object.keys(blankDeviceModal()).sort(),
        Object.keys(state.deviceModal).sort(),
    );
});

test("each call brings its own maps, not the running state's", () => {
    const first = blankDeviceModal();
    const second = blankDeviceModal();

    first.actionDeliveries.x = 1;
    first.configurationSync.entries.y = 2;

    assert.deepEqual(second.actionDeliveries, {});
    assert.deepEqual(second.configurationSync.entries, {});
    assert.deepEqual(state.deviceModal.actionDeliveries, {});
});
