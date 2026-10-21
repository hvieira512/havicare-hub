import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { installDeferredFetch } from "./support/deferred-fetch.js";
import { autoEls } from "./support/els.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initGatewayLinksUi, refreshGatewayOptions } =
    await import("../../src/Dashboard/dashboard/devices/gateway-links-ui.js");

const els = autoEls((name) => (name.endsWith("Btn")
    ? "button"
    : /Company$|LicenseId$|Imei$/.test(name) ? "input" : "div"));

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

/** Os gateways de uma licença anterior, marcados e gravados, ligariam o sensor a outro cliente. */
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
