import test from "node:test";
import assert from "node:assert/strict";

import {renderRequestCardShell} from "../../src/Dashboard/dashboard/telemetry-cards.js";

const connectivityCard = (data) => renderRequestCardShell(
    {feature: "connectivity", requestable: false},
    false,
    [{type: "connectivity", occurredAt: "2026-08-07T13:00:00Z", data}],
);

test("MKGW3 wifi connectivity shows the interface and signal strength", () => {
    // Exactly the payload GatewayNormalizer emits for a MOKO 3004 message.
    const html = connectivityCard({interface: "wifi", signalStrengthDbm: -50});

    assert.match(html, /Wi-Fi · -50 dBm/);
    assert.match(html, /fa-wifi/);
});

test("wired gateways render without a signal reading", () => {
    const html = connectivityCard({interface: "ethernet"});

    assert.match(html, /Ethernet/);
    assert.match(html, /fa-ethernet/);
    assert.doesNotMatch(html, /dBm/);
});

test("MKGW4 cellular connectivity includes the network type", () => {
    const html = connectivityCard({
        interface: "cellular",
        networkType: "LTE",
        signalQuality: 18,
        signalStrengthDbm: -77,
    });

    assert.match(html, /Rede móvel · LTE · -77 dBm/);
    assert.match(html, /fa-tower-cell/);
});

test("a zero dBm reading is rendered rather than treated as missing", () => {
    const html = connectivityCard({interface: "wifi", signalStrengthDbm: 0});

    assert.match(html, /Wi-Fi · 0 dBm/);
});

test("connectivity falls back to its label when the payload carries nothing", () => {
    const html = connectivityCard({});

    assert.match(html, /Conectividade/);
    // Never the titleized English key that the missing label map produced.
    assert.doesNotMatch(html, /Connectivity/);
});

test("connectivity is labelled in Portuguese, not titleized", () => {
    const html = renderRequestCardShell({feature: "connectivity", requestable: false}, false, []);

    assert.match(html, /Conectividade/);
    assert.doesNotMatch(html, /Connectivity/);
});
