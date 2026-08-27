import test from "node:test";
import assert from "node:assert/strict";

import {
    gatewaySignalRows,
    linkSignal,
    signalBand,
    signalLabel,
    signalMeter,
} from "../../src/Dashboard/dashboard/devices/gateway-signal.js";

// Com a forma das linhas `linkedDevices` que o `/api/devices/{imei}` serve, onde o hub
// acrescenta o último avistamento registado do par (dispositivo, gateway).
const link = (deviceKey, model, extra = {}) => ({deviceKey, model, ...extra});

test("a link row carries the signal recorded for that pair", () => {
    const signal = linkSignal(link("c5e390f30bce", "MKGW4", {
        rssiDbm: -71,
        signalSeenAt: "2026-08-18T10:55:44Z",
    }));

    assert.equal(signal.rssiDbm, -71);
    assert.equal(signal.at, "2026-08-18T10:55:44Z");
});

test("a link never heard on has no signal rather than a zero", () => {
    assert.equal(linkSignal(link("dc1603ecf1f7", "MKGW4")), null);
    assert.equal(linkSignal(link("dc1603ecf1f7", "MKGW4", {rssiDbm: null})), null);
    assert.equal(linkSignal(undefined), null);
});

test("zero dBm is a real reading, not a missing one", () => {
    assert.equal(linkSignal(link("c5e390f30bce", "MKGW4", {rssiDbm: 0}))?.rssiDbm, 0);
});

test("an unheard link reads as unknown, not as near", () => {
    assert.equal(signalLabel(null), "Sem sinal");
    assert.equal(signalBand(null).bars, 0);
    assert.equal(signalBand(null).tone, "secondary");
});

test("a heard link shows the band, the reading and how stale it is", () => {
    const label = signalLabel({rssiDbm: -64, at: new Date().toISOString()});

    assert.match(label, /^Bom · -64 dBm · há \d+s$/);
});

test("a reading without a timestamp still shows band and strength", () => {
    assert.equal(signalLabel({rssiDbm: -64, at: ""}), "Bom · -64 dBm");
});

test("every band boundary lands in the documented category", () => {
    const band = (rssiDbm) => signalBand({rssiDbm, at: ""}).label;

    assert.equal(band(-30), "Excelente");
    assert.equal(band(-60), "Excelente");
    assert.equal(band(-61), "Bom");
    assert.equal(band(-67), "Bom");
    assert.equal(band(-68), "Razoável");
    assert.equal(band(-70), "Razoável");
    assert.equal(band(-71), "Fraco");
    assert.equal(band(-80), "Fraco");
    assert.equal(band(-81), "Muito fraco");
    assert.equal(band(-90), "Muito fraco");
    assert.equal(band(-91), "Inutilizável");
});

test("no two neighbouring bands share both bar count and colour", () => {
    // O medidor não pode depender só do tom: quem não separa as cores continua a ter a
    // contagem de barras cheias, e vice-versa.
    const bands = [-55, -64, -69, -75, -85, -95].map((rssiDbm) => signalBand({rssiDbm, at: ""}));
    const fingerprints = bands.map((band) => `${band.bars}:${band.tone}`);

    assert.equal(new Set(fingerprints).size, bands.length);
});

test("the meter carries the reading as a tooltip and an aria-label", () => {
    const html = signalMeter({rssiDbm: -64, at: ""});

    assert.match(html, /data-bs-toggle="tooltip"/);
    assert.match(html, /data-bs-title="Bom · -64 dBm"/);
    assert.match(html, /aria-label="Bom · -64 dBm"/);
    // Três de quatro barras cheias, nesta banda.
    assert.equal((html.match(/signal-meter-bar-on/g) || []).length, 3);
    assert.match(html, /text-success/);
});

test("the meter renders empty bars rather than nothing when unheard", () => {
    const html = signalMeter(null);

    assert.equal((html.match(/signal-meter-bar-on/g) || []).length, 0);
    assert.equal((html.match(/signal-meter-bar/g) || []).length, 4);
    assert.match(html, /Sem sinal/);
});

test("every link gets a row, heard or not", () => {
    const html = gatewaySignalRows([
        link("c5e390f30bce", "MKGW4", {rssiDbm: -71, signalSeenAt: new Date().toISOString()}),
        link("dc1603ecf1f7", "MKGW4"),
    ]);

    assert.match(html, /c5e390f30bce/);
    assert.match(html, /Fraco · -71 dBm/);
    // O gateway que nunca reportou tem de ficar listado, a mostrar a falta.
    assert.match(html, /dc1603ecf1f7/);
    assert.match(html, /Sem sinal/);
});

test("a gateway's own page shows the sensors it relays", () => {
    // As mesmas linhas vistas do outro lado da ligação: é isto que uma derivação do lado do
    // browser não consegue fazer, porque a telemetria de um gateway não traz leitura nenhuma
    // dos sensores que ele retransmite.
    const html = gatewaySignalRows([
        link("fbd87c59ba8b", "W6B", {rssiDbm: -75, signalSeenAt: new Date().toISOString()}),
    ]);

    assert.match(html, /fbd87c59ba8b/);
    assert.match(html, /Fraco · -75 dBm/);
});

test("no linked devices renders nothing at all", () => {
    assert.equal(gatewaySignalRows([]), "");
});
