import test from "node:test";
import assert from "node:assert/strict";

import {
    eligibleGateways,
    gatewayKeysFromLinks,
    gatewayLinkChanges,
} from "../../src/Dashboard/dashboard/devices/gateway-links.js";

test("gateway selector only offers gateways from the sensor company and license", () => {
    const devices = [
        {imei: "bb", deviceType: "gateway", company: "havicare", licenseId: 1},
        {imei: "aa", deviceType: "gateway", company: "havicare", licenseId: "1"},
        {imei: "wrong-license", deviceType: "gateway", company: "havicare", licenseId: "2"},
        {imei: "wrong-company", deviceType: "gateway", company: "other", licenseId: "1"},
        {imei: "sensor", deviceType: "diaper_sensor", company: "havicare", licenseId: "1"},
    ];

    assert.deepEqual(
        eligibleGateways(devices, "havicare", "1").map((device) => device.imei),
        ["aa", "bb"],
    );
});

test("linked device response resolves every gateway key without duplicates", () => {
    const links = [
        {deviceType: "gateway", gatewayDeviceKey: "D48C49F7909C", deviceKey: "ignored"},
        {deviceType: "gateway", gatewayDeviceKey: "d48c49f7909c"},
        {deviceType: "diaper_sensor", gatewayDeviceKey: "not-a-related-gateway"},
    ];

    assert.deepEqual(gatewayKeysFromLinks(links), ["d48c49f7909c"]);
});

test("gateway link changes support multiple additions and removals", () => {
    assert.deepEqual(
        gatewayLinkChanges(["gateway-a", "gateway-b"], ["gateway-b", "gateway-c"]),
        {add: ["gateway-c"], remove: ["gateway-a"]},
    );
});
