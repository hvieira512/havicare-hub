import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { flush } from "./support/deferred-fetch.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { editDevice, initDeviceModal, saveDevice } =
    await import("../../src/Dashboard/dashboard/devices/device-modal.js");
const { initGatewayLinksUi } =
    await import("../../src/Dashboard/dashboard/devices/gateway-links-ui.js");

/**
 * Com o detalhe recusado, o formulário fica com os valores de arranque — tipo «watch»,
 * licença «0», SIM vazio — e o modal já está à vista. O `saveDevice` lê só o DOM, por isso
 * «Guardar» escrevia isso por cima do registo real.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) target[name] = document.createElement("input");
        return target[name];
    },
});

const errorResponse = {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ error: { message: "Servidor indisponível." } }),
    headers: { get: () => "application/json" },
};

beforeEach(() => {
    initDeviceModal({ els, deviceModal: { show() {}, hide() {} } });
    initGatewayLinksUi({ els });
    state.deviceTypeSuppliersModels = [
        { deviceType: "watch", suppliers: [{ name: "wonlex", models: [{ internalModel: "D41" }] }] },
    ];
    state.licenses = [{ licenseId: 1, company: "havicare" }];
    state.capabilityCatalogByType.watch = [];
});

test("o modal cujo detalhe o servidor recusou não se deixa gravar", async () => {
    globalThis.fetch = async () => errorResponse;

    await editDevice("aaa111", "wonlex", "D41");
    await flush();

    // O que o modal mostra depois do erro: a classificação da linha da lista, e os valores
    // de arranque no resto. É com isto à frente que alguém carrega em «Guardar».
    els.deviceForm.dataset.deviceType = "watch";
    els.deviceForm.dataset.supplier = "wonlex";
    els.deviceForm.dataset.model = "D41";
    els.deviceImei.value = "861265061009822";
    els.deviceLicenseId.value = "0";

    let posted = false;
    globalThis.fetch = async () => {
        posted = true;
        return errorResponse;
    };
    await saveDevice();

    assert.equal(posted, false, "o Guardar não pode chegar ao servidor");
    assert.match(state.deviceModal.errorMessage, /não chegou a carregar/i);
});

/** A guarda é só da edição: criar não tem detalhe para carregar. */
test("criar um dispositivo continua a poder gravar-se", async () => {
    state.deviceModal = { ...state.deviceModal, mode: "create", detailLoaded: false };

    let posted = false;
    globalThis.fetch = async () => {
        posted = true;
        return errorResponse;
    };
    els.deviceForm.dataset.deviceType = "watch";
    els.deviceForm.dataset.supplier = "wonlex";
    els.deviceForm.dataset.model = "D41";
    els.deviceImei.value = "861265061009822";
    els.deviceLicenseId.value = "1";

    await saveDevice();

    assert.equal(posted, true);
});
