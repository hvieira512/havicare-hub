import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { installDeferredFetch } from "./support/deferred-fetch.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initGatewayLinksUi, refreshGatewayOptions } =
    await import("../../src/Dashboard/dashboard/devices/gateway-links-ui.js");

const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) {
            const isField = /Company$|LicenseId$|Imei$/.test(name);
            target[name] = document.createElement(
                name.endsWith("Btn") ? "button" : isField ? "input" : "div",
            );
        }
        return target[name];
    },
});

const gatewaysOfBothLicenses = {
    data: [
        { imei: "gw-l1", deviceType: "gateway", company: "havicare", licenseId: "1" },
        { imei: "gw-l2", deviceType: "gateway", company: "havicare", licenseId: "2" },
    ],
    pagination: { page: 1, total_pages: 1, total: 2, limit: 500 },
};

beforeEach(() => {
    initGatewayLinksUi({ els });
    els.deviceForm.dataset.deviceType = "diaper_sensor";
    els.deviceCompany.value = "havicare";
    state.deviceModal.gatewayOptions = [];
    state.selectedDetail = null;
});

/**
 * Trocar de licença duas vezes depressa desenhava os gateways da licença anterior e guardava-os
 * no estado do modal. Marcar um e gravar ligava o sensor a um gateway de outro cliente.
 */
test("a resposta atrasada não desenha os gateways da licença anterior", async () => {
    const fetches = installDeferredFetch();

    els.deviceLicenseId.value = "1";
    const first = refreshGatewayOptions([]);
    els.deviceLicenseId.value = "2";
    const second = refreshGatewayOptions([]);

    await fetches.respond("licenseId=2", gatewaysOfBothLicenses);
    await fetches.respond("licenseId=1", gatewaysOfBothLicenses);
    await Promise.all([first, second]);

    assert.deepEqual(
        state.deviceModal.gatewayOptions.map((gateway) => gateway.imei),
        ["gw-l2"],
        "só podem ficar os gateways da licença que está no formulário",
    );
    assert.doesNotMatch(
        els.deviceGatewayLinksList.innerHTML,
        /gw-l1/,
        "o gateway da licença anterior não pode ficar no ecrã",
    );
});
