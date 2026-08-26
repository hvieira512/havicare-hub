import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o nome de uma capacidade vem do catalogo, e
// esse caminho passa pelo api/http.js, que toca em window ao carregar.
import "./support/browser-env.js";
import {renderRequestCardShell} from "../../src/Dashboard/dashboard/telemetry-cards.js";
import {state} from "../../src/Dashboard/dashboard/state.js";

// O nome de uma capacidade vem do catálogo do tipo do dispositivo escolhido, e não de um
// mapa escrito no frontend. Um cartão desenhado sem catálogo mostra a chave humanizada,
// por isso o teste põe o gateway em cima da mesa antes de desenhar.
state.capabilityCatalogByType.gateway = [
    {key: "connectivity", label: "Conectividade"},
];
state.selectedDetail = {model: {deviceType: "gateway"}};

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
