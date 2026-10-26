import test from "node:test";
import assert from "node:assert/strict";

import { cardContent as uplinkCardContent } from "./support/cards.js";
import { requestCardShell as buildCard } from "../../src/Dashboard/dashboard/components/cards/request.js";

const requestCardShell = asText(buildCard);
import { state } from "../../src/Dashboard/dashboard/core/state.js";
import { asText } from "./support/dom.js";

state.capabilityCatalogByType.watch = [{ key: "location", label: "Localização" }];
state.selectedDetail = { model: { deviceType: "watch" } };

/**
 * As três formas em que um `location` chega em produção: resolvido com coordenadas, fixo de
 * rádio que não resolveu, e relatório periódico sem prova nenhuma.
 */

// A idade é relativa ao `Date.now()`: dois minutos dão «há 2m» com quase um minuto de margem
// em qualquer sentido.
const minutesAgo = (minutes) =>
    new Date(Date.now() - minutes * 60_000).toISOString();

const locationCard = (reports) =>
    requestCardShell(
        { feature: "location", requestable: false },
        false,
        reports.map(({ data, minutes = 2 }) => ({
            type: "location",
            occurredAt: minutesAgo(minutes),
            data,
        })),
    );

const GPS_FIX = {
    source: "gps",
    hasCoordinates: true,
    lat: 41.706841,
    lon: -8.793279,
    accuracyMeters: 5,
    gpsValid: true,
};

// O que o mapa de rádio privado devolve quando resolve um fixo de antenas e WiFi: as
// coordenadas com a origem original, porque o meio pelo qual se tentou não muda.
const RADIO_FIX = {
    source: "cell_wifi",
    hasCoordinates: true,
    lat: 41.706841,
    lon: -8.793279,
    accuracyMeters: 1,
    baseStations: [{ cellId: "194809015" }, { cellId: "194809005" }],
    wifiAccessPoints: [{ mac: "dc:fe:23:b8:31:73" }],
};

const UNRESOLVED = {
    source: "cell_wifi",
    hasCoordinates: false,
    gpsValid: false,
    speedKmh: 0.1,
    baseStations: [{ cellId: "677900", gsmSignal: 60 }],
    wifiAccessPoints: [{ mac: "dc-fe-23-b7-ed-ff" }],
};

// Um `upLocation` periódico com `baseStation: []`: o relógio acordou, tentou e não viu nada.
const NOTHING_SEEN = { hasCoordinates: false, reportKind: "periodic" };

test("with a position, the value is the coordinates and the details say how and how precisely", () => {
    const html = locationCard([{ data: GPS_FIX }]);

    // Cinco decimais: ~1 m, e o sexto são dez centímetros num mosaico de 206px.
    assert.match(html, /41\.70684, -8\.79328/);
    assert.match(html, /GPS · ±5 m · há 2 min/);
});

test("a position resolved from radio says Rádio, not the raw source", () => {
    // `cell`, `wifi` e `cell_wifi` são todos triangulação; o que distingue da de GPS é a
    // proveniência, não qual das antenas entrou na conta.
    const html = locationCard([{ data: RADIO_FIX }]);

    assert.match(html, /Rádio · ±1 m · há 2 min/);
    assert.doesNotMatch(html, /Cell Wifi/);
});

test("without a position, the value is a dash and the details are the radio evidence", () => {
    const html = locationCard([{ data: UNRESOLVED }]);

    assert.match(html, /—/);
    assert.match(html, /1 antena · 1 rede WiFi · há 2 min/);
    assert.doesNotMatch(html, /Atualização de localização/);
    assert.doesNotMatch(html, /GPS válido/);
    assert.doesNotMatch(html, /Velocidade/);
});

test("counts are pluralised, and a zero count does not show", () => {
    const html = locationCard([
        {
            data: {
                source: "cell",
                baseStations: [{ cellId: "1" }, { cellId: "2" }, { cellId: "3" }],
            },
        },
    ]);

    assert.match(html, /3 antenas · há 2 min/);
    assert.doesNotMatch(html, /rede/);
});

test("reporting and seeing nothing is a different failure, and is said in words", () => {
    // Aponta para o aparelho e não para a cobertura: não é "tentou e não resolveu".
    const html = locationCard([{ data: NOTHING_SEEN }]);

    assert.match(html, /Sem dados de rádio · há 2 min/);
});

test("a report without a position does not erase the last known one", () => {
    const html = locationCard([
        { data: UNRESOLVED, minutes: 1 },
        { data: RADIO_FIX, minutes: 14 },
    ]);

    assert.match(html, /41\.70684, -8\.79328/);
    // A idade é a do fixo que se mostra, e não a da tentativa mais recente.
    assert.match(html, /Rádio · ±1 m · há 14 min/);
});

test("a device that never resolved still shows its last attempt", () => {
    const html = locationCard([
        { data: UNRESOLVED, minutes: 1 },
        { data: UNRESOLVED, minutes: 2 },
    ]);

    assert.match(html, /1 antena · 1 rede WiFi · há 1 min/);
});

test("the chronological list shows no age: the time has its own column", () => {
    const content = uplinkCardContent("location", UNRESOLVED);

    assert.equal(content.details, "1 antena · 1 rede WiFi");
});

test("the watch geofence says the direction in Portuguese", () => {
    const content = uplinkCardContent("zone_exit", { zone: "geofence" });

    assert.equal(content.value, "Saiu da zona segura");
});
