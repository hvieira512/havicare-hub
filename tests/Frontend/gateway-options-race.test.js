import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { installDeferredFetch } from "./support/deferred-fetch.js";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/core/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/core/state.js");
const { refreshGatewayOptions } =
    await import("../../src/Dashboard/dashboard/devices/gateways/gateway-links-ui.js");

const els = autoEls((name) => (name.endsWith("Btn")
    ? "button"
    : /Company$|LicenseId$|Imei$/.test(name) ? "input" : "div"));

const gatewaysOfBothLicenses = {
    data: [
        { imei: "gw-l1", deviceType: "gateway", company: "havicare", licenseId: "1" },
        { imei: "gw-l2", deviceType: "gateway", company: "havicare", licenseId: "2" },
    ],
    pagination: { page: 1, totalPages: 1, total: 2, limit: 500 },
};

beforeEach(() => {
    cacheElements(els);
    els.deviceForm.dataset.deviceType = "diaper_sensor";
    els.deviceCompany.value = "havicare";

    state.selectedDetail = null;
});

/** Os gateways de uma licença anterior, marcados e gravados, ligariam o sensor a outro cliente. */
test("a late reply does not draw the previous licence's gateways", async () => {
    const fetches = installDeferredFetch();

    els.deviceLicenseId.value = "1";
    const first = refreshGatewayOptions([]);
    els.deviceLicenseId.value = "2";
    const second = refreshGatewayOptions([]);

    await fetches.respond("licenseId=2", gatewaysOfBothLicenses);
    await fetches.respond("licenseId=1", gatewaysOfBothLicenses);
    await Promise.all([first, second]);

    assert.match(els.deviceGatewayLinksList.innerHTML, /gw-l2/);
    assert.doesNotMatch(
        els.deviceGatewayLinksList.innerHTML,
        /gw-l1/,
        "o gateway da licença anterior não pode ficar no ecrã",
    );
});
