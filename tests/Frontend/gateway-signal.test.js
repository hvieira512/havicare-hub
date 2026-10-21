import test from "node:test";
import assert from "node:assert/strict";

import {
    gatewaySignalRows as buildRows,
    linkSignal,
    signalBand,
    signalLabel,
    signalMeter as buildMeter,
} from "../../src/Dashboard/dashboard/devices/gateway-signal.js";
import { asText } from "./support/dom.js";

const signalMeter = asText(buildMeter);
const gatewaySignalRows = asText(buildRows);

// Com a forma das linhas `linkedDevices` que o `/api/devices/{imei}` serve, onde o hub
// acrescenta o último avistamento registado do par (dispositivo, gateway).
const link = (deviceKey, model, extra = {}) => ({ deviceKey, model, ...extra });

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
    assert.equal(linkSignal(link("dc1603ecf1f7", "MKGW4", { rssiDbm: null })), null);
    assert.equal(linkSignal(undefined), null);
});

test("zero dBm is a real reading, not a missing one", () => {
    assert.equal(linkSignal(link("c5e390f30bce", "MKGW4", { rssiDbm: 0 }))?.rssiDbm, 0);
});

test("an unheard link reads as unknown, not as near", () => {
    assert.equal(signalLabel(null), "Sem sinal");
    assert.equal(signalBand(null).bars, 0);
    assert.equal(signalBand(null).tone, "secondary");
});

test("a heard link shows the band, the reading and how stale it is", () => {
    const label = signalLabel({ rssiDbm: -64, at: new Date().toISOString() });

    assert.match(label, /^Bom · -64 dBm · (agora|há \d+ s)$/);
});

test("a reading without a timestamp still shows band and strength", () => {
    assert.equal(signalLabel({ rssiDbm: -64, at: "" }), "Bom · -64 dBm");
});

/**
 * O registo em Redis guarda o último dBm para sempre: acima de 60 s -- os 30 s que o hub tolera
 * mais os 30 s da sondagem -- a leitura já não se mostra como sinal.
 */
const secondsAgo = (seconds) => new Date(Date.now() - (seconds * 1000)).toISOString();

test("a stale reading stops claiming a quality", () => {
    const stale = { rssiDbm: -32, at: secondsAgo(28 * 86400) };

    assert.equal(signalBand(stale).bars, 0);
    assert.equal(signalBand(stale).tone, "secondary");
    assert.doesNotMatch(signalLabel(stale), /Excelente/);
});

test("but still says when it was and what it read", () => {
    const label = signalLabel({ rssiDbm: -32, at: secondsAgo(28 * 86400) });

    assert.match(label, /-32 dBm/);
    assert.match(label, /28 dias/);
});

test("within the limit, the reading still counts", () => {
    const fresh = { rssiDbm: -32, at: secondsAgo(59) };

    assert.equal(signalBand(fresh).label, "Excelente");
    assert.equal(signalBand(fresh).bars, 4);
});

test("the limit is the hub's 30 s plus the 30 s poll", () => {
    assert.equal(signalBand({ rssiDbm: -32, at: secondsAgo(61) }).bars, 0);
});

test("every band boundary lands in the documented category", () => {
    const band = (rssiDbm) => signalBand({ rssiDbm, at: "" }).label;

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
    const bands = [-55, -64, -69, -75, -85, -95].map((rssiDbm) => signalBand({ rssiDbm, at: "" }));
    const fingerprints = bands.map((band) => `${band.bars}:${band.tone}`);

    assert.equal(new Set(fingerprints).size, bands.length);
});

test("the meter renders empty bars rather than nothing when unheard", () => {
    const html = signalMeter(null);

    assert.match(html, /Sem sinal/);
});

test("every link gets a row, heard or not", () => {
    const html = gatewaySignalRows([
        link("c5e390f30bce", "MKGW4", { rssiDbm: -71, signalSeenAt: new Date().toISOString() }),
        link("dc1603ecf1f7", "MKGW4"),
    ]);

    assert.match(html, /c5e390f30bce/);
    assert.match(html, /Fraco · -71 dBm/);
    // O gateway que nunca reportou tem de ficar listado, a mostrar a falta.
    assert.match(html, /dc1603ecf1f7/);
    assert.match(html, /Sem sinal/);
});

test("no linked devices renders nothing at all", () => {
    assert.equal(gatewaySignalRows([]), "");
});
