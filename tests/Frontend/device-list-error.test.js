import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { deviceListBody as buildBody } from "../../src/Dashboard/dashboard/devices/list.js";

// O construtor devolve um fragmento de marcação; as assertivas de texto querem texto.
const deviceListBody = (...args) => String(buildBody(...args));

/** «Não foi possível carregar» e «lista vazia» não se leem igual. */
test("uma falha a carregar mostra estado de erro com repetição, não 'lista vazia'", () => {
    const body = deviceListBody({ devices: [], devicesError: { code: "network_error" } });

    assert.match(body, /Não foi possível carregar/, "devia dizer que falhou");
    assert.match(body, /data-action="retryDeviceList"/, "devia oferecer repetir");
    assert.doesNotMatch(body, /Não há dispositivos/, "não é uma lista vazia");
});

test("sem dispositivos e sem erro mostra a lista vazia normal", () => {
    const body = deviceListBody({ devices: [] });

    assert.match(body, /Não há dispositivos/);
    assert.doesNotMatch(body, /retryDeviceList/);
});
