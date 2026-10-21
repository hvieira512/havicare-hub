import test from "node:test";
import assert from "node:assert/strict";

import { requestCardShell as buildCard } from "../../src/Dashboard/dashboard/components/cards/request.js";

// O construtor devolve um fragmento de marcação; as assertivas de texto querem texto.
const requestCardShell = (...args) => String(buildCard(...args));

const connectivityCard = (data) => requestCardShell(
    { feature: "connectivity", requestable: false },
    false,
    [{ type: "connectivity", occurredAt: "2026-08-07T13:00:00Z", data }],
);

test("MKGW3 wifi connectivity shows the interface and signal strength", () => {
    // Exactamente o payload que o `GatewayNormalizer` emite para uma mensagem MOKO 3004.
    const html = connectivityCard({ interface: "wifi", signalStrengthDbm: -50 });

    assert.match(html, /Wi-Fi · -50 dBm/);
});

test("wired gateways render without a signal reading", () => {
    const html = connectivityCard({ interface: "ethernet" });

    assert.match(html, /Ethernet/);
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
});

test("a zero dBm reading is rendered rather than treated as missing", () => {
    const html = connectivityCard({ interface: "wifi", signalStrengthDbm: 0 });

    assert.match(html, /Wi-Fi · 0 dBm/);
});
